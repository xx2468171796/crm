import { invoke } from '@tauri-apps/api/core';
import { open } from '@tauri-apps/plugin-dialog';

export interface GroupFolder {
  group_code: string;
  group_name: string;
  path: string;
  has_works: boolean;
  has_models: boolean;
  has_customer: boolean;
}

export interface LocalFile {
  rel_path: string;
  filename: string;
  size: number;
  modified_at: number;
  is_dir: boolean;
}

export interface FileMetadata {
  path: string;
  size: number;
  modified_at: number;
  created_at: number;
  is_file: boolean;
  is_dir: boolean;
}

export interface DirEntryInfo {
  name: string;
  path: string;
  is_file: boolean;
  is_dir: boolean;
}

export async function selectDirectory(): Promise<string | null> {
  try {
    const selected = await open({
      directory: true,
      multiple: false,
      title: '选择同步根目录',
    });
    return selected as string | null;
  } catch (error) {
    console.error('[SYNC_DEBUG] 选择目录失败:', error);
    return null;
  }
}

export async function scanRootDirectory(rootPath: string): Promise<GroupFolder[]> {
  try {
    return await invoke<GroupFolder[]>('scan_root_directory', { rootPath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 扫描目录失败:', error);
    throw error;
  }
}

export async function listDirEntries(dirPath: string): Promise<DirEntryInfo[]> {
  try {
    return await invoke<DirEntryInfo[]>('list_dir_entries', { dirPath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 读取目录失败:', error);
    throw error;
  }
}

export async function getLocalFiles(
  rootPath: string,
  groupCode: string,
  assetType: string
): Promise<LocalFile[]> {
  try {
    return await invoke<LocalFile[]>('get_local_files', {
      rootPath,
      groupCode,
      assetType,
    });
  } catch (error) {
    console.error('[SYNC_DEBUG] 获取本地文件失败:', error);
    throw error;
  }
}

export async function calculateFileHash(filePath: string): Promise<string> {
  try {
    return await invoke<string>('calculate_file_hash', { filePath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 计算文件哈希失败:', error);
    throw error;
  }
}

export async function getFileMetadata(filePath: string): Promise<FileMetadata> {
  try {
    return await invoke<FileMetadata>('get_file_metadata', { filePath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 获取文件元数据失败:', error);
    throw error;
  }
}

export async function readFileChunk(
  filePath: string,
  offset: number,
  length: number
): Promise<Uint8Array> {
  try {
    const data = await invoke<number[]>('read_file_chunk', {
      filePath,
      offset,
      length,
    });
    return new Uint8Array(data);
  } catch (error) {
    console.error('[SYNC_DEBUG] 读取文件分片失败:', error);
    throw error;
  }
}

export async function getMimeType(filePath: string): Promise<string> {
  try {
    return await invoke<string>('get_mime_type', { filePath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 获取 MIME 类型失败:', error);
    throw error;
  }
}

export async function writeFileChunk(
  filePath: string,
  data: Uint8Array,
  append: boolean = false
): Promise<number> {
  try {
    return await invoke<number>('write_file_chunk', {
      filePath,
      data: Array.from(data),
      append,
    });
  } catch (error) {
    console.error('[SYNC_DEBUG] 写入文件失败:', error);
    throw error;
  }
}

export async function ensureDirectory(dirPath: string): Promise<void> {
  try {
    await invoke<void>('ensure_directory', { dirPath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 创建目录失败:', error);
    throw error;
  }
}

export interface DownloadResult {
  task_id: string;
  success: boolean;
  file_path: string;
  error: string | null;
}

export async function downloadFile(
  taskId: string,
  url: string,
  savePath: string
): Promise<DownloadResult> {
  try {
    return await invoke<DownloadResult>('download_file', {
      taskId,
      url,
      savePath,
    });
  } catch (error) {
    console.error('[SYNC_DEBUG] 下载文件失败:', error);
    throw error;
  }
}

export async function downloadFileChunked(
  taskId: string,
  url: string,
  savePath: string
): Promise<DownloadResult> {
  try {
    return await invoke<DownloadResult>('download_file_chunked', {
      taskId,
      url,
      savePath,
    });
  } catch (error) {
    console.error('[SYNC_DEBUG] 分块下载文件失败:', error);
    throw error;
  }
}

export async function openFileLocation(filePath: string): Promise<void> {
  try {
    await invoke<void>('open_file_location', { filePath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 打开文件位置失败:', error);
    throw error;
  }
}

export async function openFile(filePath: string): Promise<void> {
  try {
    await invoke<void>('open_file', { filePath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 打开文件失败:', error);
    throw error;
  }
}

export async function getTempDir(): Promise<string> {
  try {
    return await invoke<string>('get_temp_dir');
  } catch (error) {
    console.error('[SYNC_DEBUG] 获取临时目录失败:', error);
    throw error;
  }
}

export interface FolderFileInfo {
  name: string;
  relative_path: string;
  absolute_path: string;
  size: number;
}

export async function scanFolderRecursive(folderPath: string): Promise<FolderFileInfo[]> {
  try {
    return await invoke<FolderFileInfo[]>('scan_folder_recursive', { folderPath });
  } catch (error) {
    console.error('[SYNC_DEBUG] 扫描文件夹失败:', error);
    throw error;
  }
}

/**
 * 预览文件：优先检查本地项目文件夹，没有则下载到项目文件夹后打开
 * @param downloadUrl 下载URL
 * @param filename 文件名
 * @param localProjectPath 可选，本地项目路径（如果提供，优先检查本地文件）
 * @param relativePath 可选，文件相对路径
 */
export async function previewFile(
  downloadUrl: string,
  filename: string,
  localProjectPath?: string,
  relativePath?: string
): Promise<void> {
  try {
    console.log('[Preview] 开始预览:', filename);
    console.log('[Preview] 本地项目路径:', localProjectPath);
    console.log('[Preview] 相对路径:', relativePath);
    
    // 如果提供了本地项目路径，优先检查本地文件
    if (localProjectPath) {
      const localFilePath = relativePath 
        ? `${localProjectPath}\\${relativePath.replace(/\//g, '\\')}`
        : `${localProjectPath}\\${filename}`;
      
      console.log('[Preview] 检查本地文件:', localFilePath);
      
      try {
        // 检查文件是否存在
        const metadata = await getFileMetadata(localFilePath);
        if (metadata && metadata.size > 0) {
          console.log('[Preview] 本地文件存在，直接打开');
          await openFile(localFilePath);
          return;
        }
      } catch {
        console.log('[Preview] 本地文件不存在，需要下载');
      }
      
      // 本地文件不存在，下载到项目文件夹
      const targetPath = localFilePath;
      const targetDir = targetPath.substring(0, targetPath.lastIndexOf('\\'));
      
      console.log('[Preview] 下载到项目文件夹:', targetPath);
      
      // 确保目录存在
      await ensureDirectory(targetDir);
      
      // 下载文件
      const taskId = `preview-${Date.now()}`;
      const result = await downloadFileChunked(taskId, downloadUrl, targetPath);
      
      if (!result.success) {
        throw new Error(result.error || '下载失败');
      }
      
      console.log('[Preview] 下载完成，打开文件:', result.file_path);
      await openFile(result.file_path);
      return;
    }
    
    // 没有本地项目路径，使用临时目录（兼容旧逻辑）
    console.log('[Preview] 使用临时目录缓存');
    console.log('[Preview] 下载URL:', downloadUrl.substring(0, 150) + '...');
    
    const tempDir = await getTempDir();
    const cachePath = `${tempDir}\\preview_cache`;
    
    await ensureDirectory(cachePath);
    
    const timestamp = Date.now();
    const safeName = filename.replace(/[<>:"/\\|?*]/g, '_');
    const localPath = `${cachePath}\\${timestamp}_${safeName}`;
    
    const taskId = `preview-${timestamp}`;
    const result = await downloadFileChunked(taskId, downloadUrl, localPath);
    
    if (!result.success) {
      throw new Error(result.error || '下载失败');
    }
    
    console.log('[Preview] 下载完成，打开文件:', result.file_path);
    await openFile(result.file_path);
  } catch (error) {
    console.error('[Preview] 预览失败:', error);
    throw error;
  }
}
