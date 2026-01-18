import { useState, useMemo } from 'react';
import { ChevronRight, ChevronDown, Folder, FileText, Upload, Check } from 'lucide-react';

export interface LocalFileItem {
  name: string;
  path: string;
  relative_path: string;
}

interface FolderNode {
  name: string;
  path: string;
  files: LocalFileItem[];
  children: Record<string, FolderNode>;
}

interface LocalFileTreeProps {
  files: LocalFileItem[];
  cloudFiles: Array<{ filename: string; relative_path?: string }>;
  onUploadFile: (file: LocalFileItem) => void;
  onUploadFolder: (folderPath: string, files: LocalFileItem[]) => void;
  selectedFiles: Set<string>;
  onToggleSelect: (filePath: string) => void;
  onSelectAll: (files: LocalFileItem[]) => void;
}

function buildFolderTree(files: LocalFileItem[]): FolderNode {
  const root: FolderNode = { name: '', path: '', files: [], children: {} };
  
  for (const file of files) {
    const parts = file.relative_path.split('/');
    let current = root;
    
    for (let i = 0; i < parts.length - 1; i++) {
      const part = parts[i];
      if (!current.children[part]) {
        current.children[part] = {
          name: part,
          path: parts.slice(0, i + 1).join('/'),
          files: [],
          children: {},
        };
      }
      current = current.children[part];
    }
    
    current.files.push(file);
  }
  
  return root;
}

function FolderNodeComponent({
  node,
  depth = 0,
  cloudFiles,
  onUploadFile,
  onUploadFolder,
  selectedFiles,
  onToggleSelect,
  allFiles,
}: {
  node: FolderNode;
  depth?: number;
  cloudFiles: Array<{ filename: string; relative_path?: string }>;
  onUploadFile: (file: LocalFileItem) => void;
  onUploadFolder: (folderPath: string, files: LocalFileItem[]) => void;
  selectedFiles: Set<string>;
  onToggleSelect: (filePath: string) => void;
  allFiles: LocalFileItem[];
}) {
  const [expanded, setExpanded] = useState(true);
  
  // 收集该文件夹下所有文件（包括子文件夹）
  const collectAllFiles = (n: FolderNode): LocalFileItem[] => {
    let result = [...n.files];
    for (const child of Object.values(n.children)) {
      result = result.concat(collectAllFiles(child));
    }
    return result;
  };
  
  const folderFiles = useMemo(() => collectAllFiles(node), [node]);
  const pendingFiles = folderFiles.filter(f => 
    !cloudFiles.some(cf => cf.filename === f.name || cf.relative_path === f.relative_path)
  );
  
  const hasChildren = Object.keys(node.children).length > 0 || node.files.length > 0;
  
  if (!hasChildren) return null;
  
  return (
    <div>
      {/* 文件夹头部 */}
      {node.name && (
        <div 
          className="flex items-center gap-2 py-2 px-2 hover:bg-green-50 rounded cursor-pointer bg-green-50/30"
          style={{ paddingLeft: `${depth * 16 + 8}px` }}
        >
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="flex items-center gap-2 flex-1"
          >
            {expanded ? (
              <ChevronDown className="w-4 h-4 text-gray-400 flex-shrink-0" />
            ) : (
              <ChevronRight className="w-4 h-4 text-gray-400 flex-shrink-0" />
            )}
            <Folder className="w-4 h-4 text-yellow-500 flex-shrink-0" />
            <span className="text-sm font-medium text-gray-700">{node.name}</span>
            <span className="text-xs text-gray-400">({pendingFiles.length} 个待上传)</span>
          </button>
          
          {pendingFiles.length > 0 && (
            <button
              type="button"
              onClick={() => onUploadFolder(node.path, pendingFiles)}
              className="px-2 py-1 bg-green-500 hover:bg-green-600 text-white text-xs rounded flex items-center gap-1"
              title="上传整个文件夹"
            >
              <Upload className="w-3 h-3" />
              上传文件夹
            </button>
          )}
        </div>
      )}
      
      {/* 展开内容 */}
      {(expanded || !node.name) && (
        <div>
          {/* 子文件夹 */}
          {Object.values(node.children).map(child => (
            <FolderNodeComponent
              key={child.path}
              node={child}
              depth={depth + 1}
              cloudFiles={cloudFiles}
              onUploadFile={onUploadFile}
              onUploadFolder={onUploadFolder}
              selectedFiles={selectedFiles}
              onToggleSelect={onToggleSelect}
              allFiles={allFiles}
            />
          ))}
          
          {/* 当前文件夹的文件 */}
          {node.files.map(file => {
            const isUploaded = cloudFiles.some(cf => 
              cf.filename === file.name || cf.relative_path === file.relative_path
            );
            
            return (
              <div 
                key={file.path}
                className={`flex items-center gap-2 py-2 px-2 hover:bg-gray-50 rounded ${isUploaded ? 'opacity-50' : 'bg-green-50/50'}`}
                style={{ paddingLeft: `${(depth + 1) * 16 + 8}px` }}
              >
                {!isUploaded && (
                  <input
                    type="checkbox"
                    checked={selectedFiles.has(file.path)}
                    onChange={() => onToggleSelect(file.path)}
                    className="w-4 h-4 flex-shrink-0"
                  />
                )}
                <div className="w-4 h-4 flex-shrink-0" />
                <FileText className="w-4 h-4 text-green-500 flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-gray-700 truncate">{file.name}</span>
                    <span className={`px-1.5 py-0.5 rounded text-[10px] ${isUploaded ? 'bg-gray-100 text-gray-500' : 'bg-green-100 text-green-600'}`}>
                      {isUploaded ? '已上传' : '本地'}
                    </span>
                  </div>
                </div>
                
                {!isUploaded && (
                  <button
                    type="button"
                    onClick={() => onUploadFile(file)}
                    className="px-2 py-1 bg-green-500 hover:bg-green-600 text-white text-xs rounded"
                  >
                    上传
                  </button>
                )}
                {isUploaded && (
                  <Check className="w-4 h-4 text-green-500" />
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

export function LocalFileTree({
  files,
  cloudFiles,
  onUploadFile,
  onUploadFolder,
  selectedFiles,
  onToggleSelect,
  onSelectAll,
}: LocalFileTreeProps) {
  const tree = useMemo(() => buildFolderTree(files), [files]);
  
  const pendingFiles = files.filter(f => 
    !cloudFiles.some(cf => cf.filename === f.name || cf.relative_path === f.relative_path)
  );
  
  if (files.length === 0) {
    return null;
  }
  
  return (
    <div className="py-2">
      {/* 全选/批量操作 */}
      {pendingFiles.length > 0 && (
        <div className="flex items-center justify-between px-4 py-2 bg-green-50 border-b border-green-100">
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={pendingFiles.length > 0 && pendingFiles.every(f => selectedFiles.has(f.path))}
              onChange={() => onSelectAll(pendingFiles)}
              className="w-4 h-4"
            />
            <span className="text-sm text-green-700">
              {selectedFiles.size > 0 ? `已选 ${selectedFiles.size} 个` : `全选 (${pendingFiles.length} 个待上传)`}
            </span>
          </div>
          <div className="flex items-center gap-2">
            {selectedFiles.size > 0 && (
              <button
                type="button"
                onClick={() => {
                  const selectedPending = pendingFiles.filter(f => selectedFiles.has(f.path));
                  onUploadFolder('', selectedPending);
                }}
                className="px-3 py-1 bg-green-500 hover:bg-green-600 text-white text-xs rounded flex items-center gap-1"
              >
                <Upload className="w-3 h-3" />
                上传选中 ({selectedFiles.size})
              </button>
            )}
            {pendingFiles.length > 0 && (
              <button
                type="button"
                onClick={() => onUploadFolder('', pendingFiles)}
                className="px-3 py-1 bg-green-500 hover:bg-green-600 text-white text-xs rounded flex items-center gap-1"
              >
                <Upload className="w-3 h-3" />
                上传全部 ({pendingFiles.length})
              </button>
            )}
          </div>
        </div>
      )}
      
      <FolderNodeComponent
        node={tree}
        cloudFiles={cloudFiles}
        onUploadFile={onUploadFile}
        onUploadFolder={onUploadFolder}
        selectedFiles={selectedFiles}
        onToggleSelect={onToggleSelect}
        allFiles={files}
      />
    </div>
  );
}

export default LocalFileTree;
