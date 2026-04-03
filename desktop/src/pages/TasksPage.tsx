import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Clock, ChevronRight, RefreshCw, User, Calendar, X, Search } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useToast } from '@/hooks/use-toast';
import { isManager } from '@/lib/utils';
import ProjectSelector from '@/components/relational-selector/ProjectSelector';
import { ProjectItem } from '@/components/relational-selector/types';
import { http } from '@/lib/http';

interface Task {
  id: number;
  title: string;
  description: string;
  status: 'pending' | 'in_progress' | 'completed';
  priority: 'high' | 'medium' | 'low';
  deadline: string | null;
  project_id: number | null;
  project_name: string | null;
  project_code: string | null;
  assignee_id: number | null;
  assignee_name: string | null;
  creator_name: string | null;
  create_time: string;
  update_time: string;
}

interface Stats {
  total: number;
  pending: number;
  in_progress: number;
  completed: number;
}

interface ProjectOption {
  id: number;
  project_code: string;
  project_name: string;
}

interface UserOption {
  id: number;
  name: string;
}

type FilterStatus = '' | 'pending' | 'in_progress' | 'completed';

export default function TasksPage() {
  const navigate = useNavigate();
  const { token, user } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { toast } = useToast();
  
  const [loading, setLoading] = useState(true);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [stats, setStats] = useState<Stats>({ total: 0, pending: 0, in_progress: 0, completed: 0 });
  const [filterStatus, setFilterStatus] = useState<FilterStatus>('');
  const [filterUserId, setFilterUserId] = useState('');
  
  // 时间筛选
  const [dateRange, setDateRange] = useState('all');
  const [customStartDate, setCustomStartDate] = useState('');
  const [customEndDate, setCustomEndDate] = useState('');
  
  // 排序
  const [sortBy, setSortBy] = useState<'priority' | 'deadline' | 'create_time' | 'status'>('priority');
  
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [, setProjects] = useState<ProjectOption[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  
  // 新任务表单
  const [newTask, setNewTask] = useState({
    title: '',
    description: '',
    priority: 'medium' as 'high' | 'medium' | 'low',
    deadline: '',
    project_id: '',
    assignee_id: '',
  });
  const [showProjectSelector, setShowProjectSelector] = useState(false);
  const [selectedProject, setSelectedProject] = useState<ProjectItem | null>(null);
  
  const isManagerRole = isManager(user?.role);

  // 加载任务列表
  const loadTasks = useCallback(async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    
    try {
      const params = new URLSearchParams();
      if (filterStatus) params.append('status', filterStatus);
      if (filterUserId) params.append('user_id', filterUserId);
      if (dateRange && dateRange !== 'all') params.append('date_filter', dateRange);
      if (dateRange === 'custom' && customStartDate) params.append('start_date', customStartDate);
      if (dateRange === 'custom' && customEndDate) params.append('end_date', customEndDate);
      if (sortBy) params.append('sort', sortBy);
      
      const data: any = await http.get(`desktop_tasks_manage.php?action=my_tasks&${params.toString()}`);
      
      if (data.success) {
        setTasks(data.data.tasks || []);
        setStats(data.data.stats || { total: 0, pending: 0, in_progress: 0, completed: 0 });
      } else {
        toast({ title: '加载失败', description: data.error || '获取任务列表失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('加载任务失败:', error);
      toast({ title: '加载失败', description: '网络错误', variant: 'destructive' });
    } finally {
      setLoading(false);
    }
  }, [serverUrl, token, filterStatus, filterUserId, dateRange, customStartDate, customEndDate, sortBy]);

  // 加载项目和用户列表
  const loadOptions = useCallback(async () => {
    if (!serverUrl || !token) return;
    
    try {
      const [projectsData, usersData] = await Promise.all([
        http.get<any>('desktop_tasks_manage.php?action=projects'),
        http.get<any>('desktop_tasks_manage.php?action=users'),
      ]);
      if (projectsData.success) setProjects(projectsData.data || []);
      if (usersData.success) setUsers(usersData.data || []);
    } catch (error) {
      console.error('加载选项失败:', error);
    }
  }, [serverUrl, token]);

  // 更新任务状态
  const updateTaskStatus = async (taskId: number, newStatus: string) => {
    if (!serverUrl || !token) return;
    
    try {
      const data: any = await http.post('desktop_tasks_manage.php?action=update_status', { task_id: taskId, status: newStatus });
      if (data.success) {
        loadTasks();
        toast({ title: '更新成功', variant: 'default' });
      } else {
        toast({ title: '更新失败', description: data.error || '未知错误', variant: 'destructive' });
      }
    } catch (error) {
      console.error('更新任务状态失败:', error);
      toast({ title: '更新失败', description: '网络错误', variant: 'destructive' });
    }
  };

  // 创建任务
  const createTask = async () => {
    if (!serverUrl || !token) return;
    if (!newTask.title.trim()) {
      toast({ title: '提示', description: '请输入任务标题', variant: 'destructive' });
      return;
    }
    
    try {
      const data: any = await http.post('desktop_tasks_manage.php?action=create', {
        title: newTask.title,
        description: newTask.description,
        priority: newTask.priority,
        deadline: newTask.deadline || null,
        project_id: newTask.project_id ? parseInt(newTask.project_id) : null,
        assignee_id: newTask.assignee_id ? parseInt(newTask.assignee_id) : null,
      });
      if (data.success) {
        setShowCreateModal(false);
        setNewTask({ title: '', description: '', priority: 'medium', deadline: '', project_id: '', assignee_id: '' });
        loadTasks();
        toast({ title: '创建成功', variant: 'default' });
      } else {
        toast({ title: '创建失败', description: data.error || '未知错误', variant: 'destructive' });
      }
    } catch (error) {
      console.error('创建任务失败:', error);
      toast({ title: '创建失败', description: '网络错误', variant: 'destructive' });
    }
  };

  useEffect(() => {
    loadTasks();
    loadOptions();
  }, [loadTasks, loadOptions]);

  // 获取优先级样式
  const getPriorityStyle = (priority: string) => {
    switch (priority) {
      case 'high': return 'bg-red-100 text-red-700';
      case 'medium': return 'bg-yellow-100 text-yellow-700';
      case 'low': return 'bg-blue-100 text-blue-700';
      default: return 'bg-gray-100 text-gray-700';
    }
  };

  // 获取状态样式
  const getStatusStyle = (status: string) => {
    switch (status) {
      case 'pending': return 'bg-gray-100 text-gray-700';
      case 'in_progress': return 'bg-blue-100 text-blue-700';
      case 'completed': return 'bg-green-100 text-green-700';
      default: return 'bg-gray-100 text-gray-700';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'pending': return '待处理';
      case 'in_progress': return '进行中';
      case 'completed': return '已完成';
      default: return status;
    }
  };

  const getPriorityText = (priority: string) => {
    switch (priority) {
      case 'high': return '紧急';
      case 'medium': return '普通';
      case 'low': return '低';
      default: return priority;
    }
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <h1 className="text-lg font-semibold text-gray-800">任务管理</h1>
            
            {/* 状态筛选 */}
            <div className="flex gap-2">
              {[
                { key: '', label: '全部', count: stats.total },
                { key: 'pending', label: '待处理', count: stats.pending },
                { key: 'in_progress', label: '进行中', count: stats.in_progress },
                { key: 'completed', label: '已完成', count: stats.completed },
              ].map((item) => (
                <button
                  key={item.key}
                  onClick={() => setFilterStatus(item.key as FilterStatus)}
                  className={`px-3 py-1.5 text-sm rounded-lg transition-colors ${
                    filterStatus === item.key
                      ? 'bg-blue-500 text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  {item.label} ({item.count})
                </button>
              ))}
            </div>
            
            {/* 时间筛选 */}
            <select
              value={dateRange}
              onChange={(e) => setDateRange(e.target.value)}
              className="px-3 py-1.5 border rounded-lg text-sm"
              title="时间筛选"
            >
              <option value="all">全部时间</option>
              <option value="today">今天</option>
              <option value="week">本周</option>
              <option value="month">本月</option>
              <option value="last_month">上月</option>
              <option value="custom">自定义</option>
            </select>
            
            {dateRange === 'custom' && (
              <>
                <input
                  type="date"
                  value={customStartDate}
                  onChange={(e) => setCustomStartDate(e.target.value)}
                  className="px-2 py-1.5 border rounded-lg text-sm"
                  title="开始日期"
                />
                <span className="text-gray-400">-</span>
                <input
                  type="date"
                  value={customEndDate}
                  onChange={(e) => setCustomEndDate(e.target.value)}
                  className="px-2 py-1.5 border rounded-lg text-sm"
                  title="结束日期"
                />
              </>
            )}
            
            {/* 管理员：人员筛选 */}
            {isManagerRole && users.length > 0 && (
              <select
                value={filterUserId}
                onChange={(e) => setFilterUserId(e.target.value)}
                className="px-3 py-1.5 border rounded-lg text-sm"
                title="人员筛选"
              >
                <option value="">所有人员</option>
                {users.map((u) => (
                  <option key={u.id} value={u.id}>{u.name}</option>
                ))}
              </select>
            )}
            
            {/* 排序选择器 */}
            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as typeof sortBy)}
              className="px-3 py-1.5 border rounded-lg text-sm"
              title="排序方式"
            >
              <option value="priority">按优先级</option>
              <option value="deadline">按截止日期</option>
              <option value="create_time">按创建时间</option>
              <option value="status">按状态</option>
            </select>
            
            <button
              onClick={() => loadTasks()}
              className="p-1.5 text-gray-500 hover:text-blue-500 hover:bg-blue-50 rounded transition-colors"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>
          </div>
          
          <button
            onClick={() => setShowCreateModal(true)}
            className="flex items-center gap-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors"
          >
            <Plus className="w-4 h-4" />
            新建任务
          </button>
        </div>
      </div>

      {/* 任务列表 */}
      <div className="flex-1 overflow-auto p-4">
        {loading ? (
          <div className="flex items-center justify-center h-32 text-gray-400">加载中...</div>
        ) : tasks.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <Clock className="w-12 h-12 mb-4 opacity-50" />
            <p>暂无任务</p>
            <button
              onClick={() => setShowCreateModal(true)}
              className="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"
            >
              创建第一个任务
            </button>
          </div>
        ) : (
          <div className="space-y-3">
            {tasks.map((task) => (
              <div
                key={task.id}
                className="bg-white rounded-xl p-4 border hover:shadow-md transition-all"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-2">
                      <span className={`px-2 py-0.5 text-xs rounded ${getPriorityStyle(task.priority)}`}>
                        {getPriorityText(task.priority)}
                      </span>
                      <span className={`px-2 py-0.5 text-xs rounded ${getStatusStyle(task.status)}`}>
                        {getStatusText(task.status)}
                      </span>
                    </div>
                    
                    <h3 className="font-semibold text-gray-800 mb-1">{task.title}</h3>
                    
                    {task.description && (
                      <p className="text-sm text-gray-500 mb-2 line-clamp-2">{task.description}</p>
                    )}
                    
                    <div className="flex items-center gap-4 text-xs text-gray-400">
                      {task.project_name && (
                        <span
                          className="flex items-center gap-1 cursor-pointer hover:text-blue-500"
                          onClick={() => task.project_id && navigate(`/project/${task.project_id}`)}
                        >
                          <ChevronRight className="w-3 h-3" />
                          {task.project_name}
                        </span>
                      )}
                      {task.assignee_name && (
                        <span className="flex items-center gap-1">
                          <User className="w-3 h-3" />
                          {task.assignee_name}
                        </span>
                      )}
                      {task.deadline && (
                        <span className="flex items-center gap-1">
                          <Calendar className="w-3 h-3" />
                          {task.deadline}
                        </span>
                      )}
                      <span>创建于 {task.create_time}</span>
                    </div>
                  </div>
                  
                  {/* 状态切换 */}
                  <div className="flex flex-col gap-1">
                    {task.status !== 'completed' && (
                      <>
                        {task.status === 'pending' && (
                          <button
                            onClick={() => updateTaskStatus(task.id, 'in_progress')}
                            className="px-3 py-1.5 text-xs bg-blue-500 text-white rounded hover:bg-blue-600"
                          >
                            开始
                          </button>
                        )}
                        {task.status === 'in_progress' && (
                          <button
                            onClick={() => updateTaskStatus(task.id, 'completed')}
                            className="px-3 py-1.5 text-xs bg-green-500 text-white rounded hover:bg-green-600"
                          >
                            完成
                          </button>
                        )}
                        <button
                          onClick={() => updateTaskStatus(task.id, 'pending')}
                          className="px-3 py-1.5 text-xs text-gray-500 hover:text-gray-700"
                        >
                          重置
                        </button>
                      </>
                    )}
                    {task.status === 'completed' && (
                      <button
                        onClick={() => updateTaskStatus(task.id, 'pending')}
                        className="px-3 py-1.5 text-xs text-gray-500 hover:text-gray-700"
                      >
                        重新开始
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* 创建任务弹窗 */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-full max-w-lg mx-4 shadow-2xl">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">新建任务</h2>
              <button onClick={() => setShowCreateModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X className="w-5 h-5" />
              </button>
            </div>
            
            <div className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">任务标题 *</label>
                <input
                  type="text"
                  value={newTask.title}
                  onChange={(e) => setNewTask({ ...newTask, title: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  placeholder="输入任务标题"
                />
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">描述</label>
                <textarea
                  value={newTask.description}
                  onChange={(e) => setNewTask({ ...newTask, description: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  rows={3}
                  placeholder="输入任务描述"
                />
              </div>
              
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">优先级</label>
                  <select
                    value={newTask.priority}
                    onChange={(e) => setNewTask({ ...newTask, priority: e.target.value as 'high' | 'medium' | 'low' })}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="high">紧急</option>
                    <option value="medium">普通</option>
                    <option value="low">低</option>
                  </select>
                </div>
                
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">截止日期</label>
                  <input
                    type="date"
                    value={newTask.deadline}
                    onChange={(e) => setNewTask({ ...newTask, deadline: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">关联项目</label>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setShowProjectSelector(true)}
                    className="flex-1 px-3 py-2 border rounded-lg text-left hover:bg-gray-50 flex items-center justify-between"
                  >
                    {selectedProject ? (
                      <span className="text-gray-800">{selectedProject.project_name} ({selectedProject.project_code})</span>
                    ) : (
                      <span className="text-gray-400">点击选择项目</span>
                    )}
                    <Search className="w-4 h-4 text-gray-400" />
                  </button>
                  {selectedProject && (
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedProject(null);
                        setNewTask({ ...newTask, project_id: '' });
                      }}
                      className="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded"
                    >
                      <X className="w-4 h-4" />
                    </button>
                  )}
                </div>
              </div>
              
              {/* 项目选择器弹窗 */}
              <ProjectSelector
                open={showProjectSelector}
                onClose={() => setShowProjectSelector(false)}
                mode="single"
                value={selectedProject ? [selectedProject.id] : []}
                onChange={(_ids, items) => {
                  if (items.length > 0) {
                    setSelectedProject(items[0]);
                    setNewTask({ ...newTask, project_id: String(items[0].id) });
                  }
                  setShowProjectSelector(false);
                }}
              />
              
              {isManagerRole && users.length > 0 && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">分配给</label>
                  <select
                    value={newTask.assignee_id}
                    onChange={(e) => setNewTask({ ...newTask, assignee_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg"
                  >
                    <option value="">分配给自己</option>
                    {users.map((u) => (
                      <option key={u.id} value={u.id}>{u.name}</option>
                    ))}
                  </select>
                </div>
              )}
            </div>
            
            <div className="flex justify-end gap-3 p-4 border-t">
              <button
                onClick={() => setShowCreateModal(false)}
                className="px-4 py-2 text-gray-600 hover:text-gray-800"
              >
                取消
              </button>
              <button
                onClick={createTask}
                className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"
              >
                创建
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
