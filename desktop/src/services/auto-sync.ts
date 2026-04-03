import { useSyncStore } from '@/stores/sync';
import { useSettingsStore } from '@/stores/settings';
import { scanRootDirectory, getLocalFiles, getFileMetadata } from '@/lib/tauri';
import { http } from '@/lib/http';

interface ConflictInfo {
  localPath: string;
  relPath: string;
  localSize: number;
  localModified: number;
  remoteSize: number;
  remoteModified: string;
  groupCode: string;
  assetType: 'works' | 'models';
}

let syncTimer: ReturnType<typeof setInterval> | null = null;
let isRunning = false;

export async function startAutoSync(intervalMinutes: number = 30): Promise<void> {
  stopAutoSync();
  
  if (intervalMinutes < 1) return;
  
  console.log('[SYNC_DEBUG] 启动定时同步，间隔:', intervalMinutes, '分钟');
  
  syncTimer = setInterval(async () => {
    await runAutoSync();
  }, intervalMinutes * 60 * 1000);
  
  useSettingsStore.getState().setAutoSyncEnabled(true);
  useSettingsStore.getState().setAutoSyncInterval(intervalMinutes);
}

export function stopAutoSync(): void {
  if (syncTimer) {
    clearInterval(syncTimer);
    syncTimer = null;
    console.log('[SYNC_DEBUG] 停止定时同步');
  }
  useSettingsStore.getState().setAutoSyncEnabled(false);
}

export async function runAutoSync(): Promise<void> {
  if (isRunning) {
    console.log('[SYNC_DEBUG] 同步正在进行中，跳过');
    return;
  }
  
  const { rootDir } = useSettingsStore.getState();
  if (!rootDir) {
    console.log('[SYNC_DEBUG] 未设置根目录，跳过同步');
    return;
  }
  
  isRunning = true;
  console.log('[SYNC_DEBUG] 开始自动同步...');
  
  try {
    const groups = await scanRootDirectory(rootDir);
    
    for (const group of groups) {
      await syncGroupAssets(group.group_code, group.path, 'works');
      await syncGroupAssets(group.group_code, group.path, 'models');
    }
    
    useSettingsStore.getState().setLastSyncTime(Date.now());
    console.log('[SYNC_DEBUG] 自动同步完成');
  } catch (error) {
    console.error('[SYNC_DEBUG] 自动同步失败:', error);
  } finally {
    isRunning = false;
  }
}

async function syncGroupAssets(
  groupCode: string,
  groupPath: string,
  assetType: 'works' | 'models'
): Promise<void> {
  const { rootDir } = useSettingsStore.getState();
  if (!rootDir) return;
  
  try {
    const localFiles = await getLocalFiles(rootDir, groupCode, assetType);
    const filesToCheck = localFiles.filter(f => !f.is_dir);
    
    if (filesToCheck.length === 0) return;
    
    const params = new URLSearchParams({
      group_code: groupCode,
      asset_type: assetType,
      per_page: '500',
    });
    
    const response = await http.get<any>(`desktop_group_resources.php?${params}`);
    if (!response.success) return;
    
    const remoteResources = response.data?.items || [];
    const remoteByRelPath = new Map<string, any>();
    for (const r of remoteResources) {
      remoteByRelPath.set(r.rel_path, r);
    }
    
    const conflicts: ConflictInfo[] = [];
    
    for (const file of filesToCheck) {
      const remote = remoteByRelPath.get(file.rel_path);
      
      if (!remote) {
        continue;
      }
      
      const dirName = assetType === 'works' ? '作品文件' : '模型文件';
      const fullPath = `${groupPath}/${dirName}/${file.rel_path}`;
      
      try {
        const localMeta = await getFileMetadata(fullPath);
        
        if (localMeta.size !== remote.filesize) {
          conflicts.push({
            localPath: fullPath,
            relPath: file.rel_path,
            localSize: localMeta.size,
            localModified: localMeta.modified_at,
            remoteSize: remote.filesize,
            remoteModified: remote.updated_at || remote.created_at,
            groupCode,
            assetType,
          });
        }
      } catch (error) {
        console.error('[SYNC_DEBUG] 检查文件冲突失败:', file.rel_path, error);
      }
    }
    
    if (conflicts.length > 0) {
      useSyncStore.getState().setConflicts(conflicts);
      console.log('[SYNC_DEBUG] 发现', conflicts.length, '个同名文件冲突');
    }
    
  } catch (error) {
    console.error('[SYNC_DEBUG] 同步群资源失败:', groupCode, assetType, error);
  }
}

export function getConflicts(): ConflictInfo[] {
  return useSyncStore.getState().conflicts || [];
}

export function clearConflicts(): void {
  useSyncStore.getState().setConflicts([]);
}

export async function resolveConflict(
  conflict: ConflictInfo,
  action: 'keep_local' | 'keep_remote' | 'rename_upload'
): Promise<void> {
  const { addUploadTask } = useSyncStore.getState();
  
  if (action === 'keep_local' || action === 'rename_upload') {
    let relPath = conflict.relPath;
    
    if (action === 'rename_upload') {
      const ext = relPath.includes('.') ? '.' + relPath.split('.').pop() : '';
      const baseName = relPath.replace(ext, '');
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
      relPath = `${baseName}_${timestamp}${ext}`;
    }
    
    const filename = relPath.split('/').pop() || relPath;
    
    addUploadTask({
      id: `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
      groupCode: conflict.groupCode,
      assetType: conflict.assetType,
      relPath,
      filename,
      localPath: conflict.localPath,
      filesize: conflict.localSize,
      status: 'pending',
      progress: 0,
      uploadedParts: 0,
      totalParts: 0,
      speed: 0,
      createdAt: Date.now(),
      updatedAt: Date.now(),
    });
  }
  
  const conflicts = getConflicts().filter(
    c => c.localPath !== conflict.localPath
  );
  useSyncStore.getState().setConflicts(conflicts);
}
