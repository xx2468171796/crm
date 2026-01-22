import { useState, useEffect, useCallback } from 'react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useToast } from '@/hooks/use-toast';
import {
  HardDrive, Upload, Trash2, Share2, Folder, RefreshCw,
  ChevronRight, Copy, Lock, Clock, Check, X, ArrowLeft,
  FolderPlus, Edit3, Move, MoreVertical
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

  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<{ current: number; total: number; filename: string } | null>(null);

  const [showShareModal, setShowShareModal] = useState(false);
  const [sharePassword, setSharePassword] = useState('');
  const [shareMaxVisits, setShareMaxVisits] = useState('');
  const [shareExpireDays, setShareExpireDays] = useState('7');
  const [generatingShare, setGeneratingShare] = useState(false);
  const [generatedLink, setGeneratedLink] = useState<ShareLink | null>(null);

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

  // 右键菜单
  const [contextMenu, setContextMenu] = useState<{ x: number; y: number; file: DriveFile } | null>(null);

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

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const fileList = e.target.files;
    if (!fileList || fileList.length === 0) return;

    setUploading(true);
    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < fileList.length; i++) {
      const file = fileList[i];
      setUploadProgress({ current: i + 1, total: fileList.length, filename: file.name });

      try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder_path', currentPath);

        const res = await fetch(`${serverUrl}/api/personal_drive_upload.php`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${token}` },
          body: formData,
        });
        const data = await res.json();
        if (data.success) {
          successCount++;
        } else {
          failCount++;
          console.error('上传失败:', data.error);
        }
      } catch (err) {
        failCount++;
        console.error('上传错误:', err);
      }
    }

    setUploading(false);
    setUploadProgress(null);
    e.target.value = '';

    if (successCount > 0) {
      toast({ title: '上传完成', description: `成功 ${successCount} 个${failCount > 0 ? `，失败 ${failCount} 个` : ''}` });
      loadFiles(currentPath);
    } else if (failCount > 0) {
      toast({ title: '上传失败', description: `${failCount} 个文件上传失败`, variant: 'destructive' });
    }
  };

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

  const handleBatchDelete = async () => {
    if (selectedFiles.size === 0) return;
    if (!confirm(`确定要删除选中的 ${selectedFiles.size} 个文件吗？`)) return;

    setDeleting(true);
    let successCount = 0;
    let failCount = 0;

    for (const fileId of selectedFiles) {
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
        if (data.success) successCount++;
        else failCount++;
      } catch {
        failCount++;
      }
    }

    setDeleting(false);
    setSelectedFiles(new Set());
    toast({ title: '批量删除完成', description: `成功 ${successCount} 个${failCount > 0 ? `，失败 ${failCount} 个` : ''}` });
    loadFiles(currentPath);
  };

  const openShareModal = () => {
    setShowShareModal(true);
    setGeneratedLink(null);
    setSharePassword('');
    setShareMaxVisits('');
    setShareExpireDays('7');
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
    setContextMenu(null);
  };

  // 右键菜单
  const handleContextMenu = (e: React.MouseEvent, file: DriveFile) => {
    e.preventDefault();
    setContextMenu({ x: e.clientX, y: e.clientY, file });
  };

  // 关闭右键菜单
  useEffect(() => {
    const handleClick = () => setContextMenu(null);
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  }, []);

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
              <Upload className="w-4 h-4" />
              上传文件
              <input type="file" multiple onChange={handleFileUpload} className="hidden" disabled={uploading} />
            </label>
            <button
              onClick={openShareModal}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-green-500/80 hover:bg-green-600 rounded-lg transition-colors"
            >
              <Share2 className="w-4 h-4" />
              生成分享链接
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

      {/* 上传进度 */}
      {uploadProgress && (
        <div className="bg-blue-50 border-b border-blue-100 px-6 py-3">
          <div className="flex items-center gap-3">
            <RefreshCw className="w-4 h-4 text-blue-600 animate-spin" />
            <span className="text-sm text-blue-700">
              正在上传 ({uploadProgress.current}/{uploadProgress.total}): {uploadProgress.filename}
            </span>
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

      {/* 文件列表 */}
      <div className="p-6">
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
              <div className="text-center py-12 text-gray-400">
                <HardDrive className="w-12 h-12 mx-auto mb-3 opacity-50" />
                <div>暂无文件</div>
                <div className="text-sm mt-1">上传文件或通过分享链接接收文件</div>
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
                生成分享链接
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
                      生成链接后，他人可以通过此链接上传文件到您的网盘。文件将自动重命名为"分享+文件名+时间"。
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
    </div>
  );
}
