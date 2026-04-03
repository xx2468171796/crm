import { useCallback, useRef } from 'react';
import { useSyncStore, type UploadTask } from '@/stores/sync';
import { useSettingsStore } from '@/stores/settings';
import { http, getApiBaseUrl } from '@/lib/http';
import { readFileChunk, getFileMetadata, getMimeType } from '@/lib/tauri';
import { toast } from './use-toast';
import { useQueryClient } from '@tanstack/react-query';
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
  deliverable_id?: number;
  async?: boolean;
}

export function useUploader() {
  const { addUploadTask, updateUploadTask } = useSyncStore();
  const { maxConcurrentUploads, partSize: partSizeMB } = useSettingsStore();
  const activeUploads = useRef<Set<string>>(new Set());
  const abortControllers = useRef<Map<string, AbortController>>(new Map());
  const queryClient = useQueryClient();

  const initUpload = useCallback(async (
    groupCode: string,
    assetType: 'works' | 'models' | 'customer',
    projectId: number | undefined,
    relPath: string,
    filename: string,
    filesize: number,
    mimeType: string
  ): Promise<InitUploadResponse> => {
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
  }, []);

  const uploadPartToServer = useCallback(async (
    uploadId: string,
    partNumber: number,
    data: Uint8Array,
    abortSignal?: AbortSignal
  ): Promise<UploadPartResponse> => {
    const formData = new FormData();
    formData.append('upload_id', uploadId);
    formData.append('part_number', partNumber.toString());
    formData.append('chunk', new Blob([data as unknown as BlobPart]));
    
    const baseUrl = getApiBaseUrl();
    const token = useAuthStore.getState().token;
    
    const response = await fetch(`${baseUrl}desktop_chunk_upload.php`, {
      method: 'POST',
      body: formData,
      signal: abortSignal,
      headers: token ? { 'Authorization': `Bearer ${token}` } : {},
    });
    
    if (!response.ok) {
      throw new Error(`分片上传失败: HTTP ${response.status}`);
    }
    
    const result = await response.json();
    if (!result.success) {
      throw new Error(result.error || '分片上传失败');
    }
    
    return result.data;
  }, []);


  const completeUpload = useCallback(async (
    uploadId: string
  ): Promise<CompleteResponse> => {
    const response = await http.post<CompleteResponse>('desktop_chunk_upload.php', {
      action: 'complete',
      upload_id: uploadId,
    });

    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '完成上传失败');
    }

    return response.data;
  }, []);

  const startUpload = useCallback(async (taskId: string) => {
    const task = useSyncStore.getState().uploadTasks.find(t => t.id === taskId);
    if (!task || activeUploads.current.has(taskId)) return;

    activeUploads.current.add(taskId);
    const abortController = new AbortController();
    abortControllers.current.set(taskId, abortController);

    try {
      updateUploadTask(taskId, { status: 'uploading' });

      let uploadId = task.uploadId;
      let storageKey = task.storageKey;
      let totalParts = task.totalParts;
      let partSize = task.partSize || partSizeMB * 1024 * 1024;

      if (!uploadId || !storageKey) {
        const mimeType = await getMimeType(task.localPath);
        const initResult = await initUpload(
          task.groupCode,
          task.assetType,
          task.projectId,
          task.relPath,
          task.filename,
          task.filesize,
          mimeType
        );

        uploadId = initResult.upload_id;
        storageKey = initResult.storage_key;
        totalParts = initResult.total_parts;
        partSize = initResult.part_size;

        updateUploadTask(taskId, {
          uploadId,
          storageKey,
          totalParts,
          partSize,
        });
      }

      let uploadedCount = task.uploadedParts || 0;

      for (let partNumber = uploadedCount + 1; partNumber <= totalParts; partNumber++) {
        if (abortController.signal.aborted) {
          updateUploadTask(taskId, { status: 'paused' });
          return;
        }

        const offset = (partNumber - 1) * partSize;
        const remaining = task.filesize - offset;
        if (remaining <= 0) {
          throw new Error(`分片读取失败：offset 超出文件大小（part=${partNumber}, offset=${offset}, filesize=${task.filesize}, partSize=${partSize}, totalParts=${totalParts}）`);
        }
        const length = Math.min(partSize, remaining);

        const chunkData = await readFileChunk(task.localPath, offset, length);
        await uploadPartToServer(uploadId!, partNumber, chunkData, abortController.signal);

        uploadedCount++;

        const progress = (uploadedCount / totalParts) * 100;
        updateUploadTask(taskId, {
          uploadedParts: uploadedCount,
          progress,
        });
      }

      await completeUpload(uploadId!);

      updateUploadTask(taskId, {
        status: 'completed',
        progress: 100,
      });

      toast({ title: '上传完成', description: task.filename, variant: 'success' });
      
      // 上传完成后刷新文件列表
      queryClient.invalidateQueries({ queryKey: ['project-files'] });
      queryClient.invalidateQueries({ queryKey: ['remote-files'] });
    } catch (error) {
      if ((error as Error).name === 'AbortError') {
        updateUploadTask(taskId, { status: 'paused' });
      } else {
        console.error('[SYNC_DEBUG] 上传失败:', error);
        updateUploadTask(taskId, {
          status: 'failed',
          error: (error as Error).message,
        });
        toast({
          title: '上传失败',
          description: (error as Error).message,
          variant: 'destructive',
        });
      }
    } finally {
      activeUploads.current.delete(taskId);
      abortControllers.current.delete(taskId);

      // 自动继续调度队列，避免任务卡在 pending
      setTimeout(() => {
        const state = useSyncStore.getState();
        const runningCount = state.uploadTasks.filter(t => t.status === 'uploading').length;
        if (runningCount >= maxConcurrentUploads) return;
        const next = state.uploadTasks.find(t => t.status === 'pending');
        if (next) {
          startUpload(next.id);
        }
      }, 0);
    }
  }, [partSizeMB, initUpload, uploadPartToServer, completeUpload, updateUploadTask, maxConcurrentUploads, queryClient]);

  const pauseUpload = useCallback((taskId: string) => {
    const controller = abortControllers.current.get(taskId);
    if (controller) {
      controller.abort();
    }
    updateUploadTask(taskId, { status: 'paused' });
  }, [updateUploadTask]);

  const resumeUpload = useCallback((taskId: string) => {
    startUpload(taskId);
  }, [startUpload]);

  const cancelUpload = useCallback((taskId: string) => {
    const controller = abortControllers.current.get(taskId);
    if (controller) {
      controller.abort();
    }
    useSyncStore.getState().removeUploadTask(taskId);
  }, []);

  const queueUpload = useCallback(async (
    groupCode: string,
    assetType: 'works' | 'models' | 'customer',
    localPath: string,
    relPath: string,
    projectId?: number
  ) => {
    try {
      const metadata = await getFileMetadata(localPath);
      if (!metadata.is_file) {
        throw new Error('仅支持上传文件，不支持文件夹');
      }
      const filename = localPath.split('/').pop() || localPath.split('\\').pop() || 'unknown';

      const task: UploadTask = {
        id: `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
        groupCode,
        assetType,
        relPath,
        filename,
        localPath,
        filesize: metadata.size,
        projectId,
        status: 'pending',
        progress: 0,
        uploadedParts: 0,
        totalParts: 0,
        speed: 0,
        createdAt: Date.now(),
        updatedAt: Date.now(),
      };

      addUploadTask(task);

      // 这里不能使用闭包里的 uploadTasks，否则会拿到旧快照导致新任务一直 pending
      const state = useSyncStore.getState();
      const runningCount = state.uploadTasks.filter(t => t.status === 'uploading').length;
      if (runningCount < maxConcurrentUploads) startUpload(task.id);

      return task.id;
    } catch (error) {
      console.error('[SYNC_DEBUG] 创建上传任务失败:', error);
      toast({
        title: '创建上传任务失败',
        description: (error as Error).message,
        variant: 'destructive',
      });
      throw error;
    }
  }, [addUploadTask, maxConcurrentUploads, startUpload]);

  const processQueue = useCallback(() => {
    const tasks = useSyncStore.getState().uploadTasks;
    const runningCount = tasks.filter(t => t.status === 'uploading').length;
    const pendingTasks = tasks.filter(t => t.status === 'pending');

    const slotsAvailable = maxConcurrentUploads - runningCount;
    for (let i = 0; i < Math.min(slotsAvailable, pendingTasks.length); i++) {
      startUpload(pendingTasks[i].id);
    }
  }, [maxConcurrentUploads, startUpload]);

  return {
    queueUpload,
    startUpload,
    pauseUpload,
    resumeUpload,
    cancelUpload,
    processQueue,
  };
}
