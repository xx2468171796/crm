import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { LayoutGrid, List, Search, MoreVertical, User, Users, ChevronRight, ChevronDown, RefreshCw, Calendar, DollarSign, FolderOpen, Trash2, AlertTriangle } from 'lucide-react';
import { invoke } from '@tauri-apps/api/core';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import { useToast } from '@/hooks/use-toast';
import TimeRangeFilter, { TimeRange } from '@/components/TimeRangeFilter';

interface TechUser {
  id: number;
  name: string;
  assignment_id?: number;
  commission?: number | null;
  commission_note?: string | null;
}

interface Project {
  id: number;
  project_code: string;
  project_name: string;
  current_status: string;
  customer_id: number;
  customer_name: string;
  customer_group_code: string;
  customer_group_name: string | null;
  group_name?: string | null;
  tech_users: TechUser[];
  update_time: string;
}

function sanitizeFolderName(name: string): string {
  return (name || '').replace(/[\/\\:*?"<>|]/g, '_');
}

interface StatusConfig {
  key: string;
  label: string;
  color: string;
}

interface KanbanColumn {
  status: StatusConfig;
  projects: Project[];
}

interface FilterUser {
  id: number;
  name: string;
}

type ViewMode = 'kanban' | 'table' | 'person';

export default function ProjectKanbanPage() {
  const navigate = useNavigate();
  const { token } = useAuthStore();
  const { serverUrl, rootDir } = useSettingsStore();
  const { canManageProjects } = usePermissionsStore();
  const { toast } = useToast();
  
  // 状态
  const [loading, setLoading] = useState(true);
  const [viewMode, setViewMode] = useState<ViewMode>('kanban');
  const [statuses, setStatuses] = useState<StatusConfig[]>([]);
  const [kanban, setKanban] = useState<Record<string, KanbanColumn>>({});
  const [projects, setProjects] = useState<Project[]>([]);
  const [total, setTotal] = useState(0);
  
  // 筛选
  const [search, setSearch] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [filterUserId, setFilterUserId] = useState('');
  const [userType, setUserType] = useState<'tech' | 'sales'>('tech');
  const [filterUsers, setFilterUsers] = useState<FilterUser[]>([]);
  
  // 时间筛选
  const [timeRange, setTimeRange] = useState<TimeRange>('all');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  
  // 状态变更弹窗
  const [statusMenuProject, setStatusMenuProject] = useState<number | null>(null);
  
  // 提成设置弹窗
  const [showCommissionEditor, setShowCommissionEditor] = useState(false);
  const [editingProject, setEditingProject] = useState<Project | null>(null);
  const [editingTech, setEditingTech] = useState<TechUser | null>(null);
  const [commissionAmount, setCommissionAmount] = useState('');
  const [commissionNote, setCommissionNote] = useState('');
  
  // 表格视图分组展开状态
  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({});
  
  // 删除确认弹窗
  const [deleteConfirm, setDeleteConfirm] = useState<Project | null>(null);
  const [deleting, setDeleting] = useState(false);
  
  const isManager = canManageProjects();

  // 加载看板数据
  const loadKanban = useCallback(async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (search) params.append('search', search);
      if (filterStatus) params.append('status', filterStatus);
      if (filterUserId) params.append('user_id', filterUserId);
      params.append('user_type', userType);
      if (startDate) params.append('start_date', startDate);
      if (endDate) params.append('end_date', endDate);
      
      const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=kanban&${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setStatuses(data.data.statuses || []);
        setKanban(data.data.kanban || {});
        setTotal(data.data.total || 0);
        
        // 扁平化项目列表用于表格视图
        const allProjects: Project[] = [];
        Object.values(data.data.kanban || {}).forEach((col: any) => {
          allProjects.push(...(col.projects || []));
        });
        setProjects(allProjects);
      }
    } catch (error) {
      console.error('加载看板失败:', error);
    } finally {
      setLoading(false);
    }
  }, [serverUrl, token, search, filterStatus, filterUserId, userType, startDate, endDate]);

  // 加载筛选选项
  const loadFilters = useCallback(async () => {
    if (!serverUrl || !token || !isManager) return;
    try {
      const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=filters&user_type=${userType}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setFilterUsers(data.data.users || []);
      }
    } catch (error) {
      console.error('加载筛选选项失败:', error);
    }
  }, [serverUrl, token, userType, isManager]);

  // 变更项目状态
  const changeStatus = async (projectId: number, newStatus: string) => {
    if (!serverUrl || !token) return;
    try {
      const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=change_status`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ project_id: projectId, status: newStatus }),
      });
      const data = await response.json();
      if (data.success) {
        loadKanban(); // 刷新数据
      } else {
        const errMsg = typeof data.error === 'object' ? data.error.message : data.error;
        toast({ title: '错误', description: errMsg || '状态变更失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('变更状态失败:', error);
      toast({ title: '错误', description: '状态变更失败', variant: 'destructive' });
    }
    setStatusMenuProject(null);
  };

  useEffect(() => {
    loadKanban();
  }, [loadKanban]);

  useEffect(() => {
    loadFilters();
  }, [loadFilters]);

  // 搜索防抖
  useEffect(() => {
    const timer = setTimeout(() => {
      loadKanban();
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  // 跳转到项目详情
  const goToProject = (projectId: number) => {
    navigate(`/project/${projectId}`);
  };

  // 跳转到客户详情
  const goToCustomer = (customerId: number) => {
    navigate(`/customer/${customerId}`);
  };

  // 打开提成设置弹窗
  const openCommissionEditor = (project: Project, tech: TechUser) => {
    setEditingProject(project);
    setEditingTech(tech);
    setCommissionAmount(tech.commission?.toString() || '');
    setCommissionNote(tech.commission_note || '');
    setShowCommissionEditor(true);
  };

  // 打开项目文件夹（使用设置中的同步根目录，与项目详情页一致）
  const openProjectFolder = async (project: Project) => {
    if (!rootDir) {
      toast({ title: '请先在设置中配置同步根目录', variant: 'destructive' });
      return;
    }
    
    try {
      const groupCode = project.customer_group_code || `P${project.id}`;
      const groupName = sanitizeFolderName(project.customer_group_name || project.group_name || project.customer_name || '');
      const groupFolderName = groupName ? `${groupCode}_${groupName}` : groupCode;
      const projectName = sanitizeFolderName(project.project_name || project.project_code || `项目${project.id}`);
      const folderName = `${groupFolderName}/${projectName}`;
      
      await invoke('open_project_folder', {
        workDir: rootDir,
        projectName: folderName,
        subFolder: null,
      });
      
      toast({ title: '已打开文件夹', description: folderName });
    } catch (e) {
      console.error('打开文件夹失败:', e);
      toast({ title: '打开文件夹失败', variant: 'destructive' });
    }
  };

  // 保存提成设置
  const saveCommission = async () => {
    if (!serverUrl || !token || !editingTech?.assignment_id) return;
    try {
      const res = await fetch(`${serverUrl}/api/desktop_tech_commission.php?action=set_commission`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          assignment_id: editingTech.assignment_id,
          commission_amount: parseFloat(commissionAmount) || 0,
          commission_note: commissionNote,
        }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '提成设置成功', variant: 'default' });
        setShowCommissionEditor(false);
        setEditingProject(null);
        setEditingTech(null);
        loadKanban();
      } else {
        toast({ title: data.error || '设置失败', variant: 'destructive' });
      }
    } catch (e) {
      console.error('设置提成失败:', e);
      toast({ title: '设置失败', variant: 'destructive' });
    }
  };

  // 删除项目
  const deleteProject = async () => {
    if (!serverUrl || !token || !deleteConfirm) return;
    setDeleting(true);
    try {
      const response = await fetch(`${serverUrl}/api/projects.php?id=${deleteConfirm.id}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '项目已删除', description: `${deleteConfirm.project_name} 已移至回收站，15天后自动永久删除` });
        setDeleteConfirm(null);
        loadKanban();
      } else {
        toast({ title: data.message || '删除失败', variant: 'destructive' });
      }
    } catch (e) {
      console.error('删除项目失败:', e);
      toast({ title: '删除失败', variant: 'destructive' });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 筛选栏 */}
      <div className="bg-white border-b px-6 py-3">
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-4">
            <h1 className="text-lg font-semibold text-gray-800">项目看板</h1>
            
            {/* 搜索框 */}
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input
                type="text"
                placeholder="搜索项目/客户..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9 pr-3 py-1.5 w-48 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            
            {/* 时间筛选 */}
            <TimeRangeFilter
              value={timeRange}
              onChange={(range, start, end) => {
                setTimeRange(range);
                setStartDate(start);
                setEndDate(end);
              }}
            />
            
            {/* 管理员筛选 */}
            {isManager && (
              <>
                <select
                  value={userType}
                  onChange={(e) => {
                    setUserType(e.target.value as 'tech' | 'sales');
                    setFilterUserId('');
                  }}
                  className="px-3 py-1.5 border rounded-lg text-sm"
                >
                  <option value="tech">设计师</option>
                  <option value="sales">销售人员</option>
                </select>
                <select
                  value={filterUserId}
                  onChange={(e) => setFilterUserId(e.target.value)}
                  className="px-3 py-1.5 border rounded-lg text-sm"
                >
                  <option value="">所有人员</option>
                  {filterUsers.map((u) => (
                    <option key={u.id} value={u.id}>{u.name}</option>
                  ))}
                </select>
              </>
            )}
            
            {/* 状态筛选 */}
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="px-3 py-1.5 border rounded-lg text-sm"
            >
              <option value="">所有状态</option>
              {statuses.map((s) => (
                <option key={s.key} value={s.key}>{s.label}</option>
              ))}
            </select>
            
            {/* 刷新按钮 */}
            <button
              onClick={() => loadKanban()}
              className="p-1.5 text-gray-500 hover:text-blue-500 hover:bg-blue-50 rounded transition-colors"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>
          </div>
          
          {/* 视图切换 */}
          <div className="flex border rounded overflow-hidden">
            <button
              onClick={() => setViewMode('kanban')}
              className={`px-3 py-1.5 text-sm flex items-center gap-1.5 ${
                viewMode === 'kanban' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'
              }`}
            >
              <LayoutGrid size={16} />
              看板
            </button>
            <button
              onClick={() => setViewMode('table')}
              className={`px-3 py-1.5 text-sm flex items-center gap-1.5 border-l ${
                viewMode === 'table' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'
              }`}
            >
              <List size={16} />
              表格
            </button>
            <button
              onClick={() => setViewMode('person')}
              className={`px-3 py-1.5 text-sm flex items-center gap-1.5 border-l ${
                viewMode === 'person' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'
              }`}
            >
              <Users size={16} />
              人员
            </button>
          </div>
        </div>
        
        {/* 统计 */}
        <div className="flex gap-6 mt-3 text-sm">
          <span className="text-gray-500">共 <b className="text-gray-800">{total}</b> 个项目</span>
        </div>
      </div>

      {/* 内容区 */}
      {loading ? (
        <div className="flex-1 flex items-center justify-center text-gray-400">加载中...</div>
      ) : total === 0 ? (
        <div className="flex-1 flex flex-col items-center justify-center text-gray-400">
          <Calendar size={48} className="mb-4 opacity-50" />
          <p>暂无项目</p>
        </div>
      ) : viewMode === 'person' ? (
        /* 人员视图 - 按客户分组 */
        <div className="flex-1 overflow-auto p-4 space-y-3">
          {(() => {
            // 按客户分组项目
            const customerGroups: Record<number, { id: number; name: string; projects: Project[] }> = {};
            projects.forEach(project => {
              const cid = project.customer_id;
              if (!customerGroups[cid]) {
                customerGroups[cid] = { id: cid, name: project.customer_name || '未知客户', projects: [] };
              }
              customerGroups[cid].projects.push(project);
            });
            const customers = Object.values(customerGroups).sort((a, b) => b.projects.length - a.projects.length);
            
            return customers.map((customer) => {
              const isExpanded = expandedGroups[`customer_${customer.id}`] !== false;
              return (
                <div key={customer.id} className="bg-white rounded-lg border overflow-hidden">
                  <button 
                    className="w-full px-4 py-3 flex items-center gap-3 border-b hover:bg-gray-50 transition-colors"
                    onClick={() => setExpandedGroups(prev => ({ ...prev, [`customer_${customer.id}`]: !isExpanded }))}
                  >
                    <ChevronDown 
                      className={`w-4 h-4 text-gray-500 transition-transform ${isExpanded ? '' : '-rotate-90'}`}
                    />
                    <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center font-semibold">
                      {customer.name.charAt(0)}
                    </div>
                    <span className="font-semibold text-gray-800">{customer.name}</span>
                    <span className="px-2 py-0.5 bg-gray-200 text-gray-600 rounded text-xs font-semibold">
                      {customer.projects.length} 个项目
                    </span>
                  </button>
                  {isExpanded && (
                    <table className="w-full">
                      <thead className="bg-gray-50">
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">项目</th>
                          <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">状态</th>
                          <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">负责人</th>
                          <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">更新时间</th>
                          <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">操作</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {customer.projects.map((project) => {
                          const statusConfig = statuses.find(s => s.key === project.current_status);
                          return (
                            <tr
                              key={project.id}
                              className="hover:bg-gray-50 cursor-pointer"
                              onClick={() => goToProject(project.id)}
                            >
                              <td className="px-4 py-3">
                                <div className="font-medium text-gray-800">{project.project_name}</div>
                                <div className="text-xs text-gray-400 font-mono">{project.project_code}</div>
                              </td>
                              <td className="px-4 py-3">
                                <span 
                                  className="px-2 py-1 rounded text-xs font-medium text-white"
                                  style={{ backgroundColor: statusConfig?.color || '#64748b' }}
                                >
                                  {statusConfig?.label || project.current_status}
                                </span>
                              </td>
                              <td className="px-4 py-3">
                                <div className="flex -space-x-1">
                                  {project.tech_users.slice(0, 3).map((tech) => (
                                    <div
                                      key={tech.id}
                                      className="w-6 h-6 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 text-white text-xs flex items-center justify-center border-2 border-white"
                                      title={tech.name}
                                    >
                                      {tech.name.charAt(0)}
                                    </div>
                                  ))}
                                </div>
                              </td>
                              <td className="px-4 py-3 text-sm text-gray-500">{project.update_time}</td>
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-1">
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      openProjectFolder(project);
                                    }}
                                    className="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors"
                                    title="打开文件夹"
                                  >
                                    <FolderOpen className="w-4 h-4" />
                                  </button>
                                  {isManager && (
                                    <button
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        setDeleteConfirm(project);
                                      }}
                                      className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                                      title="删除项目"
                                    >
                                      <Trash2 className="w-4 h-4" />
                                    </button>
                                  )}
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  )}
                </div>
              );
            });
          })()}
        </div>
      ) : viewMode === 'kanban' ? (
        /* 看板视图 */
        <div className="flex-1 overflow-x-auto p-6">
          <div className="flex gap-5 h-full min-w-max">
            {statuses.map((status) => {
              const column = kanban[status.key];
              const columnProjects = column?.projects || [];
              return (
                <div key={status.key} className="w-[340px] min-w-[320px] flex flex-col bg-gray-100/50 rounded-xl border-2 border-dashed border-gray-200">
                  {/* 列头 */}
                  <div className="p-4 flex items-center justify-between">
                    <h3 className="text-sm font-bold uppercase tracking-wide flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full" style={{ background: status.color }} />
                      {status.label}
                    </h3>
                    <span className="px-2 py-0.5 bg-gray-200 text-gray-600 rounded text-xs font-semibold">
                      {columnProjects.length}
                    </span>
                  </div>
                  
                  {/* 卡片列表 */}
                  <div className="flex-1 overflow-y-auto px-4 pb-4 space-y-4">
                    {columnProjects.map((project) => (
                      <div
                        key={project.id}
                        className="bg-white rounded-lg p-4 border border-gray-200 shadow-sm hover:shadow-md transition-all cursor-pointer relative group"
                        onClick={() => goToProject(project.id)}
                      >
                        {/* 卡片菜单 */}
                        <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setStatusMenuProject(statusMenuProject === project.id ? null : project.id);
                            }}
                            className="p-1 hover:bg-gray-100 rounded"
                          >
                            <MoreVertical className="w-4 h-4 text-gray-400" />
                          </button>
                          
                          {/* 状态变更菜单 */}
                          {statusMenuProject === project.id && (
                            <div className="absolute right-0 top-8 bg-white border rounded-lg shadow-lg z-10 min-w-[140px] py-1">
                              <div className="px-3 py-1.5 text-xs text-gray-400 border-b">变更状态</div>
                              {statuses.map((s) => (
                                <button
                                  key={s.key}
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    changeStatus(project.id, s.key);
                                  }}
                                  className={`w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center gap-2 ${
                                    project.current_status === s.key ? 'text-blue-600 font-medium' : 'text-gray-700'
                                  }`}
                                >
                                  <span className="w-2 h-2 rounded-full" style={{ background: s.color }} />
                                  {s.label}
                                </button>
                              ))}
                              <div className="border-t mt-1 pt-1">
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    goToCustomer(project.customer_id);
                                  }}
                                  className="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-700"
                                >
                                  <User className="w-3 h-3" />
                                  查看客户
                                </button>
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    openProjectFolder(project);
                                  }}
                                  className="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-700"
                                >
                                  <FolderOpen className="w-3 h-3" />
                                  打开文件夹
                                </button>
                                {isManager && (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      setStatusMenuProject(null);
                                      setDeleteConfirm(project);
                                    }}
                                    className="w-full px-3 py-2 text-left text-sm hover:bg-red-50 flex items-center gap-2 text-red-600"
                                  >
                                    <Trash2 className="w-3 h-3" />
                                    删除项目
                                  </button>
                                )}
                              </div>
                            </div>
                          )}
                        </div>
                        
                        <div className="text-xs text-gray-400 font-mono mb-1">{project.project_code}</div>
                        <div className="font-semibold text-gray-800 mb-1 pr-6">{project.project_name}</div>
                        <div className="text-sm text-gray-500">{project.customer_name}</div>
                        {project.customer_group_name && (
                          <div className="text-xs text-gray-400 mb-2">{project.customer_group_name}</div>
                        )}
                        
                        {/* 设计负责人 */}
                        {project.tech_users.length > 0 && (
                          <div className="space-y-1 mb-2">
                            {project.tech_users.map((tech) => (
                              <div
                                key={tech.id}
                                className="flex items-center justify-between"
                              >
                                <div className="flex items-center gap-2">
                                  <div
                                    className="w-6 h-6 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 text-white text-xs flex items-center justify-center"
                                    title={tech.name}
                                  >
                                    {tech.name.charAt(0)}
                                  </div>
                                  <span className="text-xs text-gray-600">{tech.name}</span>
                                  {tech.commission != null && (
                                    <span className="text-xs text-green-600">¥{tech.commission}</span>
                                  )}
                                </div>
                                {isManager && tech.assignment_id && (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      openCommissionEditor(project, tech);
                                    }}
                                    className="p-1 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded transition-colors"
                                    title="设置提成"
                                  >
                                    <DollarSign className="w-3.5 h-3.5" />
                                  </button>
                                )}
                              </div>
                            ))}
                          </div>
                        )}
                        
                        <div className="flex items-center justify-between pt-3 border-t border-gray-100">
                          <span className="text-xs text-gray-400">{project.update_time}</span>
                          <ChevronRight className="w-4 h-4 text-gray-300" />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      ) : (
        /* 表格视图 - 按状态分组（可展开/收起） */
        <div className="flex-1 overflow-auto p-4 space-y-3">
          {statuses.map((status) => {
            const statusProjects = projects.filter(p => p.current_status === status.key);
            if (statusProjects.length === 0) return null;
            const isExpanded = expandedGroups[status.key] !== false; // 默认展开
            return (
              <div key={status.key} className="bg-white rounded-lg border overflow-hidden">
                <button 
                  className="w-full px-4 py-3 flex items-center gap-2 border-b hover:bg-gray-50 transition-colors"
                  style={{ backgroundColor: `${status.color}10` }}
                  onClick={() => setExpandedGroups(prev => ({ ...prev, [status.key]: !isExpanded }))}
                >
                  <ChevronDown 
                    className={`w-4 h-4 text-gray-500 transition-transform ${isExpanded ? '' : '-rotate-90'}`}
                  />
                  <span 
                    className="w-3 h-3 rounded-full"
                    style={{ backgroundColor: status.color }}
                  />
                  <span className="font-semibold text-gray-800">{status.label}</span>
                  <span className="text-sm text-gray-500">({statusProjects.length})</span>
                </button>
                {isExpanded && (
                  <table className="w-full">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">项目</th>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">客户</th>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">客户群</th>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">负责人</th>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">更新时间</th>
                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">操作</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {statusProjects.map((project) => (
                        <tr
                          key={project.id}
                          className="hover:bg-gray-50 cursor-pointer"
                          onClick={() => goToProject(project.id)}
                        >
                          <td className="px-4 py-3">
                            <div className="font-medium text-gray-800">{project.project_name}</div>
                            <div className="text-xs text-gray-400 font-mono">{project.project_code}</div>
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-600">{project.customer_name}</td>
                          <td className="px-4 py-3 text-sm text-gray-600">{project.customer_group_name || '-'}</td>
                          <td className="px-4 py-3">
                            <div className="flex -space-x-1">
                              {project.tech_users.slice(0, 3).map((tech) => (
                                <div
                                  key={tech.id}
                                  className="w-6 h-6 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 text-white text-xs flex items-center justify-center border-2 border-white"
                                  title={tech.name}
                                >
                                  {tech.name.charAt(0)}
                                </div>
                              ))}
                            </div>
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500">{project.update_time}</td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <button
                                onClick={(e) => {
                                  e.stopPropagation();
                                  openProjectFolder(project);
                                }}
                                className="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors"
                                title="打开文件夹"
                              >
                                <FolderOpen className="w-4 h-4" />
                              </button>
                              <select
                                value={project.current_status}
                                onChange={(e) => {
                                  e.stopPropagation();
                                  changeStatus(project.id, e.target.value);
                                }}
                                onClick={(e) => e.stopPropagation()}
                                className="px-2 py-1 text-xs border rounded"
                              >
                                {statuses.map((s) => (
                                  <option key={s.key} value={s.key}>{s.label}</option>
                                ))}
                              </select>
                              {isManager && (
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setDeleteConfirm(project);
                                  }}
                                  className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors"
                                  title="删除项目"
                                >
                                  <Trash2 className="w-4 h-4" />
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* 提成设置弹窗 */}
      {showCommissionEditor && editingTech && editingProject && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-[400px] shadow-2xl">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">
              设置提成 - {editingTech.name}
            </h3>
            <p className="text-sm text-gray-500 mb-4">
              项目: {editingProject.project_name}
            </p>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">提成金额 (元)</label>
                <input
                  type="number"
                  value={commissionAmount}
                  onChange={(e) => setCommissionAmount(e.target.value)}
                  placeholder="请输入提成金额"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">备注</label>
                <textarea
                  value={commissionNote}
                  onChange={(e) => setCommissionNote(e.target.value)}
                  placeholder="可选，填写提成说明"
                  rows={3}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => {
                  setShowCommissionEditor(false);
                  setEditingProject(null);
                  setEditingTech(null);
                }}
                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
              >
                取消
              </button>
              <button
                onClick={saveCommission}
                className="px-4 py-2 text-sm bg-green-500 text-white rounded-lg hover:bg-green-600"
              >
                保存
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 删除确认弹窗 */}
      {deleteConfirm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-[400px] max-w-[90vw] shadow-xl">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <AlertTriangle className="w-5 h-5 text-red-600" />
              </div>
              <h3 className="text-lg font-semibold text-gray-800">确认删除项目</h3>
            </div>
            <div className="mb-6">
              <p className="text-gray-600 mb-2">
                确定要删除项目 <span className="font-semibold text-gray-800">{deleteConfirm.project_name}</span> 吗？
              </p>
              <p className="text-sm text-gray-500">
                项目编号：{deleteConfirm.project_code}
              </p>
              <div className="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p className="text-sm text-yellow-700">
                  ⚠️ 删除后项目及相关交付物将移至回收站，15天后自动永久删除。
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setDeleteConfirm(null)}
                disabled={deleting}
                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
              >
                取消
              </button>
              <button
                onClick={deleteProject}
                disabled={deleting}
                className="px-4 py-2 text-sm bg-red-500 text-white rounded-lg hover:bg-red-600 disabled:opacity-50 flex items-center gap-2"
              >
                {deleting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    删除中...
                  </>
                ) : (
                  <>
                    <Trash2 className="w-4 h-4" />
                    确认删除
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
