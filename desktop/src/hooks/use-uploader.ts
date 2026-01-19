import { useCallback, useRef } from 'react';
import { useSyncStore, type UploadTask } from '@/stores/sync';
import { useSettingsStore } from '@/stores/settings';
import { http } from '@/lib/http';
import { readFileChunk, getFileMetadata, getMimeType } from '@/lib/tauri';
import { toast } from './use-toast';
import { applyAcceleration } from '@/lib/urlReplacer';
import { useQueryClient } from '@tanstack/react-query';

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
  deliverable_id?: number;
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
    const response = await http.post<InitUploadResponse>('desktop_upload_init.php', {
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

  const getPartUrl = useCallback(async (
    uploadId: string,
    storageKey: string,
    partNumber: number
  ): Promise<PartUrlResponse> => {
    const response = await http.post<PartUrlResponse>('desktop_upload_part_url.php', {
      upload_id: uploadId,
      storage_key: storageKey,
      part_number: partNumber,
    });

    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '获取预签名URL失败');
    }

    return response.data;
  }, []);

  const uploadPart = useCallback(async (
    presignedUrl: string,
    data: Uint8Array,
    abortSignal?: AbortSignal
  ): Promise<string> => {
    const response = await fetch(presignedUrl, {
      method: 'PUT',
      body: new Blob([data as unknown as BlobPart]),
      signal: abortSignal,
    });

    if (!response.ok) {
      throw new Error(`分片上传失败: HTTP ${response.status}`);
    }

    const etagRaw = response.headers.get('ETag') || response.headers.get('etag') || '';
    const etag = etagRaw.replace(/"/g, '');
    if (!etag) {
      throw new Error('分片上传成功但未返回 ETag（可能被代理/网关剥离响应头）');
    }
    return etag;
  }, []);

  const completeUpload = useCallback(async (
    uploadId: string,
    storageKey: string,
    parts: Array<{ PartNumber: number; ETag: string }>,
    meta?: {
      project_id?: number;
      asset_type?: 'works' | 'models' | 'customer';
      filename?: string;
      filesize?: number;
      rel_path?: string;
      group_code?: string;
    }
  ): Promise<CompleteResponse> => {
    const response = await http.post<CompleteResponse>('desktop_upload_complete.php', {
      upload_id: uploadId,
      storage_key: storageKey,
      parts,
      ...(meta || {}),
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

      const uploadedParts: Array<{ PartNumber: number; ETag: string }> = [];
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
        const { presigned_url } = await getPartUrl(uploadId!, storageKey!, partNumber);
        // 应用加速节点URL替换
        const accelerationUrl = useSettingsStore.getState().accelerationNodeUrl;
        const finalUrl = applyAcceleration(presigned_url, accelerationUrl);
        const etag = await uploadPart(finalUrl, chunkData, abortController.signal);

        uploadedParts.push({ PartNumber: partNumber, ETag: etag });
        uploadedCount++;

        const progress = (uploadedCount / totalParts) * 100;
        updateUploadTask(taskId, {
          uploadedParts: uploadedCount,
          progress,
        });
      }

      await completeUpload(uploadId!, storageKey!, uploadedParts, {
        project_id: task.projectId ?? 0,
        asset_type: task.assetType,
        filename: task.filename,
        filesize: task.filesize,
        rel_path: task.relPath,
        group_code: task.groupCode,
      });

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
  }, [partSizeMB, initUpload, getPartUrl, uploadPart, completeUpload, updateUploadTask, maxConcurrentUploads, queryClient]);

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
