import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Check, X, ChevronDown, ChevronUp, ExternalLink, Eye } from 'lucide-react';
import { formatFileSize } from '@/lib/utils';

interface ApprovalItem {
  id: number;
  filename: string;
  file_path?: string;
  file_category: string;
  file_size: number;
  approval_status: number;
  project: {
    id: number;
    code: string;
    name: string;
    status: string;
    customer_name: string;
  };
  uploader: {
    id: number;
    name: string;
    role: string;
  };
  tech_user: {
    id: number;
    name: string;
  } | null;
  upload_time: string;
  rejection_reason: string | null;
}

interface ApprovalTableProps {
  data: ApprovalItem[];
  loading: boolean;
  selectedIds: Set<number>;
  onSelectChange: (ids: Set<number>) => void;
  onApprove: (id: number) => void;
  onReject: (id: number) => void;
  isManager: boolean;
  onProjectClick?: (projectId: number) => void;
  onPreview?: (file: { id: number; filename: string; file_path: string }) => void;
}

type SortKey = 'filename' | 'project.name' | 'uploader.name' | 'upload_time' | 'project.status';
type SortOrder = 'asc' | 'desc';

export default function ApprovalTable({
  data,
  loading,
  selectedIds,
  onSelectChange,
  onApprove,
  onReject,
  isManager,
  onProjectClick,
  onPreview,
}: ApprovalTableProps) {
  const navigate = useNavigate();
  
  const handleProjectClick = (projectId: number) => {
    if (onProjectClick) {
      onProjectClick(projectId);
    } else {
      navigate(`/project/${projectId}`);
    }
  };
  const [sortKey, setSortKey] = useState<SortKey>('upload_time');
  const [sortOrder, setSortOrder] = useState<SortOrder>('desc');

  const handleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      setSortKey(key);
      setSortOrder('asc');
    }
  };

  // 排序数据
  const sortedData = [...data].sort((a, b) => {
    let aVal: string | number = '';
    let bVal: string | number = '';
    switch (sortKey) {
      case 'filename':
        aVal = a.filename?.toLowerCase() || '';
        bVal = b.filename?.toLowerCase() || '';
        break;
      case 'project.name':
        aVal = a.project?.name?.toLowerCase() || '';
        bVal = b.project?.name?.toLowerCase() || '';
        break;
      case 'uploader.name':
        aVal = a.uploader?.name?.toLowerCase() || '';
        bVal = b.uploader?.name?.toLowerCase() || '';
        break;
      case 'upload_time':
        aVal = a.upload_time || '';
        bVal = b.upload_time || '';
        break;
      case 'project.status':
        aVal = a.project?.status?.toLowerCase() || '';
        bVal = b.project?.status?.toLowerCase() || '';
        break;
    }
    if (aVal < bVal) return sortOrder === 'asc' ? -1 : 1;
    if (aVal > bVal) return sortOrder === 'asc' ? 1 : -1;
    return 0;
  });

  const handleSelectAll = () => {
    if (selectedIds.size === sortedData.length) {
      onSelectChange(new Set());
    } else {
      onSelectChange(new Set(sortedData.map(item => item.id)));
    }
  };

  const handleSelectOne = (id: number) => {
    const newSet = new Set(selectedIds);
    if (newSet.has(id)) {
      newSet.delete(id);
    } else {
      newSet.add(id);
    }
    onSelectChange(newSet);
  };

  // formatFileSize imported from @/lib/utils

  const getStatusBadge = (status: number) => {
    switch (status) {
      case 1:
        return <span className="px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded">已通过</span>;
      case 2:
        return <span className="px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded">已驳回</span>;
      default:
        return <span className="px-2 py-0.5 text-xs bg-yellow-100 text-yellow-700 rounded">待审批</span>;
    }
  };

  const SortIcon = ({ columnKey }: { columnKey: SortKey }) => {
    if (sortKey !== columnKey) return null;
    return sortOrder === 'asc' ? <ChevronUp size={14} /> : <ChevronDown size={14} />;
  };

  if (loading) {
    return <div className="flex items-center justify-center h-64 text-gray-400">加载中...</div>;
  }

  if (data.length === 0) {
    return (
      <div className="flex items-center justify-center h-64 text-gray-400">
        暂无数据
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse">
        <thead className="bg-gray-50 border-b">
          <tr>
            {isManager && (
              <th className="w-10 px-4 py-3 text-left">
                <input
                  type="checkbox"
                  checked={selectedIds.size === sortedData.length && sortedData.length > 0}
                  onChange={handleSelectAll}
                  className="rounded"
                />
              </th>
            )}
            <th
              className="px-4 py-3 text-left text-xs font-medium text-gray-600 cursor-pointer hover:bg-gray-100"
              onClick={() => handleSort('filename')}
            >
              <div className="flex items-center gap-1">
                文件名 <SortIcon columnKey="filename" />
              </div>
            </th>
            <th
              className="px-4 py-3 text-left text-xs font-medium text-gray-600 cursor-pointer hover:bg-gray-100"
              onClick={() => handleSort('project.name')}
            >
              <div className="flex items-center gap-1">
                项目 <SortIcon columnKey="project.name" />
              </div>
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-600">客户</th>
            <th
              className="px-4 py-3 text-left text-xs font-medium text-gray-600 cursor-pointer hover:bg-gray-100"
              onClick={() => handleSort('uploader.name')}
            >
              <div className="flex items-center gap-1">
                上传者 <SortIcon columnKey="uploader.name" />
              </div>
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-600">设计师</th>
            <th
              className="px-4 py-3 text-left text-xs font-medium text-gray-600 cursor-pointer hover:bg-gray-100"
              onClick={() => handleSort('project.status')}
            >
              <div className="flex items-center gap-1">
                项目阶段 <SortIcon columnKey="project.status" />
              </div>
            </th>
            <th
              className="px-4 py-3 text-left text-xs font-medium text-gray-600 cursor-pointer hover:bg-gray-100"
              onClick={() => handleSort('upload_time')}
            >
              <div className="flex items-center gap-1">
                提交时间 <SortIcon columnKey="upload_time" />
              </div>
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-600">状态</th>
            {isManager && (
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-600">操作</th>
            )}
          </tr>
        </thead>
        <tbody className="divide-y">
          {sortedData.map((item) => (
            <tr key={item.id} className="hover:bg-gray-50">
              {isManager && (
                <td className="px-4 py-3">
                  {item.approval_status === 0 && (
                    <input
                      type="checkbox"
                      checked={selectedIds.has(item.id)}
                      onChange={() => handleSelectOne(item.id)}
                      className="rounded"
                    />
                  )}
                </td>
              )}
              <td className="px-4 py-3 text-sm">
                <div className="flex items-center gap-2">
                  <div className="flex-1 min-w-0">
                    <div className="max-w-xs truncate" title={item.filename}>
                      {item.filename}
                    </div>
                    <div className="text-xs text-gray-400">{formatFileSize(item.file_size)}</div>
                  </div>
                  {onPreview && (
                    <button
                      onClick={() => onPreview({ id: item.id, filename: item.filename, file_path: item.file_path || '' })}
                      className="p-1 text-blue-500 hover:bg-blue-50 rounded flex-shrink-0"
                      title="预览"
                    >
                      <Eye size={16} />
                    </button>
                  )}
                </div>
              </td>
              <td className="px-4 py-3 text-sm">
                <div 
                  className="max-w-xs truncate cursor-pointer hover:text-blue-600 flex items-center gap-1 group" 
                  title={item.project.name}
                  onClick={() => handleProjectClick(item.project.id)}
                >
                  <span>{item.project.name}</span>
                  <ExternalLink className="w-3 h-3 text-gray-300 group-hover:text-blue-500" />
                </div>
                <div className="text-xs text-gray-400">{item.project.code}</div>
              </td>
              <td className="px-4 py-3 text-sm">
                <div className="max-w-xs truncate">{item.project.customer_name || '-'}</div>
              </td>
              <td className="px-4 py-3 text-sm">{item.uploader.name}</td>
              <td className="px-4 py-3 text-sm">{item.tech_user?.name || '-'}</td>
              <td className="px-4 py-3 text-sm">{item.project.status}</td>
              <td className="px-4 py-3 text-sm">{item.upload_time}</td>
              <td className="px-4 py-3">{getStatusBadge(item.approval_status)}</td>
              {isManager && (
                <td className="px-4 py-3">
                  {item.approval_status === 0 && (
                    <div className="flex gap-2">
                      <button
                        onClick={() => onApprove(item.id)}
                        className="p-1 text-green-600 hover:bg-green-50 rounded"
                        title="通过"
                      >
                        <Check size={16} />
                      </button>
                      <button
                        onClick={() => onReject(item.id)}
                        className="p-1 text-red-600 hover:bg-red-50 rounded"
                        title="驳回"
                      >
                        <X size={16} />
                      </button>
                    </div>
                  )}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
