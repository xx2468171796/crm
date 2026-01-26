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
    if (!serverUrl || !token) return [];
    
    try {
      const data: any = await http.get('desktop_project_stage.php');
      return data.success ? data.data?.projects || [] : [];
    } catch (err) {
      console.error('[AutoSync] 获取项目列表失败:', err);
      return [];
    }
  }, [serverUrl, token]);

  // 获取项目文件列表
  const fetchProjectFiles = useCallback(async (projectId: number) => {
    if (!serverUrl || !token) return null;
    
    try {
      const data: any = await http.get(`desktop_project_files.php?project_id=${projectId}`);
      return data.success ? data.data : null;
    } catch (err) {
      console.error('[AutoSync] 获取文件列表失败:', err);
      return null;
    }
  }, [serverUrl, token]);

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
    if (!serverUrl || !token) return false;
    
    try {
      const meta = await getFileMetadata(filePath);
      const fileSize = meta.size;
      
      // 1. 初始化分片上传（使用新的本地缓存API）
      const initRes = await fetch(`${serverUrl}/api/desktop_chunk_upload.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'init',
          group_code: groupCode,
          project_id: projectId || 0,
          asset_type: assetType,
          filename: fileName,
          filesize: fileSize,
          mime_type: 'application/octet-stream',
        }),
      });
      const initData = await initRes.json();
      
      if (!initData.success || !initData.data) {
        throw new Error(initData.error || '初始化上传失败');
      }
      
      const { upload_id, part_size, total_parts } = initData.data;
      
      // 2. 分片上传到本地缓存
      for (let partNumber = 1; partNumber <= total_parts; partNumber++) {
        const start = (partNumber - 1) * part_size;
        const end = Math.min(start + part_size, fileSize);
        const chunkData = await readFileChunk(filePath, start, end - start);
        
        const formData = new FormData();
        formData.append('upload_id', upload_id);
        formData.append('part_number', partNumber.toString());
        formData.append('chunk', new Blob([chunkData]));
        
        const uploadRes = await fetch(`${serverUrl}/api/desktop_chunk_upload.php`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`,
          },
          body: formData,
        });
        
        const uploadData = await uploadRes.json();
        if (!uploadData.success) {
          throw new Error(uploadData.error || `分片 ${partNumber} 上传失败`);
        }
      }
      
      // 3. 完成上传（服务端异步上传到S3）
      const completeRes = await fetch(`${serverUrl}/api/desktop_chunk_upload.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'complete', upload_id }),
      });
      const completeData = await completeRes.json();
      
      return completeData.success;
    } catch (err) {
      console.error('[AutoSync] 上传失败:', err);
      return false;
    }
  }, [serverUrl, token]);

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

  // 执行同步
  const performSync = useCallback(async () => {
    // 只要开启自动同步且有根目录就执行（上传和下载分别检查各自的开关）
    if (isScanningRef.current || !rootDir || !autoSync) {
      return;
    }
    
    isScanningRef.current = true;
    console.log('[AutoSync] 开始扫描...', { rootDir });
    
    try {
      const allProjects = await fetchProjects();
      // 限制：只同步前 20 个项目，避免请求过多导致服务器崩溃
      const projects = allProjects.slice(0, 20);
      console.log(`[AutoSync] 处理 ${projects.length}/${allProjects.length} 个项目`);
      
      let uploadCount = 0;
      let downloadCount = 0;
      
      // 限制并发：每次只处理一个项目，每个项目之间间隔 500ms
      for (let i = 0; i < projects.length; i++) {
        const project = projects[i];
        // 使用 group_code 作为本地文件夹名（与 S3 路径一致）
        const groupCode = project.group_code || `P${project.id}`;
        const groupName = sanitizeFolderName(project.group_name || '');
        const groupFolderName = groupName ? `${groupCode}_${groupName}` : groupCode;
        // 使用项目名称作为子文件夹
        const projectName = sanitizeFolderName(project.project_name || project.project_code || `项目${project.id}`);
        const basePath = `${rootDir}/${groupFolderName}/${projectName}`;
        
        // 添加延迟，避免请求过于密集
        if (i > 0) {
          await delay(500);
        }
        
        // 获取云端文件列表
        const cloudFiles = await fetchProjectFiles(project.id);
        if (!cloudFiles) continue;
        
        // 自动上传：作品文件和模型文件
        if (config.autoUpload) {
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
        
        // 自动下载：客户文件
        if (config.autoDownload) {
          const customerFiles = cloudFiles.categories?.['客户文件']?.files || [];
          const localCustomerFiles = await scanLocalFolder(`${basePath}/客户文件`);
          const localFileNames = localCustomerFiles.map(f => f.name);
          
          console.log('[AutoSync] 客户文件列表:', customerFiles.length, '本地文件:', localFileNames.length);
          
          for (const cloudFile of customerFiles) {
            if (!localFileNames.includes(cloudFile.filename)) {
              console.log('[AutoSync] 下载客户文件:', cloudFile.filename);
              
              // 优先使用 API 返回的 download_url（预签名URL）
              let downloadUrl = cloudFile.download_url;
              const storageKey = cloudFile.storage_key;
              
              // 如果没有 download_url，则调用 API 获取
              if (!downloadUrl && storageKey) {
                try {
                  const downloadRes = await fetch(`${serverUrl}/api/desktop_download.php?action=get_url&storage_key=${encodeURIComponent(storageKey)}`, {
                    headers: { 'Authorization': `Bearer ${token}` },
                  });
                  const downloadData = await downloadRes.json();
                  if (downloadData.success && downloadData.data?.url) {
                    downloadUrl = downloadData.data.url;
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
  }, [rootDir, autoSync, config, fetchProjects, fetchProjectFiles, scanLocalFolder, uploadFile, downloadFileToLocal, serverUrl, token, toast]);

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
