import { useState, useEffect } from 'react';
import { FolderSync, Upload, Download, AlertCircle, CheckCircle, Clock, RefreshCw, X, Trash2 } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useSyncStore } from '@/stores/sync';

interface FileLog {
  id: number;
  filename: string;
  operation: 'upload' | 'download';
  status: 'success' | 'failed' | 'pending';
  size: number;
  project_name: string;
  folder_type: string;
  created_at: string;
  error_message?: string;
}

export default function FileLogsPage() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { uploadTasks, downloadTasks, removeUploadTask, removeDownloadTask } = useSyncStore();
  const [logs, setLogs] = useState<FileLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'upload' | 'download'>('all');

  // 活跃任务（上传中/下载中/等待中），根据筛选过滤
  const activeUploadTasks = uploadTasks.filter(t => ['pending', 'uploading', 'paused'].includes(t.status));
  const activeDownloadTasks = downloadTasks.filter(t => ['pending', 'downloading', 'paused'].includes(t.status));
  
  // 根据筛选条件过滤显示的活跃任务
  const filteredUploadTasks = filter === 'download' ? [] : activeUploadTasks;
  const filteredDownloadTasks = filter === 'upload' ? [] : activeDownloadTasks;
  const hasActiveTasks = filteredUploadTasks.length > 0 || filteredDownloadTasks.length > 0;

  // 格式化速度
  const formatSpeed = (bytesPerSecond: number) => {
    if (bytesPerSecond < 1024) return `${bytesPerSecond.toFixed(0)} B/s`;
    if (bytesPerSecond < 1024 * 1024) return `${(bytesPerSecond / 1024).toFixed(1)} KB/s`;
    return `${(bytesPerSecond / (1024 * 1024)).toFixed(1)} MB/s`;
  };

  useEffect(() => {
    loadLogs();
  }, [serverUrl, token, filter]);

  const loadLogs = async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (filter !== 'all') params.append('operation', filter);
      
      const response = await fetch(`${serverUrl}/api/desktop_file_logs.php?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setLogs(data.data.items || []);
      }
    } catch (error) {
      console.error('加载文件日志失败:', error);
    } finally {
      setLoading(false);
    }
  };

  const formatSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'success':
        return <CheckCircle className="w-4 h-4 text-green-500" />;
      case 'failed':
        return <AlertCircle className="w-4 h-4 text-red-500" />;
      default:
        return <Clock className="w-4 h-4 text-yellow-500" />;
    }
  };

  const getOperationIcon = (operation: string) => {
    return operation === 'upload' ? (
      <Upload className="w-4 h-4 text-blue-500" />
    ) : (
      <Download className="w-4 h-4 text-green-500" />
    );
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <FolderSync className="w-6 h-6 text-blue-500" />
            <h1 className="text-lg font-semibold text-gray-800">文件日志</h1>
          </div>
          <div className="flex items-center gap-3">
            {/* 筛选 */}
            <div className="flex border rounded-lg overflow-hidden">
              {(['all', 'upload', 'download'] as const).map((type) => (
                <button
                  key={type}
                  onClick={() => setFilter(type)}
                  className={`px-4 py-1.5 text-sm ${
                    filter === type
                      ? 'bg-blue-500 text-white'
                      : 'bg-white text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  {type === 'all' ? '全部' : type === 'upload' ? '上传' : '下载'}
                </button>
              ))}
            </div>
            {/* 刷新 */}
            <button
              onClick={loadLogs}
              className="p-2 text-gray-500 hover:text-blue-500 hover:bg-blue-50 rounded-lg transition-colors"
            >
              <RefreshCw className={`w-5 h-5 ${loading ? 'animate-spin' : ''}`} />
            </button>
          </div>
        </div>
      </div>

      {/* 正在进行的任务 */}
      <div className="flex-1 overflow-auto p-4 space-y-4">
        {hasActiveTasks && (
          <div className="bg-white rounded-xl border overflow-hidden">
            <div className="px-4 py-3 bg-blue-50 border-b flex items-center justify-between">
              <div className="flex items-center gap-2">
                <RefreshCw className="w-4 h-4 text-blue-500 animate-spin" />
                <span className="font-medium text-blue-700">正在进行的任务</span>
                <span className="text-sm text-blue-500">
                  ({filteredUploadTasks.length + filteredDownloadTasks.length})
                </span>
              </div>
              <button
                onClick={() => {
                  activeUploadTasks.forEach(t => removeUploadTask(t.id));
                  activeDownloadTasks.forEach(t => removeDownloadTask(t.id));
                }}
                className="flex items-center gap-1 px-2 py-1 text-xs text-red-500 hover:bg-red-50 rounded transition-colors"
                title="清除所有任务"
              >
                <Trash2 className="w-3 h-3" />
                清除全部
              </button>
            </div>
            <div className="divide-y">
              {/* 上传任务 */}
              {filteredUploadTasks.map((task) => (
                <div key={task.id} className="p-4">
                  <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center gap-2">
                      <Upload className="w-4 h-4 text-blue-500" />
                      <span className="text-sm font-medium text-gray-800 truncate max-w-[200px]">
                        {task.filename}
                      </span>
                      <span className="px-1.5 py-0.5 text-xs bg-blue-100 text-blue-600 rounded">
                        上传
                      </span>
                    </div>
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                      {task.status === 'uploading' && task.speed > 0 && (
                        <span>{formatSpeed(task.speed)}</span>
                      )}
                      <span>{task.uploadedParts}/{task.totalParts} 分片</span>
                      <span>{Math.round(task.progress)}%</span>
                      <button
                        onClick={() => removeUploadTask(task.id)}
                        className="ml-1 p-0.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded"
                        title="取消任务"
                      >
                        <X className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div
                      className="bg-blue-500 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${task.progress}%` }}
                    />
                  </div>
                  <div className="flex items-center justify-between mt-1">
                    <span className="text-xs text-gray-400">
                      {formatSize(task.filesize)}
                    </span>
                    <span className="text-xs text-gray-400">
                      {task.status === 'pending' ? '等待中' : task.status === 'paused' ? '已暂停' : '上传中'}
                    </span>
                  </div>
                </div>
              ))}
              {/* 下载任务 */}
              {filteredDownloadTasks.map((task) => (
                <div key={task.id} className="p-4">
                  <div className="flex items-center justify-between mb-2">
                    <div className="flex items-center gap-2">
                      <Download className="w-4 h-4 text-green-500" />
                      <span className="text-sm font-medium text-gray-800 truncate max-w-[200px]">
                        {task.filename}
                      </span>
                      <span className="px-1.5 py-0.5 text-xs bg-green-100 text-green-600 rounded">
                        下载
                      </span>
                    </div>
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                      {task.status === 'downloading' && task.speed > 0 && (
                        <span>{formatSpeed(task.speed)}</span>
                      )}
                      <span>{Math.round(task.progress)}%</span>
                      <button
                        onClick={() => removeDownloadTask(task.id)}
                        className="ml-1 p-0.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded"
                        title="取消任务"
                      >
                        <X className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div
                      className="bg-green-500 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${task.progress}%` }}
                    />
                  </div>
                  <div className="flex items-center justify-between mt-1">
                    <span className="text-xs text-gray-400">
                      {formatSize(task.filesize)}
                    </span>
                    <span className="text-xs text-gray-400">
                      {task.status === 'pending' ? '等待中' : task.status === 'paused' ? '已暂停' : '下载中'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* 日志列表 */}
        {loading ? (
          <div className="flex items-center justify-center h-64 text-gray-400">
            加载中...
          </div>
        ) : logs.length === 0 && !hasActiveTasks ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <FolderSync className="w-12 h-12 mb-4 opacity-50" />
            <p>暂无同步日志</p>
          </div>
        ) : logs.length > 0 && (
          <div className="bg-white rounded-xl border overflow-hidden">
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    文件
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    项目
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    类型
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    操作
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    大小
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    状态
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    时间
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {logs.map((log) => (
                  <tr key={log.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <span className="text-sm text-gray-800 font-medium">
                        {log.filename}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {log.project_name}
                    </td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 text-xs rounded bg-gray-100 text-gray-600">
                        {log.folder_type}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5">
                        {getOperationIcon(log.operation)}
                        <span className="text-sm text-gray-600">
                          {log.operation === 'upload' ? '上传' : '下载'}
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {formatSize(log.size)}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5">
                        {getStatusIcon(log.status)}
                        <span
                          className={`text-sm ${
                            log.status === 'success'
                              ? 'text-green-600'
                              : log.status === 'failed'
                              ? 'text-red-600'
                              : 'text-yellow-600'
                          }`}
                        >
                          {log.status === 'success'
                            ? '成功'
                            : log.status === 'failed'
                            ? '失败'
                            : '进行中'}
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {log.created_at}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
