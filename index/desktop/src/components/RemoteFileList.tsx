import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { 
  FolderOpen, 
  File, 
  RefreshCw, 
  Download, 
  ChevronRight, 
  Image, 
  FileText, 
  FileArchive,
  FileCode,
  Film,
  Music
} from 'lucide-react';
import { http } from '@/lib/http';
import { formatFileSize } from '@/lib/utils';
import { cn } from '@/lib/utils';

interface RemoteFile {
  rel_path: string;
  filename: string;
  size: number;
  modified_at: string | null;
  storage_key: string | null;
  is_dir: boolean;
}

interface RemoteFilesResponse {
  files: RemoteFile[];
  total: number;
  prefix: string;
}

interface Props {
  groupCode: string;
  projectId?: number;
  assetType: 'works' | 'models';
  onDownload?: (file: RemoteFile) => void;
}

// 文件图标映射
function getFileIcon(filename: string, isDir: boolean) {
  if (isDir) return <FolderOpen className="w-4 h-4 text-amber-500" />;
  
  const ext = filename.split('.').pop()?.toLowerCase() || '';
  
  // 图片
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'psd', 'ai', 'eps'].includes(ext)) {
    return <Image className="w-4 h-4 text-emerald-500" />;
  }
  // 视频
  if (['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv'].includes(ext)) {
    return <Film className="w-4 h-4 text-purple-500" />;
  }
  // 音频
  if (['mp3', 'wav', 'flac', 'aac', 'ogg'].includes(ext)) {
    return <Music className="w-4 h-4 text-pink-500" />;
  }
  // 压缩包
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
    return <FileArchive className="w-4 h-4 text-orange-500" />;
  }
  // 代码/CAD
  if (['dwg', 'dxf', 'max', '3ds', 'fbx', 'obj', 'blend', 'c4d', 'ma', 'mb'].includes(ext)) {
    return <FileCode className="w-4 h-4 text-blue-500" />;
  }
  // 文档
  if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'].includes(ext)) {
    return <FileText className="w-4 h-4 text-red-500" />;
  }
  
  return <File className="w-4 h-4 text-gray-400" />;
}

// 格式化时间
function formatModifiedAt(isoStr: string | null) {
  if (!isoStr) return '-';
  try {
    const date = new Date(isoStr);
    return date.toLocaleString('zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return isoStr;
  }
}

export default function RemoteFileList({ groupCode, projectId, assetType, onDownload }: Props) {
  const [currentPath, setCurrentPath] = useState('');

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['remote-files', groupCode, projectId || 0, assetType, currentPath],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('group_code', groupCode);
      if (projectId && projectId > 0) {
        params.set('project_id', String(projectId));
      }
      params.set('asset_type', assetType);
      if (currentPath) {
        params.set('path', currentPath);
      }
      const response = await http.get<RemoteFilesResponse>(
        `desktop_files.php?${params.toString()}`
      );
      return response;
    },
    enabled: !!groupCode,
  });

  const files = data?.data?.files || [];

  const handleDirClick = (file: RemoteFile) => {
    if (file.is_dir) {
      setCurrentPath(file.rel_path);
    }
  };

  const handleGoUp = () => {
    if (!currentPath) return;
    const parts = currentPath.split('/');
    parts.pop();
    setCurrentPath(parts.join('/'));
  };


  // 面包屑导航
  const breadcrumbs = currentPath ? currentPath.split('/') : [];

  return (
    <div className="flex flex-col h-full">
      {/* 工具栏 */}
      <div className="flex items-center justify-between px-4 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
        <div className="flex items-center gap-2 text-sm">
          <button
            onClick={() => setCurrentPath('')}
            className="text-primary hover:underline font-medium"
          >
            根目录
          </button>
          {breadcrumbs.map((part, idx) => (
            <span key={idx} className="flex items-center gap-2">
              <ChevronRight className="w-3 h-3 text-gray-400" />
              <button
                onClick={() => setCurrentPath(breadcrumbs.slice(0, idx + 1).join('/'))}
                className={cn(
                  "hover:underline",
                  idx === breadcrumbs.length - 1 ? "text-gray-600 dark:text-gray-300" : "text-primary"
                )}
              >
                {part}
              </button>
            </span>
          ))}
        </div>
        <button
          onClick={() => refetch()}
          disabled={isFetching}
          className="p-1.5 text-gray-500 hover:text-primary transition-colors disabled:opacity-50"
          title="刷新"
        >
          <RefreshCw className={cn("w-4 h-4", isFetching && "animate-spin")} />
        </button>
      </div>

      {/* 文件列表 */}
      <div className="flex-1 overflow-auto">
        {isLoading ? (
          <div className="flex items-center justify-center h-48">
            <RefreshCw className="w-6 h-6 text-primary animate-spin" />
          </div>
        ) : files.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-48 text-gray-400">
            <FolderOpen className="w-12 h-12 mb-3 opacity-50" />
            <p className="text-sm">此目录为空</p>
          </div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-800/30 sticky top-0 z-10">
              <tr className="text-left text-xs text-gray-500 uppercase tracking-wider">
                <th className="px-4 py-2.5 font-medium">名称</th>
                <th className="px-4 py-2.5 font-medium w-24 text-right">大小</th>
                <th className="px-4 py-2.5 font-medium w-40">修改时间</th>
                <th className="px-4 py-2.5 font-medium w-20 text-center">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {/* 返回上级 */}
              {currentPath && (
                <tr 
                  className="hover:bg-gray-50 dark:hover:bg-gray-800/30 cursor-pointer transition-colors"
                  onClick={handleGoUp}
                >
                  <td className="px-4 py-2.5" colSpan={4}>
                    <div className="flex items-center gap-2 text-gray-500">
                      <FolderOpen className="w-4 h-4" />
                      <span className="text-sm">..</span>
                    </div>
                  </td>
                </tr>
              )}
              {/* 文件列表 */}
              {files.map((file) => (
                <tr 
                  key={file.rel_path}
                  className={cn(
                    "transition-colors",
                    file.is_dir 
                      ? "hover:bg-amber-50 dark:hover:bg-amber-900/10 cursor-pointer" 
                      : "hover:bg-gray-50 dark:hover:bg-gray-800/30"
                  )}
                  onClick={() => file.is_dir && handleDirClick(file)}
                >
                  <td className="px-4 py-2.5">
                    <div className="flex items-center gap-2">
                      {getFileIcon(file.filename, file.is_dir)}
                      <span className={cn(
                        "text-sm truncate max-w-[300px]",
                        file.is_dir ? "font-medium text-amber-700 dark:text-amber-400" : "text-gray-700 dark:text-gray-200"
                      )}>
                        {file.filename}
                      </span>
                      {file.is_dir && (
                        <ChevronRight className="w-3 h-3 text-gray-400 ml-auto" />
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-2.5 text-sm text-gray-500 text-right">
                    {file.is_dir ? '-' : formatFileSize(file.size)}
                  </td>
                  <td className="px-4 py-2.5 text-sm text-gray-500">
                    {formatModifiedAt(file.modified_at)}
                  </td>
                  <td className="px-4 py-2.5 text-center">
                    {!file.is_dir && onDownload && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          onDownload(file);
                        }}
                        className="p-1.5 text-gray-400 hover:text-primary hover:bg-primary/10 rounded transition-colors"
                        title="下载"
                      >
                        <Download className="w-4 h-4" />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* 状态栏 */}
      <div className="px-4 py-2 text-xs text-gray-500 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700">
        共 {files.length} 个项目
        {files.filter(f => !f.is_dir).length > 0 && (
          <span className="ml-2">
            ({files.filter(f => !f.is_dir).length} 个文件, 
            {files.filter(f => f.is_dir).length} 个文件夹)
          </span>
        )}
      </div>
    </div>
  );
}
