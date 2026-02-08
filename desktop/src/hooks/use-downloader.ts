import { useCallback, useRef } from 'react';
import { useSyncStore, type DownloadTask } from '@/stores/sync';
import { useSettingsStore } from '@/stores/settings';
import { http } from '@/lib/http';
import { writeFileChunk, ensureDirectory } from '@/lib/tauri';
import { toast } from './use-toast';
import { applyAcceleration } from '@/lib/urlReplacer';

interface DownloadUrlResponse {
  presigned_url: string;
  filename: string;
  filesize: number;
  mime_type: string;
  expires_in: number;
}

interface StorageKeyDownloadResponse {
  presigned_url: string;
  expires_in: number;
  filename: string;
  storage_key: string;
}

export function useDownloader() {
  const { addDownloadTask, updateDownloadTask } = useSyncStore();
  const activeDownloads = useRef<Set<string>>(new Set());
  const abortControllers = useRef<Map<string, AbortController>>(new Map());

  const getDownloadUrl = useCallback(async (resourceId: number): Promise<DownloadUrlResponse> => {
    const response = await http.post<DownloadUrlResponse>('desktop_download_url.php', {
      resource_id: resourceId,
    });

    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '获取下载链接失败');
    }

    return response.data;
  }, []);

  // 通过 storage_key 获取下载链接
  const getDownloadUrlByStorageKey = useCallback(async (storageKey: string): Promise<StorageKeyDownloadResponse> => {
    const response = await http.post<StorageKeyDownloadResponse>('desktop_download.php', {
      storage_key: storageKey,
    });

    if (!response.success || !response.data) {
      throw new Error(response.error?.message || '获取下载链接失败');
    }

    return response.data;
  }, []);

  const startDownload = useCallback(async (taskId: string) => {
    // 使用 getState() 获取最新 task，避免 stale closure
    const currentTasks = useSyncStore.getState().downloadTasks;
    const task = currentTasks.find(t => t.id === taskId);
    if (!task || activeDownloads.current.has(taskId)) return;

    activeDownloads.current.add(taskId);

    try {
      updateDownloadTask(taskId, { status: 'downloading' });

      // 获取 presignedUrl（通过 task 的扩展属性）
      const presignedUrl = (task as any).presignedUrl;
      if (!presignedUrl) {
        throw new Error('缺少下载 URL，请重新创建下载任务');
      }

      // 使用 startDownloadWithUrl 来实际下载
      // 直接调用 startDownloadWithUrl 逻辑以避免循环依赖
      const dirPath = task.localPath.replace(/[^/\\]+$/, '');
      await ensureDirectory(dirPath);

      const accelerationUrl = useSettingsStore.getState().accelerationNodeUrl;
      const finalUrl = applyAcceleration(presignedUrl, accelerationUrl);

      const abortController = new AbortController();
      abortControllers.current.set(taskId, abortController);

      const downloadResponse = await fetch(finalUrl, {
        signal: abortController.signal,
      });

      if (!downloadResponse.ok) {
        throw new Error(`下载失败: HTTP ${downloadResponse.status}`);
      }

      const reader = downloadResponse.body?.getReader();
      if (!reader) {
        throw new Error('无法获取下载流');
      }

      let downloadedBytes = 0;
      let isFirstChunk = true;

      while (true) {
        if (abortController.signal.aborted) {
          updateDownloadTask(taskId, { status: 'paused' });
          reader.cancel();
          return;
        }

        const { done, value } = await reader.read();
        if (done) break;

        await writeFileChunk(task.localPath, value, !isFirstChunk);
        isFirstChunk = false;

        downloadedBytes += value.length;
        const progress = (downloadedBytes / task.filesize) * 100;
        updateDownloadTask(taskId, { progress });
      }

      updateDownloadTask(taskId, {
        status: 'completed',
        progress: 100,
      });

      toast({ title: '下载完成', description: task.filename, variant: 'success' });
    } catch (error) {
      if ((error as Error).name === 'AbortError') {
        updateDownloadTask(taskId, { status: 'paused' });
      } else {
        console.error('[SYNC_DEBUG] 下载失败:', error);
        updateDownloadTask(taskId, {
          status: 'failed',
          error: (error as Error).message,
        });
        toast({
          title: '下载失败',
          description: (error as Error).message,
          variant: 'destructive',
        });
      }
    } finally {
      activeDownloads.current.delete(taskId);
      abortControllers.current.delete(taskId);
    }
  }, [updateDownloadTask]);

  const pauseDownload = useCallback((taskId: string) => {
    const controller = abortControllers.current.get(taskId);
    if (controller) {
      controller.abort();
    }
    updateDownloadTask(taskId, { status: 'paused' });
  }, [updateDownloadTask]);

  const resumeDownload = useCallback((taskId: string) => {
    startDownload(taskId);
  }, [startDownload]);

  const cancelDownload = useCallback((taskId: string) => {
    const controller = abortControllers.current.get(taskId);
    if (controller) {
      controller.abort();
    }
    useSyncStore.getState().removeDownloadTask(taskId);
  }, []);

  const queueDownload = useCallback(async (
    groupCode: string,
    resourceId: number,
    relPath: string,
    filename: string,
    filesize: number
  ) => {
    const currentRootDir = useSettingsStore.getState().rootDir;
    if (!currentRootDir) {
      toast({ title: '请先在设置中选择同步目录', variant: 'destructive' });
      throw new Error('未设置同步目录');
    }

    try {
      const { presigned_url } = await getDownloadUrl(resourceId);

      const localPath = `${currentRootDir}/${groupCode}/客户文件/${relPath}`;

      const task: DownloadTask = {
        id: `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
        groupCode,
        relPath,
        filename,
        localPath,
        filesize,
        status: 'pending',
        progress: 0,
        speed: 0,
        createdAt: Date.now(),
        updatedAt: Date.now(),
      };

      (task as any).presignedUrl = presigned_url;

      addDownloadTask(task);

      const currentTasks = useSyncStore.getState().downloadTasks;
      const currentMaxConcurrent = useSettingsStore.getState().maxConcurrentUploads;
      const runningCount = currentTasks.filter(t => t.status === 'downloading').length;
      if (runningCount < currentMaxConcurrent) {
        startDownloadWithUrl(task.id, presigned_url);
      }

      return task.id;
    } catch (error) {
      console.error('[SYNC_DEBUG] 创建下载任务失败:', error);
      toast({
        title: '创建下载任务失败',
        description: (error as Error).message,
        variant: 'destructive',
      });
      throw error;
    }
  }, [addDownloadTask, getDownloadUrl, startDownloadWithUrl]);

  const startDownloadWithUrl = useCallback(async (taskId: string, presignedUrl: string) => {
    const currentTasks = useSyncStore.getState().downloadTasks;
    const task = currentTasks.find(t => t.id === taskId);
    if (!task || activeDownloads.current.has(taskId)) return;

    activeDownloads.current.add(taskId);
    const abortController = new AbortController();
    abortControllers.current.set(taskId, abortController);

    try {
      updateDownloadTask(taskId, { status: 'downloading' });

      const dirPath = task.localPath.replace(/[^/\\]+$/, '');
      await ensureDirectory(dirPath);

      // 应用加速节点URL替换
      const accelerationUrl = useSettingsStore.getState().accelerationNodeUrl;
      const finalUrl = applyAcceleration(presignedUrl, accelerationUrl);
      
      const downloadResponse = await fetch(finalUrl, {
        signal: abortController.signal,
      });

      if (!downloadResponse.ok) {
        throw new Error(`下载失败: HTTP ${downloadResponse.status}`);
      }

      const reader = downloadResponse.body?.getReader();
      if (!reader) {
        throw new Error('无法获取下载流');
      }

      let downloadedBytes = 0;
      let isFirstChunk = true;

      while (true) {
        if (abortController.signal.aborted) {
          updateDownloadTask(taskId, { status: 'paused' });
          reader.cancel();
          return;
        }

        const { done, value } = await reader.read();
        if (done) break;

        await writeFileChunk(task.localPath, value, !isFirstChunk);
        isFirstChunk = false;

        downloadedBytes += value.length;
        const progress = (downloadedBytes / task.filesize) * 100;
        updateDownloadTask(taskId, { progress });
      }

      updateDownloadTask(taskId, {
        status: 'completed',
        progress: 100,
      });

      toast({ title: '下载完成', description: task.filename, variant: 'success' });
    } catch (error) {
      if ((error as Error).name === 'AbortError') {
        updateDownloadTask(taskId, { status: 'paused' });
      } else {
        console.error('[SYNC_DEBUG] 下载失败:', error);
        updateDownloadTask(taskId, {
          status: 'failed',
          error: (error as Error).message,
        });
        toast({
          title: '下载失败',
          description: (error as Error).message,
          variant: 'destructive',
        });
      }
    } finally {
      activeDownloads.current.delete(taskId);
      abortControllers.current.delete(taskId);
    }
  }, [updateDownloadTask]);

  const processQueue = useCallback(() => {
    const currentTasks = useSyncStore.getState().downloadTasks;
    const currentMaxConcurrent = useSettingsStore.getState().maxConcurrentUploads;
    const runningCount = currentTasks.filter(t => t.status === 'downloading').length;
    const pendingTasks = currentTasks.filter(t => t.status === 'pending');

    const slotsAvailable = currentMaxConcurrent - runningCount;
    for (let i = 0; i < Math.min(slotsAvailable, pendingTasks.length); i++) {
      const task = pendingTasks[i];
      const presignedUrl = (task as any).presignedUrl;
      if (presignedUrl) {
        startDownloadWithUrl(task.id, presignedUrl);
      }
    }
  }, [startDownloadWithUrl]);

  // 通过 storage_key 下载文件（用于远程文件列表）
  const queueDownloadByStorageKey = useCallback(async (
    groupCode: string,
    assetType: 'works' | 'models',
    storageKey: string,
    relPath: string,
    filename: string,
    filesize: number
  ) => {
    const currentRootDir = useSettingsStore.getState().rootDir;
    if (!currentRootDir) {
      toast({ title: '请先在设置中选择同步目录', variant: 'destructive' });
      throw new Error('未设置同步目录');
    }

    try {
      const { presigned_url } = await getDownloadUrlByStorageKey(storageKey);

      const assetTypeDir = assetType === 'works' ? '作品文件' : '模型文件';
      const localPath = `${currentRootDir}/${groupCode}_${groupCode}/${assetTypeDir}/${relPath}`.replace(/\//g, '\\');

      const task: DownloadTask = {
        id: `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
        groupCode,
        relPath,
        filename,
        localPath,
        filesize,
        status: 'pending',
        progress: 0,
        speed: 0,
        createdAt: Date.now(),
        updatedAt: Date.now(),
      };

      (task as any).presignedUrl = presigned_url;

      addDownloadTask(task);

      const currentTasks = useSyncStore.getState().downloadTasks;
      const currentMaxConcurrent = useSettingsStore.getState().maxConcurrentUploads;
      const runningCount = currentTasks.filter(t => t.status === 'downloading').length;
      if (runningCount < currentMaxConcurrent) {
        startDownloadWithUrl(task.id, presigned_url);
      }

      return task.id;
    } catch (error) {
      console.error('[SYNC_DEBUG] 创建下载任务失败:', error);
      toast({
        title: '创建下载任务失败',
        description: (error as Error).message,
        variant: 'destructive',
      });
      throw error;
    }
  }, [addDownloadTask, getDownloadUrlByStorageKey, startDownloadWithUrl]);

  return {
    queueDownload,
    queueDownloadByStorageKey,
    startDownload,
    pauseDownload,
    resumeDownload,
    cancelDownload,
    processQueue,
  };
}
