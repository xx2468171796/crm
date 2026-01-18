import { useState, useEffect, useRef } from 'react';
import { X, Minus, AlertTriangle, CheckCircle, ChevronRight, Clock, Pin, RefreshCw, Plus, ChevronDown, Circle, CheckCircle2, FileText } from 'lucide-react';
import { invoke } from '@tauri-apps/api/core';
import { getCurrentWindow } from '@tauri-apps/api/window';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { onEvent, requestOpenTaskDetail, requestOpenProjectDetail, EVENTS } from '@/lib/windowEvents';
import { http } from '@/lib/http';

type TaskView = 'today' | 'yesterday' | 'future' | 'help' | 'assigned';

interface Task {
  id: number;
  title: string;
  project_id: number | null;
  project_name: string | null;
  project_code: string | null;
  priority: 'high' | 'medium' | 'low';
  task_date: string;
  status: string;
  need_help: number;
  assigned_by: number | null;
  assigned_by_name: string | null;
}

interface ProjectStage {
  id: number;
  project_code: string;
  project_name: string;
  customer_name: string;
  current_status: string;
  stage_name: string;
  stage_color: string;
  remaining_days: number | null;
  deadline_status: 'normal' | 'urgent' | 'overdue';
}

const VIEW_LABELS: Record<TaskView, string> = {
  today: '今天',
  yesterday: '昨天',
  future: '未来',
  help: '协助',
  assigned: '上级',
};

const STAGES = [
  { value: '待沟通', label: '待沟通', color: '#9CA3AF' },
  { value: '需求确认', label: '需求确认', color: '#3B82F6' },
  { value: '设计中', label: '设计中', color: '#F59E0B' },
  { value: '设计核对', label: '设计核对', color: '#F97316' },
  { value: '设计完工', label: '设计完工', color: '#14b8a6' },
  { value: '设计评价', label: '设计评价', color: '#10B981' },
];

export default function FloatingWindow() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<ProjectStage[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedTask, setSelectedTask] = useState<Task | null>(null);
  const [isPinned, setIsPinned] = useState(true);
  const [currentView, setCurrentView] = useState<TaskView>('today');
  const [expandedProject, setExpandedProject] = useState<number | null>(null);
  const [showNewTask, setShowNewTask] = useState(false);
  const [newTaskTitle, setNewTaskTitle] = useState('');
  const [newTaskProjectId, setNewTaskProjectId] = useState<number | null>(null);
  const [newTaskNeedHelp, setNewTaskNeedHelp] = useState(false);
  const [newTaskDate, setNewTaskDate] = useState(new Date().toISOString().split('T')[0]);
  const [newTaskPriority, setNewTaskPriority] = useState<'low' | 'medium' | 'high'>('medium');
  const [submitting, setSubmitting] = useState(false);

  const loadTasksInFlightRef = useRef(false);
  const loadProjectsInFlightRef = useRef(false);

  useEffect(() => {
    loadTasks();
    loadProjects();
    const interval = setInterval(() => {
      loadTasks();
      loadProjects();
    }, 60000);
    return () => clearInterval(interval);
  }, [serverUrl, token]);

  useEffect(() => {
    loadTasks();
  }, [currentView]);

  const loadTasks = async () => {
    if (!serverUrl || !token) return;
    if (loadTasksInFlightRef.current) return;
    loadTasksInFlightRef.current = true;
    setLoading(true);
    try {
      const data: any = await http.get(`desktop_daily_tasks.php?view=${currentView}`);
      if (data.success) {
        const taskList = data.data.items || [];
        setTasks(taskList);
        try {
          await invoke('update_tray_task_count', { count: taskList.length });
        } catch (e) {
          console.error('更新托盘任务数量失败:', e);
        }
      }
    } catch (error) {
      console.error('加载任务失败:', error);
    } finally {
      loadTasksInFlightRef.current = false;
      setLoading(false);
    }
  };

  const loadProjects = async () => {
    if (!serverUrl || !token) return;
    if (loadProjectsInFlightRef.current) return;
    loadProjectsInFlightRef.current = true;
    try {
      const data: any = await http.get('desktop_project_stage.php');
      console.log('[FloatingWindow] 项目响应:', data);
      if (data.success) {
        // 打印前5个项目的状态信息
        const projects = data.data.projects || [];
        console.log('[FloatingWindow] 前5个项目状态:', projects.slice(0, 5).map((p: ProjectStage) => ({
          id: p.id,
          name: p.project_name,
          current_status: p.current_status,
          stage_name: p.stage_name,
        })));
        setProjects(projects);
      }
    } catch (error) {
      console.error('加载项目失败:', error);
    } finally {
      loadProjectsInFlightRef.current = false;
    }
  };

  // 勾选完成任务
  const handleToggleComplete = async (taskId: number, currentStatus: string) => {
    if (!serverUrl || !token) return;
    const newStatus = currentStatus === 'completed' ? 'pending' : 'completed';
    try {
      const data: any = await http.put('desktop_daily_tasks.php', { id: taskId, status: newStatus });
      if (data.success) {
        loadTasks();
      }
    } catch (error) {
      console.error('更新任务状态失败:', error);
    }
  };

  const handleStageChange = async (projectId: number, newStatus: string) => {
    if (!serverUrl || !token) {
      console.error('[FloatingWindow] 缺少 serverUrl 或 token');
      return;
    }
    console.log('[FloatingWindow] 切换阶段:', { projectId, newStatus, serverUrl });
    try {
      const requestBody = { project_id: projectId, status: newStatus };
      const data: any = await http.post('desktop_project_stage.php', requestBody);
      console.log('[FloatingWindow] 切换阶段响应:', data);
      if (data.success) {
        loadProjects();
        setExpandedProject(null);
      } else {
        console.error('[FloatingWindow] 切换阶段失败:', data.error);
        alert(`切换失败: ${data.error || '未知错误'}`);
      }
    } catch (error) {
      console.error('[FloatingWindow] 切换阶段异常:', error);
    }
  };

  const handleCreateTask = async () => {
    if (!serverUrl || !token || !newTaskTitle.trim()) return;
    setSubmitting(true);
    try {
      const data: any = await http.post('desktop_daily_tasks.php', {
        title: newTaskTitle.trim(),
        project_id: newTaskProjectId,
        need_help: newTaskNeedHelp ? 1 : 0,
        task_date: newTaskDate,
        priority: newTaskPriority,
      });
      if (data.success) {
        setShowNewTask(false);
        setNewTaskTitle('');
        setNewTaskProjectId(null);
        setNewTaskNeedHelp(false);
        setNewTaskDate(new Date().toISOString().split('T')[0]);
        setNewTaskPriority('medium');
        loadTasks();
      }
    } catch (error) {
      console.error('创建任务失败:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const handleClose = async () => {
    console.log('[FloatingWindow] handleClose 被调用');
    try {
      const win = getCurrentWindow();
      console.log('[FloatingWindow] 正在隐藏窗口...');
      await win.hide();
      console.log('[FloatingWindow] 隐藏成功');
    } catch (e) {
      console.error('[FloatingWindow] 关闭窗口失败:', e);
    }
  };

  const handleMinimize = async () => {
    console.log('[FloatingWindow] handleMinimize 被调用');
    try {
      const win = getCurrentWindow();
      console.log('[FloatingWindow] 正在最小化窗口...');
      await win.minimize();
      console.log('[FloatingWindow] 最小化成功');
    } catch (e) {
      console.error('[FloatingWindow] 最小化窗口失败:', e);
    }
  };

  // 切换置顶
  const handleTogglePin = async () => {
    console.log('[FloatingWindow] handleTogglePin 被调用, isPinned:', isPinned);
    try {
      const win = getCurrentWindow();
      const newState = !isPinned;
      console.log('[FloatingWindow] 正在设置置顶:', newState);
      await win.setAlwaysOnTop(newState);
      setIsPinned(newState);
      console.log('[FloatingWindow] 设置置顶成功');
    } catch (e) {
      console.error('[FloatingWindow] 切换置顶失败:', e);
    }
  };

  // 初始化置顶状态
  useEffect(() => {
    console.log('[FloatingWindow] 组件已挂载');
    const initPinState = async () => {
      try {
        const win = getCurrentWindow();
        console.log('[FloatingWindow] 正在获取置顶状态...');
        const pinned = await win.isAlwaysOnTop();
        console.log('[FloatingWindow] 置顶状态:', pinned);
        setIsPinned(pinned);
      } catch (e) {
        console.error('[FloatingWindow] 获取置顶状态失败:', e);
      }
    };
    initPinState();
  }, []);

  // 监听任务更新事件
  useEffect(() => {
    let unlisten: (() => void) | null = null;
    
    const setupListener = async () => {
      unlisten = await onEvent<{ tasks: Task[] }>(EVENTS.TASKS_UPDATED, (payload) => {
        setTasks(payload.tasks);
      });
    };
    
    setupListener();
    return () => {
      if (unlisten) unlisten();
    };
  }, []);

  // 点击任务时发送事件到主窗口
  const handleTaskClick = async (task: Task) => {
    setSelectedTask(selectedTask?.id === task.id ? null : task);
    // 通知主窗口打开任务详情
    await requestOpenTaskDetail(task.id);
  };

  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'high': return 'text-red-500 bg-red-50';
      case 'medium': return 'text-yellow-600 bg-yellow-50';
      default: return 'text-blue-500 bg-blue-50';
    }
  };

  const getPriorityIcon = (priority: string) => {
    switch (priority) {
      case 'high': return <AlertTriangle className="w-3 h-3" />;
      default: return <Clock className="w-3 h-3" />;
    }
  };

  return (
    <div className="w-full h-full bg-white/95 backdrop-blur-sm rounded-xl shadow-2xl border border-gray-200 overflow-hidden flex flex-col">
      {/* 标题栏 - 可拖拽 */}
      <div 
        data-tauri-drag-region
        className="h-8 bg-gradient-to-r from-blue-500 to-blue-600 flex items-center select-none cursor-move"
      >
        {/* 置顶按钮 */}
        <button
          onClick={handleTogglePin}
          className="w-8 h-8 flex items-center justify-center hover:bg-white/20 transition-colors relative z-10"
          title={isPinned ? '取消置顶' : '置顶'}
        >
          <Pin className={`w-3.5 h-3.5 text-white transition-transform ${isPinned ? 'rotate-[-45deg]' : ''}`} />
        </button>

        {/* 标题区域 */}
        <div className="flex-1 text-center text-xs font-medium text-white">
          任务悬浮窗
        </div>

        {/* 窗口控制按钮 */}
        <div className="flex relative z-10">
          <button
            onClick={handleMinimize}
            className="w-8 h-8 flex items-center justify-center hover:bg-white/20 text-white/80 hover:text-white transition-colors"
            title="最小化"
          >
            <Minus className="w-3.5 h-3.5" />
          </button>
          <button
            onClick={handleClose}
            className="w-8 h-8 flex items-center justify-center hover:bg-red-500 text-white/80 hover:text-white transition-colors"
            title="隐藏"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      {/* 任务视图切换 */}
      <div className="px-2 py-2 bg-gray-50 border-b">
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-2">
            <span className="text-xs text-gray-500">{VIEW_LABELS[currentView]}任务</span>
            <span className="text-sm font-semibold text-blue-600">{tasks.length}</span>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => setShowNewTask(true)}
              className="p-1 rounded hover:bg-blue-100 text-blue-500 transition-colors"
              title="新建任务"
            >
              <Plus className="w-3.5 h-3.5" />
            </button>
            <button
              onClick={() => { loadTasks(); loadProjects(); }}
              className="p-1 rounded hover:bg-gray-200 text-gray-500 transition-colors"
              title="刷新"
            >
              <RefreshCw className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
        {/* 五个视图切换 */}
        <div className="flex gap-0.5">
          {(Object.keys(VIEW_LABELS) as TaskView[]).map((view) => (
            <button
              key={view}
              onClick={() => setCurrentView(view)}
              className={`flex-1 py-1 text-[10px] rounded transition-colors ${
                currentView === view
                  ? 'bg-blue-500 text-white'
                  : 'bg-white text-gray-600 hover:bg-gray-100'
              }`}
            >
              {VIEW_LABELS[view]}
            </button>
          ))}
        </div>
      </div>

      {/* 任务列表 */}
      <div className="flex-1 overflow-y-auto">
        {loading ? (
          <div className="flex items-center justify-center h-32 text-gray-400 text-sm">
            加载中...
          </div>
        ) : tasks.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-32 text-gray-400">
            <CheckCircle className="w-8 h-8 mb-2 opacity-50" />
            <span className="text-sm">今日无任务</span>
          </div>
        ) : (
          <div className="p-2 space-y-2">
            {tasks.map((task) => (
              <div
                key={task.id}
                className={`p-3 rounded-lg border transition-all ${
                  selectedTask?.id === task.id
                    ? 'bg-blue-50 border-blue-200'
                    : task.status === 'completed' ? 'bg-gray-50 border-gray-100' : 'bg-white border-gray-100 hover:border-gray-200 hover:shadow-sm'
                }`}
              >
                <div className="flex items-start gap-2">
                  {/* 完成复选框 */}
                  <button
                    onClick={(e) => { e.stopPropagation(); handleToggleComplete(task.id, task.status); }}
                    className="mt-0.5 flex-shrink-0"
                    title={task.status === 'completed' ? '标记未完成' : '标记完成'}
                  >
                    {task.status === 'completed' ? (
                      <CheckCircle2 className="w-4 h-4 text-green-500" />
                    ) : (
                      <Circle className="w-4 h-4 text-gray-300 hover:text-green-400" />
                    )}
                  </button>
                  <div className="flex-1 min-w-0 cursor-pointer" onClick={() => handleTaskClick(task)}>
                    <div className="flex items-center gap-1.5 mb-1">
                      <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] ${getPriorityColor(task.priority)}`}>
                        {getPriorityIcon(task.priority)}
                        {task.priority === 'high' ? '紧急' : task.priority === 'medium' ? '普通' : '低'}
                      </span>
                      {task.need_help === 1 && (
                        <span className="px-1.5 py-0.5 rounded text-[10px] bg-orange-50 text-orange-500">需协助</span>
                      )}
                      {task.assigned_by && (
                        <span className="px-1.5 py-0.5 rounded text-[10px] bg-purple-50 text-purple-500">上级分配</span>
                      )}
                    </div>
                    <p className="text-xs font-medium text-gray-800 truncate">{task.title}</p>
                    <p className="text-[10px] text-gray-500 truncate">
                      {task.project_name || '未关联项目'} · {task.task_date}
                    </p>
                  </div>
                  <ChevronRight className={`w-4 h-4 text-gray-400 transition-transform ${
                    selectedTask?.id === task.id ? 'rotate-90' : ''
                  }`} />
                </div>
                
                {/* 任务详情 */}
                {selectedTask?.id === task.id && (
                  <div className="mt-2 pt-2 border-t border-gray-100 text-[10px] text-gray-600 space-y-1">
                    <div className="flex justify-between">
                      <span>截止日期</span>
                      <span className="font-medium">{task.task_date}</span>
                    </div>
                    <div className="flex justify-between">
                      <span>状态</span>
                      <span className="font-medium">{task.status || '进行中'}</span>
                    </div>
                    {task.assigned_by_name && (
                      <div className="flex justify-between">
                        <span>分配人</span>
                        <span className="font-medium">{task.assigned_by_name}</span>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* 新建任务弹窗 */}
      {showNewTask && (
        <div className="absolute inset-0 bg-black/50 flex items-center justify-center z-20">
          <div className="bg-white rounded-lg shadow-xl w-[280px] p-3">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium">新建任务</span>
              <button onClick={() => setShowNewTask(false)} className="text-gray-400 hover:text-gray-600" title="关闭">
                <X className="w-4 h-4" />
              </button>
            </div>
            <input
              type="text"
              value={newTaskTitle}
              onChange={(e) => setNewTaskTitle(e.target.value)}
              placeholder="任务标题"
              className="w-full px-2 py-1.5 text-xs border rounded mb-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
              autoFocus
            />
            <select
              value={newTaskProjectId || ''}
              onChange={(e) => setNewTaskProjectId(e.target.value ? Number(e.target.value) : null)}
              className="w-full px-2 py-1.5 text-xs border rounded mb-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
              title="选择关联项目"
            >
              <option value="">不关联项目</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>{p.project_name}</option>
              ))}
            </select>
            <div className="flex gap-2 mb-2">
              <input
                type="date"
                value={newTaskDate}
                onChange={(e) => setNewTaskDate(e.target.value)}
                className="flex-1 px-2 py-1.5 text-xs border rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                title="截止日期"
              />
              <select
                value={newTaskPriority}
                onChange={(e) => setNewTaskPriority(e.target.value as 'low' | 'medium' | 'high')}
                className="px-2 py-1.5 text-xs border rounded focus:outline-none focus:ring-1 focus:ring-blue-500"
                title="优先级"
              >
                <option value="low">低优先级</option>
                <option value="medium">普通</option>
                <option value="high">紧急</option>
              </select>
            </div>
            <label className="flex items-center gap-2 text-xs text-gray-600 mb-3">
              <input
                type="checkbox"
                checked={newTaskNeedHelp}
                onChange={(e) => setNewTaskNeedHelp(e.target.checked)}
                className="rounded"
              />
              需要协助
            </label>
            <div className="flex gap-2">
              <button
                onClick={() => setShowNewTask(false)}
                className="flex-1 py-1.5 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200"
              >
                取消
              </button>
              <button
                onClick={handleCreateTask}
                disabled={!newTaskTitle.trim() || submitting}
                className="flex-1 py-1.5 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
              >
                {submitting ? '创建中...' : '创建'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 我的项目阶段 */}
      {projects.length > 0 && (
        <div className="border-t bg-gray-50 p-2 max-h-40 overflow-y-auto">
          <div className="text-xs text-gray-500 mb-2">我的项目</div>
          <div className="space-y-1">
            {projects.slice(0, 5).map((project) => (
              <div key={project.id} className="bg-white rounded border p-2">
                <div className="flex items-center justify-between">
                  <div 
                    className="flex-1 min-w-0 cursor-pointer hover:bg-gray-50 rounded -m-1 p-1"
                    onClick={() => requestOpenProjectDetail(project.id)}
                  >
                    <p className="text-xs font-medium text-gray-800 truncate">{project.project_name}</p>
                    <p className="text-[10px] text-gray-500 truncate">{project.customer_name}</p>
                  </div>
                  {/* 表单入口 */}
                  <button
                    onClick={(e) => { e.stopPropagation(); window.location.href = `/forms?projectId=${project.id}`; }}
                    className="p-1 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded"
                    title="查看表单"
                  >
                    <FileText className="w-3.5 h-3.5" />
                  </button>
                  <div className="relative">
                    <button
                      onClick={() => setExpandedProject(expandedProject === project.id ? null : project.id)}
                      className="flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium"
                      style={{ backgroundColor: project.stage_color + '20', color: project.stage_color }}
                    >
                      {project.stage_name}
                      <ChevronDown className={`w-3 h-3 transition-transform ${expandedProject === project.id ? 'rotate-180' : ''}`} />
                    </button>
                    {/* 阶段下拉选择器 */}
                    {expandedProject === project.id && (
                      <div className="absolute right-0 top-full mt-1 bg-white border rounded shadow-lg z-10 min-w-[100px]">
                        {STAGES.map((stage) => (
                          <button
                            key={stage.value}
                            onClick={() => handleStageChange(project.id, stage.value)}
                            className={`w-full text-left px-2 py-1 text-[10px] hover:bg-gray-50 ${
                              project.current_status === stage.value ? 'font-bold' : ''
                            }`}
                            style={{ color: stage.color }}
                          >
                            {stage.label}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
                {/* 到期提醒 */}
                {project.remaining_days !== null && (
                  <div className={`mt-1 text-[10px] ${
                    project.deadline_status === 'overdue' ? 'text-red-500' :
                    project.deadline_status === 'urgent' ? 'text-orange-500' : 'text-gray-400'
                  }`}>
                    {project.deadline_status === 'overdue' ? `超期 ${Math.abs(project.remaining_days)} 天` :
                     project.deadline_status === 'urgent' ? `剩余 ${project.remaining_days} 天` :
                     `剩余 ${project.remaining_days} 天`}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
