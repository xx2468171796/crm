import { useState } from 'react';
import { ChevronRight, ChevronDown, Folder, FileText, Download, Eye, Play, Pencil, Trash2 } from 'lucide-react';
import { formatFileSize } from '@/lib/utils';

export interface FileNode {
  name: string;
  type: 'folder' | 'file';
  path: string;
  children?: FileNode[];
  file?: {
    filename: string;
    relative_path: string;
    file_size: number;
    storage_key: string;
    download_url: string;
    thumbnail_url?: string;
    last_modified?: string;
    approval_status?: string;
    uploader_name?: string;
    create_time?: string;
    id?: number;
  };
}

interface FileTreeProps {
  nodes: FileNode[];
  onPreview?: (file: FileNode['file']) => void;
  onDownload?: (file: FileNode['file']) => void;
  onDelete?: (file: FileNode['file']) => void;
  onRename?: (file: FileNode['file']) => void;
  canManageFile?: (file: FileNode['file']) => boolean;
  previewingFile?: string | null;
}

// formatFileSize imported from @/lib/utils

function FileTreeNode({ 
  node, 
  depth = 0,
  onPreview,
  onDownload,
  onDelete,
  onRename,
  canManageFile,
  previewingFile,
}: { 
  node: FileNode; 
  depth?: number;
  onPreview?: (file: FileNode['file']) => void;
  onDownload?: (file: FileNode['file']) => void;
  onDelete?: (file: FileNode['file']) => void;
  onRename?: (file: FileNode['file']) => void;
  canManageFile?: (file: FileNode['file']) => boolean;
  previewingFile?: string | null;
}) {
  const [expanded, setExpanded] = useState(depth < 2);
  
  if (node.type === 'folder') {
    const fileCount = countFiles(node);
    return (
      <div>
        <div 
          className="flex items-center gap-2 py-1.5 px-2 hover:bg-gray-50 rounded cursor-pointer"
          style={{ paddingLeft: `${depth * 16 + 8}px` }}
          onClick={() => setExpanded(!expanded)}
        >
          {expanded ? (
            <ChevronDown className="w-4 h-4 text-gray-400 flex-shrink-0" />
          ) : (
            <ChevronRight className="w-4 h-4 text-gray-400 flex-shrink-0" />
          )}
          <Folder className="w-4 h-4 text-yellow-500 flex-shrink-0" />
          <span className="text-sm font-medium text-gray-700 truncate">{node.name}</span>
          <span className="text-xs text-gray-400 ml-auto">{fileCount} 个文件</span>
        </div>
        {expanded && node.children && (
          <div>
            {node.children.map((child, index) => (
              <FileTreeNode 
                key={child.path || index} 
                node={child} 
                depth={depth + 1}
                onPreview={onPreview}
                onDownload={onDownload}
                onDelete={onDelete}
                onRename={onRename}
                canManageFile={canManageFile}
                previewingFile={previewingFile}
              />
            ))}
          </div>
        )}
      </div>
    );
  }
  
  // 文件节点
  const file = node.file;
  if (!file) return null;
  
  const ext = (file.filename || '').split('.').pop()?.toLowerCase() || '';
  const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext);
  const isVideo = ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext);
  const isPreviewing = previewingFile === file.filename;
  
  // 审核状态
  const approvalStatus = file.approval_status || '';
  const getApprovalLabel = () => {
    switch (approvalStatus) {
      case 'approved': return { text: '已通过', color: 'bg-green-100 text-green-700' };
      case 'rejected': return { text: '已驳回', color: 'bg-red-100 text-red-700' };
      case 'pending': return { text: '待审核', color: 'bg-yellow-100 text-yellow-700' };
      default: return null;
    }
  };
  const approval = getApprovalLabel();
  
  return (
    <div 
      className="flex items-center gap-2 py-2 px-2 hover:bg-gray-50 rounded group"
      style={{ paddingLeft: `${depth * 16 + 8}px` }}
    >
      <div className="w-4 h-4 flex-shrink-0" /> {/* 占位 */}
      
      {/* 缩略图/图标 */}
      <div className="w-10 h-10 rounded bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0">
        {isImage && file.thumbnail_url ? (
          <img 
            src={file.thumbnail_url} 
            alt={file.filename}
            className="w-full h-full object-cover"
            onError={(e) => {
              (e.target as HTMLImageElement).style.display = 'none';
            }}
          />
        ) : isVideo ? (
          <div className="w-full h-full bg-gray-200 flex items-center justify-center">
            <Play className="w-5 h-5 text-gray-500" />
          </div>
        ) : (
          <FileText className="w-5 h-5 text-gray-400" />
        )}
      </div>
      
      {/* 文件名和状态 */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <p className="text-sm font-medium text-gray-800 truncate">{node.name}</p>
          <span className="px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded text-[10px] flex-shrink-0">云端</span>
          {approval && (
            <span className={`px-1.5 py-0.5 rounded text-[10px] flex-shrink-0 ${approval.color}`}>{approval.text}</span>
          )}
        </div>
        <p className="text-xs text-gray-400">
          {formatFileSize(file.file_size)}
          {file.uploader_name && ` • ${file.uploader_name}`}
          {file.create_time && ` • ${file.create_time}`}
        </p>
      </div>
      
      {/* 操作按钮 */}
      <div className="flex items-center gap-1">
        {onPreview && (
          <button
            type="button"
            disabled={isPreviewing}
            onClick={() => onPreview(file)}
            className="p-1.5 hover:bg-gray-100 rounded"
            title="预览"
          >
            <Eye className={`w-4 h-4 ${isPreviewing ? 'text-gray-300 animate-pulse' : 'text-blue-500'}`} />
          </button>
        )}
        {onDownload && (
          <button
            type="button"
            onClick={() => onDownload(file)}
            className="p-1.5 hover:bg-gray-100 rounded"
            title="下载"
          >
            <Download className="w-4 h-4 text-green-500" />
          </button>
        )}
        {onRename && canManageFile && canManageFile(file) && (
          <button
            type="button"
            onClick={() => onRename(file)}
            className="p-1.5 hover:bg-gray-100 rounded"
            title="重命名"
          >
            <Pencil className="w-4 h-4 text-gray-500" />
          </button>
        )}
        {onDelete && canManageFile && canManageFile(file) && (
          <button
            type="button"
            onClick={() => onDelete(file)}
            className="p-1.5 hover:bg-red-100 rounded"
            title="删除"
          >
            <Trash2 className="w-4 h-4 text-red-500" />
          </button>
        )}
      </div>
    </div>
  );
}

function countFiles(node: FileNode): number {
  if (node.type === 'file') return 1;
  if (!node.children) return 0;
  return node.children.reduce((sum, child) => sum + countFiles(child), 0);
}

export function FileTree({ nodes, onPreview, onDownload, onDelete, onRename, canManageFile, previewingFile }: FileTreeProps) {
  if (!nodes || nodes.length === 0) {
    return (
      <div className="text-center py-8 text-gray-400 text-sm">
        暂无文件
      </div>
    );
  }
  
  return (
    <div className="py-2">
      {nodes.map((node, index) => (
        <FileTreeNode 
          key={node.path || index} 
          node={node}
          onPreview={onPreview}
          onDownload={onDownload}
          onDelete={onDelete}
          onRename={onRename}
          canManageFile={canManageFile}
          previewingFile={previewingFile}
        />
      ))}
    </div>
  );
}

export default FileTree;
