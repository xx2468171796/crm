import { useState, useRef, useCallback } from 'react';
import { Send, FolderOpen, X, File, CheckCircle } from 'lucide-react';
import { invoke } from '@tauri-apps/api/core';
import { open } from '@tauri-apps/plugin-dialog';

interface QuickSendProps {
  onClose?: () => void;
}

export default function QuickSend({ onClose }: QuickSendProps) {
  const [files, setFiles] = useState<string[]>([]);
  const [sending, setSending] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const dropRef = useRef<HTMLDivElement>(null);

  const handleSelectFiles = async () => {
    try {
      const selected = await open({
        multiple: true,
        title: '选择要发送的文件',
      });
      if (selected) {
        const paths = Array.isArray(selected) ? selected : [selected];
        setFiles(prev => [...prev, ...paths]);
      }
    } catch (e) {
      console.error('选择文件失败:', e);
    }
  };

  const handleRemoveFile = (index: number) => {
    setFiles(prev => prev.filter((_, i) => i !== index));
  };

  const handleSend = async () => {
    if (files.length === 0) {
      setError('请先选择文件');
      return;
    }

    setSending(true);
    setError(null);

    try {
      // 1. 复制文件到剪贴板
      await invoke('copy_files_to_clipboard', { paths: files });
      
      // 2. 短暂延迟后模拟粘贴
      await new Promise(resolve => setTimeout(resolve, 300));
      await invoke('simulate_paste');
      
      setSuccess(true);
      setFiles([]);
      
      // 3秒后重置成功状态
      setTimeout(() => setSuccess(false), 3000);
    } catch (e) {
      setError(String(e));
    } finally {
      setSending(false);
    }
  };

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    const droppedFiles = Array.from(e.dataTransfer.files).map(f => (f as any).path || f.name);
    if (droppedFiles.length > 0) {
      setFiles(prev => [...prev, ...droppedFiles]);
    }
  }, []);

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
  };

  return (
    <div className="bg-white rounded-lg border shadow-lg p-4 w-80">
      {/* 标题 */}
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-semibold text-gray-800">快捷发送</h3>
        {onClose && (
          <button
            onClick={onClose}
            className="p-1 hover:bg-gray-100 rounded"
          >
            <X className="w-4 h-4 text-gray-500" />
          </button>
        )}
      </div>

      {/* 拖拽区域 */}
      <div
        ref={dropRef}
        onDrop={handleDrop}
        onDragOver={handleDragOver}
        onClick={handleSelectFiles}
        className="border-2 border-dashed border-gray-200 rounded-lg p-4 text-center cursor-pointer hover:border-blue-300 hover:bg-blue-50/50 transition-colors"
      >
        <FolderOpen className="w-8 h-8 text-gray-400 mx-auto mb-2" />
        <p className="text-sm text-gray-500">点击选择或拖拽文件</p>
      </div>

      {/* 文件列表 */}
      {files.length > 0 && (
        <div className="mt-3 space-y-2 max-h-32 overflow-y-auto">
          {files.map((file, index) => (
            <div
              key={index}
              className="flex items-center gap-2 p-2 bg-gray-50 rounded text-sm"
            >
              <File className="w-4 h-4 text-gray-400 flex-shrink-0" />
              <span className="flex-1 truncate text-gray-700">
                {file.split(/[/\\]/).pop()}
              </span>
              <button
                onClick={() => handleRemoveFile(index)}
                className="p-0.5 hover:bg-gray-200 rounded"
              >
                <X className="w-3 h-3 text-gray-500" />
              </button>
            </div>
          ))}
        </div>
      )}

      {/* 错误提示 */}
      {error && (
        <div className="mt-3 p-2 bg-red-50 text-red-600 text-xs rounded">
          {error}
        </div>
      )}

      {/* 成功提示 */}
      {success && (
        <div className="mt-3 p-2 bg-green-50 text-green-600 text-xs rounded flex items-center gap-2">
          <CheckCircle className="w-4 h-4" />
          文件已发送到剪贴板
        </div>
      )}

      {/* 发送按钮 */}
      <button
        onClick={handleSend}
        disabled={files.length === 0 || sending}
        className={`mt-3 w-full py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2 transition-colors ${
          files.length === 0 || sending
            ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
            : 'bg-blue-500 text-white hover:bg-blue-600'
        }`}
      >
        <Send className="w-4 h-4" />
        {sending ? '发送中...' : '复制并粘贴'}
      </button>

      <p className="mt-2 text-xs text-gray-400 text-center">
        提示：请先点击目标聊天窗口
      </p>
    </div>
  );
}
