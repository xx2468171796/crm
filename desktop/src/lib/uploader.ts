import { http } from './http';
import { getFileMetadata } from './tauri';
import { useSyncStore, type UploadTask } from '@/stores/sync';

interface InitUploadResponse {
  upload_id: string;
  storage_key: string;
  part_size: number;
  total_parts: number;
}

interface PartUrlResponse {
  presigned_url: string;
  part_number: number;
  expires_in: number;
}

interface CompleteResponse {
  resource_id: number;
  etag: string;
  storage_key: string;
}

export class FileUploader {
  private abortController: AbortController | null = null;
  
  async initUpload(
    groupCode: string,
    assetType: 'works' | 'models',
    relPath: string,
    filename: string,
    filesize: number,
    mimeType: string
  ): Promise<InitUploadResponse> {
    const response = await http.post<InitUploadResponse>('desktop_upload_init.php', {
      group_code: groupCode,
      asset_type: assetType,
      rel_path: relPath,
      filename,
      filesize,
      mime_type: mimeType,
    });
    
    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '初始化上传失败');
    }
    
    return response.data;
  }
  
  async getPartUrl(
    uploadId: string,
    storageKey: string,
    partNumber: number
  ): Promise<PartUrlResponse> {
    const response = await http.post<PartUrlResponse>('desktop_upload_part_url.php', {
      upload_id: uploadId,
      storage_key: storageKey,
      part_number: partNumber,
    });
    
    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '获取预签名URL失败');
    }
    
    return response.data;
  }
  
  async uploadPart(
    presignedUrl: string,
    data: ArrayBuffer,
    _onProgress?: (loaded: number) => void
  ): Promise<string> {
    this.abortController = new AbortController();
    
    const response = await fetch(presignedUrl, {
      method: 'PUT',
      body: data,
      signal: this.abortController.signal,
    });
    
    if (!response.ok) {
      throw new Error(`分片上传失败: HTTP ${response.status}`);
    }
    
    const etag = response.headers.get('ETag') || '';
    return etag.replace(/"/g, '');
  }
  
  async completeUpload(
    uploadId: string,
    storageKey: string,
    parts: Array<{ PartNumber: number; ETag: string }>
  ): Promise<CompleteResponse> {
    const response = await http.post<CompleteResponse>('desktop_upload_complete.php', {
      upload_id: uploadId,
      storage_key: storageKey,
      parts,
    });
    
    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '完成上传失败');
    }
    
    return response.data;
  }
  
  abort() {
    if (this.abortController) {
      this.abortController.abort();
      this.abortController = null;
    }
  }
}

export async function createUploadTask(
  groupCode: string,
  assetType: 'works' | 'models',
  localPath: string,
  relPath: string
): Promise<UploadTask> {
  const metadata = await getFileMetadata(localPath);
  const filename = localPath.split('/').pop() || localPath.split('\\').pop() || 'unknown';
  
  const task: UploadTask = {
    id: `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
    groupCode,
    assetType,
    relPath,
    filename,
    localPath,
    filesize: metadata.size,
    status: 'pending',
    progress: 0,
    uploadedParts: 0,
    totalParts: 0,
    speed: 0,
    createdAt: Date.now(),
    updatedAt: Date.now(),
  };
  
  useSyncStore.getState().addUploadTask(task);
  
  return task;
}

export const fileUploader = new FileUploader();
