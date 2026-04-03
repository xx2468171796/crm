import { Upload } from 'lucide-react';
import { formatFileSize } from '@/lib/utils';
import { open } from '@tauri-apps/plugin-dialog';
import { scanFolderRecursive } from '@/lib/tauri';

const FILE_CATEGORIES = ['客户文件', '作品文件', '模型文件'] as const;
const FILE_CATEGORY_COLORS: Record<string, { bg: string; header: string }> = {
  '客户文件': { bg: 'bg-blue-50 border-blue-200', header: 'bg-blue-100 text-blue-800' },
  '作品文件': { bg: 'bg-green-50 border-green-200', header: 'bg-green-100 text-green-800' },
  '模型文件': { bg: 'bg-purple-50 border-purple-200', header: 'bg-purple-100 text-purple-800' },
};

type PendingUploadItem =
  | { kind: 'web'; file: File }
  | { kind: 'local'; path: string; name: string; size: number };

interface UploadModalProps {
  uploadCategory: (typeof FILE_CATEGORIES)[number];
  pendingUploads: PendingUploadItem[];
  uploading: boolean;
  uploadProgress: { current: number; total: number; filename: string; percent?: number } | null;
  dragOver: boolean;
  isTauri: boolean;
  onFileSelect: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onDrop: (e: React.DragEvent) => void;
  onDragOver: (e: React.DragEvent) => void;
  onDragLeave: (e: React.DragEvent) => void;
  onStartUpload: () => void;
  onClose: () => void;
  onRemoveFile: (index: number) => void;
  onCategoryChange: (category: (typeof FILE_CATEGORIES)[number]) => void;
  onAddLocalItems: (items: PendingUploadItem[]) => void;
}

export default function UploadModal({
  uploadCategory,
  pendingUploads,
  uploading,
  uploadProgress,
  dragOver,
  isTauri,
  onFileSelect,
  onDrop,
  onDragOver,
  onDragLeave,
  onStartUpload,
  onClose,
  onRemoveFile,
  onCategoryChange,
  onAddLocalItems,
}: UploadModalProps) {
  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div className="bg-white rounded-xl p-6 w-[480px] max-h-[80vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">上传文件</h3>
          {!uploading && (
            <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          )}
        </div>

        {/* 拖拽区域 */}
        {!uploading && (
          <div
            onDragOver={onDragOver}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
            className={`border-2 border-dashed rounded-xl p-8 text-center transition-colors mb-4 ${
              dragOver ? 'border-blue-500 bg-blue-50' : 'border-gray-300 hover:border-gray-400'
            }`}
          >
            <Upload className="w-10 h-10 mx-auto mb-3 text-gray-400" />
            <p className="text-gray-600 mb-2">拖拽文件到此处</p>
            <p className="text-gray-400 text-sm mb-3">或</p>
            <label className="inline-block px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 cursor-pointer">
              选择文件
              <input type="file" multiple className="hidden" onChange={onFileSelect} />
            </label>
            {isTauri && (
              <button
                type="button"
                onClick={async () => {
                  const folderPath = await open({ directory: true, title: '选择文件夹' });
                  if (!folderPath) return;
                  const scanned = await scanFolderRecursive(folderPath as string);
                  const newItems = scanned.map(f => ({
                    kind: 'local' as const,
                    path: f.absolute_path,
                    name: f.relative_path,
                    size: f.size || 0,
                  }));
                  onAddLocalItems(newItems);
                }}
                className="inline-block px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 ml-2"
              >
                选择文件夹
              </button>
            )}
          </div>
        )}

        {/* 待上传文件列表 */}
        {pendingUploads.length > 0 && !uploading && (
          <div className="mb-4">
            <p className="text-sm text-gray-500 mb-2">待上传文件 ({pendingUploads.length})</p>
            <div className="space-y-2 max-h-32 overflow-y-auto">
              {pendingUploads.map((item, index) => (
                <div key={index} className="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-800 truncate">{item.kind === 'web' ? item.file.name : item.name}</p>
                    <p className="text-xs text-gray-400">
                      {formatFileSize(item.kind === 'web' ? item.file.size : item.size || 0)}
                    </p>
                  </div>
                  <button onClick={() => onRemoveFile(index)} className="ml-2 text-gray-400 hover:text-red-500">
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* 上传进度 */}
        {uploading && uploadProgress && (
          <div className="mb-4 p-4 bg-blue-50 rounded-xl">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-medium text-blue-700">正在上传</p>
              <p className="text-sm text-blue-600">{uploadProgress.current}/{uploadProgress.total} 分片</p>
            </div>
            <p className="text-xs text-blue-500 mb-2 truncate">{uploadProgress.filename}</p>
            <div className="w-full bg-blue-200 rounded-full h-2">
              <div
                className="bg-blue-500 h-2 rounded-full transition-all duration-100"
                style={{ width: `${uploadProgress.percent ?? (uploadProgress.total > 0 ? Math.round((uploadProgress.current / uploadProgress.total) * 100) : 0)}%` }}
              />
            </div>
            <p className="text-xs text-blue-500 mt-1 text-right">
              {uploadProgress.percent ?? (uploadProgress.total > 0 ? Math.round((uploadProgress.current / uploadProgress.total) * 100) : 0)}%
            </p>
          </div>
        )}

        {/* 分类选择 */}
        {!uploading && (
          <div className="mb-4">
            <p className="text-sm text-gray-500 mb-2">选择上传目录</p>
            <div className="grid grid-cols-3 gap-2">
              {FILE_CATEGORIES.map((cat) => {
                const colors = FILE_CATEGORY_COLORS[cat];
                const isSelected = uploadCategory === cat;
                return (
                  <button
                    key={cat}
                    type="button"
                    onClick={() => onCategoryChange(cat)}
                    className={`p-3 rounded-lg border-2 transition-all ${
                      isSelected
                        ? `${colors.bg} border-current`
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                  >
                    <div className={`text-sm font-medium ${isSelected ? colors.header.split(' ')[1] : 'text-gray-700'}`}>
                      {cat}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        )}

        {/* 操作按钮 */}
        <div className="flex gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={uploading}
            className={`flex-1 px-4 py-2 rounded-lg ${
              uploading ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            取消
          </button>
          <button
            type="button"
            onClick={onStartUpload}
            disabled={uploading || pendingUploads.length === 0}
            className={`flex-1 px-4 py-2 rounded-lg ${
              uploading || pendingUploads.length === 0
                ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                : 'bg-blue-500 text-white hover:bg-blue-600'
            }`}
          >
            {uploading ? '上传中...' : `上传 (${pendingUploads.length})`}
          </button>
        </div>
      </div>
    </div>
  );
}
