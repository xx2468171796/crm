import { useState, useEffect } from 'react';
import { Users, CheckCircle, Clock, AlertCircle, Folder, RefreshCw, Plus, X } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

type ViewType = 'today' | 'yesterday' | 'future' | 'help' | 'all';

interface Task {
  id: number;
  title: string;
  task_date: string;
  status: string;
  project_id: number | null;
  need_help: number;
  assigned_by: number | null;
  project_name: string | null;
  project_code: string | null;
}

interface TeamMember {
  user_id: number;
  user_name: string;
  role: string;
  task_count: number;
  completed_count: number;
  tasks: Task[];
}

export default function TeamProgressPage() {
  const { token, user } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [currentView, setCurrentView] = useState<ViewType>('today');
  const [expandedMember, setExpandedMember] = useState<number | null>(null);
  const [showAssignModal, setShowAssignModal] = useState(false);
  const [assignToUserId, setAssignToUserId] = useState<number | null>(null);
  const [assignTaskTitle, setAssignTaskTitle] = useState('');
  const [assignTaskDate, setAssignTaskDate] = useState(new Date().toISOString().split('T')[0]);
  const [submitting, setSubmitting] = useState(false);

  const views: { key: ViewType; label: string }[] = [
    { key: 'today', label: '今天' },
    { key: 'yesterday', label: '昨天' },
    { key: 'future', label: '未来' },
    { key: 'help', label: '需协助' },
    { key: 'all', label: '全部' },
  ];

  const loadTeamTasks = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_team_tasks.php?view=${currentView}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setMembers(data.data.members || []);
      }
    } catch (error) {
      console.error('加载团队任务失败:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (serverUrl && token) {
      loadTeamTasks();
    }
  }, [currentView, serverUrl, token]);

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'completed': return <CheckCircle className="text-green-500" size={14} />;
      case 'in_progress': return <Clock className="text-blue-500" size={14} />;
      default: return <AlertCircle className="text-gray-400" size={14} />;
    }
  };

  // 主管分配任务给成员
  const handleAssignTask = async () => {
    if (!serverUrl || !token || !assignTaskTitle.trim() || !assignToUserId) return;
    setSubmitting(true);
    try {
      const res = await fetch(`${serverUrl}/api/desktop_daily_tasks.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          title: assignTaskTitle.trim(),
          task_date: assignTaskDate,
          user_id: assignToUserId, // 指定任务所属用户
          assigned_by: user?.id, // 当前主管分配
        }),
      });
      const data = await res.json();
      if (data.success) {
        setShowAssignModal(false);
        setAssignTaskTitle('');
        setAssignToUserId(null);
        setAssignTaskDate(new Date().toISOString().split('T')[0]);
        loadTeamTasks();
      }
    } catch (error) {
      console.error('分配任务失败:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const openAssignModal = (userId: number) => {
    setAssignToUserId(userId);
    setShowAssignModal(true);
  };

  const isManager = ['admin', 'super_admin', 'manager', 'tech_manager'].includes(user?.role || '');
  
  if (!isManager) {
    return (
      <div className="flex-1 flex items-center justify-center bg-gray-50">
        <div className="text-center text-gray-500">
          <Users size={48} className="mx-auto mb-4 opacity-50" />
          <p>仅主管和管理员可查看团队任务</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Users size={24} className="text-blue-500" />
            <h1 className="text-xl font-semibold text-gray-800">团队任务看板</h1>
          </div>
          <div className="flex items-center gap-2">
            {views.map((view) => (
              <button
                key={view.key}
                onClick={() => setCurrentView(view.key)}
                className={`px-3 py-1.5 text-sm rounded-lg transition-colors ${
                  currentView === view.key
                    ? 'bg-blue-500 text-white'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
              >
                {view.label}
              </button>
            ))}
            <button
              onClick={loadTeamTasks}
              className="p-2 text-gray-500 hover:bg-gray-100 rounded-lg"
              title="刷新"
            >
              <RefreshCw size={18} className={loading ? 'animate-spin' : ''} />
            </button>
          </div>
        </div>
      </div>

      {/* 成员列表 */}
      <div className="flex-1 overflow-y-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-64 text-gray-400">加载中...</div>
        ) : members.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-400">
            <Users size={48} className="mb-4 opacity-50" />
            <p>暂无团队成员数据</p>
          </div>
        ) : (
          <div className="space-y-4">
            {members.filter(m => m.task_count > 0 || currentView === 'all').map((member) => (
              <div key={member.user_id} className="bg-white rounded-lg border overflow-hidden">
                {/* 成员头部 */}
                <div
                  className="p-4 cursor-pointer hover:bg-gray-50 transition-colors"
                  onClick={() => setExpandedMember(expandedMember === member.user_id ? null : member.user_id)}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-medium">
                        {member.user_name.charAt(0)}
                      </div>
                      <div>
                        <h3 className="font-medium text-gray-800">{member.user_name}</h3>
                        <div className="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
                          <span className="px-1.5 py-0.5 bg-gray-100 rounded">{member.role}</span>
                          <span>共 {member.task_count} 个任务</span>
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-4 text-sm">
                      <div className="flex items-center gap-1">
                        <CheckCircle size={14} className="text-green-500" />
                        <span>{member.completed_count}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        <Clock size={14} className="text-blue-500" />
                        <span>{member.task_count - member.completed_count}</span>
                      </div>
                      <button
                        onClick={(e) => { e.stopPropagation(); openAssignModal(member.user_id); }}
                        className="p-1.5 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded"
                        title={`给 ${member.user_name} 分配任务`}
                      >
                        <Plus size={16} />
                      </button>
                    </div>
                  </div>
                  
                  {/* 进度条 */}
                  {member.task_count > 0 && (
                    <div className="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden">
                      <div
                        className="bg-green-500 h-full transition-all"
                        style={{ width: `${(member.completed_count / member.task_count) * 100}%` }}
                      />
                    </div>
                  )}
                </div>

                {/* 任务详情 */}
                {expandedMember === member.user_id && member.tasks.length > 0 && (
                  <div className="border-t bg-gray-50 p-4">
                    <div className="space-y-2">
                      {member.tasks.map((task) => (
                        <div key={task.id} className="flex items-center gap-3 py-2 px-3 bg-white rounded hover:bg-gray-50 cursor-pointer">
                          {getStatusIcon(task.status)}
                          <span className="flex-1 text-sm">{task.title}</span>
                          {task.project_name && (
                            <span className="flex items-center gap-1 text-xs text-gray-400">
                              <Folder size={12} />
                              {task.project_name}
                            </span>
                          )}
                          <span className="text-xs text-gray-400">{task.task_date}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* 分配任务弹窗 */}
      {showAssignModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-4 w-80 shadow-xl">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-medium text-gray-800">
                分配任务给 {members.find(m => m.user_id === assignToUserId)?.user_name}
              </h3>
              <button onClick={() => setShowAssignModal(false)} className="text-gray-400 hover:text-gray-600">
                <X size={18} />
              </button>
            </div>
            <input
              type="text"
              value={assignTaskTitle}
              onChange={(e) => setAssignTaskTitle(e.target.value)}
              placeholder="任务标题"
              className="w-full px-3 py-2 text-sm border rounded mb-2 focus:outline-none focus:ring-1 focus:ring-blue-500"
              autoFocus
            />
            <input
              type="date"
              value={assignTaskDate}
              onChange={(e) => setAssignTaskDate(e.target.value)}
              className="w-full px-3 py-2 text-sm border rounded mb-3 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <div className="flex gap-2">
              <button
                onClick={() => setShowAssignModal(false)}
                className="flex-1 py-2 text-sm bg-gray-100 text-gray-600 rounded hover:bg-gray-200"
              >
                取消
              </button>
              <button
                onClick={handleAssignTask}
                disabled={!assignTaskTitle.trim() || submitting}
                className="flex-1 py-2 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
              >
                {submitting ? '分配中...' : '分配'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
