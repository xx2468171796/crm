import { X, RotateCcw } from 'lucide-react';
import { useState, useEffect } from 'react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

interface Filters {
  status: string;
  projectId: number | null;
  uploaderId: number | null;
  techUserId: number | null;
  projectStatus: string | null;
  fileCategories: string[];
  startDate: string;
  endDate: string;
}

interface ApprovalFiltersProps {
  filters: Filters;
  onChange: (filters: Filters) => void;
  onReset: () => void;
}

interface Project {
  id: number;
  project_code: string;
  project_name: string;
}

interface User {
  id: number;
  name: string;
}

const PROJECT_STAGES = [
  '待沟通',
  '需求确认',
  '设计中',
  '设计核对',
  '设计完工',
  '设计评价',
];

const FILE_CATEGORIES = [
  { value: 'artwork_file', label: '作品文件' },
  { value: 'model_file', label: '模型文件' },
  { value: 'customer_file', label: '客户文件' },
];

export default function ApprovalFilters({ filters, onChange, onReset }: ApprovalFiltersProps) {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [projects, setProjects] = useState<Project[]>([]);
  const [users, setUsers] = useState<User[]>([]);

  useEffect(() => {
    if (!serverUrl || !token) return;

    // 加载项目列表
    fetch(`${serverUrl}/api/desktop_projects.php?action=list&per_page=500`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.data) {
          setProjects(data.data.items || []);
        }
      })
      .catch((err) => console.error('加载项目列表失败:', err));

    // 加载人员列表
    fetch(`${serverUrl}/api/desktop_projects.php?action=filters&user_type=tech`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success && data.data) {
          setUsers(data.data.users || []);
        }
      })
      .catch((err) => console.error('加载人员列表失败:', err));
  }, [serverUrl, token]);

  const handleChange = (key: keyof Filters, value: any) => {
    onChange({ ...filters, [key]: value });
  };

  const handleFileCategoryToggle = (category: string) => {
    const newCategories = filters.fileCategories.includes(category)
      ? filters.fileCategories.filter((c) => c !== category)
      : [...filters.fileCategories, category];
    handleChange('fileCategories', newCategories);
  };

  const getActiveFilterCount = () => {
    let count = 0;
    if (filters.projectId) count++;
    if (filters.uploaderId) count++;
    if (filters.techUserId) count++;
    if (filters.projectStatus) count++;
    if (filters.fileCategories.length > 0) count++;
    if (filters.startDate) count++;
    if (filters.endDate) count++;
    return count;
  };

  const activeCount = getActiveFilterCount();

  return (
    <div className="bg-white rounded-lg border p-4 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="font-medium text-gray-800">筛选条件</h3>
        {activeCount > 0 && (
          <button
            onClick={onReset}
            className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700"
          >
            <RotateCcw size={14} />
            重置 ({activeCount})
          </button>
        )}
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {/* 项目选择 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">项目</label>
          <select
            value={filters.projectId || ''}
            onChange={(e) => handleChange('projectId', e.target.value ? Number(e.target.value) : null)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          >
            <option value="">全部项目</option>
            {projects.map((project) => (
              <option key={project.id} value={project.id}>
                {project.project_name}
              </option>
            ))}
          </select>
        </div>

        {/* 上传人员选择 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">上传人员</label>
          <select
            value={filters.uploaderId || ''}
            onChange={(e) => handleChange('uploaderId', e.target.value ? Number(e.target.value) : null)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          >
            <option value="">全部人员</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>
        </div>

        {/* 设计师选择 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">设计师</label>
          <select
            value={filters.techUserId || ''}
            onChange={(e) => handleChange('techUserId', e.target.value ? Number(e.target.value) : null)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          >
            <option value="">全部设计师</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>
        </div>

        {/* 项目阶段选择 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">项目阶段</label>
          <select
            value={filters.projectStatus || ''}
            onChange={(e) => handleChange('projectStatus', e.target.value || null)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          >
            <option value="">全部阶段</option>
            {PROJECT_STAGES.map((stage) => (
              <option key={stage} value={stage}>
                {stage}
              </option>
            ))}
          </select>
        </div>

        {/* 开始日期 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">开始日期</label>
          <input
            type="date"
            value={filters.startDate}
            onChange={(e) => handleChange('startDate', e.target.value)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>

        {/* 结束日期 */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">结束日期</label>
          <input
            type="date"
            value={filters.endDate}
            onChange={(e) => handleChange('endDate', e.target.value)}
            className="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>
      </div>

      {/* 文件类型多选 */}
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">文件类型</label>
        <div className="flex gap-3">
          {FILE_CATEGORIES.map((category) => (
            <label key={category.value} className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={filters.fileCategories.includes(category.value)}
                onChange={() => handleFileCategoryToggle(category.value)}
                className="rounded"
              />
              <span className="text-sm">{category.label}</span>
            </label>
          ))}
        </div>
      </div>

      {/* 激活的筛选条件标签 */}
      {activeCount > 0 && (
        <div className="flex flex-wrap gap-2 pt-2 border-t">
          {filters.projectId && (
            <div className="flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded">
              项目: {projects.find((p) => p.id === filters.projectId)?.project_name}
              <button onClick={() => handleChange('projectId', null)} className="hover:text-blue-900">
                <X size={12} />
              </button>
            </div>
          )}
          {filters.uploaderId && (
            <div className="flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded">
              上传人员: {users.find((u) => u.id === filters.uploaderId)?.name}
              <button onClick={() => handleChange('uploaderId', null)} className="hover:text-blue-900">
                <X size={12} />
              </button>
            </div>
          )}
          {filters.techUserId && (
            <div className="flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded">
              设计师: {users.find((u) => u.id === filters.techUserId)?.name}
              <button onClick={() => handleChange('techUserId', null)} className="hover:text-blue-900">
                <X size={12} />
              </button>
            </div>
          )}
          {filters.projectStatus && (
            <div className="flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded">
              项目阶段: {filters.projectStatus}
              <button onClick={() => handleChange('projectStatus', null)} className="hover:text-blue-900">
                <X size={12} />
              </button>
            </div>
          )}
          {filters.fileCategories.length > 0 && (
            <div className="flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 text-xs rounded">
              文件类型: {filters.fileCategories.length}个
              <button onClick={() => handleChange('fileCategories', [])} className="hover:text-blue-900">
                <X size={12} />
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
