import { useState, useEffect, useRef } from 'react';
import { 
  X, Minus, Pin, RefreshCw, Plus, Search, Bell, Settings,
  FolderKanban, ListTodo, MessageSquare, FileText, UserPlus,
  ChevronRight, ChevronDown, Clock, AlertTriangle, CheckCircle2, Circle,
  Layers, ArrowUpDown, Check, FolderOpen
} from 'lucide-react';
import { getCurrentWindow } from '@tauri-apps/api/window';
import { invoke } from '@tauri-apps/api/core';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { requestOpenTaskDetail, requestOpenProjectDetail, requestOpenFormDetail, onEvent, EVENTS, requestSettings, type SettingsSyncPayload } from '@/lib/windowEvents';
import ToastContainer from '@/components/Toast';
import PopupNotificationContainer from '@/components/PopupNotification';
import { toast, popup } from '@/stores/toast';
import FloatingProjectSelector from '@/components/FloatingProjectSelector';
import { http } from '@/lib/http';

type SidebarTab = 'project' | 'task' | 'message' | 'settings';
type TaskFilter = 'all' | 'today' | 'yesterday' | 'future' | 'help' | 'assigned';

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
  create_time: string | null;
}

function sanitizeFolderName(name: string): string {
  return (name || '').replace(/[\/\\:*?"<>|]/g, '_');
}

interface Project {
  id: number;
  project_code: string;
  project_name: string;
  customer_name: string;
  customer_group?: string | null;
  current_status: string;
  stage_name: string;
  stage_color: string;
  remaining_days: number | null;
  total_days?: number | null;
  deadline_status?: string;
  group_name?: string;
  group_code?: string;
}

interface Message {
  id: string;
  title: string;
  content: string;
  type: 'project' | 'task' | 'system' | 'form';
  form_type?: 'requirement' | 'evaluation';
  status?: string;
  time?: string;
  full_time?: string;
  is_read: boolean;
  data?: {
    form_id?: number;
    project_code?: string;
  };
}

const SIDEBAR_TABS = [
  { id: 'project' as SidebarTab, icon: FolderKanban, label: '项目' },
  { id: 'task' as SidebarTab, icon: ListTodo, label: '任务' },
];

const TASK_FILTERS = [
  { id: 'all' as TaskFilter, label: '全部' },
  { id: 'today' as TaskFilter, label: '今日' },
  { id: 'yesterday' as TaskFilter, label: '昨日' },
  { id: 'future' as TaskFilter, label: '未来' },
  { id: 'help' as TaskFilter, label: '协助' },
  { id: 'assigned' as TaskFilter, label: '上级' },
];

// 配置常量
const DEADLINE_CHECK_INTERVAL_MS = 3600000; // 截止日期检查间隔：1小时
const MAX_TOAST_ITEMS = 3; // 最多显示的 toast 数量

export default function FloatingWindowV2() {
  const { token, user } = useAuthStore();
  const { serverUrl, setServerUrl, rootDir, setRootDir } = useSettingsStore();
  
  // 监听主窗口的设置同步事件
  useEffect(() => {
    let unlisten: (() => void) | null = null;
    
    const setupSettingsListener = async () => {
      unlisten = await onEvent<SettingsSyncPayload>(EVENTS.SETTINGS_SYNC, (payload) => {
        console.log('[FloatingWindow] 收到设置同步:', payload);
        // 使用 getState() 获取最新值，避免 stale closure
        const currentServerUrl = useSettingsStore.getState().serverUrl;
        const currentRootDir = useSettingsStore.getState().rootDir;
        if (payload.serverUrl && payload.serverUrl !== currentServerUrl) {
          setServerUrl(payload.serverUrl);
        }
        if (payload.rootDir && payload.rootDir !== currentRootDir) {
          setRootDir(payload.rootDir);
        }
      });
      
      // 启动时请求主窗口发送设置
      console.log('[FloatingWindow] 请求主窗口发送设置');
      await requestSettings();
    };
    
    setupSettingsListener();
    return () => {
      if (unlisten) unlisten();
    };
  }, []);
  
  // 调试日志
  console.log('[FloatingWindow] 认证状态:', { 
    hasToken: !!token, 
    hasUser: !!user, 
    serverUrl,
    localStorage_remember: localStorage.getItem('remember_login'),
    localStorage_auth: localStorage.getItem('auth-storage')?.substring(0, 100)
  });
  
  // UI 状态
  const [activeTab, setActiveTab] = useState<SidebarTab>('task');
  const [taskFilter, setTaskFilter] = useState<TaskFilter>('all');
  const [isPinned, setIsPinned] = useState(true);
  const [loading, setLoading] = useState(false);
  const [showNewTask, setShowNewTask] = useState(false);
  const [showSearch, setShowSearch] = useState(false);
  const [searchText, setSearchText] = useState('');
  const [sortBy, setSortBy] = useState<'status' | 'time'>('status');
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());
  const [groupEnabled, setGroupEnabled] = useState(true);
  
  // 数据状态
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [messages, setMessages] = useState<Message[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  
  // 消息筛选
  type MessageFilter = 'today' | 'yesterday' | 'all' | 'custom';
  const [messageFilter, setMessageFilter] = useState<MessageFilter>('all');
  // 消息类型筛选
  type MessageTypeFilter = 'all' | 'form' | 'task' | 'project';
  const [messageTypeFilter, setMessageTypeFilter] = useState<MessageTypeFilter>('all');
  // 自定义日期范围
  const [customStartDate, setCustomStartDate] = useState<string>('');
  const [customEndDate, setCustomEndDate] = useState<string>('');
  const [showDatePicker, setShowDatePicker] = useState(false);
  // 消息选择模式（用于批量删除）
  const [messageSelectMode, setMessageSelectMode] = useState(false);
  const [selectedMessages, setSelectedMessages] = useState<Set<string>>(new Set());
  
  // 新建任务
  const [newTaskTitle, setNewTaskTitle] = useState('');
  const [newTaskProjectId, setNewTaskProjectId] = useState<number | null>(null);
  const [newTaskProjectName, setNewTaskProjectName] = useState<string>('');
  const [newTaskDate, setNewTaskDate] = useState(new Date().toLocaleDateString('sv-SE'));
  const [newTaskPriority, setNewTaskPriority] = useState<'high' | 'medium' | 'low'>('medium');
  const [newTaskNeedHelp, setNewTaskNeedHelp] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [showProjectSelector, setShowProjectSelector] = useState(false);
  
  // 分配任务
  const [showAssignTask, setShowAssignTask] = useState(false);
  const [assignTaskTitle, setAssignTaskTitle] = useState('');
  const [assignTaskUserId, setAssignTaskUserId] = useState<number | null>(null);
  const [assignTaskDate, setAssignTaskDate] = useState(new Date().toISOString().split('T')[0]);
  const [teamMembers, setTeamMembers] = useState<Array<{ id: number; name: string }>>([]);
  
  // 通知状态 - 使用 ref 避免 stale closure
  const lastFormCountRef = useRef<number>(0);
  const lastEvalCountRef = useRef<number>(0);
  
  // 轮询刷新倒计时
  const [refreshCountdown, setRefreshCountdown] = useState(10);
  
  // 悬浮球模式
  const [isMiniMode, setIsMiniMode] = useState(false);

  const loadTasksInFlightRef = useRef(false);
  const loadProjectsInFlightRef = useRef(false);
  const loadMessagesInFlightRef = useRef(false);
  const checkNotificationsInFlightRef = useRef(false);
  
  // 使用 ref 存储最新函数引用，避免轮询 stale closure
  const loadTasksRef = useRef<typeof loadTasks>();
  const loadProjectsRef = useRef<typeof loadProjects>();
  const checkDeadlineRemindersRef = useRef<typeof checkDeadlineReminders>();
  const [floatingIconSize, setFloatingIconSize] = useState(() => {
    try {
      const stored = localStorage.getItem('floating_settings');
      if (stored) {
        const settings = JSON.parse(stored);
        return settings.iconSize || 48;
      }
    } catch (e) {
      console.error('加载悬浮图标设置失败:', e);
    }
    return 48;
  });
  
  // 通知设置
  const [enableNotification, setEnableNotification] = useState(() => {
    try {
      const stored = localStorage.getItem('floating_settings');
      if (stored) {
        const settings = JSON.parse(stored);
        return settings.enableNotification !== false;
      }
    } catch (e) {}
    return true;
  });
  const [enableNotificationSound, setEnableNotificationSound] = useState(() => {
    try {
      const stored = localStorage.getItem('floating_settings');
      if (stored) {
        const settings = JSON.parse(stored);
        return settings.enableNotificationSound !== false;
      }
    } catch (e) {}
    return true;
  });
  const [lastSeenMessageIds, setLastSeenMessageIds] = useState<Set<string>>(() => {
    try {
      const stored = localStorage.getItem('last_seen_message_ids');
      if (stored) {
        const ids = JSON.parse(stored);
        console.log('[Notification] 从 localStorage 恢复已见消息:', ids.length);
        return new Set(ids);
      }
    } catch (e) {}
    return new Set();
  });
  const prevSizeRef = useRef<{ width: number; height: number } | null>(null);
  const dragStartRef = useRef<{ x: number; y: number; time: number } | null>(null);
  
  // 项目阶段列表（使用中文状态值，与后端 ProjectService::STAGES 保持一致）
  const PROJECT_STAGES = [
    { key: '待沟通', name: '待沟通', color: '#6366f1' },
    { key: '需求确认', name: '需求确认', color: '#8b5cf6' },
    { key: '设计中', name: '设计中', color: '#ec4899' },
    { key: '设计核对', name: '设计核对', color: '#f97316' },
    { key: '设计完工', name: '设计完工', color: '#14b8a6' },
    { key: '设计评价', name: '设计评价', color: '#10b981' },
  ];

  // 任务状态列表
  const TASK_STATUSES = [
    { key: 'pending', name: '待处理', color: '#F59E0B' },
    { key: 'in_progress', name: '进行中', color: '#3B82F6' },
    { key: 'completed', name: '已完成', color: '#22C55E' },
  ];

  // 搜索过滤
  const filteredProjects = projects.filter(p => 
    !searchText || 
    p.project_name.toLowerCase().includes(searchText.toLowerCase()) ||
    p.customer_name.toLowerCase().includes(searchText.toLowerCase()) ||
    p.project_code.toLowerCase().includes(searchText.toLowerCase())
  );
  
  const filteredTasks = tasks.filter(t => 
    !searchText || 
    t.title.toLowerCase().includes(searchText.toLowerCase()) ||
    (t.project_name && t.project_name.toLowerCase().includes(searchText.toLowerCase()))
  );

  // 排序
  const sortedProjects = [...filteredProjects].sort((a, b) => {
    if (sortBy === 'time') {
      // 按剩余天数排序：剩余天数少的（紧急的）在前面，null 值排最后
      const aRemaining = a.remaining_days ?? 9999;
      const bRemaining = b.remaining_days ?? 9999;
      return aRemaining - bRemaining;
    }
    return PROJECT_STAGES.findIndex(s => s.key === a.current_status) - 
           PROJECT_STAGES.findIndex(s => s.key === b.current_status);
  });
  
  const sortedTasks = [...filteredTasks].sort((a, b) => {
    if (sortBy === 'time') {
      return a.task_date.localeCompare(b.task_date);
    }
    return TASK_STATUSES.findIndex(s => s.key === a.status) - 
           TASK_STATUSES.findIndex(s => s.key === b.status);
  });

  // 按状态分组项目（匹配 key 或 name）
  const groupedProjects = groupEnabled 
    ? PROJECT_STAGES.map(stage => ({
        ...stage,
        items: sortedProjects.filter(p => 
          p.current_status === stage.key || p.current_status === stage.name
        )
      })).filter(g => g.items.length > 0)
    : [{ key: 'all', name: '全部', color: '#6B7280', items: sortedProjects }];

  // 按状态分组任务
  const groupedTasks = groupEnabled
    ? TASK_STATUSES.map(status => ({
        ...status,
        items: sortedTasks.filter(t => t.status === status.key)
      })).filter(g => g.items.length > 0)
    : [{ key: 'all', name: '全部', color: '#6B7280', items: sortedTasks }];

  // 消息类型定义
  const MESSAGE_TYPES = [
    { key: 'form', name: '表单', color: '#3b82f6' },
    { key: 'task', name: '任务', color: '#10b981' },
    { key: 'project', name: '项目', color: '#8b5cf6' },
    { key: 'system', name: '系统', color: '#6b7280' },
  ];

  // 按类型分组消息
  const groupedMessages = groupEnabled
    ? MESSAGE_TYPES.map(type => ({
        ...type,
        items: messages.filter(m => m.type === type.key)
      })).filter(g => g.items.length > 0)
    : [{ key: 'all', name: '全部', color: '#6B7280', items: messages }];

  // 初始化展开状态（首次加载时展开所有分组）
  useEffect(() => {
    if (groupEnabled && expandedGroups.size === 0) {
      const allKeys = new Set([
        ...groupedProjects.map(g => `project-${g.key}`),
        ...groupedTasks.map(g => `task-${g.key}`),
        ...groupedMessages.map(g => `message-${g.key}`)
      ]);
      setExpandedGroups(allKeys);
    }
  }, [groupedProjects.length, groupedTasks.length, groupedMessages.length, groupEnabled]);

  // 切换分组展开/收起
  const toggleGroup = (groupKey: string) => {
    setExpandedGroups(prev => {
      const newSet = new Set(prev);
      if (newSet.has(groupKey)) {
        newSet.delete(groupKey);
      } else {
        newSet.add(groupKey);
      }
      return newSet;
    });
  };

  // 同步函数引用到 ref，避免轮询 stale closure
  useEffect(() => {
    loadTasksRef.current = loadTasks;
    loadProjectsRef.current = loadProjects;
    checkDeadlineRemindersRef.current = checkDeadlineReminders;
  });

  // 10秒轮询刷新（替代 WebSocket）
  useEffect(() => {
    if (!serverUrl || !token) return;
    
    // 首次加载时检查截止日期
    checkDeadlineReminders();
    
    let countdownInterval: NodeJS.Timeout | null = null;
    
    // 每秒更新倒计时，仅在窗口可见时执行
    const startPolling = async () => {
      const win = getCurrentWindow();
      const isVisible = await win.isVisible();
      
      if (!isVisible) {
        // 窗口隐藏时，清除定时器
        if (countdownInterval) {
          clearInterval(countdownInterval);
          countdownInterval = null;
        }
        return;
      }
      
      // 窗口可见时，启动或继续轮询
      if (!countdownInterval) {
        countdownInterval = setInterval(async () => {
          // 每次轮询前检查窗口是否可见
          const win = getCurrentWindow();
          const isVisible = await win.isVisible();
          if (!isVisible) return;
          
          setRefreshCountdown(prev => {
            if (prev <= 1) {
              // 倒计时结束，刷新数据（只刷新任务和项目，消息不自动刷新避免覆盖已读状态）
              // 通过 ref.current 调用，避免 stale closure
              if (loadTasksRef.current) loadTasksRef.current();
              if (loadProjectsRef.current) loadProjectsRef.current();
              // 消息只检查数量变化，不重新加载列表（避免覆盖本地已读状态）
              // loadMessages(); // 移除：避免每10秒刷新导致已读状态被覆盖
              // 每次轮询时检查截止日期（函数内部会限制频率）
              if (checkDeadlineRemindersRef.current) checkDeadlineRemindersRef.current();
              return 10; // 重置为10秒
            }
            return prev - 1;
          });
        }, 1000);
      }
    };
    
    // 启动轮询
    startPolling();
    
    // 监听窗口可见性变化
    const win = getCurrentWindow();
    const unlisten = (win as any).onVisibilityChanged((visible: boolean) => {
      if (visible) {
        startPolling();
      } else {
        if (countdownInterval) {
          clearInterval(countdownInterval);
          countdownInterval = null;
        }
      }
    });
    
    return () => {
      if (countdownInterval) clearInterval(countdownInterval);
      unlisten.then((fn: () => void) => fn());
    };
  }, [serverUrl, token]);
  
  // 窗口控制
  const handleClose = async () => {
    try {
      const win = getCurrentWindow();
      await win.hide();
    } catch (e) {
      console.error('关闭窗口失败:', e);
    }
  };

  const _handleMinimize = async () => {
    try {
      const win = getCurrentWindow();
      await win.minimize();
    } catch (e) {
      console.error('最小化窗口失败:', e);
    }
  };
  void _handleMinimize;

  const handleTogglePin = async () => {
    try {
      const win = getCurrentWindow();
      const newState = !isPinned;
      await win.setAlwaysOnTop(newState);
      setIsPinned(newState);
    } catch (e) {
      console.error('切换置顶失败:', e);
    }
  };

  // 加载数据 - 使用与主窗口相同的 tasks 表
  const loadTasks = async (showLoading = false) => {
    if (!serverUrl || !token) {
      console.log('[FloatingWindow] 加载任务跳过: serverUrl=', serverUrl, 'token=', token ? '有' : '无');
      return;
    }
    if (showLoading) setLoading(true);
    if (loadTasksInFlightRef.current) {
      return;
    }
    loadTasksInFlightRef.current = true;

    try {
      // 使用 desktop_tasks_manage.php API，与主窗口一致
      const params = new URLSearchParams({ action: 'my_tasks' });
      // 传递日期筛选参数
      if (taskFilter === 'all' || taskFilter === 'today' || taskFilter === 'yesterday' || taskFilter === 'future') {
        params.append('date_filter', taskFilter);
      }
      
      const endpoint = `desktop_tasks_manage.php?${params.toString()}`;
      console.log('[FloatingWindow] 加载任务:', endpoint);
      const data: any = await http.get(endpoint);
      console.log('[FloatingWindow] 任务响应:', data);
      if (data.success) {
        // 转换数据格式以匹配悬浮窗的 Task 接口
        // API 返回 { data: { tasks: [...], stats: {...} } }
        const taskList = data.data?.tasks || data.data || [];
        const tasks = taskList.map((t: Record<string, unknown>) => ({
          id: t.id,
          title: t.title,
          project_id: t.project_id,
          project_name: t.project_name,
          project_code: t.project_code,
          status: t.status,
          priority: t.priority || 'medium',
          task_date: t.deadline ? (typeof t.deadline === 'number' ? new Date(Number(t.deadline) * 1000).toISOString().split('T')[0] : t.deadline) : null,
          need_help: t.need_help ? 1 : 0,
          assigned_by: t.created_by || null,
          assigned_by_name: t.creator_name || null,
          create_time: t.create_time || null,
        }));
        console.log('[FloatingWindow] 解析后的任务:', tasks);
        setTasks(tasks);
        try {
          await invoke('update_tray_task_count', { count: tasks.length });
        } catch (e) {
          console.error('更新托盘任务数量失败:', e);
        }
      }
    } catch (e) {
      console.error('[FloatingWindow] 加载任务失败:', e);
    } finally {
      loadTasksInFlightRef.current = false;
      if (showLoading) setLoading(false);
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
        // API 返回 { data: { projects: [...], stages: {...} } }
        setProjects(data.data?.projects || []);
      }
    } catch (e) {
      console.error('加载项目失败:', e);
    } finally {
      loadProjectsInFlightRef.current = false;
    }
  };

  const loadMessages = async () => {
    if (!serverUrl || !token) return;
    if (loadMessagesInFlightRef.current) return;
    loadMessagesInFlightRef.current = true;
    try {
      const params = new URLSearchParams();
      if (messageFilter === 'custom' && customStartDate && customEndDate) {
        params.append('start_date', customStartDate);
        params.append('end_date', customEndDate);
      } else if (messageFilter !== 'all') {
        params.append('filter', messageFilter);
      }
      if (messageTypeFilter !== 'all') {
        params.append('type', messageTypeFilter);
      }
      const endpoint = `desktop_notifications.php${params.toString() ? '?' + params.toString() : ''}`;
      console.log('[FloatingWindow] 加载消息:', endpoint);
      const data: any = await http.get(endpoint);
      console.log('[FloatingWindow] 消息响应:', data);
      if (data.success) {
        const newMessages = data.data || [];
        checkNewMessages(newMessages);
        setMessages(newMessages);
        setUnreadCount(data.unread_count || 0);
      }
    } catch (e) {
      console.error('加载消息失败:', e);
      setMessages([]);
      setUnreadCount(0);
    } finally {
      loadMessagesInFlightRef.current = false;
    }
  };
  
  // 标记消息已读
  const handleMarkMessageRead = async (messageId: string) => {
    if (!serverUrl || !token) return;
    try {
      await http.post('desktop_notifications.php', { action: 'mark_read', id: messageId });
      // 更新本地状态
      setMessages(prev => prev.map(m => 
        m.id === messageId ? { ...m, is_read: true } : m
      ));
      setUnreadCount(prev => Math.max(0, prev - 1));
    } catch (e) {
      console.error('标记已读失败:', e);
    }
  };

  // 全部已读
  const handleMarkAllRead = async () => {
    if (!serverUrl || !token) return;
    const unreadIds = messages.filter(m => !m.is_read).map(m => m.id);
    if (unreadIds.length === 0) {
      toast.info('没有未读消息');
      return;
    }
    try {
      const data: any = await http.post('desktop_notifications.php', { action: 'mark_all_read', notification_ids: unreadIds });
      if (data.success) {
        setMessages(prev => prev.map(m => ({ ...m, is_read: true })));
        setUnreadCount(0);
        toast.success('已全部标记为已读');
      }
    } catch (e) {
      console.error('全部已读失败:', e);
      toast.error('操作失败');
    }
  };

  // 删除单条消息
  const handleDeleteMessage = async (messageId: string) => {
    if (!serverUrl || !token) return;
    try {
      const data: any = await http.post('desktop_notifications.php', { action: 'delete', id: messageId });
      if (data.success) {
        setMessages(prev => prev.filter(m => m.id !== messageId));
        toast.success('已删除');
      }
    } catch (e) {
      console.error('删除消息失败:', e);
      toast.error('删除失败');
    }
  };

  // 批量删除消息
  const handleBatchDeleteMessages = async () => {
    if (!serverUrl || !token) return;
    if (selectedMessages.size === 0) {
      toast.info('请先选择要删除的消息');
      return;
    }
    try {
      const data: any = await http.post('desktop_notifications.php', { action: 'batch_delete', notification_ids: Array.from(selectedMessages) });
      if (data.success) {
        setMessages(prev => prev.filter(m => !selectedMessages.has(m.id)));
        setSelectedMessages(new Set());
        setMessageSelectMode(false);
        toast.success(`已删除 ${data.count} 条消息`);
      }
    } catch (e) {
      console.error('批量删除失败:', e);
      toast.error('批量删除失败');
    }
  };

  // 切换消息选中状态
  const toggleMessageSelection = (messageId: string) => {
    setSelectedMessages(prev => {
      const newSet = new Set(prev);
      if (newSet.has(messageId)) {
        newSet.delete(messageId);
      } else {
        newSet.add(messageId);
      }
      return newSet;
    });
  };

  // 初始化
  useEffect(() => {
    loadTasks(true); // 首次加载显示 loading
    loadProjects();
    loadMessages();
    loadTeamMembers();
    checkNotifications();
    
    // 初始化置顶状态
    const initPin = async () => {
      try {
        const win = getCurrentWindow();
        const pinned = await win.isAlwaysOnTop();
        setIsPinned(pinned);
      } catch (e) {
        console.error('获取置顶状态失败:', e);
      }
    };
    initPin();

    // 定时检查通知 (30秒)
    const notifyInterval = setInterval(() => {
      checkNotifications();
    }, 30000);
    
    return () => {
      clearInterval(notifyInterval);
    };
  }, [serverUrl, token]);

  useEffect(() => {
    loadTasks();
  }, [taskFilter]);

  useEffect(() => {
    loadMessages();
  }, [messageFilter, messageTypeFilter]);

  // 刷新当前标签页数据
  const handleRefresh = () => {
    if (activeTab === 'task') loadTasks();
    else if (activeTab === 'project') loadProjects();
    else if (activeTab === 'message') loadMessages();
  };

  // 创建任务 - 使用与主窗口相同的 API
  const handleCreateTask = async () => {
    if (!newTaskTitle.trim() || !serverUrl || !token) return;
    setSubmitting(true);
    try {
      const data: any = await http.post('desktop_tasks_manage.php?action=create', {
        title: newTaskTitle,
        project_id: newTaskProjectId,
        deadline: newTaskDate ? Math.floor(new Date(newTaskDate + 'T12:00:00').getTime() / 1000) : null,
        priority: newTaskPriority,
        status: 'pending',
        need_help: newTaskNeedHelp ? 1 : 0,
      });
      if (data.success) {
        // 重置表单
        setNewTaskTitle('');
        setNewTaskProjectId(null);
        setNewTaskDate(new Date().toLocaleDateString('sv-SE'));
        setNewTaskPriority('medium');
        setNewTaskNeedHelp(false);
        setNewTaskProjectName('');
        setShowNewTask(false);
        loadTasks();
      }
    } catch (e) {
      console.error('创建任务失败:', e);
    } finally {
      setSubmitting(false);
    }
  };

  // 切换项目阶段
  const handleChangeProjectStage = async (projectId: number, newStatus: string) => {
    if (!serverUrl || !token) return;
    console.log('[FloatingWindow] 切换项目阶段:', { projectId, newStatus });
    try {
      const data: any = await http.post('desktop_project_stage.php', { project_id: projectId, status: newStatus });
      console.log('[FloatingWindow] 切换结果:', data);
      if (data.success) {
        toast.success('状态已更新');
        loadProjects();
      } else {
        toast.error(data.error || '切换失败');
      }
    } catch (e) {
      console.error('切换项目阶段失败:', e);
      toast.error('切换失败');
    }
  };

  // 切换任务完成状态 - 使用与主窗口相同的 API
  const handleToggleComplete = async (taskId: number, currentStatus: string) => {
    if (!serverUrl || !token) return;
    const newStatus = currentStatus === 'completed' ? 'pending' : 'completed';
    try {
      await http.post('desktop_tasks_manage.php?action=update_status', { task_id: taskId, status: newStatus });
      loadTasks();
    } catch (e) {
      console.error('更新任务状态失败:', e);
    }
  };

  // 打开主窗口并发送事件
  const openMainWindowAndNavigate = async (eventFn: () => Promise<void>) => {
    console.log('[FloatingWindow] openMainWindowAndNavigate 开始');
    try {
      // 使用 Rust 命令显示主窗口（更可靠）
      console.log('[FloatingWindow] 调用 show_main_window...');
      await invoke('show_main_window');
      console.log('[FloatingWindow] show_main_window 成功');
      // 延迟发送事件，确保主窗口已准备好
      setTimeout(() => {
        console.log('[FloatingWindow] 发送事件...');
        eventFn();
      }, 150);
    } catch (e) {
      console.error('[FloatingWindow] 打开主窗口失败:', e);
      // 降级：直接发送事件
      console.log('[FloatingWindow] 降级：直接发送事件');
      eventFn();
    }
  };

  // 点击任务
  const handleTaskClick = (task: Task) => {
    openMainWindowAndNavigate(() => requestOpenTaskDetail(task.id));
  };

  // 点击项目
  const handleProjectClick = (project: Project) => {
    console.log('[FloatingWindow] 点击项目:', { id: project.id, code: project.project_code, name: project.project_name });
    openMainWindowAndNavigate(() => requestOpenProjectDetail(project.id));
  };

  // 点击表单入口
  const handleFormClick = (projectId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    openMainWindowAndNavigate(() => requestOpenFormDetail(projectId));
  };

  // 检查通知变化
  const checkNotifications = async () => {
    if (!serverUrl || !token) return;
    if (checkNotificationsInFlightRef.current) return;
    checkNotificationsInFlightRef.current = true;
    try {
      const data: any = await http.get('desktop_notifications.php?check=1');
      if (data.success) {
        const { form_count = 0, eval_count = 0, new_tasks = [] } = data.data || {};
        
        // 需求表单变动提醒 - 使用 ref.current 避免 stale closure
        const lastFormCount = lastFormCountRef.current;
        if (form_count > lastFormCount && lastFormCount > 0) {
          const count = form_count - lastFormCount;
          sendDesktopNotification('📋 需求表单更新', `有 ${count} 个新的需求表单`);
          toast.info('需求表单更新', `有 ${count} 个新的需求表单`);
        }
        lastFormCountRef.current = form_count;
        
        // 评价表单变动提醒 - 使用 ref.current 避免 stale closure
        const lastEvalCount = lastEvalCountRef.current;
        if (eval_count > lastEvalCount && lastEvalCount > 0) {
          const count = eval_count - lastEvalCount;
          sendDesktopNotification('⭐ 评价表单更新', `有 ${count} 个新的评价`);
          toast.info('评价表单更新', `有 ${count} 个新的评价`);
        }
        lastEvalCountRef.current = eval_count;
        
        // 新任务提醒
        if (new_tasks.length > 0) {
          sendDesktopNotification('📝 新任务', `您有 ${new_tasks.length} 个新任务`);
          new_tasks.forEach((task: { title: string }) => {
            toast.success('新任务', task.title);
          });
        }
      }
    } catch (e) {
      console.error('检查通知失败:', e);
    } finally {
      checkNotificationsInFlightRef.current = false;
    }
  };
  
  // 检查截止日期提醒 - 使用 useRef 避免闭包问题
  const lastDeadlineCheckRef = useRef<number>(0);
  const notifiedItemsRef = useRef<Set<string>>(new Set()); // 记录已通知的项目，避免重复
  
  const checkDeadlineReminders = async () => {
    if (!serverUrl || !token) return;
    
    // 每小时只检查一次（避免频繁弹窗）
    const now = Date.now();
    if (now - lastDeadlineCheckRef.current < DEADLINE_CHECK_INTERVAL_MS) {
      console.log('[FloatingWindow] 截止日期检查跳过，距上次检查:', Math.round((now - lastDeadlineCheckRef.current) / 1000), '秒');
      return;
    }
    lastDeadlineCheckRef.current = now;
    console.log('[FloatingWindow] 执行截止日期检查');
    
    try {
      const data: any = await http.get('desktop_deadline_check.php');
      console.log('[FloatingWindow] 截止日期检查结果:', data);
      
      if (data.success && data.data?.reminders?.length > 0) {
        const reminders = data.data.reminders;
        
        // 过滤掉已通知的项目
        const newReminders = reminders.filter((r: { id?: string; message: string }) => {
          const key = r.id || r.message;
          return !notifiedItemsRef.current.has(key);
        });
        
        if (newReminders.length === 0) {
          console.log('[FloatingWindow] 没有新的截止日期提醒');
          return;
        }
        
        // 记录已通知的项目
        newReminders.forEach((r: { id?: string; message: string }) => {
          notifiedItemsRef.current.add(r.id || r.message);
        });
        
        // 逾期任务/项目 - 独立弹窗提醒（红色，更醒目）
        const overdueItems = newReminders.filter((r: { urgency: string }) => r.urgency === 'overdue');
        if (overdueItems.length > 0) {
          sendDesktopNotification('⚠️ 有逾期任务/项目', `${overdueItems.length} 个任务或项目已逾期`);
          // 使用独立弹窗显示，最多显示 3 条
          overdueItems.slice(0, MAX_TOAST_ITEMS).forEach((item: { message: string }) => {
            popup.error('已逾期', item.message);
          });
        }
        
        // 今天到期 - 独立弹窗提醒（黄色警告）
        const todayItems = newReminders.filter((r: { urgency: string }) => r.urgency === 'today');
        if (todayItems.length > 0) {
          sendDesktopNotification('📅 今天到期提醒', `${todayItems.length} 个任务或项目今天到期`);
          todayItems.slice(0, MAX_TOAST_ITEMS).forEach((item: { message: string }) => {
            popup.warning('今天到期', item.message);
          });
        }
        
        // 明天到期 - 独立弹窗提醒（蓝色信息）
        const tomorrowItems = newReminders.filter((r: { urgency: string }) => r.urgency === 'tomorrow');
        if (tomorrowItems.length > 0) {
          tomorrowItems.slice(0, MAX_TOAST_ITEMS - 1).forEach((item: { message: string }) => {
            popup.info('明天到期', item.message);
          });
        }
      }
    } catch (e) {
      console.error('检查截止日期失败:', e);
    }
  };

  // 加载团队成员
  const loadTeamMembers = async () => {
    if (!serverUrl || !token) return;
    try {
      const data: any = await http.get('desktop_team_tasks.php?action=members');
      if (data.success) {
        setTeamMembers(data.data?.members || []);
      }
    } catch (e) {
      console.error('加载团队成员失败:', e);
    }
  };

  // 分配任务
  const handleAssignTask = async () => {
    if (!assignTaskTitle.trim() || !assignTaskUserId || !serverUrl || !token) return;
    setSubmitting(true);
    try {
      const data: any = await http.post('desktop_daily_tasks.php', {
        action: 'assign',
        title: assignTaskTitle,
        assigned_to: assignTaskUserId,
        task_date: assignTaskDate,
      });
      if (data.success) {
        toast.success('分配成功', '任务已分配给团队成员');
        setAssignTaskTitle('');
        setAssignTaskUserId(null);
        setShowAssignTask(false);
        loadTasks();
      }
    } catch (e) {
      console.error('分配任务失败:', e);
      toast.error('分配失败', '请稍后重试');
    } finally {
      setSubmitting(false);
    }
  };

  // 标记消息已读（保留以备后用）
  const _handleMarkRead = async (msgId: string) => {
    if (!serverUrl || !token) return;
    try {
      await http.post('desktop_notifications.php', { action: 'mark_read', id: msgId });
      // 更新本地状态
      setMessages(prev => prev.map(m => m.id === msgId ? { ...m, is_read: true } : m));
      setUnreadCount(prev => Math.max(0, prev - 1));
    } catch (e) {
      console.error('标记已读失败:', e);
    }
  };
  void _handleMarkRead; // 避免未使用警告

  // 获取优先级样式
  const getPriorityStyle = (priority: string) => {
    switch (priority) {
      case 'high': return 'text-red-500 bg-red-50';
      case 'medium': return 'text-yellow-600 bg-yellow-50';
      default: return 'text-blue-500 bg-blue-50';
    }
  };

  // 获取紧急程度样式（保留以备后用）
  const _getUrgencyStyle = (urgency: string) => {
    switch (urgency) {
      case 'high': return 'border-l-red-500 bg-red-50/50';
      case 'medium': return 'border-l-yellow-500 bg-yellow-50/50';
      default: return 'border-l-blue-500 bg-blue-50/50';
    }
  };
  void _getUrgencyStyle; // 避免未使用警告

  // 搜索功能 - 根据当前 Tab 切换搜索
  const handleSearch = () => {
    // 如果当前在消息或设置 Tab，先切换到任务 Tab
    if (activeTab === 'message' || activeTab === 'settings') {
      setActiveTab('task');
    }
    setShowSearch(!showSearch);
  };
  
  // 获取搜索占位符文本
  const getSearchPlaceholder = () => {
    switch (activeTab) {
      case 'project': return '搜索项目...';
      case 'task': return '搜索任务...';
      case 'message': return '搜索消息...';
      default: return '搜索...';
    }
  };

  // 切换悬浮球模式
  const handleToggleMiniMode = async () => {
    try {
      const win = getCurrentWindow();
      const { LogicalSize } = await import('@tauri-apps/api/dpi');
      
      if (!isMiniMode) {
        // 记录当前窗口大小用于恢复
        const factor = await win.scaleFactor();
        const physicalSize = await win.innerSize();
        const logicalWidth = Math.round(physicalSize.width / factor);
        const logicalHeight = Math.round(physicalSize.height / factor);
        prevSizeRef.current = { width: logicalWidth, height: logicalHeight };
        
        // 进入悬浮球模式：先设置最小尺寸为图标尺寸，再设置窗口大小
        await win.setMinSize(new LogicalSize(floatingIconSize, floatingIconSize));
        // 使用 setTimeout 确保 setMinSize 在 setSize 之前生效
        setTimeout(async () => {
          await win.setResizable(false);
          await win.setSize(new LogicalSize(floatingIconSize, floatingIconSize));
          await win.setAlwaysOnTop(true);
        }, 50);
      } else {
        // 恢复正常模式：先恢复最小尺寸，再恢复窗口大小
        const prev = prevSizeRef.current;
        const restoreWidth = prev?.width ?? 360;
        const restoreHeight = prev?.height ?? 700;
        await win.setMinSize(new LogicalSize(350, 600));
        // 使用 setTimeout 确保 setMinSize 在 setSize 之前生效
        setTimeout(async () => {
          await win.setSize(new LogicalSize(restoreWidth, restoreHeight));
          prevSizeRef.current = null;
          await win.setResizable(true);
          await win.setAlwaysOnTop(isPinned);
        }, 50);
      }
      setIsMiniMode(!isMiniMode);
    } catch (err) {
      console.error('切换悬浮球模式失败:', err);
    }
  };

  // 悬浮球鼠标事件
  const handleMiniMouseDown = (e: React.MouseEvent) => {
    dragStartRef.current = { x: e.clientX, y: e.clientY, time: Date.now() };
  };

  const handleMiniMouseUp = async (e: React.MouseEvent) => {
    const start = dragStartRef.current;
    dragStartRef.current = null;
    if (!start) return;
    
    const dx = Math.abs(e.clientX - start.x);
    const dy = Math.abs(e.clientY - start.y);
    const dt = Date.now() - start.time;
    
    // 如果移动距离小于 5px 且时间小于 300ms，视为点击
    if (dx < 5 && dy < 5 && dt < 300) {
      await handleToggleMiniMode();
    }
  };

  const handleMiniMouseMove = async (e: React.MouseEvent) => {
    const start = dragStartRef.current;
    if (!start) return;
    
    const dx = Math.abs(e.clientX - start.x);
    const dy = Math.abs(e.clientY - start.y);
    
    // 如果移动距离超过 3px，开始拖动
    if (dx > 3 || dy > 3) {
      dragStartRef.current = null;
      try {
        const win = getCurrentWindow();
        await win.startDragging();
      } catch (err) {
        console.error('拖动失败:', err);
      }
    }
  };

  // 设置功能 - 切换到设置 Tab
  const handleSettings = () => {
    setActiveTab('settings');
  };

  // 打开项目文件夹（使用设置中的同步根目录，与项目详情页一致）
  const handleOpenProjectFolder = async (project: Project, subFolder?: string) => {
    if (!rootDir) {
      toast.error('请先在设置中配置同步根目录');
      return;
    }
    
    try {
      const groupCode = project.group_code || `P${project.id}`;
      const groupName = sanitizeFolderName(project.group_name || project.customer_name || '');
      const groupFolderName = groupName ? `${groupCode}_${groupName}` : groupCode;
      const projectName = sanitizeFolderName(project.project_name || project.project_code || `项目${project.id}`);
      const folderName = `${groupFolderName}/${projectName}`;
      
      await invoke('open_project_folder', {
        workDir: rootDir,
        projectName: folderName,
        subFolder: subFolder || null,
      });
      
      toast.success('已打开文件夹', folderName);
    } catch (e) {
      console.error('打开文件夹失败:', e);
      toast.error('打开文件夹失败', String(e));
    }
  };
  
  // 保存悬浮图标大小
  const handleFloatingIconSizeChange = (size: number) => {
    const validSize = Math.min(Math.max(size, 32), 128);
    setFloatingIconSize(validSize);
    try {
      const stored = localStorage.getItem('floating_settings');
      const settings = stored ? JSON.parse(stored) : {};
      settings.iconSize = validSize;
      localStorage.setItem('floating_settings', JSON.stringify(settings));
      toast.success('悬浮图标大小已更新', '下次进入悬浮模式生效');
    } catch (e) {
      console.error('保存悬浮图标设置失败:', e);
    }
  };

  // 保存通知设置
  const handleNotificationToggle = (enabled: boolean) => {
    setEnableNotification(enabled);
    try {
      const stored = localStorage.getItem('floating_settings');
      const settings = stored ? JSON.parse(stored) : {};
      settings.enableNotification = enabled;
      localStorage.setItem('floating_settings', JSON.stringify(settings));
    } catch (e) {
      console.error('保存通知设置失败:', e);
    }
  };

  const handleNotificationSoundToggle = (enabled: boolean) => {
    setEnableNotificationSound(enabled);
    try {
      const stored = localStorage.getItem('floating_settings');
      const settings = stored ? JSON.parse(stored) : {};
      settings.enableNotificationSound = enabled;
      localStorage.setItem('floating_settings', JSON.stringify(settings));
    } catch (e) {
      console.error('保存通知音设置失败:', e);
    }
  };

  // 发送桌面通知（使用 Tauri 原生通知 API）
  const sendDesktopNotification = async (title: string, body: string) => {
    if (!enableNotification) return;
    
    try {
      // 使用 Tauri 通知插件
      const { isPermissionGranted, requestPermission, sendNotification } = await import('@tauri-apps/plugin-notification');
      
      // 检查权限
      let permissionGranted = await isPermissionGranted();
      if (!permissionGranted) {
        const permission = await requestPermission();
        permissionGranted = permission === 'granted';
      }
      
      if (permissionGranted) {
        // 发送通知
        sendNotification({ title, body });
        console.log('[Notification] 已发送 Tauri 通知:', title);
        
        // 播放提示音
        if (enableNotificationSound) {
          try {
            const audio = new Audio('/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {});
          } catch (e) {}
        }
      }
    } catch (e) {
      console.error('发送通知失败:', e);
      // 降级：使用浏览器通知 API
      try {
        if ('Notification' in window && Notification.permission === 'granted') {
          new Notification(title, { body });
        }
      } catch {}
    }
  };

  // 检测新消息并发送通知
  const checkNewMessages = (newMessages: Message[]) => {
    console.log('[Notification] checkNewMessages 调用:', {
      enableNotification,
      newMessagesCount: newMessages.length,
      lastSeenCount: lastSeenMessageIds.size
    });
    
    if (!enableNotification || newMessages.length === 0) {
      console.log('[Notification] 跳过：通知未启用或无消息');
      return;
    }
    
    const newMessageIds = new Set(newMessages.map(m => m.id));
    const unseenMessages = newMessages.filter(m => !lastSeenMessageIds.has(m.id) && !m.is_read);
    
    console.log('[Notification] 未读新消息:', unseenMessages.length);
    
    if (unseenMessages.length > 0) {
      // 发送通知（首次加载也发送，因为可能有真正的新消息）
      const latestMessage = unseenMessages[0];
      console.log('[Notification] 准备发送通知:', latestMessage.title);
      
      // 只有当不是首次加载，或者有多条新消息时才发送桌面通知
      if (lastSeenMessageIds.size > 0 || unseenMessages.length > 1) {
        sendDesktopNotification(
          `🔔 ${latestMessage.title}`,
          latestMessage.content.substring(0, 100)
        );
      }
    } else {
      console.log('[Notification] 不发送通知：无新未读消息');
    }
    
    // 保存到 state 和 localStorage
    setLastSeenMessageIds(newMessageIds);
    try {
      localStorage.setItem('last_seen_message_ids', JSON.stringify([...newMessageIds]));
    } catch (e) {}
  };

  // 查看通知 - 切换到消息 Tab
  const handleNotifications = () => {
    setActiveTab('message');
  };

  // 悬浮球模式渲染
  if (isMiniMode) {
    return (
      <div 
        style={{ 
          width: '100%',
          height: '100%',
          backgroundColor: 'transparent',
          cursor: 'pointer',
          overflow: 'hidden',
          padding: 0,
          margin: 0,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
        onMouseDown={handleMiniMouseDown}
        onMouseMove={handleMiniMouseMove}
        onMouseUp={handleMiniMouseUp}
        onDoubleClick={handleToggleMiniMode}
        title="点击还原窗口"
      >
        <MiniModeIcon />
      </div>
    );
  }

  return (
    <div className="w-full h-screen bg-slate-900 overflow-hidden flex flex-col">
      {/* 标题栏 */}
      <div 
        data-tauri-drag-region
        className="h-10 bg-slate-800 flex items-center justify-between px-2 select-none cursor-move"
      >
        {/* 左侧工具按钮 */}
        <div className="flex items-center gap-1">
          <button 
            onClick={handleSearch}
            className="p-1.5 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
            title="搜索"
          >
            <Search className="w-4 h-4" />
          </button>
          <button 
            onClick={handleNotifications}
            className="p-1.5 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors relative"
            title="消息通知"
          >
            <Bell className="w-4 h-4" />
            {unreadCount > 0 && (
              <span className="absolute -top-0.5 -right-0.5 w-3.5 h-3.5 bg-red-500 rounded-full text-[8px] text-white flex items-center justify-center">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </button>
          <button 
            onClick={handleSettings}
            className="p-1.5 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
            title="设置"
          >
            <Settings className="w-4 h-4" />
          </button>
        </div>

        {/* 右侧窗口控制 */}
        <div className="flex items-center gap-0.5">
          <button
            onClick={handleTogglePin}
            className={`p-1.5 rounded hover:bg-slate-700 transition-colors ${isPinned ? 'text-blue-400' : 'text-slate-400 hover:text-white'}`}
            title={isPinned ? '取消置顶' : '置顶'}
          >
            <Pin className={`w-4 h-4 transition-transform ${isPinned ? 'rotate-[-45deg]' : ''}`} />
          </button>
          <button
            onClick={handleToggleMiniMode}
            className="p-1.5 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
            title="缩小为悬浮球"
          >
            <Minus className="w-4 h-4" />
          </button>
          <button
            onClick={handleClose}
            className="p-1.5 rounded hover:bg-red-500 text-slate-400 hover:text-white transition-colors"
            title="隐藏"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* 主体区域 */}
      <div className="flex-1 flex overflow-hidden">
        {/* 左侧内容区 */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {/* 子标签栏 */}
          {activeTab === 'task' && (
            <div className="px-3 py-2 bg-slate-800/50">
              {/* 搜索框 */}
              {showSearch && (
                <div className="mb-2">
                  <input
                    type="text"
                    value={searchText}
                    onChange={(e) => setSearchText(e.target.value)}
                    placeholder={getSearchPlaceholder()}
                    className="w-full px-2 py-1 text-xs bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"
                    autoFocus
                  />
                </div>
              )}
              <div className="flex items-center justify-between">
                <div className="flex gap-1">
                  {TASK_FILTERS.map((filter) => (
                    <button
                      key={filter.id}
                      onClick={() => setTaskFilter(filter.id)}
                      className={`px-3 py-1 text-xs rounded transition-colors ${
                        taskFilter === filter.id
                          ? 'bg-blue-500 text-white'
                          : 'text-slate-400 hover:text-white hover:bg-slate-700'
                      }`}
                    >
                      {filter.label}
                    </button>
                  ))}
                </div>
                <div className="flex items-center gap-0.5">
                  <button
                    onClick={() => setShowSearch(!showSearch)}
                    className={`p-1 rounded hover:bg-slate-700 transition-colors ${showSearch ? 'text-blue-400' : 'text-slate-400 hover:text-white'}`}
                    title="搜索"
                  >
                    <Search className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setGroupEnabled(!groupEnabled)}
                    className={`p-1 rounded hover:bg-slate-700 transition-colors ${groupEnabled ? 'text-blue-400' : 'text-slate-400 hover:text-white'}`}
                    title={groupEnabled ? '取消分组' : '按状态分组'}
                  >
                    <Layers className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setSortBy(sortBy === 'status' ? 'time' : 'status')}
                    className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                    title={sortBy === 'status' ? '按时间排序' : '按状态排序'}
                  >
                    <ArrowUpDown className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={handleRefresh}
                    className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                    title="刷新"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'project' && (
            <div className="px-3 py-2 bg-slate-800/50">
              {/* 搜索框 */}
              {showSearch && (
                <div className="mb-2">
                  <input
                    type="text"
                    value={searchText}
                    onChange={(e) => setSearchText(e.target.value)}
                    placeholder={getSearchPlaceholder()}
                    className="w-full px-2 py-1 text-xs bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:border-blue-500"
                    autoFocus
                  />
                </div>
              )}
              <div className="flex items-center justify-between">
                <span className="text-xs text-slate-400">我的项目 ({filteredProjects.length})</span>
                <div className="flex items-center gap-0.5">
                  <button
                    onClick={() => setShowSearch(!showSearch)}
                    className={`p-1 rounded hover:bg-slate-700 transition-colors ${showSearch ? 'text-blue-400' : 'text-slate-400 hover:text-white'}`}
                    title="搜索"
                  >
                    <Search className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setGroupEnabled(!groupEnabled)}
                    className={`p-1 rounded hover:bg-slate-700 transition-colors ${groupEnabled ? 'text-blue-400' : 'text-slate-400 hover:text-white'}`}
                    title={groupEnabled ? '取消分组' : '按状态分组'}
                  >
                    <Layers className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setSortBy(sortBy === 'status' ? 'time' : 'status')}
                    className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                    title={sortBy === 'status' ? '按时间排序' : '按状态排序'}
                  >
                    <ArrowUpDown className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={handleRefresh}
                    className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                    title="刷新"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'message' && (
            <div className="px-3 py-2 bg-slate-800/50">
              <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-slate-400">消息通知 {unreadCount > 0 && `(${unreadCount}未读)`}</span>
                <div className="flex items-center gap-1">
                  {messageSelectMode ? (
                    <>
                      <button
                        onClick={handleBatchDeleteMessages}
                        className="px-2 py-1 text-[10px] rounded bg-red-500 text-white hover:bg-red-600"
                        title="删除选中"
                      >
                        删除({selectedMessages.size})
                      </button>
                      <button
                        onClick={() => { setMessageSelectMode(false); setSelectedMessages(new Set()); }}
                        className="px-2 py-1 text-[10px] rounded bg-slate-600 text-white hover:bg-slate-500"
                        title="取消"
                      >
                        取消
                      </button>
                    </>
                  ) : (
                    <>
                      <button
                        onClick={handleMarkAllRead}
                        className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                        title="全部已读"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" />
                      </button>
                      <button
                        onClick={() => setMessageSelectMode(true)}
                        className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                        title="选择删除"
                      >
                        <X className="w-3.5 h-3.5" />
                      </button>
                      <button
                        onClick={handleRefresh}
                        className="p-1 rounded hover:bg-slate-700 text-slate-400 hover:text-white transition-colors"
                        title="刷新"
                      >
                        <RefreshCw className="w-3.5 h-3.5" />
                      </button>
                    </>
                  )}
                </div>
              </div>
              {/* 类型筛选 */}
              <div className="flex gap-1 mb-2">
                {([
                  { key: 'all', label: '全部' },
                  { key: 'form', label: '表单' },
                  { key: 'task', label: '任务' },
                  { key: 'project', label: '项目' },
                ] as const).map((item) => (
                  <button
                    key={item.key}
                    onClick={() => { setMessageTypeFilter(item.key); }}
                    className={`px-2 py-1 text-[10px] rounded transition-colors ${
                      messageTypeFilter === item.key
                        ? 'bg-blue-500 text-white'
                        : 'bg-slate-700 text-slate-400 hover:bg-slate-600'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
              {/* 时间筛选 */}
              <div className="flex gap-1 flex-wrap">
                {(['all', 'today', 'yesterday'] as const).map((filter) => (
                  <button
                    key={filter}
                    onClick={() => { setMessageFilter(filter); setShowDatePicker(false); }}
                    className={`px-2 py-1 text-[10px] rounded transition-colors ${
                      messageFilter === filter
                        ? 'bg-green-500 text-white'
                        : 'bg-slate-700 text-slate-400 hover:bg-slate-600'
                    }`}
                  >
                    {filter === 'all' ? '全部' : filter === 'today' ? '今日' : '昨日'}
                  </button>
                ))}
                <button
                  onClick={() => setShowDatePicker(!showDatePicker)}
                  className={`px-2 py-1 text-[10px] rounded transition-colors ${
                    messageFilter === 'custom'
                      ? 'bg-green-500 text-white'
                      : 'bg-slate-700 text-slate-400 hover:bg-slate-600'
                  }`}
                >
                  自定义
                </button>
              </div>
              {/* 自定义日期选择器 */}
              {showDatePicker && (
                <div className="mt-2 flex gap-2 items-center">
                  <input
                    type="date"
                    value={customStartDate}
                    onChange={(e) => setCustomStartDate(e.target.value)}
                    className="px-2 py-1 text-[10px] rounded bg-slate-700 text-white border border-slate-600"
                    title="开始日期"
                    aria-label="开始日期"
                  />
                  <span className="text-[10px] text-slate-400">至</span>
                  <input
                    type="date"
                    value={customEndDate}
                    onChange={(e) => setCustomEndDate(e.target.value)}
                    className="px-2 py-1 text-[10px] rounded bg-slate-700 text-white border border-slate-600"
                    title="结束日期"
                    aria-label="结束日期"
                  />
                  <button
                    onClick={() => {
                      if (customStartDate && customEndDate) {
                        setMessageFilter('custom');
                        loadMessages();
                      } else {
                        toast.warning('请选择日期范围');
                      }
                    }}
                    className="px-2 py-1 text-[10px] rounded bg-green-500 text-white hover:bg-green-600"
                  >
                    确定
                  </button>
                </div>
              )}
            </div>
          )}

          {/* 内容列表 - 带过渡动画和美化滚动条 */}
          <div 
            className="flex-1 overflow-y-auto p-2 space-y-2 transition-opacity duration-200 ease-in-out"
            style={{
              scrollbarWidth: 'thin',
              scrollbarColor: '#475569 #1e293b'
            }}
          >
            {loading ? (
              <div className="flex items-center justify-center h-32 text-slate-500 text-sm">
                加载中...
              </div>
            ) : activeTab === 'task' ? (
              tasks.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-32 text-slate-500">
                  <ListTodo className="w-8 h-8 mb-2 opacity-50" />
                  <span className="text-sm">暂无任务</span>
                </div>
              ) : (
                groupedTasks.map((group) => {
                  const groupKey = `task-${group.key}`;
                  const isExpanded = expandedGroups.has(groupKey);
                  return (
                  <div key={group.key} className="mb-3">
                    {/* 分组标题 - 可点击展开/收起 */}
                    <div 
                      className="flex items-center gap-2 mb-2 px-1 cursor-pointer hover:bg-slate-800/50 rounded py-1 -mx-1 transition-colors"
                      onClick={() => toggleGroup(groupKey)}
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-3 h-3 text-slate-400" />
                      ) : (
                        <ChevronRight className="w-3 h-3 text-slate-400" />
                      )}
                      <span className="w-2 h-2 rounded-full" style={{ backgroundColor: group.color }} />
                      <span className="text-xs font-medium text-slate-400">{group.name}</span>
                      <span className="text-[10px] text-slate-500">({group.items.length})</span>
                    </div>
                    {/* 任务列表 - 可折叠 */}
                    {isExpanded && (
                    <div className="space-y-2">
                      {group.items.map((task) => (
                        <div
                          key={task.id}
                          className="p-3 rounded-lg bg-slate-800 hover:bg-slate-700 transition-colors cursor-pointer"
                          onClick={() => handleTaskClick(task)}
                        >
                          <div className="flex items-start gap-2">
                            <button
                              onClick={(e) => { e.stopPropagation(); handleToggleComplete(task.id, task.status); }}
                              className="mt-0.5 flex-shrink-0"
                            >
                              {task.status === 'completed' ? (
                                <CheckCircle2 className="w-4 h-4 text-green-500" />
                              ) : (
                                <Circle className="w-4 h-4 text-slate-500 hover:text-green-400" />
                              )}
                            </button>
                            <div className="flex-1 min-w-0">
                              <div className="flex items-center gap-1.5 mb-1">
                                <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] ${getPriorityStyle(task.priority)}`}>
                                  {task.priority === 'high' ? <AlertTriangle className="w-3 h-3" /> : <Clock className="w-3 h-3" />}
                                  {task.priority === 'high' ? '紧急' : task.priority === 'medium' ? '普通' : '低'}
                                </span>
                              </div>
                              <p className={`text-xs font-medium text-white truncate ${task.status === 'completed' ? 'line-through opacity-50' : ''}`}>
                                {task.title}
                              </p>
                              <p className="text-[10px] text-slate-500 truncate">
                                {task.project_name || '未关联项目'} · {task.create_time || task.task_date || '无时间'}
                              </p>
                            </div>
                            <ChevronRight className="w-4 h-4 text-slate-500" />
                          </div>
                        </div>
                      ))}
                    </div>
                    )}
                  </div>
                );})
              )
            ) : activeTab === 'project' ? (
              projects.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-32 text-slate-500">
                  <FolderKanban className="w-8 h-8 mb-2 opacity-50" />
                  <span className="text-sm">暂无项目</span>
                </div>
              ) : (
                groupedProjects.map((group) => {
                  const groupKey = `project-${group.key}`;
                  const isExpanded = expandedGroups.has(groupKey);
                  return (
                  <div key={group.key} className="mb-3">
                    {/* 分组标题 - 可点击展开/收起 */}
                    <div 
                      className="flex items-center gap-2 mb-2 px-1 cursor-pointer hover:bg-slate-800/50 rounded py-1 -mx-1 transition-colors"
                      onClick={() => toggleGroup(groupKey)}
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-3 h-3 text-slate-400" />
                      ) : (
                        <ChevronRight className="w-3 h-3 text-slate-400" />
                      )}
                      <span className="w-2 h-2 rounded-full" style={{ backgroundColor: group.color }} />
                      <span className="text-xs font-medium text-slate-400">{group.name}</span>
                      <span className="text-[10px] text-slate-500">({group.items.length})</span>
                    </div>
                    {/* 项目列表 - 可折叠 */}
                    {isExpanded && (
                    <div className="space-y-2">
                      {group.items.map((project) => (
                        <div
                          key={project.id}
                          className="p-3 rounded-lg bg-slate-800 hover:bg-slate-700 transition-colors cursor-pointer"
                          onClick={() => handleProjectClick(project)}
                        >
                          <div className="flex items-center justify-between mb-1">
                            <p className="text-xs font-medium text-white truncate flex-1">{project.project_name}</p>
                            <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                              {/* 打开文件夹 */}
                              <button
                                onClick={(e) => { e.stopPropagation(); handleOpenProjectFolder(project); }}
                                className="p-1 text-slate-400 hover:text-yellow-400 transition-colors"
                                title="打开文件夹"
                              >
                                <FolderOpen className="w-3.5 h-3.5" />
                              </button>
                              {/* 表单入口 */}
                              <button
                                onClick={(e) => handleFormClick(project.id, e)}
                                className="p-1 text-slate-400 hover:text-blue-400 transition-colors"
                                title="查看表单"
                              >
                                <FileText className="w-3.5 h-3.5" />
                              </button>
                              {/* 阶段下拉选择器 */}
                              <select
                                value={project.current_status}
                                onChange={(e) => {
                                  e.stopPropagation();
                                  handleChangeProjectStage(project.id, e.target.value);
                                }}
                                onClick={(e) => e.stopPropagation()}
                                className="text-[10px] bg-slate-700 border border-slate-600 rounded px-1 py-0.5 text-white focus:outline-none"
                              >
                                {PROJECT_STAGES.map((s) => (
                                  <option key={s.key} value={s.key}>{s.name}</option>
                                ))}
                              </select>
                            </div>
                          </div>
                          <p className="text-[10px] text-slate-500 truncate">
                            {project.project_code} · {project.customer_name}
                            {project.customer_group && (
                              <span 
                                className="ml-1 text-indigo-400 cursor-pointer hover:text-indigo-300"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  navigator.clipboard.writeText(project.customer_group || '');
                                  toast.success('已复制客户群名称');
                                }}
                                title="点击复制客户群名称"
                              >
                                · {project.customer_group}
                              </span>
                            )}
                          </p>
                          <div className="flex items-center gap-2 text-[10px]">
                            {project.total_days && (
                              <span className="text-slate-400">
                                周期{project.total_days}天
                              </span>
                            )}
                            {project.remaining_days !== null && (
                              <span className={`${
                                project.remaining_days <= 0 ? 'text-red-500 font-medium' :
                                project.remaining_days <= 3 ? 'text-orange-500' :
                                project.remaining_days <= 7 ? 'text-yellow-600' :
                                'text-green-500'
                              }`}>
                                {project.remaining_days <= 0 
                                  ? `${project.stage_name}逾期${Math.abs(project.remaining_days)}天` 
                                  : `${project.stage_name}剩${project.remaining_days}天`}
                              </span>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                    )}
                  </div>
                );})
              )
            ) : activeTab === 'message' ? (
              messages.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-32 text-slate-500">
                  <MessageSquare className="w-8 h-8 mb-2 opacity-50" />
                  <span className="text-sm">暂无消息</span>
                </div>
              ) : (
                groupedMessages.map((group) => {
                  const groupKey = `message-${group.key}`;
                  const isExpanded = expandedGroups.has(groupKey);
                  return (
                  <div key={group.key} className="mb-3">
                    {/* 分组标题 - 可点击展开/收起 */}
                    <div 
                      className="flex items-center gap-2 mb-2 px-1 cursor-pointer hover:bg-slate-800/50 rounded py-1 -mx-1 transition-colors"
                      onClick={() => toggleGroup(groupKey)}
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-3 h-3 text-slate-400" />
                      ) : (
                        <ChevronRight className="w-3 h-3 text-slate-400" />
                      )}
                      <span className="w-2 h-2 rounded-full" style={{ backgroundColor: group.color }} />
                      <span className="text-xs font-medium text-slate-400">{group.name}</span>
                      <span className="text-[10px] text-slate-500">({group.items.length})</span>
                      {group.items.some(m => !m.is_read) && (
                        <span className="w-2 h-2 rounded-full bg-red-500" />
                      )}
                    </div>
                    {/* 消息列表 - 可折叠 */}
                    {isExpanded && (
                    <div className="space-y-2">
                      {group.items.map((msg) => (
                  <div
                    key={msg.id}
                    className={`p-3 rounded-lg border-l-2 cursor-pointer hover:bg-slate-700 transition-colors ${
                      msg.type === 'form' 
                        ? (msg.form_type === 'requirement' ? 'border-blue-500' : 'border-purple-500')
                        : 'border-slate-600'
                    } ${!msg.is_read ? 'bg-slate-800' : 'bg-slate-800/50'} ${
                      messageSelectMode && selectedMessages.has(msg.id) ? 'ring-2 ring-red-500' : ''
                    }`}
                    onClick={() => {
                      if (messageSelectMode) {
                        toggleMessageSelection(msg.id);
                      }
                    }}
                  >
                    <div className="flex items-center justify-between mb-1">
                      <div 
                        className="flex items-center gap-2 flex-1 cursor-pointer"
                        onClick={() => {
                          if (messageSelectMode) return;
                          // 表单消息：先打开主窗口，再打开表单详情
                          if (msg.type === 'form' && msg.data?.form_id) {
                            openMainWindowAndNavigate(() => requestOpenFormDetail(0, msg.data!.form_id!));
                          }
                          // 任务消息：打开任务详情
                          else if (msg.type === 'task' && (msg.data as any)?.task_id) {
                            openMainWindowAndNavigate(() => requestOpenTaskDetail((msg.data as any).task_id));
                          }
                          // 项目消息：打开项目详情
                          else if (msg.type === 'project' && msg.data?.project_code) {
                            // 需要根据 project_code 找到项目 ID，或直接使用 project_code
                            // 这里假设可以通过 project_code 导航，需要查看 requestOpenProjectDetail 的实现
                            // 暂时先尝试通过 project_code 导航
                            const project = projects.find(p => p.project_code === msg.data?.project_code);
                            if (project) {
                              openMainWindowAndNavigate(() => requestOpenProjectDetail(project.id));
                            }
                          }
                        }}
                      >
                        {messageSelectMode && (
                          <input
                            type="checkbox"
                            checked={selectedMessages.has(msg.id)}
                            onChange={() => toggleMessageSelection(msg.id)}
                            className="w-3 h-3 rounded"
                            onClick={(e) => e.stopPropagation()}
                          />
                        )}
                        {msg.type === 'form' && (
                          <FileText className={`w-3 h-3 ${msg.form_type === 'requirement' ? 'text-blue-400' : 'text-purple-400'}`} />
                        )}
                        <span className="text-xs font-medium text-white">{msg.title}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        {msg.status && (
                          <span className={`text-[10px] px-1.5 py-0.5 rounded ${
                            msg.status === '待沟通' ? 'bg-yellow-500/20 text-yellow-400' :
                            msg.status === '沟通中' ? 'bg-blue-500/20 text-blue-400' :
                            'bg-green-500/20 text-green-400'
                          }`}>
                            {msg.status}
                          </span>
                        )}
                        {!messageSelectMode && !msg.is_read && (
                          <button
                            onClick={(e) => { e.stopPropagation(); handleMarkMessageRead(msg.id); }}
                            className="w-5 h-5 rounded-full bg-blue-500 hover:bg-blue-600 flex items-center justify-center transition-colors"
                            title="标记已读"
                          >
                            <Check className="w-3 h-3 text-white" />
                          </button>
                        )}
                        {!messageSelectMode && (
                          <button
                            onClick={(e) => { e.stopPropagation(); handleDeleteMessage(msg.id); }}
                            className="w-5 h-5 rounded-full bg-slate-600 hover:bg-red-500 flex items-center justify-center transition-colors"
                            title="删除"
                          >
                            <X className="w-3 h-3 text-white" />
                          </button>
                        )}
                      </div>
                    </div>
                    <p className="text-[10px] text-slate-400 line-clamp-2">{msg.content}</p>
                    {(msg.full_time || msg.time) && (
                      <p className="text-[10px] text-slate-500 mt-1" title={msg.full_time}>
                        {msg.full_time || msg.time}
                      </p>
                    )}
                  </div>
                      ))}
                    </div>
                    )}
                  </div>
                );})
              )
            ) : activeTab === 'settings' ? (
              <div className="p-3 space-y-4">
                {/* 悬浮图标大小 */}
                <div className="bg-slate-800 rounded-lg p-3">
                  <h3 className="text-xs font-medium text-white mb-3">悬浮图标大小</h3>
                  <div className="flex items-center gap-3">
                    <input
                      type="range"
                      min="32"
                      max="128"
                      step="8"
                      value={floatingIconSize}
                      onChange={(e) => handleFloatingIconSizeChange(parseInt(e.target.value))}
                      className="flex-1 h-2 rounded-lg appearance-none cursor-pointer bg-slate-700"
                    />
                    <span className="text-xs text-white w-12 text-center">{floatingIconSize}px</span>
                    <div 
                      className="rounded-xl flex items-center justify-center bg-indigo-500"
                      style={{ 
                        width: Math.min(floatingIconSize, 40), 
                        height: Math.min(floatingIconSize, 40),
                      }}
                    >
                      <MiniModeIcon />
                    </div>
                  </div>
                  <p className="text-[10px] text-slate-500 mt-2">切换到悬浮模式时的图标大小 (32-128像素)</p>
                </div>
                
                {/* 通知设置 */}
                <div className="bg-slate-800 rounded-lg p-3">
                  <h3 className="text-xs font-medium text-white mb-3">🔔 通知设置</h3>
                  <div className="space-y-2">
                    <label className="flex items-center justify-between cursor-pointer">
                      <span className="text-xs text-slate-300">启用桌面通知</span>
                      <input
                        type="checkbox"
                        checked={enableNotification}
                        onChange={(e) => handleNotificationToggle(e.target.checked)}
                        className="w-4 h-4 rounded bg-slate-700 border-slate-600 text-blue-500 focus:ring-blue-500"
                      />
                    </label>
                    <label className="flex items-center justify-between cursor-pointer">
                      <span className="text-xs text-slate-300">启用提示音</span>
                      <input
                        type="checkbox"
                        checked={enableNotificationSound}
                        onChange={(e) => handleNotificationSoundToggle(e.target.checked)}
                        className="w-4 h-4 rounded bg-slate-700 border-slate-600 text-blue-500 focus:ring-blue-500"
                      />
                    </label>
                  </div>
                  <p className="text-[10px] text-slate-500 mt-2">有新消息时在屏幕右下角弹窗提醒</p>
                </div>
                
                {/* 自动刷新状态 */}
                <div className="bg-slate-800 rounded-lg p-3">
                  <h3 className="text-xs font-medium text-white mb-3">数据刷新</h3>
                  <div className="flex items-center gap-2">
                    <RefreshCw className="w-4 h-4 text-green-400" />
                    <span className="text-xs text-slate-300">每 10 秒自动刷新</span>
                    <span className="text-xs text-slate-500">({refreshCountdown}s)</span>
                  </div>
                </div>
                
                {/* 服务器信息 */}
                <div className="bg-slate-800 rounded-lg p-3">
                  <h3 className="text-xs font-medium text-white mb-2">服务器</h3>
                  <p className="text-[10px] text-slate-400 break-all">{serverUrl}</p>
                </div>
                
                {/* 版本信息 */}
                <div className="bg-slate-800 rounded-lg p-3">
                  <h3 className="text-xs font-medium text-white mb-2">版本</h3>
                  <p className="text-[10px] text-slate-400">v1.7.7</p>
                </div>
              </div>
            ) : null}
          </div>
        </div>

        {/* 右侧标签栏 */}
        <div className="w-14 bg-slate-800 flex flex-col items-center py-2 border-l border-slate-700">
          {SIDEBAR_TABS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`w-12 h-14 flex flex-col items-center justify-center gap-1 rounded-lg transition-colors mb-1 ${
                activeTab === tab.id
                  ? 'bg-blue-500 text-white'
                  : 'text-slate-400 hover:text-white hover:bg-slate-700'
              }`}
            >
              <tab.icon className="w-5 h-5" />
              <span className="text-[10px]">{tab.label}</span>
            </button>
          ))}

          {/* 分隔线 */}
          <div className="flex-1" />

          {/* 新增按钮 */}
          <button
            onClick={() => setShowNewTask(true)}
            className="w-12 h-14 flex flex-col items-center justify-center gap-1 rounded-lg text-blue-400 hover:text-white hover:bg-blue-500 transition-colors"
          >
            <Plus className="w-5 h-5" />
            <span className="text-[10px]">新增</span>
          </button>
          
          {/* 分配任务按钮 (管理员) */}
          {teamMembers.length > 0 && (
            <button
              onClick={() => setShowAssignTask(true)}
              className="w-12 h-14 flex flex-col items-center justify-center gap-1 rounded-lg text-green-400 hover:text-white hover:bg-green-500 transition-colors"
            >
              <UserPlus className="w-5 h-5" />
              <span className="text-[10px]">分配</span>
            </button>
          )}
        </div>
      </div>

      {/* 底部状态栏 */}
      <div className="h-7 bg-slate-800 border-t border-slate-700 flex items-center justify-between px-3 text-[10px] text-slate-500">
        <span>{user?.username || '未登录'}</span>
        <div className="flex items-center gap-2">
          <span className="flex items-center gap-1">
            <RefreshCw className="w-3 h-3" />
            {refreshCountdown}s
          </span>
          <span>v1.3.5</span>
        </div>
      </div>

      {/* 新建任务弹窗 */}
      {showNewTask && (
        <div className="absolute inset-0 bg-black/60 flex items-center justify-center z-20">
          <div className="bg-slate-800 rounded-lg shadow-xl w-[280px] p-4 max-h-[90%] overflow-y-auto">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium text-white">新建任务</span>
              <button onClick={() => setShowNewTask(false)} className="text-slate-400 hover:text-white">
                <X className="w-4 h-4" />
              </button>
            </div>
            
            {/* 任务标题 */}
            <input
              type="text"
              value={newTaskTitle}
              onChange={(e) => setNewTaskTitle(e.target.value)}
              placeholder="任务标题 *"
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-blue-500 mb-3"
              autoFocus
            />
            
            {/* 关联项目 */}
            <button
              type="button"
              onClick={() => setShowProjectSelector(true)}
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-left hover:bg-slate-600 transition-colors mb-3 flex items-center justify-between"
            >
              <span className={newTaskProjectId ? 'text-white' : 'text-slate-400'}>
                {newTaskProjectName || '选择关联项目（可选）'}
              </span>
              <ChevronRight className="w-4 h-4 text-slate-400" />
            </button>
            
            {/* 截止日期 */}
            <input
              type="date"
              value={newTaskDate}
              onChange={(e) => setNewTaskDate(e.target.value)}
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:ring-1 focus:ring-blue-500 mb-3"
            />
            
            {/* 优先级 */}
            <select
              value={newTaskPriority}
              onChange={(e) => setNewTaskPriority(e.target.value as 'high' | 'medium' | 'low')}
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:ring-1 focus:ring-blue-500 mb-3"
            >
              <option value="low">低优先级</option>
              <option value="medium">中优先级</option>
              <option value="high">高优先级</option>
            </select>
            
            {/* 需要协助 */}
            <label className="flex items-center gap-2 text-sm text-slate-300 mb-4 cursor-pointer">
              <input
                type="checkbox"
                checked={newTaskNeedHelp}
                onChange={(e) => setNewTaskNeedHelp(e.target.checked)}
                className="w-4 h-4 rounded border-slate-600 bg-slate-700 text-blue-500 focus:ring-blue-500"
              />
              需要协助
            </label>
            
            <div className="flex gap-2">
              <button
                onClick={() => setShowNewTask(false)}
                className="flex-1 py-2 text-sm text-slate-400 hover:text-white bg-slate-700 rounded transition-colors"
              >
                取消
              </button>
              <button
                onClick={handleCreateTask}
                disabled={submitting || !newTaskTitle.trim()}
                className="flex-1 py-2 text-sm text-white bg-blue-500 hover:bg-blue-600 rounded transition-colors disabled:opacity-50"
              >
                {submitting ? '创建中...' : '创建'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 分配任务弹窗 */}
      {showAssignTask && (
        <div className="absolute inset-0 bg-black/60 flex items-center justify-center z-20">
          <div className="bg-slate-800 rounded-lg shadow-xl w-[280px] p-4">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium text-white">分配任务</span>
              <button onClick={() => setShowAssignTask(false)} className="text-slate-400 hover:text-white">
                <X className="w-4 h-4" />
              </button>
            </div>
            
            {/* 任务标题 */}
            <input
              type="text"
              value={assignTaskTitle}
              onChange={(e) => setAssignTaskTitle(e.target.value)}
              placeholder="任务标题 *"
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-green-500 mb-3"
              autoFocus
            />
            
            {/* 选择成员 */}
            <select
              value={assignTaskUserId || ''}
              onChange={(e) => setAssignTaskUserId(e.target.value ? Number(e.target.value) : null)}
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:ring-1 focus:ring-green-500 mb-3"
            >
              <option value="">选择成员 *</option>
              {teamMembers.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
            
            {/* 截止日期 */}
            <input
              type="date"
              value={assignTaskDate}
              onChange={(e) => setAssignTaskDate(e.target.value)}
              className="w-full px-3 py-2 text-sm bg-slate-700 border border-slate-600 rounded text-white focus:outline-none focus:ring-1 focus:ring-green-500 mb-3"
            />
            
            <div className="flex gap-2">
              <button
                onClick={() => setShowAssignTask(false)}
                className="flex-1 py-2 text-sm text-slate-400 hover:text-white bg-slate-700 rounded transition-colors"
              >
                取消
              </button>
              <button
                onClick={handleAssignTask}
                disabled={submitting || !assignTaskTitle.trim() || !assignTaskUserId}
                className="flex-1 py-2 text-sm text-white bg-green-500 hover:bg-green-600 rounded transition-colors disabled:opacity-50"
              >
                {submitting ? '分配中...' : '分配'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Toast 通知 */}
      <ToastContainer />
      
      {/* 独立弹窗通知（截止日期提醒等） */}
      <PopupNotificationContainer />

      {/* 项目选择器 */}
      <FloatingProjectSelector
        open={showProjectSelector}
        onClose={() => setShowProjectSelector(false)}
        value={newTaskProjectId}
        onChange={(projectId, project) => {
          setNewTaskProjectId(projectId);
          setNewTaskProjectName(project?.project_name || '');
        }}
      />
    </div>
  );
}

// 悬浮球图标组件 - 使用外部 SVG 文件（支持拖动）
function MiniModeIcon() {
  return (
    <img 
      src="/floating-icon.svg" 
      alt="悬浮图标"
      style={{ 
        width: '100%', 
        height: '100%', 
        display: 'block', 
        borderRadius: '22%',
        pointerEvents: 'none',
      }}
      draggable={false}
    />
  );
}
