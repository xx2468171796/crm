import { useState, useEffect, useCallback, useRef, DragEvent } from 'react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useToast } from '@/hooks/use-toast';
import {
  HardDrive, Upload, Trash2, Share2, Folder, RefreshCw,
  ChevronRight, Copy, Lock, Clock, Check, X, ArrowLeft,
  FolderPlus, Edit3, Move, FileUp, FolderUp, Pause, Play, XCircle
} from 'lucide-react';

interface DriveFile {
  id: number;
  filename: string;
  original_filename: string;
  folder_path: string;
  storage_key: string;
  file_size: number;
  file_type: string;
  upload_source: string;
  create_time: number;
}

interface StorageInfo {
  used: number;
  limit: number;
  used_gb: number;
  limit_gb: number;
  percent: number;
}

interface ShareLink {
  id: number;
  token: string;
  share_url: string;
  expires_at: string;
  max_visits: number | null;
  has_password: boolean;
}

// 上传任务接口
interface UploadTask {
  id: string;
  file: File;
  filename: string;
  fileSize: number;
  relativePath: string; // 文件夹上传时的相对路径
  status: 'pending' | 'uploading' | 'paused' | 'completed' | 'error';
  progress: number; // 0-100
  uploadedBytes: number;
  speed: number; // bytes/s
  remainingTime: number; // seconds
  error?: string;
  uploadId?: string; // 分片上传ID
  currentChunk?: number;
  totalChunks?: number;
}

// 分片大小：90MB
const CHUNK_SIZE = 90 * 1024 * 1024;
// 小文件阈值：90MB以下直接上传
const SMALL_FILE_THRESHOLD = 90 * 1024 * 1024;

export default function PersonalDrivePage() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { toast } = useToast();

  const [loading, setLoading] = useState(true);
  const [files, setFiles] = useState<DriveFile[]>([]);
  const [folders, setFolders] = useState<string[]>([]);
  const [currentPath, setCurrentPath] = useState('/');
  const [storage, setStorage] = useState<StorageInfo | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const [_driveId, setDriveId] = useState<number | null>(null);

  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const [_uploading, _setUploading] = useState(false);
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const [_uploadProgress, _setUploadProgress] = useState<{ current: number; total: number; filename: string } | null>(null);

  // 拖拽上传状态
  const [isDragging, setIsDragging] = useState(false);
  const dragCounter = useRef(0);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const folderInputRef = useRef<HTMLInputElement>(null);

  // 上传队列
  const [uploadQueue, setUploadQueue] = useState<UploadTask[]>([]);
  const [isProcessingQueue, setIsProcessingQueue] = useState(false);
  const abortControllerRef = useRef<AbortController | null>(null);
  const uploadStartTimeRef = useRef<number>(0);
  const uploadedBytesRef = useRef<number>(0);

  const [showShareModal, setShowShareModal] = useState(false);
  const [sharePassword, setSharePassword] = useState('');
  const [shareMaxVisits, setShareMaxVisits] = useState('');
  const [shareExpireDays, setShareExpireDays] = useState('7');
  const [generatingShare, setGeneratingShare] = useState(false);
  const [generatedLink, setGeneratedLink] = useState<ShareLink | null>(null);
  // 分享节点选择
  const [shareRegions, setShareRegions] = useState<Array<{ id: number; region_name: string; is_default: boolean }>>([]);
  const [selectedShareRegion, setSelectedShareRegion] = useState<number | null>(null);

  const [selectedFiles, setSelectedFiles] = useState<Set<number>>(new Set());
  const [deleting, setDeleting] = useState(false);

  // 文件夹管理
  const [showNewFolderModal, setShowNewFolderModal] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);

  // 重命名
  const [showRenameModal, setShowRenameModal] = useState(false);
  const [renameTarget, setRenameTarget] = useState<{ id: number; name: string; type: 'file' | 'folder' } | null>(null);
  const [newName, setNewName] = useState('');

  // 移动
  const [showMoveModal, setShowMoveModal] = useState(false);
  const [moveTargetPath, setMoveTargetPath] = useState('/');

  // 右键菜单 - 支持文件和文件夹
  const [contextMenu, setContextMenu] = useState<{ x: number; y: number; item: DriveFile | { id: number; name: string; path: string }; type: 'file' | 'folder' } | null>(null);
  // 单文件分享
  const [shareFileId, setShareFileId] = useState<number | null>(null);


  const loadFiles = useCallback(async (path: string = '/') => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_list.php?folder_path=${encodeURIComponent(path)}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success) {
        setFiles(data.data.files || []);
        setFolders(data.data.folders || []);
        setCurrentPath(data.data.current_path || '/');
        setStorage(data.data.storage || null);
        setDriveId(data.data.drive_id || null);
      } else {
        toast({ title: '加载失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '加载失败', description: err.message, variant: 'destructive' });
    } finally {
      setLoading(false);
    }
  }, [serverUrl, token, toast]);

  useEffect(() => {
    loadFiles('/');
  }, [loadFiles]);

  const navigateToFolder = (folder: string) => {
    const newPath = currentPath === '/' ? `/${folder}` : `${currentPath}/${folder}`;
    loadFiles(newPath);
  };

  const navigateUp = () => {
    if (currentPath === '/') return;
    const parts = currentPath.split('/').filter(Boolean);
    parts.pop();
    const newPath = parts.length === 0 ? '/' : '/' + parts.join('/');
    loadFiles(newPath);
  };


  // ==================== 拖拽上传相关函数 ====================

  // 处理拖拽进入
  const handleDragEnter = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
    dragCounter.current++;
    if (e.dataTransfer.items && e.dataTransfer.items.length > 0) {
      setIsDragging(true);
    }
  };

  // 处理拖拽离开
  const handleDragLeave = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
    dragCounter.current--;
    if (dragCounter.current === 0) {
      setIsDragging(false);
    }
  };

  // 处理拖拽悬停
  const handleDragOver = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
  };

  // 处理拖拽放下
  const handleDrop = async (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
    dragCounter.current = 0;

    const items = e.dataTransfer.items;
    if (!items || items.length === 0) return;

    const filesToUpload: { file: File; relativePath: string }[] = [];

    // 递归读取文件夹
    const readDirectory = async (entry: FileSystemDirectoryEntry, path: string): Promise<void> => {
      return new Promise((resolve) => {
        const reader = entry.createReader();
        const readEntries = () => {
          reader.readEntries(async (entries) => {
            if (entries.length === 0) {
              resolve();
              return;
            }
            for (const ent of entries) {
              if (ent.isFile) {
                const fileEntry = ent as FileSystemFileEntry;
                const file = await new Promise<File>((res) => fileEntry.file(res));
                filesToUpload.push({ file, relativePath: path + '/' + file.name });
              } else if (ent.isDirectory) {
                await readDirectory(ent as FileSystemDirectoryEntry, path + '/' + ent.name);
              }
            }
            readEntries(); // 继续读取（可能有多批）
          });
        };
        readEntries();
      });
    };

    // 处理拖入的项目
    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      const entry = item.webkitGetAsEntry?.();
      if (entry) {
        if (entry.isFile) {
          const file = item.getAsFile();
          if (file) {
            filesToUpload.push({ file, relativePath: file.name });
          }
        } else if (entry.isDirectory) {
          await readDirectory(entry as FileSystemDirectoryEntry, entry.name);
        }
      } else {
        // 降级处理
        const file = item.getAsFile();
        if (file) {
          filesToUpload.push({ file, relativePath: file.name });
        }
      }
    }

    if (filesToUpload.length > 0) {
      addFilesToQueue(filesToUpload);
    }
  };

  // 添加文件到上传队列
  const addFilesToQueue = (filesToUpload: { file: File; relativePath: string }[]) => {
    const newTasks: UploadTask[] = filesToUpload.map(({ file, relativePath }) => ({
      id: `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
      file,
      filename: file.name,
      fileSize: file.size,
      relativePath,
      status: 'pending' as const,
      progress: 0,
      uploadedBytes: 0,
      speed: 0,
      remainingTime: 0,
      totalChunks: file.size > SMALL_FILE_THRESHOLD ? Math.ceil(file.size / CHUNK_SIZE) : 1,
      currentChunk: 0,
    }));

    setUploadQueue(prev => [...prev, ...newTasks]);
    toast({ title: '已添加到上传队列', description: `${filesToUpload.length} 个文件` });
  };

  // 处理文件选择（普通文件）
  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const fileList = e.target.files;
    if (!fileList || fileList.length === 0) return;

    const filesToUpload: { file: File; relativePath: string }[] = [];
    for (let i = 0; i < fileList.length; i++) {
      const file = fileList[i];
      filesToUpload.push({ file, relativePath: file.name });
    }
    addFilesToQueue(filesToUpload);
    e.target.value = '';
  };

  // 处理文件夹选择
  const handleFolderSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const fileList = e.target.files;
    if (!fileList || fileList.length === 0) return;

    const filesToUpload: { file: File; relativePath: string }[] = [];
    for (let i = 0; i < fileList.length; i++) {
      const file = fileList[i];
      // webkitRelativePath 包含文件夹路径
      const relativePath = (file as any).webkitRelativePath || file.name;
      filesToUpload.push({ file, relativePath });
    }
    addFilesToQueue(filesToUpload);
    e.target.value = '';
  };

  // 处理上传队列
  useEffect(() => {
    const processQueue = async () => {
      if (isProcessingQueue) return;
      
      const pendingTask = uploadQueue.find(t => t.status === 'pending');
      if (!pendingTask) return;

      setIsProcessingQueue(true);
      await uploadFile(pendingTask);
      setIsProcessingQueue(false);
    };

    processQueue();
  }, [uploadQueue, isProcessingQueue]);

  // 上传单个文件
  const uploadFile = async (task: UploadTask) => {
    // 更新状态为上传中
    setUploadQueue(prev => prev.map(t => 
      t.id === task.id ? { ...t, status: 'uploading' as const } : t
    ));

    uploadStartTimeRef.current = Date.now();
    uploadedBytesRef.current = 0;
    abortControllerRef.current = new AbortController();

    try {
      if (task.fileSize <= SMALL_FILE_THRESHOLD) {
        // 小文件直接上传
        await uploadSmallFile(task);
      } else {
        // 大文件分片上传
        await uploadLargeFile(task);
      }

      // 上传成功
      setUploadQueue(prev => prev.map(t => 
        t.id === task.id ? { ...t, status: 'completed' as const, progress: 100 } : t
      ));
      loadFiles(currentPath);
    } catch (error: any) {
      if (error.name === 'AbortError') {
        // 用户取消
        setUploadQueue(prev => prev.map(t => 
          t.id === task.id ? { ...t, status: 'paused' as const } : t
        ));
      } else {
        // 上传失败
        setUploadQueue(prev => prev.map(t => 
          t.id === task.id ? { ...t, status: 'error' as const, error: error.message } : t
        ));
      }
    }
  };

  // 小文件直接上传（带进度）
  const uploadSmallFile = async (task: UploadTask) => {
    return new Promise<void>((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          const progress = Math.round((e.loaded / e.total) * 100);
          const elapsed = (Date.now() - uploadStartTimeRef.current) / 1000;
          const speed = e.loaded / elapsed;
          const remaining = (e.total - e.loaded) / speed;

          setUploadQueue(prev => prev.map(t => 
            t.id === task.id ? {
              ...t,
              progress,
              uploadedBytes: e.loaded,
              speed,
              remainingTime: remaining,
            } : t
          ));
        }
      };

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
              resolve();
            } else {
              reject(new Error(data.error || '上传失败'));
            }
          } catch {
            reject(new Error('解析响应失败'));
          }
        } else {
          reject(new Error(`HTTP ${xhr.status}`));
        }
      };

      xhr.onerror = () => reject(new Error('网络错误'));
      xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));

      // 监听取消
      abortControllerRef.current?.signal.addEventListener('abort', () => xhr.abort());

      const formData = new FormData();
      formData.append('file', task.file);
      formData.append('folder_path', currentPath);
      formData.append('relative_path', task.relativePath);

      xhr.open('POST', `${serverUrl}/api/personal_drive_chunk_upload.php?action=direct`);
      xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.send(formData);
    });
  };

  // 大文件分片上传
  const uploadLargeFile = async (task: UploadTask) => {
    const totalChunks = Math.ceil(task.fileSize / CHUNK_SIZE);

    // 1. 初始化上传
    const initRes = await fetch(`${serverUrl}/api/personal_drive_chunk_upload.php?action=init`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        filename: task.filename,
        file_size: task.fileSize,
        folder_path: currentPath,
        total_chunks: totalChunks,
        relative_path: task.relativePath,
      }),
      signal: abortControllerRef.current?.signal,
    });
    const initData = await initRes.json();
    if (!initData.success) {
      throw new Error(initData.error || '初始化上传失败');
    }

    const uploadId = initData.data.upload_id;
    setUploadQueue(prev => prev.map(t => 
      t.id === task.id ? { ...t, uploadId, totalChunks } : t
    ));

    // 2. 上传分片
    let uploadedBytes = 0;
    for (let i = 0; i < totalChunks; i++) {
      if (abortControllerRef.current?.signal.aborted) {
        throw new DOMException('Aborted', 'AbortError');
      }

      const start = i * CHUNK_SIZE;
      const end = Math.min(start + CHUNK_SIZE, task.fileSize);
      const chunk = task.file.slice(start, end);

      await uploadChunk(task.id, uploadId, i, chunk, task.fileSize, uploadedBytes);
      uploadedBytes += chunk.size;
    }

    // 3. 完成上传
    const completeRes = await fetch(`${serverUrl}/api/personal_drive_chunk_upload.php?action=complete`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ upload_id: uploadId }),
      signal: abortControllerRef.current?.signal,
    });
    const completeData = await completeRes.json();
    if (!completeData.success) {
      throw new Error(completeData.error || '完成上传失败');
    }
  };

  // 上传单个分片
  const uploadChunk = (taskId: string, uploadId: string, chunkIndex: number, chunk: Blob, totalSize: number, previousBytes: number): Promise<void> => {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          const totalUploaded = previousBytes + e.loaded;
          const progress = Math.round((totalUploaded / totalSize) * 100);
          const elapsed = (Date.now() - uploadStartTimeRef.current) / 1000;
          const speed = totalUploaded / elapsed;
          const remaining = (totalSize - totalUploaded) / speed;

          setUploadQueue(prev => prev.map(t => 
            t.id === taskId ? {
              ...t,
              progress,
              uploadedBytes: totalUploaded,
              speed,
              remainingTime: remaining,
              currentChunk: chunkIndex + 1,
            } : t
          ));
        }
      };

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
              resolve();
            } else {
              reject(new Error(data.error || '分片上传失败'));
            }
          } catch {
            reject(new Error('解析响应失败'));
          }
        } else {
          reject(new Error(`HTTP ${xhr.status}`));
        }
      };

      xhr.onerror = () => reject(new Error('网络错误'));
      xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));

      abortControllerRef.current?.signal.addEventListener('abort', () => xhr.abort());

      const formData = new FormData();
      formData.append('upload_id', uploadId);
      formData.append('chunk_index', String(chunkIndex));
      formData.append('chunk', chunk);

      xhr.open('POST', `${serverUrl}/api/personal_drive_chunk_upload.php?action=upload_chunk`);
      xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.send(formData);
    });
  };

  // 暂停/继续上传
  const togglePauseUpload = (taskId: string) => {
    const task = uploadQueue.find(t => t.id === taskId);
    if (!task) return;

    if (task.status === 'uploading') {
      // 暂停
      abortControllerRef.current?.abort();
    } else if (task.status === 'paused' || task.status === 'error') {
      // 继续/重试
      setUploadQueue(prev => prev.map(t => 
        t.id === taskId ? { ...t, status: 'pending' as const, error: undefined } : t
      ));
    }
  };

  // 取消上传
  const cancelUpload = (taskId: string) => {
    const task = uploadQueue.find(t => t.id === taskId);
    if (task?.status === 'uploading') {
      abortControllerRef.current?.abort();
    }
    setUploadQueue(prev => prev.filter(t => t.id !== taskId));
  };

  // 清除已完成的上传
  const clearCompletedUploads = () => {
    setUploadQueue(prev => prev.filter(t => t.status !== 'completed'));
  };

  // 格式化速度
  const formatSpeed = (bytesPerSecond: number) => {
    if (bytesPerSecond < 1024) return `${bytesPerSecond.toFixed(0)} B/s`;
    if (bytesPerSecond < 1024 * 1024) return `${(bytesPerSecond / 1024).toFixed(1)} KB/s`;
    return `${(bytesPerSecond / 1024 / 1024).toFixed(1)} MB/s`;
  };

  // 格式化剩余时间
  const formatRemainingTime = (seconds: number) => {
    if (seconds < 60) return `${Math.ceil(seconds)}秒`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}分${Math.ceil(seconds % 60)}秒`;
    return `${Math.floor(seconds / 3600)}时${Math.floor((seconds % 3600) / 60)}分`;
  };

  // ==================== 拖拽上传相关函数结束 ====================

  const handleDelete = async (fileId: number) => {
    if (!confirm('确定要删除此文件吗？')) return;

    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_delete.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ file_id: fileId }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '删除成功' });
        loadFiles(currentPath);
      } else {
        toast({ title: '删除失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '删除失败', description: err.message, variant: 'destructive' });
    }
  };

  const generateShareLink = async () => {
    if (!serverUrl || !token) return;

    setGeneratingShare(true);
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_share.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          folder_path: currentPath,
          file_id: shareFileId || undefined,
          region_id: selectedShareRegion || undefined,
          password: sharePassword || undefined,
          max_visits: shareMaxVisits ? parseInt(shareMaxVisits) : undefined,
          expires_in_days: parseInt(shareExpireDays) || 7,
        }),
      });
      const data = await res.json();
      if (data.success) {
        setGeneratedLink(data.data);
        toast({ title: '分享链接已生成' });
      } else {
        toast({ title: '生成失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '生成失败', description: err.message, variant: 'destructive' });
    } finally {
      setGeneratingShare(false);
    }
  };

  // 右键菜单处理 - 文件
  const handleFileContextMenu = (e: React.MouseEvent, file: DriveFile) => {
    e.preventDefault();
    setContextMenu({ x: e.clientX, y: e.clientY, item: file, type: 'file' });
  };

  // 右键菜单处理 - 文件夹
  const handleFolderContextMenu = (e: React.MouseEvent, folder: { id: number; name: string; path: string }) => {
    e.preventDefault();
    setContextMenu({ x: e.clientX, y: e.clientY, item: folder, type: 'folder' });
  };

  // 加载分享节点
  const loadShareRegions = async () => {
    if (!serverUrl || !token) return;
    try {
      const res = await fetch(`${serverUrl}/api/share_regions.php?action=list`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success && data.data) {
        setShareRegions(data.data);
        const defaultRegion = data.data.find((r: any) => r.is_default);
        if (defaultRegion) {
          setSelectedShareRegion(defaultRegion.id);
        }
      }
    } catch (err) {
      console.error('加载分享节点失败:', err);
    }
  };

  // 打开文件分享弹窗
  const openFileShareModal = (fileId: number) => {
    setShareFileId(fileId);
    setSharePassword('');
    setShareMaxVisits('');
    setShareExpireDays('7');
    setGeneratedLink(null);
    setSelectedShareRegion(null);
    setShowShareModal(true);
    setContextMenu(null);
    loadShareRegions();
  };

  // 打开文件夹分享弹窗
  const openFolderShareModal = () => {
    setShareFileId(null);
    setSharePassword('');
    setShareMaxVisits('');
    setShareExpireDays('7');
    setGeneratedLink(null);
    setSelectedShareRegion(null);
    setShowShareModal(true);
    setContextMenu(null);
    loadShareRegions();
  };

  // 关闭右键菜单
  useEffect(() => {
    const handleClick = () => setContextMenu(null);
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  }, []);

  const copyShareLink = () => {
    if (generatedLink?.share_url) {
      navigator.clipboard.writeText(generatedLink.share_url);
      toast({ title: '链接已复制' });
    }
  };

  const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  const formatTime = (timestamp: number) => {
    return new Date(timestamp * 1000).toLocaleString('zh-CN');
  };

  const toggleSelectFile = (id: number) => {
    setSelectedFiles(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const getFileIcon = (filename: string) => {
    const ext = filename.split('.').pop()?.toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext || '')) return '🖼️';
    if (['mp4', 'avi', 'mov', 'mkv'].includes(ext || '')) return '🎬';
    if (['mp3', 'wav', 'flac'].includes(ext || '')) return '🎵';
    if (['pdf'].includes(ext || '')) return '📕';
    if (['doc', 'docx'].includes(ext || '')) return '📘';
    if (['xls', 'xlsx'].includes(ext || '')) return '📗';
    if (['zip', 'rar', '7z'].includes(ext || '')) return '📦';
    return '📄';
  };

  // 创建文件夹
  const handleCreateFolder = async () => {
    if (!newFolderName.trim()) {
      toast({ title: '请输入文件夹名称', variant: 'destructive' });
      return;
    }
    setCreatingFolder(true);
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_folder.php`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', parent_path: currentPath, folder_name: newFolderName }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '文件夹创建成功' });
        setShowNewFolderModal(false);
        setNewFolderName('');
        loadFiles(currentPath);
      } else {
        toast({ title: '创建失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '创建失败', description: err.message, variant: 'destructive' });
    } finally {
      setCreatingFolder(false);
    }
  };

  // 重命名文件
  const handleRename = async () => {
    if (!renameTarget || !newName.trim()) return;
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_file_action.php`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'rename', file_id: renameTarget.id, new_name: newName }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '重命名成功' });
        setShowRenameModal(false);
        setRenameTarget(null);
        loadFiles(currentPath);
      } else {
        toast({ title: '重命名失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '重命名失败', description: err.message, variant: 'destructive' });
    }
  };

  // 移动文件
  const handleMove = async () => {
    if (selectedFiles.size === 0) return;
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_file_action.php`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'batch_move', file_ids: Array.from(selectedFiles), target_path: moveTargetPath }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '移动成功', description: `已移动 ${data.data.moved_count} 个文件` });
        setShowMoveModal(false);
        setSelectedFiles(new Set());
        loadFiles(currentPath);
      } else {
        toast({ title: '移动失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '移动失败', description: err.message, variant: 'destructive' });
    }
  };

  // 批量删除(使用新API)
  const handleBatchDeleteNew = async () => {
    if (selectedFiles.size === 0) return;
    if (!confirm(`确定要删除选中的 ${selectedFiles.size} 个文件吗？`)) return;
    setDeleting(true);
    try {
      const res = await fetch(`${serverUrl}/api/personal_drive_file_action.php`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'batch_delete', file_ids: Array.from(selectedFiles) }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '删除成功', description: `已删除 ${data.data.deleted_count} 个文件` });
        setSelectedFiles(new Set());
        loadFiles(currentPath);
      } else {
        toast({ title: '删除失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '删除失败', description: err.message, variant: 'destructive' });
    } finally {
      setDeleting(false);
    }
  };

  // 打开重命名弹窗
  const openRenameModal = (file: DriveFile) => {
    setRenameTarget({ id: file.id, name: file.filename, type: 'file' });
    setNewName(file.filename);
    setShowRenameModal(true);
  };


  return (
    <div className="min-h-screen bg-gray-50">
      {/* 顶部栏 */}
      <div className="bg-gradient-to-r from-cyan-600 to-teal-600 text-white px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <HardDrive className="w-8 h-8" />
            <div>
              <h1 className="text-xl font-bold">我的网盘</h1>
              {storage && (
                <div className="text-sm opacity-90">
                  已用 {storage.used_gb} GB / {storage.limit_gb} GB ({storage.percent}%)
                </div>
              )}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg cursor-pointer transition-colors">
              <FileUp className="w-4 h-4" />
              上传文件
              <input 
                ref={fileInputRef}
                type="file" 
                multiple 
                onChange={handleFileSelect} 
                className="hidden" 
              />
            </label>
            <label className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg cursor-pointer transition-colors">
              <FolderUp className="w-4 h-4" />
              上传文件夹
              <input 
                ref={folderInputRef}
                type="file" 
                // @ts-ignore - webkitdirectory is not in types
                webkitdirectory=""
                // @ts-ignore
                directory=""
                multiple 
                onChange={handleFolderSelect} 
                className="hidden" 
              />
            </label>
            <button
              onClick={openFolderShareModal}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-green-500/80 hover:bg-green-600 rounded-lg transition-colors"
            >
              <Share2 className="w-4 h-4" />
              分享链接
            </button>
            <button
              onClick={() => loadFiles(currentPath)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg transition-colors"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>
          </div>
        </div>

        {/* 存储空间进度条 */}
        {storage && (
          <div className="mt-3">
            <div className="h-2 bg-white/20 rounded-full overflow-hidden">
              <div
                className={`h-full transition-all ${storage.percent > 90 ? 'bg-red-400' : storage.percent > 70 ? 'bg-yellow-400' : 'bg-white'}`}
                style={{ width: `${storage.percent}%` }}
              />
            </div>
          </div>
        )}
      </div>

      {/* 上传队列 */}
      {uploadQueue.length > 0 && (
        <div className="bg-white border-b shadow-sm">
          <div className="px-6 py-2 bg-gradient-to-r from-blue-50 to-cyan-50 border-b flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Upload className="w-4 h-4 text-blue-600" />
              <span className="text-sm font-medium text-blue-800">
                上传队列 ({uploadQueue.filter(t => t.status === 'completed').length}/{uploadQueue.length})
              </span>
            </div>
            <div className="flex items-center gap-2">
              {uploadQueue.some(t => t.status === 'completed') && (
                <button
                  onClick={clearCompletedUploads}
                  className="text-xs text-gray-500 hover:text-gray-700 px-2 py-1 rounded hover:bg-white/50"
                >
                  清除已完成
                </button>
              )}
            </div>
          </div>
          <div className="max-h-64 overflow-y-auto">
            {uploadQueue.map((task) => (
              <div key={task.id} className="px-6 py-3 border-b last:border-b-0 hover:bg-gray-50">
                <div className="flex items-center gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-800 truncate" title={task.relativePath}>
                        {task.filename}
                      </span>
                      {task.relativePath !== task.filename && (
                        <span className="text-xs text-gray-400 truncate" title={task.relativePath}>
                          ({task.relativePath})
                        </span>
                      )}
                      <span className="text-xs text-gray-400">
                        {formatFileSize(task.fileSize)}
                      </span>
                      {task.totalChunks && task.totalChunks > 1 && (
                        <span className="text-xs text-blue-500">
                          分片 {task.currentChunk || 0}/{task.totalChunks}
                        </span>
                      )}
                    </div>
                    <div className="mt-1.5 flex items-center gap-3">
                      {/* 进度条 */}
                      <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div
                          className={`h-full transition-all duration-300 ${
                            task.status === 'completed' ? 'bg-green-500' :
                            task.status === 'error' ? 'bg-red-500' :
                            task.status === 'paused' ? 'bg-yellow-500' :
                            'bg-blue-500'
                          }`}
                          style={{ width: `${task.progress}%` }}
                        />
                      </div>
                      <span className="text-xs text-gray-500 w-12 text-right">{task.progress}%</span>
                    </div>
                    {/* 状态信息 */}
                    <div className="mt-1 flex items-center gap-3 text-xs">
                      {task.status === 'uploading' && (
                        <>
                          <span className="text-blue-600">{formatSpeed(task.speed)}</span>
                          <span className="text-gray-400">剩余 {formatRemainingTime(task.remainingTime)}</span>
                          <span className="text-gray-400">{formatFileSize(task.uploadedBytes)} / {formatFileSize(task.fileSize)}</span>
                        </>
                      )}
                      {task.status === 'completed' && (
                        <span className="text-green-600 flex items-center gap-1">
                          <Check className="w-3 h-3" /> 上传完成
                        </span>
                      )}
                      {task.status === 'error' && (
                        <span className="text-red-600">{task.error || '上传失败'}</span>
                      )}
                      {task.status === 'paused' && (
                        <span className="text-yellow-600">已暂停</span>
                      )}
                      {task.status === 'pending' && (
                        <span className="text-gray-400">等待中...</span>
                      )}
                    </div>
                  </div>
                  {/* 操作按钮 */}
                  <div className="flex items-center gap-1">
                    {(task.status === 'uploading' || task.status === 'paused' || task.status === 'error') && (
                      <button
                        onClick={() => togglePauseUpload(task.id)}
                        className={`p-1.5 rounded-lg transition-colors ${
                          task.status === 'uploading' 
                            ? 'text-yellow-600 hover:bg-yellow-50' 
                            : 'text-blue-600 hover:bg-blue-50'
                        }`}
                        title={task.status === 'uploading' ? '暂停' : '继续'}
                      >
                        {task.status === 'uploading' ? <Pause className="w-4 h-4" /> : <Play className="w-4 h-4" />}
                      </button>
                    )}
                    {task.status !== 'completed' && (
                      <button
                        onClick={() => cancelUpload(task.id)}
                        className="p-1.5 text-red-500 hover:bg-red-50 rounded-lg transition-colors"
                        title="取消"
                      >
                        <XCircle className="w-4 h-4" />
                      </button>
                    )}
                    {task.status === 'completed' && (
                      <button
                        onClick={() => cancelUpload(task.id)}
                        className="p-1.5 text-gray-400 hover:bg-gray-100 rounded-lg transition-colors"
                        title="移除"
                      >
                        <X className="w-4 h-4" />
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* 路径导航 */}
      <div className="bg-white border-b px-6 py-3 flex items-center gap-2">
        <button
          onClick={() => loadFiles('/')}
          className="text-sm text-gray-600 hover:text-cyan-600 flex items-center gap-1"
        >
          <HardDrive className="w-4 h-4" />
          网盘根目录
        </button>
        {currentPath !== '/' && currentPath.split('/').filter(Boolean).map((part, index, arr) => (
          <div key={index} className="flex items-center gap-2">
            <ChevronRight className="w-4 h-4 text-gray-400" />
            <button
              onClick={() => {
                const newPath = '/' + arr.slice(0, index + 1).join('/');
                loadFiles(newPath);
              }}
              className="text-sm text-gray-600 hover:text-cyan-600"
            >
              {part}
            </button>
          </div>
        ))}
        {currentPath !== '/' && (
          <button
            onClick={navigateUp}
            className="ml-auto text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1"
          >
            <ArrowLeft className="w-4 h-4" />
            返回上级
          </button>
        )}
      </div>

      {/* 工具栏 */}
      <div className="bg-white border-b px-6 py-2 flex items-center gap-3">
        <button
          onClick={() => { setShowNewFolderModal(true); setNewFolderName(''); }}
          className="text-sm text-gray-600 hover:text-cyan-600 flex items-center gap-1.5 px-2 py-1 rounded hover:bg-gray-100"
        >
          <FolderPlus className="w-4 h-4" />
          新建文件夹
        </button>
      </div>

      {/* 批量操作栏 */}
      {selectedFiles.size > 0 && (
        <div className="bg-cyan-50 border-b px-6 py-2 flex items-center gap-3">
          <span className="text-sm text-cyan-700">已选择 {selectedFiles.size} 个文件</span>
          <button
            onClick={handleBatchDeleteNew}
            disabled={deleting}
            className="text-sm text-red-600 hover:text-red-700 flex items-center gap-1"
          >
            <Trash2 className="w-4 h-4" />
            {deleting ? '删除中...' : '批量删除'}
          </button>
          <button
            onClick={() => { setShowMoveModal(true); setMoveTargetPath('/'); }}
            className="text-sm text-blue-600 hover:text-blue-700 flex items-center gap-1"
          >
            <Move className="w-4 h-4" />
            移动到
          </button>
          <button
            onClick={() => setSelectedFiles(new Set())}
            className="text-sm text-gray-500 hover:text-gray-700"
          >
            取消选择
          </button>
        </div>
      )}

      {/* 文件列表 - 支持拖拽上传 */}
      <div 
        className={`p-6 min-h-[400px] relative transition-colors ${isDragging ? 'bg-cyan-50' : ''}`}
        onDragEnter={handleDragEnter}
        onDragLeave={handleDragLeave}
        onDragOver={handleDragOver}
        onDrop={handleDrop}
      >
        {/* 拖拽提示遮罩 */}
        {isDragging && (
          <div className="absolute inset-0 bg-cyan-100/80 border-4 border-dashed border-cyan-400 rounded-xl flex items-center justify-center z-10 pointer-events-none">
            <div className="text-center">
              <Upload className="w-16 h-16 text-cyan-500 mx-auto mb-4" />
              <p className="text-xl font-semibold text-cyan-700">释放鼠标上传文件</p>
              <p className="text-sm text-cyan-600 mt-2">支持文件和文件夹</p>
            </div>
          </div>
        )}

        {loading ? (
          <div className="text-center py-12 text-gray-400">
            <RefreshCw className="w-8 h-8 mx-auto animate-spin mb-2" />
            加载中...
          </div>
        ) : (
          <div className="space-y-2">
            {/* 文件夹列表 */}
            {folders.map((folder) => (
              <div
                key={folder}
                onClick={() => navigateToFolder(folder)}
                onContextMenu={(e) => handleFolderContextMenu(e, { id: 0, name: folder, path: currentPath === '/' ? `/${folder}` : `${currentPath}/${folder}` })}
                className="flex items-center gap-3 p-3 bg-white rounded-lg border hover:border-cyan-300 hover:bg-cyan-50 cursor-pointer transition-colors"
              >
                <Folder className="w-8 h-8 text-yellow-500" />
                <div className="flex-1">
                  <div className="font-medium text-gray-800">{folder}</div>
                  <div className="text-xs text-gray-400">文件夹</div>
                </div>
                <ChevronRight className="w-5 h-5 text-gray-400" />
              </div>
            ))}

            {/* 文件列表 */}
            {files.map((file) => (
              <div
                key={file.id}
                onContextMenu={(e) => handleFileContextMenu(e, file)}
                className={`flex items-center gap-3 p-3 bg-white rounded-lg border transition-colors ${selectedFiles.has(file.id) ? 'border-cyan-400 bg-cyan-50' : 'hover:border-gray-300'}`}
              >
                <input
                  type="checkbox"
                  checked={selectedFiles.has(file.id)}
                  onChange={() => toggleSelectFile(file.id)}
                  className="w-4 h-4 rounded border-gray-300 text-cyan-600 focus:ring-cyan-500"
                />
                <div className="text-2xl">{getFileIcon(file.filename)}</div>
                <div className="flex-1 min-w-0">
                  <div className="font-medium text-gray-800 truncate">{file.filename}</div>
                  <div className="text-xs text-gray-400 flex items-center gap-2">
                    <span>{formatFileSize(file.file_size)}</span>
                    <span>•</span>
                    <span>{formatTime(file.create_time)}</span>
                    {file.upload_source === 'share' && (
                      <>
                        <span>•</span>
                        <span className="text-green-600">分享上传</span>
                      </>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  <button
                    onClick={() => openFileShareModal(file.id)}
                    className="p-2 text-gray-400 hover:text-green-500 rounded-lg hover:bg-green-50 transition-colors"
                    title="分享"
                  >
                    <Share2 className="w-4 h-4" />
                  </button>
                  <button
                    onClick={() => openRenameModal(file)}
                    className="p-2 text-gray-400 hover:text-blue-500 rounded-lg hover:bg-blue-50 transition-colors"
                    title="重命名"
                  >
                    <Edit3 className="w-4 h-4" />
                  </button>
                  <button
                    onClick={() => handleDelete(file.id)}
                    className="p-2 text-gray-400 hover:text-red-500 rounded-lg hover:bg-red-50 transition-colors"
                    title="删除"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}

            {/* 空状态 */}
            {folders.length === 0 && files.length === 0 && (
              <div className="text-center py-16 text-gray-400">
                <div className="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                  <Upload className="w-10 h-10 text-gray-300" />
                </div>
                <div className="text-lg font-medium text-gray-500">暂无文件</div>
                <div className="text-sm mt-2 text-gray-400">
                  拖拽文件或文件夹到此处上传
                </div>
                <div className="text-sm text-gray-400">
                  或点击上方按钮选择文件
                </div>
                <div className="mt-4 flex items-center justify-center gap-4 text-xs text-gray-400">
                  <span className="flex items-center gap-1">
                    <FileUp className="w-4 h-4" /> 支持任意文件
                  </span>
                  <span className="flex items-center gap-1">
                    <FolderUp className="w-4 h-4" /> 支持文件夹
                  </span>
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      {/* 分享链接弹窗 */}
      {showShareModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[500px] max-h-[80vh] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between bg-gradient-to-r from-cyan-500 to-teal-600">
              <h3 className="text-lg font-semibold flex items-center gap-2 text-white">
                <Share2 className="w-5 h-5" />
                {shareFileId ? '分享文件' : '分享当前目录'}
              </h3>
              <button
                onClick={() => setShowShareModal(false)}
                className="p-1.5 hover:bg-white/20 rounded-lg text-white/80 hover:text-white"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-4">
              {!generatedLink ? (
                <>
                  <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-sm text-blue-700">
                      {shareFileId 
                        ? '生成链接后，他人可以通过此链接下载此文件。'
                        : '生成链接后，他人可以通过此链接查看和下载当前目录的文件。'}
                    </p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      <Clock className="w-4 h-4 inline mr-1" />
                      有效期（天）
                    </label>
                    <input
                      type="number"
                      value={shareExpireDays}
                      onChange={(e) => setShareExpireDays(e.target.value)}
                      min="1"
                      max="365"
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      placeholder="默认7天"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      <Lock className="w-4 h-4 inline mr-1" />
                      访问密码（可选）
                    </label>
                    <input
                      type="text"
                      value={sharePassword}
                      onChange={(e) => setSharePassword(e.target.value)}
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      placeholder="留空表示不设置密码"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      访问次数限制（可选）
                    </label>
                    <input
                      type="number"
                      value={shareMaxVisits}
                      onChange={(e) => setShareMaxVisits(e.target.value)}
                      min="1"
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      placeholder="留空表示不限制"
                    />
                  </div>

                  {/* 分享节点选择 */}
                  {shareRegions.length > 0 && (
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        分享节点
                      </label>
                      <select
                        value={selectedShareRegion || ''}
                        onChange={(e) => setSelectedShareRegion(e.target.value ? parseInt(e.target.value) : null)}
                        className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      >
                        <option value="">默认节点</option>
                        {shareRegions.map((r) => (
                          <option key={r.id} value={r.id}>
                            {r.is_default ? '⭐ ' : ''}{r.region_name}
                          </option>
                        ))}
                      </select>
                    </div>
                  )}
                </>
              ) : (
                <>
                  <div className="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <div className="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                      <Check className="w-6 h-6 text-green-600" />
                    </div>
                    <h4 className="font-semibold text-green-800 mb-2">链接已生成</h4>
                    <p className="text-sm text-green-700 mb-1">
                      有效期至: {new Date(generatedLink.expires_at).toLocaleString('zh-CN')}
                    </p>
                    {sharePassword && (
                      <p className="text-sm text-green-700">访问密码: {sharePassword}</p>
                    )}
                  </div>

                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={generatedLink.share_url}
                      readOnly
                      className="flex-1 px-3 py-2 border rounded-lg text-sm bg-gray-50"
                    />
                    <button
                      onClick={copyShareLink}
                      className="px-4 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700 flex items-center gap-1"
                    >
                      <Copy className="w-4 h-4" />
                      复制
                    </button>
                  </div>
                </>
              )}
            </div>

            <div className="px-6 py-4 border-t bg-gray-50 flex gap-3">
              {!generatedLink ? (
                <>
                  <button
                    onClick={() => setShowShareModal(false)}
                    className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                  >
                    取消
                  </button>
                  <button
                    onClick={generateShareLink}
                    disabled={generatingShare}
                    className="flex-1 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700 disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    {generatingShare ? (
                      <>
                        <RefreshCw className="w-4 h-4 animate-spin" />
                        生成中...
                      </>
                    ) : (
                      <>
                        <Share2 className="w-4 h-4" />
                        生成链接
                      </>
                    )}
                  </button>
                </>
              ) : (
                <button
                  onClick={() => {
                    setGeneratedLink(null);
                    setShowShareModal(false);
                  }}
                  className="w-full py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                >
                  关闭
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* 新建文件夹弹窗 */}
      {showNewFolderModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[400px] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between bg-gradient-to-r from-cyan-500 to-teal-600">
              <h3 className="text-lg font-semibold flex items-center gap-2 text-white">
                <FolderPlus className="w-5 h-5" />
                新建文件夹
              </h3>
              <button onClick={() => setShowNewFolderModal(false)} className="p-1.5 hover:bg-white/20 rounded-lg text-white/80 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="p-6">
              <label className="block text-sm font-medium text-gray-700 mb-2">文件夹名称</label>
              <input
                type="text"
                value={newFolderName}
                onChange={(e) => setNewFolderName(e.target.value)}
                className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                placeholder="请输入文件夹名称"
                autoFocus
              />
            </div>
            <div className="px-6 py-4 border-t bg-gray-50 flex gap-3">
              <button onClick={() => setShowNewFolderModal(false)} className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">取消</button>
              <button onClick={handleCreateFolder} disabled={creatingFolder} className="flex-1 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700 disabled:opacity-50">
                {creatingFolder ? '创建中...' : '创建'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 重命名弹窗 */}
      {showRenameModal && renameTarget && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[400px] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between bg-gradient-to-r from-blue-500 to-indigo-600">
              <h3 className="text-lg font-semibold flex items-center gap-2 text-white">
                <Edit3 className="w-5 h-5" />
                重命名
              </h3>
              <button onClick={() => setShowRenameModal(false)} className="p-1.5 hover:bg-white/20 rounded-lg text-white/80 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="p-6">
              <label className="block text-sm font-medium text-gray-700 mb-2">新名称</label>
              <input
                type="text"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="请输入新名称"
                autoFocus
              />
            </div>
            <div className="px-6 py-4 border-t bg-gray-50 flex gap-3">
              <button onClick={() => setShowRenameModal(false)} className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">取消</button>
              <button onClick={handleRename} className="flex-1 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">确定</button>
            </div>
          </div>
        </div>
      )}

      {/* 移动文件弹窗 */}
      {showMoveModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[400px] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between bg-gradient-to-r from-purple-500 to-pink-600">
              <h3 className="text-lg font-semibold flex items-center gap-2 text-white">
                <Move className="w-5 h-5" />
                移动到
              </h3>
              <button onClick={() => setShowMoveModal(false)} className="p-1.5 hover:bg-white/20 rounded-lg text-white/80 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="p-6">
              <label className="block text-sm font-medium text-gray-700 mb-2">目标路径</label>
              <input
                type="text"
                value={moveTargetPath}
                onChange={(e) => setMoveTargetPath(e.target.value)}
                className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                placeholder="/ 表示根目录"
              />
              <p className="text-xs text-gray-500 mt-2">输入目标文件夹路径，例如: /项目资料</p>
            </div>
            <div className="px-6 py-4 border-t bg-gray-50 flex gap-3">
              <button onClick={() => setShowMoveModal(false)} className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">取消</button>
              <button onClick={handleMove} className="flex-1 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">移动</button>
            </div>
          </div>
        </div>
      )}

      {/* 右键菜单 */}
      {contextMenu && (
        <div
          className="fixed bg-white rounded-lg shadow-xl border py-1 z-50 min-w-[150px]"
          style={{ left: contextMenu.x, top: contextMenu.y }}
        >
          {contextMenu.type === 'file' ? (
            <>
              <button
                onClick={() => openFileShareModal((contextMenu.item as DriveFile).id)}
                className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 flex items-center gap-2"
              >
                <Share2 className="w-4 h-4 text-green-500" />
                分享文件
              </button>
              <button
                onClick={() => { openRenameModal(contextMenu.item as DriveFile); setContextMenu(null); }}
                className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 flex items-center gap-2"
              >
                <Edit3 className="w-4 h-4 text-blue-500" />
                重命名
              </button>
              <button
                onClick={() => { handleDelete((contextMenu.item as DriveFile).id); setContextMenu(null); }}
                className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 flex items-center gap-2 text-red-600"
              >
                <Trash2 className="w-4 h-4" />
                删除
              </button>
            </>
          ) : (
            <>
              <button
                onClick={() => { openFolderShareModal(); }}
                className="w-full px-4 py-2 text-left text-sm hover:bg-gray-100 flex items-center gap-2"
              >
                <Share2 className="w-4 h-4 text-green-500" />
                分享文件夹
              </button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
