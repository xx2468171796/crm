import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { ListTodo, Kanban, FileCheck, DollarSign, TrendingUp, AlertTriangle } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { isManager as checkIsManager } from '@/lib/utils';

interface DashboardStats {
  todayTasks: number;
  activeProjects: number;
  pendingApprovals: number;
  monthCommission: number;
  urgentTasks: number;
  completedToday: number;
}

interface TeamMember {
  id: number;
  name: string;
  todayTasks: number;
  completedTasks: number;
}

export default function DashboardPage() {
  const navigate = useNavigate();
  const { user, token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [stats, setStats] = useState<DashboardStats>({
    todayTasks: 0,
    activeProjects: 0,
    pendingApprovals: 0,
    monthCommission: 0,
    urgentTasks: 0,
    completedToday: 0,
  });
  const [teamMembers, setTeamMembers] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedMember, setSelectedMember] = useState<number | null>(null);

  const isManager = checkIsManager(user?.role);

  useEffect(() => {
    loadDashboard();
  }, [serverUrl, token, selectedMember]);

  const loadDashboard = async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (selectedMember) params.append('user_id', String(selectedMember));
      
      const response = await fetch(`${serverUrl}/api/desktop_dashboard.php?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setStats(data.data.stats || stats);
        if (data.data.team_members) {
          setTeamMembers(data.data.team_members);
        }
      }
    } catch (error) {
      console.error('加载仪表盘失败:', error);
    } finally {
      setLoading(false);
    }
  };

  // 根据角色显示不同的统计卡片
  const statCards = [
    { label: '今日任务', value: stats.todayTasks, icon: ListTodo, color: 'bg-blue-500', path: '/tasks' },
    { label: '进行中项目', value: stats.activeProjects, icon: Kanban, color: 'bg-purple-500', path: '/project-kanban' },
    // 管理员才能看到待审批作品
    ...(isManager ? [{ label: '待审批作品', value: stats.pendingApprovals, icon: FileCheck, color: 'bg-orange-500', path: '/approval' }] : []),
    // 本月提成
    { label: '本月提成', value: `¥${stats.monthCommission.toLocaleString()}`, icon: DollarSign, color: 'bg-green-500', path: '/finance' },
  ];

  return (
    <div className="flex-1 p-6 overflow-auto bg-gray-50">
      <div className="max-w-6xl mx-auto">
        {/* 页面标题 */}
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">
              {isManager ? '团队总览' : '工作总览'}
            </h1>
            <p className="text-gray-500 mt-1">
              欢迎回来，{user?.name || user?.username}
            </p>
          </div>
          
          {/* 技术主管：成员筛选 */}
          {isManager && teamMembers.length > 0 && (
            <select
              value={selectedMember || ''}
              onChange={(e) => setSelectedMember(e.target.value ? Number(e.target.value) : null)}
              className="px-4 py-2 border rounded-lg bg-white text-gray-700"
            >
              <option value="">全部成员</option>
              {teamMembers.map((member) => (
                <option key={member.id} value={member.id}>
                  {member.name}
                </option>
              ))}
            </select>
          )}
        </div>

        {/* 统计卡片 */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          {statCards.map((card, index) => (
            <div
              key={index}
              onClick={() => navigate(card.path)}
              className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm hover:shadow-md transition-shadow cursor-pointer"
            >
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-500">{card.label}</p>
                  <p className="text-2xl font-bold text-gray-800 mt-1">
                    {loading ? '-' : card.value}
                  </p>
                </div>
                <div className={`w-12 h-12 rounded-xl ${card.color} flex items-center justify-center`}>
                  <card.icon className="w-6 h-6 text-white" />
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* 快速状态 */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* 紧急任务 */}
          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 mb-4">
              <AlertTriangle className="w-5 h-5 text-red-500" />
              <h3 className="font-semibold text-gray-800">紧急任务</h3>
              <span className="px-2 py-0.5 bg-red-100 text-red-600 rounded-full text-xs font-medium">
                {stats.urgentTasks}
              </span>
            </div>
            {loading ? (
              <div className="text-gray-400 text-center py-8">加载中...</div>
            ) : stats.urgentTasks === 0 ? (
              <div className="text-gray-400 text-center py-8">暂无紧急任务</div>
            ) : (
              <div className="text-gray-500 text-center py-8">
                有 {stats.urgentTasks} 个紧急任务待处理
              </div>
            )}
          </div>

          {/* 今日进度 */}
          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center gap-2 mb-4">
              <TrendingUp className="w-5 h-5 text-green-500" />
              <h3 className="font-semibold text-gray-800">今日进度</h3>
            </div>
            <div className="flex items-center justify-center py-4">
              <div className="text-center">
                <div className="text-4xl font-bold text-gray-800">
                  {loading ? '-' : stats.completedToday}
                  <span className="text-lg text-gray-400">/{stats.todayTasks}</span>
                </div>
                <p className="text-gray-500 mt-1">任务完成</p>
              </div>
            </div>
            {stats.todayTasks > 0 && (
              <div className="mt-4">
                <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-green-500 rounded-full transition-all"
                    style={{
                      width: `${Math.round((stats.completedToday / stats.todayTasks) * 100)}%`,
                    }}
                  />
                </div>
                <p className="text-xs text-gray-400 mt-1 text-right">
                  {Math.round((stats.completedToday / stats.todayTasks) * 100)}%
                </p>
              </div>
            )}
          </div>
        </div>

        {/* 技术主管：团队成员列表 */}
        {isManager && teamMembers.length > 0 && (
          <div className="mt-6 bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <h3 className="font-semibold text-gray-800 mb-4">团队成员</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {teamMembers.map((member) => (
                <div
                  key={member.id}
                  className="p-4 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors"
                  onClick={() => setSelectedMember(member.id)}
                >
                  <p className="font-medium text-gray-800">{member.name}</p>
                  <p className="text-sm text-gray-500">
                    {member.completedTasks}/{member.todayTasks} 任务
                  </p>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
