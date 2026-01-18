// file-watcher service
import { useSettingsStore } from '@/stores/settings';
import { http } from '@/lib/http';

export interface FileChangeEvent {
  type: 'create' | 'modify' | 'rename' | 'delete';
  path: string;
  oldPath?: string;
  groupCode: string;
  assetType: 'works' | 'models' | 'customer';
  relPath: string;
  oldRelPath?: string;
}

export async function handleFileRename(
  groupCode: string,
  assetType: 'works' | 'models',
  oldRelPath: string,
  newRelPath: string
): Promise<boolean> {
  try {
    const params = new URLSearchParams({
      group_code: groupCode,
      asset_type: assetType,
      per_page: '500',
    });
    
    const response = await http.get<any>(`desktop_group_resources.php?${params}`);
    if (!response.success) return false;
    
    const resources = response.data?.items || [];
    const resource = resources.find((r: any) => r.rel_path === oldRelPath);
    
    if (!resource) {
      console.log('[SYNC_DEBUG] 未找到原文件，可能是新文件:', oldRelPath);
      return false;
    }
    
    const renameResponse = await http.post<any>('tech_resource_rename.php', {
      resource_id: resource.id,
      new_rel_path: newRelPath,
      new_filename: newRelPath.split('/').pop() || newRelPath,
    });
    
    if (renameResponse.success) {
      console.log('[SYNC_DEBUG] 文件重命名同步成功:', oldRelPath, '->', newRelPath);
      return true;
    } else {
      console.error('[SYNC_DEBUG] 文件重命名同步失败:', renameResponse.error);
      return false;
    }
  } catch (error) {
    console.error('[SYNC_DEBUG] 文件重命名同步异常:', error);
    return false;
  }
}

export async function handleFileMove(
  groupCode: string,
  assetType: 'works' | 'models',
  oldRelPath: string,
  newRelPath: string
): Promise<boolean> {
  return handleFileRename(groupCode, assetType, oldRelPath, newRelPath);
}

export function parseLocalPath(
  rootDir: string,
  fullPath: string
): { groupCode: string; assetType: 'works' | 'models' | 'customer'; relPath: string } | null {
  if (!fullPath.startsWith(rootDir)) return null;
  
  const relativePath = fullPath.slice(rootDir.length + 1);
  const parts = relativePath.split('/');
  
  if (parts.length < 3) return null;
  
  const groupDir = parts[0];
  const match = groupDir.match(/^(Q\d{10})_/);
  if (!match) return null;
  
  const groupCode = match[1];
  const assetDir = parts[1];
  
  let assetType: 'works' | 'models' | 'customer';
  if (assetDir === '作品文件') {
    assetType = 'works';
  } else if (assetDir === '模型文件') {
    assetType = 'models';
  } else if (assetDir === '客户文件') {
    assetType = 'customer';
  } else {
    return null;
  }
  
  const relPath = parts.slice(2).join('/');
  
  return { groupCode, assetType, relPath };
}

export async function syncLocalRename(
  rootDir: string,
  oldPath: string,
  newPath: string
): Promise<boolean> {
  const oldInfo = parseLocalPath(rootDir, oldPath);
  const newInfo = parseLocalPath(rootDir, newPath);
  
  if (!oldInfo || !newInfo) {
    console.log('[SYNC_DEBUG] 无法解析路径:', oldPath, newPath);
    return false;
  }
  
  if (oldInfo.groupCode !== newInfo.groupCode) {
    console.log('[SYNC_DEBUG] 不支持跨群移动');
    return false;
  }
  
  if (oldInfo.assetType !== newInfo.assetType) {
    console.log('[SYNC_DEBUG] 不支持跨资源类型移动');
    return false;
  }
  
  if (oldInfo.assetType === 'customer') {
    console.log('[SYNC_DEBUG] 客户文件不支持重命名同步');
    return false;
  }
  
  return handleFileRename(
    oldInfo.groupCode,
    oldInfo.assetType,
    oldInfo.relPath,
    newInfo.relPath
  );
}

export function addPendingRename(oldPath: string, newPath: string): void {
  const { rootDir } = useSettingsStore.getState();
  if (!rootDir) return;
  
  syncLocalRename(rootDir, oldPath, newPath).then(success => {
    if (success) {
      console.log('[SYNC_DEBUG] 重命名同步完成');
    }
  });
}
