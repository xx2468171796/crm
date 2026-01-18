import { useState, useEffect, useCallback } from 'react';
import { X, Search, Check, ChevronDown, FolderKanban } from 'lucide-react';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';

interface ProjectItem {
  id: number;
  project_code: string;
  project_name: string;
  customer_name: string;
  current_status: string;
  group_id?: number;
  group_name?: string;
}

interface GroupItem {
  id: number;
  name: string;
}

interface FloatingProjectSelectorProps {
  open: boolean;
  onClose: () => void;
  value: number | null;
  onChange: (projectId: number | null, project: ProjectItem | null) => void;
}

export default function FloatingProjectSelector({
  open,
  onClose,
  value,
  onChange,
}: FloatingProjectSelectorProps) {
  const { serverUrl } = useSettingsStore();
  const { token } = useAuthStore();
  
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [projects, setProjects] = useState<ProjectItem[]>([]);
  const [groups, setGroups] = useState<GroupItem[]>([]);
  const [selectedGroupId, setSelectedGroupId] = useState<number | null>(null);
  const [showGroupDropdown, setShowGroupDropdown] = useState(false);

  // 加载分组列表
  const loadGroups = useCallback(async () => {
    if (!serverUrl || !token) return;
    try {
      const response = await fetch(`${serverUrl}/api/desktop_groups.php`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setGroups(data.data || []);
      }
    } catch (error) {
      console.error('加载分组失败:', error);
    }
  }, [serverUrl, token]);

  // 搜索项目
  const searchProjects = useCallback(async () => {
    if (!serverUrl || !token) {
      console.log('[FloatingProjectSelector] 跳过搜索: serverUrl=', serverUrl, 'token=', token ? '有' : '无');
      return;
    }
    setLoading(true);
    try {
      const params = new URLSearchParams({
        type: 'project',
        q: search,
        limit: '50',
      });
      if (selectedGroupId) {
        params.append('group_id', String(selectedGroupId));
      }
      
      const url = `${serverUrl}/api/desktop_search.php?${params}`;
      console.log('[FloatingProjectSelector] 搜索项目:', url);
      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      console.log('[FloatingProjectSelector] 响应:', data);
      setProjects(data.success ? (data.data || []) : []);
    } catch (error) {
      console.error('[FloatingProjectSelector] 搜索项目失败:', error);
      setProjects([]);
    } finally {
      setLoading(false);
    }
  }, [serverUrl, token, search, selectedGroupId]);

  // 初始化
  useEffect(() => {
    if (open) {
      loadGroups();
      setSearch('');
      setSelectedGroupId(null);
    }
  }, [open, loadGroups]);

  // 防抖搜索
  useEffect(() => {
    if (!open) return;
    const timer = setTimeout(searchProjects, 300);
    return () => clearTimeout(timer);
  }, [open, search, selectedGroupId, searchProjects]);

  // 选择项目
  const handleSelect = (project: ProjectItem) => {
    onChange(project.id, project);
    onClose();
  };

  // 清除选择
  const handleClear = () => {
    onChange(null, null);
    onClose();
  };

  if (!open) return null;

  const selectedGroup = groups.find(g => g.id === selectedGroupId);

  return (
    <div className="absolute inset-0 bg-black/60 flex items-center justify-center z-30">
      <div className="bg-slate-800 rounded-lg shadow-xl w-[320px] max-h-[80%] flex flex-col">
        {/* 头部 */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-slate-700">
          <span className="text-sm font-medium text-white">选择关联项目</span>
          <button onClick={onClose} className="text-slate-400 hover:text-white">
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* 筛选区 */}
        <div className="px-4 py-3 space-y-2 border-b border-slate-700">
          {/* 分组筛选 */}
          <div className="relative">
            <button
              onClick={() => setShowGroupDropdown(!showGroupDropdown)}
              className="w-full flex items-center justify-between px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white hover:bg-slate-600 transition-colors"
            >
              <span className="flex items-center gap-2">
                <FolderKanban className="w-4 h-4 text-slate-400" />
                {selectedGroup ? selectedGroup.name : '全部分组'}
              </span>
              <ChevronDown className={`w-4 h-4 text-slate-400 transition-transform ${showGroupDropdown ? 'rotate-180' : ''}`} />
            </button>
            
            {showGroupDropdown && (
              <div className="absolute top-full left-0 right-0 mt-1 bg-slate-700 border border-slate-600 rounded shadow-lg z-10 max-h-40 overflow-y-auto">
                <button
                  onClick={() => { setSelectedGroupId(null); setShowGroupDropdown(false); }}
                  className={`w-full px-3 py-2 text-sm text-left hover:bg-slate-600 transition-colors ${!selectedGroupId ? 'text-blue-400' : 'text-white'}`}
                >
                  全部分组
                </button>
                {groups.map(group => (
                  <button
                    key={group.id}
                    onClick={() => { setSelectedGroupId(group.id); setShowGroupDropdown(false); }}
                    className={`w-full px-3 py-2 text-sm text-left hover:bg-slate-600 transition-colors ${selectedGroupId === group.id ? 'text-blue-400' : 'text-white'}`}
                  >
                    {group.name}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* 搜索框 */}
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="搜索项目编号、名称、客户..."
              className="w-full pl-9 pr-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-blue-500"
              autoFocus
            />
          </div>
        </div>

        {/* 项目列表 */}
        <div className="flex-1 overflow-y-auto min-h-[200px] max-h-[300px]">
          {loading ? (
            <div className="flex items-center justify-center h-32 text-slate-400">
              <span className="text-sm">加载中...</span>
            </div>
          ) : projects.length === 0 ? (
            <div className="flex items-center justify-center h-32 text-slate-400">
              <span className="text-sm">暂无项目</span>
            </div>
          ) : (
            <div className="p-2 space-y-1">
              {projects.map(project => (
                <button
                  key={project.id}
                  onClick={() => handleSelect(project)}
                  className={`w-full p-3 text-left rounded transition-colors ${
                    value === project.id 
                      ? 'bg-blue-500/20 border border-blue-500/50' 
                      : 'bg-slate-700/50 hover:bg-slate-700 border border-transparent'
                  }`}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs text-slate-400">{project.project_code}</span>
                    {value === project.id && <Check className="w-4 h-4 text-blue-400" />}
                  </div>
                  <div className="text-sm text-white font-medium truncate">{project.project_name}</div>
                  <div className="flex items-center gap-2 mt-1">
                    <span className="text-xs text-slate-400 truncate">{project.customer_name}</span>
                    <span className="px-1.5 py-0.5 text-[10px] rounded bg-slate-600 text-slate-300">
                      {project.current_status}
                    </span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>

        {/* 底部按钮 */}
        <div className="flex gap-2 px-4 py-3 border-t border-slate-700">
          <button
            onClick={handleClear}
            className="flex-1 py-2 text-sm text-slate-400 hover:text-white bg-slate-700 rounded transition-colors"
          >
            不关联
          </button>
          <button
            onClick={onClose}
            className="flex-1 py-2 text-sm text-white bg-blue-500 hover:bg-blue-600 rounded transition-colors"
          >
            取消
          </button>
        </div>
      </div>
    </div>
  );
}
