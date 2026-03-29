import { FolderOpen, FileText, Eye, Download, Trash2 } from 'lucide-react';
import { formatFileSize } from '@/lib/utils';
import { isManager as checkIsManager } from '@/lib/utils';
import FileTree, { type FileNode } from '@/components/FileTree';
import LocalFileTree, { type LocalFileItem } from '@/components/LocalFileTree';

interface FileItem {
  id: number;
  filename: string;
  file_path: string;
  storage_key?: string;
  download_url?: string;
  file_size: number;
  approval_status: string | number;
  uploader_name: string;
  create_time: string;
  uploader_id?: number;
  relative_path?: string;
  thumbnail_url?: string;
  last_modified?: string;
  [key: string]: any;
}

interface DeliverableCategoryProps {
  categoryName: string;
  categoryFiles: FileItem[];
  treeNodes?: FileNode[];
  colors: { bg: string; header: string };
  localFiles: LocalFileItem[];
  fileViewMode: 'list' | 'tree';
  selectedFileIds: Set<number>;
  selectedLocalFiles: Set<string>;
  previewingFile: string | null;
  isManager: boolean;
  userRole?: string;
  userId?: number;
  onSetFileViewMode: (mode: 'list' | 'tree') => void;
  onToggleSelectFile: (id: number) => void;
  onSelectAllFiles?: (files: FileItem[]) => void;
  onSetShowBatchDeleteConfirm: (show: boolean) => void;
  onBatchApprove: (files: FileItem[]) => void;
  onBatchReject: (files: FileItem[]) => void;
  onBatchResubmit: (files: FileItem[], categoryName: string) => void;
  onOpenLocalFolder: (categoryName: string) => void;
  onUploadFile: (file: LocalFileItem, categoryName: string) => void;
  onUploadFolder: (folderPath: string, files: LocalFileItem[], categoryName: string) => void;
  onToggleSelectLocalFile: (filePath: string) => void;
  onSelectAllLocalFiles: (files: LocalFileItem[]) => void;
  onFilePreview: (file: FileItem, categoryName: string) => void;
  onFileDelete: (file: FileItem) => void;
  onFileRename: (file: FileItem) => void;
  onFileDownload: (file: FileItem) => void;
  onApprove: (fileId: number) => void;
  onReject: (fileId: number) => void;
  onResubmit: (file: FileItem, categoryName: string) => void;
  normalizeApprovalStatus: (raw: any) => 'pending' | 'approved' | 'rejected';
  canManageFile: (file: FileItem) => boolean;
}

export default function DeliverableCategory({
  categoryName,
  categoryFiles,
  treeNodes,
  colors,
  localFiles,
  fileViewMode,
  selectedFileIds,
  selectedLocalFiles,
  previewingFile,
  isManager,
  userRole,
  userId,
  onSetFileViewMode,
  onToggleSelectFile,
  onSetShowBatchDeleteConfirm,
  onBatchApprove,
  onBatchReject,
  onBatchResubmit,
  onOpenLocalFolder,
  onUploadFile,
  onUploadFolder,
  onToggleSelectLocalFile,
  onSelectAllLocalFiles,
  onFilePreview,
  onFileDelete,
  onFileRename,
  onFileDownload,
  onApprove,
  onReject,
  onResubmit,
  normalizeApprovalStatus,
  canManageFile,
}: DeliverableCategoryProps) {
  const deletableSelected = categoryFiles
    .filter((f) => selectedFileIds.has(Number(f.id)) && Number(f.id) > 0)
    .filter((f) => canManageFile(f));
  const pendingSelected = categoryFiles
    .filter((f) => selectedFileIds.has(Number(f.id)) && Number(f.id) > 0)
    .filter((f) => normalizeApprovalStatus(f.approval_status) === 'pending');
  const rejectedSelected = categoryFiles
    .filter((f) => selectedFileIds.has(Number(f.id)) && normalizeApprovalStatus(f.approval_status) === 'rejected');

  const manageableFiles = categoryFiles.filter((f) => canManageFile(f) && Number(f.id) > 0);
  const allManageableSelected = manageableFiles.length > 0 && manageableFiles.every((f) => selectedFileIds.has(Number(f.id)));

  return (
    <div className={`bg-white rounded-xl border ${colors.bg}`}>
      <div className={`px-4 py-3 border-b rounded-t-xl ${colors.header}`}>
        <div className="flex items-center justify-between">
          <h4 className="font-medium">{categoryName}</h4>
          <div className="flex items-center gap-2">
            <span className="text-xs opacity-70">
              {categoryFiles.length} 个文件
            </span>
            <div className="flex items-center gap-1 bg-white/50 rounded p-0.5">
              <button
                type="button"
                onClick={() => onSetFileViewMode('list')}
                className={`px-2 py-0.5 text-xs rounded ${fileViewMode === 'list' ? 'bg-white shadow' : 'hover:bg-white/50'}`}
                title="列表视图"
              >
                列表
              </button>
              <button
                type="button"
                onClick={() => onSetFileViewMode('tree')}
                className={`px-2 py-0.5 text-xs rounded ${fileViewMode === 'tree' ? 'bg-white shadow' : 'hover:bg-white/50'}`}
                title="树状视图"
              >
                树状
              </button>
            </div>
            {/* 全选/取消全选按钮 */}
            {manageableFiles.length > 0 && (
              <button
                type="button"
                className="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200"
                onClick={() => {
                  if (allManageableSelected) {
                    // 取消全选 - handled by parent via onToggleSelectFile per file
                    manageableFiles.forEach((f) => {
                      if (selectedFileIds.has(Number(f.id))) {
                        onToggleSelectFile(Number(f.id));
                      }
                    });
                  } else {
                    // 全选
                    manageableFiles.forEach((f) => {
                      if (!selectedFileIds.has(Number(f.id))) {
                        onToggleSelectFile(Number(f.id));
                      }
                    });
                  }
                }}
                title={allManageableSelected ? '取消全选' : '全选'}
              >
                {allManageableSelected ? '取消全选' : '全选'}
              </button>
            )}
            {deletableSelected.length > 0 && (
              <button
                type="button"
                className="px-2 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600"
                onClick={() => onSetShowBatchDeleteConfirm(true)}
                title="批量删除"
              >
                批量删除({deletableSelected.length})
              </button>
            )}
            {isManager && pendingSelected.length > 0 && (
              <>
                <button
                  type="button"
                  className="px-2 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600"
                  onClick={() => onBatchApprove(pendingSelected)}
                  title="批量通过"
                >
                  批量通过({pendingSelected.length})
                </button>
                <button
                  type="button"
                  className="px-2 py-1 text-xs bg-orange-500 text-white rounded hover:bg-orange-600"
                  onClick={() => onBatchReject(pendingSelected)}
                  title="批量驳回"
                >
                  批量驳回({pendingSelected.length})
                </button>
              </>
            )}
            {rejectedSelected.length > 0 && (
              <button
                type="button"
                className="px-2 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600"
                onClick={() => onBatchResubmit(rejectedSelected, categoryName)}
                title="批量重新提交"
              >
                批量重新提交({rejectedSelected.length})
              </button>
            )}
            <button
              onClick={() => onOpenLocalFolder(categoryName)}
              className="p-1 hover:bg-white/50 rounded"
              title={`打开本地${categoryName}文件夹`}
            >
              <FolderOpen className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
      <div className="divide-y">
        {/* 本地文件 - 树状视图 */}
        {localFiles.length > 0 && (
          <LocalFileTree
            files={localFiles}
            cloudFiles={categoryFiles.map((f) => ({
              filename: f.filename,
              relative_path: f.relative_path,
            }))}
            onUploadFile={(file) => onUploadFile(file, categoryName)}
            onUploadFolder={(folderPath, files) => onUploadFolder(folderPath, files, categoryName)}
            selectedFiles={selectedLocalFiles}
            onToggleSelect={onToggleSelectLocalFile}
            onSelectAll={onSelectAllLocalFiles}
          />
        )}

        {/* 云端文件 - 树状视图 */}
        {fileViewMode === 'tree' && categoryFiles.length > 0 && (
          <FileTree
            nodes={(() => {
              if (treeNodes && treeNodes.length > 0) {
                return treeNodes;
              }
              return categoryFiles.map((f) => ({
                name: f.filename || f.relative_path?.split('/').pop() || '未知文件',
                type: 'file' as const,
                path: f.relative_path || f.filename,
                file: {
                  id: f.id,
                  filename: f.filename,
                  relative_path: f.relative_path || f.filename,
                  file_size: f.file_size || 0,
                  storage_key: f.storage_key || f.file_path || '',
                  download_url: f.download_url || '',
                  thumbnail_url: f.thumbnail_url,
                  last_modified: f.last_modified,
                  approval_status: String(f.approval_status),
                  uploader_name: f.uploader_name,
                  create_time: f.create_time,
                },
              }));
            })()}
            onPreview={(file) => {
              if (!file) return;
              const originalFile = categoryFiles.find(
                (f) => f.storage_key === file.storage_key || f.filename === file.filename
              );
              if (originalFile) onFilePreview(originalFile, categoryName);
            }}
            onDownload={(file) => {
              if (!file) return;
              const originalFile = categoryFiles.find(
                (f) => f.storage_key === file.storage_key || f.filename === file.filename
              );
              if (originalFile) onFileDownload(originalFile);
            }}
            onDelete={(file) => {
              if (!file) return;
              const originalFile = categoryFiles.find(
                (f) => f.storage_key === file.storage_key || f.filename === file.filename
              );
              if (originalFile) onFileDelete(originalFile);
            }}
            onRename={(file) => {
              if (!file) return;
              const originalFile = categoryFiles.find(
                (f) => f.storage_key === file.storage_key || f.filename === file.filename
              );
              if (originalFile) onFileRename(originalFile);
            }}
            canManageFile={(file) => {
              if (!file) return false;
              if (checkIsManager(userRole)) return true;
              const originalFile = categoryFiles.find(
                (f) => f.storage_key === file.storage_key || f.filename === file.filename
              ) as FileItem | undefined;
              if (!originalFile) return false;
              const uploaderId = Number(originalFile?.uploader_id || 0);
              const approvalStatus = normalizeApprovalStatus(originalFile?.approval_status);
              const isUploader = uploaderId > 0 && uploaderId === (userId || 0);
              return isUploader && (approvalStatus === 'pending' || approvalStatus === 'rejected');
            }}
            previewingFile={previewingFile}
          />
        )}

        {/* 云端文件 - 列表视图 */}
        {fileViewMode === 'list' && categoryFiles.map((file) => (
          <div key={file.id} className="p-4 hover:bg-gray-50 flex items-center justify-between">
            <div className="flex items-center gap-3">
              {canManageFile(file) && Number(file.id) > 0 && (
                <input
                  type="checkbox"
                  checked={selectedFileIds.has(Number(file.id))}
                  onChange={() => onToggleSelectFile(Number(file.id))}
                  className="w-4 h-4"
                  title="选择"
                />
              )}
              <div
                className={`w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden cursor-pointer ${previewingFile === file.filename ? 'ring-2 ring-blue-500' : ''}`}
                onClick={() => onFilePreview(file, categoryName)}
              >
                {(() => {
                  const ext = (file.filename || '').split('.').pop()?.toLowerCase() || '';
                  const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext);
                  const isVideo = ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext);

                  if (isImage && file.thumbnail_url) {
                    return (
                      <img
                        src={file.thumbnail_url}
                        alt={file.filename}
                        className="w-full h-full object-cover"
                        onError={(e) => {
                          (e.target as HTMLImageElement).style.display = 'none';
                          const parent = (e.target as HTMLImageElement).parentElement;
                          if (parent) {
                            const icon = parent.querySelector('.fallback-icon');
                            if (icon) icon.classList.remove('hidden');
                          }
                        }}
                      />
                    );
                  }
                  if (isVideo) {
                    return (
                      <div className="w-full h-full bg-gray-200 flex items-center justify-center relative">
                        <svg className="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 24 24">
                          <path d="M8 5v14l11-7z"/>
                        </svg>
                      </div>
                    );
                  }
                  return null;
                })()}
                <FileText className={`fallback-icon w-5 h-5 text-gray-400 ${(file.thumbnail_url && ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes((file.filename || '').split('.').pop()?.toLowerCase() || '')) || ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes((file.filename || '').split('.').pop()?.toLowerCase() || '') ? 'hidden' : ''}`} />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <p className="font-medium text-gray-800">{file.filename ? (file.filename.includes('/') || file.filename.includes('\\') ? file.filename.split(/[/\\]/).pop() : file.filename) : '未知文件'}</p>
                  <span className="px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded text-[10px]">云端</span>
                  {localFiles.some(lf => lf.name === file.filename) && (
                    <span className="px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded text-[10px]">已同步</span>
                  )}
                </div>
                <p className="text-xs text-gray-400">
                  {formatFileSize(file.file_size)} • {file.uploader_name} • {file.create_time}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              {/* 审批状态徽章和操作按钮 */}
              {normalizeApprovalStatus(file.approval_status) === 'pending' && (
                <>
                  <span className="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded text-xs">待审核</span>
                  {isManager && (
                    <>
                      <button
                        type="button"
                        onClick={() => onApprove(file.id)}
                        className="px-2 py-0.5 bg-green-500 hover:bg-green-600 text-white rounded text-xs"
                        title="通过"
                      >
                        通过
                      </button>
                      <button
                        type="button"
                        onClick={() => onReject(file.id)}
                        className="px-2 py-0.5 bg-red-500 hover:bg-red-600 text-white rounded text-xs"
                        title="驳回"
                      >
                        驳回
                      </button>
                    </>
                  )}
                </>
              )}
              {normalizeApprovalStatus(file.approval_status) === 'approved' && (
                <span className="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">已通过</span>
              )}
              {normalizeApprovalStatus(file.approval_status) === 'rejected' && (
                <>
                  <span className="px-2 py-0.5 bg-red-100 text-red-700 rounded text-xs">已驳回</span>
                  <button
                    type="button"
                    onClick={() => onResubmit(file, categoryName)}
                    className="px-2 py-0.5 bg-blue-500 hover:bg-blue-600 text-white rounded text-xs"
                    title="重新提交"
                  >
                    重新提交
                  </button>
                </>
              )}
              <button
                type="button"
                disabled={previewingFile === file.filename}
                onClick={() => onFilePreview(file, categoryName)}
                className="p-2 hover:bg-gray-100 rounded-lg"
                title="预览"
              >
                <Eye className={`w-4 h-4 ${previewingFile === file.filename ? 'text-gray-300 animate-pulse' : 'text-blue-500'}`} />
              </button>
              <button
                type="button"
                onClick={() => onFileDownload(file)}
                className="p-2 hover:bg-gray-100 rounded-lg"
                title="下载"
              >
                <Download className="w-4 h-4 text-gray-500" />
              </button>
              {canManageFile(file) && (
                <button
                  type="button"
                  onClick={() => onFileRename(file)}
                  className="p-2 hover:bg-gray-100 rounded-lg"
                  title="重命名"
                >
                  <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
              )}
              {canManageFile(file) && (
                <button
                  onClick={() => onFileDelete(file)}
                  className="p-2 hover:bg-red-100 rounded-lg"
                  title="删除"
                >
                  <Trash2 className="w-4 h-4 text-red-500" />
                </button>
              )}
            </div>
          </div>
        ))}

        {/* 空状态 */}
        {categoryFiles.length === 0 && localFiles.length === 0 && (
          <div className="p-6 text-center text-gray-400 text-sm">
            暂无{categoryName}
          </div>
        )}
      </div>
    </div>
  );
}
