import { useEffect, useRef, useCallback } from 'react';
import { useSettingsStore } from '@/stores/settings';
import { useSyncStore } from '@/stores/sync';
import { useAuthStore } from '@/stores/auth';
import { useToast } from '@/hooks/use-toast';
import { http } from '@/lib/http';
import { downloadFileChunked, getFileMetadata, listDirEntries, readFileChunk } from '@/lib/tauri';

interface CloudFile {
  id: number;
  filename: string;
  file_size?: number;
  size?: number;
  storage_key?: string;
  download_url?: string;
}

function sanitizeFolderName(name: string): string {
  return (name || '').replace(/[\/\\:*?"<>|]/g, '_');
}

interface LocalFile {
  name: string;
  path: string;
}

export function useAutoSync() {
  const { serverUrl, rootDir, autoSync, syncInterval } = useSettingsStore();
  const { config } = useSyncStore();
  const { token } = useAuthStore();
  const { toast } = useToast();
  
  const intervalRef = useRef<NodeJS.Timeout | null>(null);
  const isScanningRef = useRef(false);

  // 获取所有项目列表
  const fetchProjects = useCallback(async () => {
    const { serverUrl: sUrl } = useSettingsStore.getState();
    const { token: t } = useAuthStore.getState();
    if (!sUrl || !t) return [];
    
    try {
      const data = await http.get<{ projects: any[] }>('desktop_project_stage.php');
      return data.success ? data.data?.projects || [] : [];
    } catch (err) {
      console.error('[AutoSync] 获取项目列表失败:', err);
      return [];
    }
  }, []);

  // 获取项目文件列表
  const fetchProjectFiles = useCallback(async (projectId: number) => {
    const { serverUrl: sUrl } = useSettingsStore.getState();
    const { token: t } = useAuthStore.getState();
    if (!sUrl || !t) return null;
    
    try {
      const data = await http.get<any>(`desktop_project_files.php?project_id=${projectId}`);
      return data.success ? data.data : null;
    } catch (err) {
      console.error('[AutoSync] 获取文件列表失败:', err);
      return null;
    }
  }, []);

  // 扫描本地文件夹
  const scanLocalFolder = useCallback(async (folderPath: string): Promise<LocalFile[]> => {
    try {
      const entries = await listDirEntries(folderPath).catch(() => []);
      return entries
        .filter(e => e.is_file)
        .map(e => ({ name: e.name, path: e.path }));
    } catch (err) {
      return [];
    }
  }, []);

  // 上传文件（使用本地缓存分片上传API）
  const uploadFile = useCallback(async (
    filePath: string,
    fileName: string,
    groupCode: string,
    assetType: 'works' | 'models',
    projectId?: number
  ) => {
    const currentServerUrl = useSettingsStore.getState().serverUrl;
    const currentToken = useAuthStore.getState().token;
    if (!currentServerUrl || !currentToken) return false;
    
    try {
      const meta = await getFileMetadata(filePath);
      const fileSize = meta.size;
      
      // 1. 初始化分片上传
      const initResult = await http.post<{ upload_id: string; part_size: number; total_parts: number }>(
        'desktop_chunk_upload.php',
        { action: 'init', group_code: groupCode, project_id: projectId || 0, asset_type: assetType, filename: fileName, filesize: fileSize, mime_type: 'application/octet-stream' }
      );
      
      if (!initResult.success || !initResult.data) {
        throw new Error(initResult.error?.message || '初始化上传失败');
      }
      
      const { upload_id, part_size, total_parts } = initResult.data;
      
      // 2. 并发分片上传到本地缓存（3个并发）
      const CONCURRENT_UPLOADS = 3;
      
      const uploadPart = async (partNumber: number): Promise<void> => {
        const start = (partNumber - 1) * part_size;
        const end = Math.min(start + part_size, fileSize);
        const chunkData = await readFileChunk(filePath, start, end - start);
        
        const formData = new FormData();
        formData.append('upload_id', upload_id);
        formData.append('part_number', partNumber.toString());
        formData.append('chunk', new Blob([chunkData as any], { type: 'application/octet-stream' }));
        
        // FormData 上传需要直接 fetch（http 客户端设置 Content-Type 为 JSON）
        const uploadRes = await fetch(`${currentServerUrl}/api/desktop_chunk_upload.php`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${currentToken}` },
          body: formData,
        });
        
        const uploadData = await uploadRes.json();
        if (!uploadData.success) {
          throw new Error(uploadData.error || `分片 ${partNumber} 上传失败`);
        }
      };
      
      // 并发上传所有分片
      const partNumbers = Array.from({ length: total_parts }, (_, i) => i + 1);
      for (let i = 0; i < partNumbers.length; i += CONCURRENT_UPLOADS) {
        const batch = partNumbers.slice(i, i + CONCURRENT_UPLOADS);
        await Promise.all(batch.map(partNumber => uploadPart(partNumber)));
      }
      
      // 3. 完成上传
      const completeResult = await http.post<void>('desktop_chunk_upload.php', { action: 'complete', upload_id });
      
      return completeResult.success;
    } catch (err) {
      console.error('[AutoSync] 上传失败:', err);
      return false;
    }
  }, []);

  // 下载文件到本地
  const downloadFileToLocal = useCallback(async (
    downloadUrl: string,
    localPath: string
  ) => {
    try {
      const taskId = `auto-sync-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const result = await downloadFileChunked(taskId, downloadUrl, localPath);
      if (!result.success) {
        throw new Error(result.error || '下载失败');
      }
      return true;
    } catch (err) {
      console.error('[AutoSync] 下载失败:', err);
      return false;
    }
  }, []);

  // 延迟函数
  const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

  // 执行同步 — 使用 getState() 获取最新值，减少依赖
  const performSync = useCallback(async () => {
    const { rootDir: currentRootDir, autoSync: currentAutoSync } = useSettingsStore.getState();
    const currentConfig = useSyncStore.getState().config;
    
    if (isScanningRef.current || !currentRootDir || !currentAutoSync) {
      return;
    }
    
    isScanningRef.current = true;
    console.log('[AutoSync] 开始扫描...', { rootDir: currentRootDir });
    
    try {
      const allProjects = await fetchProjects();
      const projects = allProjects.slice(0, 20);
      console.log(`[AutoSync] 处理 ${projects.length}/${allProjects.length} 个项目`);
      
      let uploadCount = 0;
      let downloadCount = 0;
      
      for (let i = 0; i < projects.length; i++) {
        const project = projects[i];
        const groupCode = project.group_code || `P${project.id}`;
        const groupName = sanitizeFolderName(project.group_name || '');
        const groupFolderName = groupName ? `${groupCode}_${groupName}` : groupCode;
        const projectName = sanitizeFolderName(project.project_name || project.project_code || `项目${project.id}`);
        const basePath = `${currentRootDir}/${groupFolderName}/${projectName}`;
        
        if (i > 0) {
          await delay(500);
        }
        
        const cloudFiles = await fetchProjectFiles(project.id);
        if (!cloudFiles) continue;
        
        // 自动上传
        if (currentConfig.autoUpload) {
          for (const category of ['作品文件', '模型文件']) {
            const localFiles = await scanLocalFolder(`${basePath}/${category}`);
            const cloudFileNames = (cloudFiles.categories?.[category]?.files || []).map((f: CloudFile) => f.filename);
            
            for (const localFile of localFiles) {
              if (!cloudFileNames.includes(localFile.name)) {
                console.log('[AutoSync] 发现新文件:', localFile.name);
                const success = await uploadFile(
                  localFile.path,
                  localFile.name,
                  groupCode,
                  category === '作品文件' ? 'works' : 'models',
                  project.id
                );
                if (success) uploadCount++;
              }
            }
          }
        }
        
        // 自动下载
        if (currentConfig.autoDownload) {
          const customerFiles = cloudFiles.categories?.['客户文件']?.files || [];
          const localCustomerFiles = await scanLocalFolder(`${basePath}/客户文件`);
          const localFileNames = localCustomerFiles.map(f => f.name);
          
          for (const cloudFile of customerFiles) {
            if (!localFileNames.includes(cloudFile.filename)) {
              console.log('[AutoSync] 下载客户文件:', cloudFile.filename);
              
              let downloadUrl = cloudFile.download_url;
              const storageKey = cloudFile.storage_key;
              
              // 使用统一 http 客户端获取下载 URL
              if (!downloadUrl && storageKey) {
                try {
                  const downloadResult = await http.get<{ url: string }>(`desktop_download.php?action=get_url&storage_key=${encodeURIComponent(storageKey)}`);
                  if (downloadResult.success && downloadResult.data?.url) {
                    downloadUrl = downloadResult.data.url;
                  }
                } catch (e) {
                  console.error('[AutoSync] 获取下载URL失败:', e);
                }
              }
              
              if (downloadUrl) {
                const localPath = `${basePath}/客户文件/${cloudFile.filename}`;
                const success = await downloadFileToLocal(downloadUrl, localPath);
                if (success) downloadCount++;
              } else {
                console.warn('[AutoSync] 跳过下载（缺少 download_url/storage_key）:', cloudFile.filename);
              }
            }
          }
        }
      }
      
      if (uploadCount > 0 || downloadCount > 0) {
        toast({
          title: '自动同步完成',
          description: `上传 ${uploadCount} 个文件，下载 ${downloadCount} 个文件`,
        });
      }
    } catch (err) {
      console.error('[AutoSync] 同步失败:', err);
    } finally {
      isScanningRef.current = false;
    }
  }, [fetchProjects, fetchProjectFiles, scanLocalFolder, uploadFile, downloadFileToLocal, toast]);

  // 启动/停止自动同步
  useEffect(() => {
    if (autoSync && rootDir && syncInterval > 0) {
      console.log(`[AutoSync] 启动自动同步，间隔 ${syncInterval} 秒`);
      
      // 立即执行一次
      performSync();
      
      // 定时执行
      intervalRef.current = setInterval(performSync, syncInterval * 1000);
      
      return () => {
        if (intervalRef.current) {
          clearInterval(intervalRef.current);
          intervalRef.current = null;
        }
      };
    }
  }, [autoSync, rootDir, syncInterval, performSync]);

  return { performSync };
}
