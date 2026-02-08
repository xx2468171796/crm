import { http, getApiBaseUrl } from './http';
import { getFileMetadata } from './tauri';
import { useSyncStore, type UploadTask } from '@/stores/sync';
import { useAuthStore } from '@/stores/auth';

interface InitUploadResponse {
  upload_id: string;
  storage_key: string;
  part_size: number;
  total_parts: number;
}

interface UploadPartResponse {
  part_number: number;
  uploaded: number;
  total: number;
}

interface CompleteResponse {
  storage_key: string;
  deliverable_id: number;
  async?: boolean;
}

export class FileUploader {
  private abortController: AbortController | null = null;
  
  async initUpload(
    groupCode: string,
    assetType: 'works' | 'models' | 'customer',
    relPath: string,
    filename: string,
    filesize: number,
    mimeType: string,
    projectId?: number
  ): Promise<InitUploadResponse> {
    const response = await http.post<InitUploadResponse>('desktop_chunk_upload.php', {
      action: 'init',
      group_code: groupCode,
      project_id: projectId || 0,
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
  
  async uploadPart(
    uploadId: string,
    partNumber: number,
    data: ArrayBuffer,
    _onProgress?: (loaded: number) => void
  ): Promise<UploadPartResponse> {
    this.abortController = new AbortController();
    
    const formData = new FormData();
    formData.append('upload_id', uploadId);
    formData.append('part_number', partNumber.toString());
    formData.append('chunk', new Blob([data]));
    
    const baseUrl = getApiBaseUrl();
    const response = await fetch(`${baseUrl}desktop_chunk_upload.php`, {
      method: 'POST',
      body: formData,
      signal: this.abortController.signal,
      headers: {
        'Authorization': `Bearer ${useAuthStore.getState().token || ''}`,
      },
    });
    
    if (!response.ok) {
      throw new Error(`分片上传失败: HTTP ${response.status}`);
    }
    
    const result = await response.json();
    if (!result.success) {
      throw new Error(result.error || '分片上传失败');
    }
    
    return result.data;
  }
  
  async completeUpload(
    uploadId: string,
    _storageKey?: string,
    _parts?: Array<{ PartNumber: number; ETag: string }>
  ): Promise<CompleteResponse> {
    const response = await http.post<CompleteResponse>('desktop_chunk_upload.php', {
      action: 'complete',
      upload_id: uploadId,
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
