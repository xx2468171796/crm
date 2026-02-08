import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { DollarSign, TrendingUp, Calendar, Users, ExternalLink } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { isManager as checkIsManager, canViewFinance } from '@/lib/utils';

interface CommissionItem {
  id: number;
  project_id: number;
  project_name: string;
  customer_name: string;
  amount: number;
  status: 'pending' | 'confirmed' | 'paid';
  created_at: string;
}

interface FinanceStats {
  lastMonth: number;
  thisMonth: number;
  pending: number;
  total: number;
  filteredTotal?: number; // 根据筛选条件计算的合计
}

interface TeamMember {
  id: number;
  name: string;
  thisMonth: number;
}

type TimeRange = 'last_month' | 'this_month' | 'custom';

export default function FinancePage() {
  const navigate = useNavigate();
  const { user, token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [commissions, setCommissions] = useState<CommissionItem[]>([]);
  const [stats, setStats] = useState<FinanceStats>({
    lastMonth: 0,
    thisMonth: 0,
    pending: 0,
    total: 0,
  });
  const [teamMembers, setTeamMembers] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [timeRange, setTimeRange] = useState<TimeRange>('this_month');
  const [selectedMember, setSelectedMember] = useState<number | null>(null);
  const [customStartDate, setCustomStartDate] = useState<string>('');
  const [customEndDate, setCustomEndDate] = useState<string>('');

  const isManager = checkIsManager(user?.role);
  
  // 权限检查：design_manager 不能访问财务页面
  useEffect(() => {
    if (!canViewFinance(user?.role)) {
      navigate('/dashboard');
    }
  }, [user?.role, navigate]);

  useEffect(() => {
    // 自定义时间范围需要选择日期后才加载
    if (timeRange === 'custom' && (!customStartDate || !customEndDate)) {
      return;
    }
    loadFinance();
  }, [serverUrl, token, timeRange, selectedMember, customStartDate, customEndDate]);

  const loadFinance = async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const params = new URLSearchParams();
      params.append('range', timeRange);
      if (selectedMember) params.append('user_id', String(selectedMember));
      if (timeRange === 'custom' && customStartDate && customEndDate) {
        params.append('start_date', customStartDate);
        params.append('end_date', customEndDate);
      }

      const response = await fetch(`${serverUrl}/api/desktop_finance.php?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setStats(data.data.stats || { lastMonth: 0, thisMonth: 0, pending: 0, total: 0 });
        setCommissions(data.data.items || []);
        if (data.data.team_members) {
          setTeamMembers(data.data.team_members);
        }
      }
    } catch (error) {
      console.error('加载财务数据失败:', error);
    } finally {
      setLoading(false);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return <span className="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700">已发放</span>;
      case 'confirmed':
        return <span className="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700">已确认</span>;
      default:
        return <span className="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700">待确认</span>;
    }
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <DollarSign className="w-6 h-6 text-green-500" />
            <h1 className="text-lg font-semibold text-gray-800">
              {isManager ? '团队提成' : '我的提成'}
            </h1>
          </div>
          <div className="flex items-center gap-3">
            {/* 技术主管：成员筛选 */}
            {isManager && teamMembers.length > 0 && (
              <select
                value={selectedMember || ''}
                onChange={(e) => setSelectedMember(e.target.value ? Number(e.target.value) : null)}
                className="px-3 py-1.5 border rounded-lg bg-white text-gray-700 text-sm"
              >
                <option value="">全部成员</option>
                {teamMembers.map((member) => (
                  <option key={member.id} value={member.id}>
                    {member.name}
                  </option>
                ))}
              </select>
            )}
            {/* 时间筛选 */}
            <div className="flex items-center gap-2">
              <div className="flex border rounded-lg overflow-hidden">
                {([
                  { key: 'last_month', label: '上月' },
                  { key: 'this_month', label: '本月' },
                  { key: 'custom', label: '自定义' },
                ] as const).map((item) => (
                  <button
                    key={item.key}
                    onClick={() => setTimeRange(item.key)}
                    className={`px-4 py-1.5 text-sm ${
                      timeRange === item.key
                        ? 'bg-green-500 text-white'
                        : 'bg-white text-gray-600 hover:bg-gray-50'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
              {/* 自定义日期选择器 */}
              {timeRange === 'custom' && (
                <div className="flex items-center gap-2 ml-2">
                  <input
                    type="date"
                    value={customStartDate}
                    onChange={(e) => setCustomStartDate(e.target.value)}
                    className="px-2 py-1.5 border rounded-lg text-sm"
                  />
                  <span className="text-gray-500">至</span>
                  <input
                    type="date"
                    value={customEndDate}
                    onChange={(e) => setCustomEndDate(e.target.value)}
                    className="px-2 py-1.5 border rounded-lg text-sm"
                  />
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* 内容 */}
      <div className="flex-1 overflow-auto p-6">
        {/* 统计卡片 */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-500">上月提成</p>
                <p className="text-2xl font-bold text-gray-800 mt-1">
                  ¥{loading ? '-' : stats.lastMonth.toLocaleString()}
                </p>
              </div>
              <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                <Calendar className="w-5 h-5 text-gray-500" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-500">本月提成</p>
                <p className="text-2xl font-bold text-green-600 mt-1">
                  ¥{loading ? '-' : stats.thisMonth.toLocaleString()}
                </p>
              </div>
              <div className="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                <TrendingUp className="w-5 h-5 text-green-500" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-500">待发放</p>
                <p className="text-2xl font-bold text-yellow-600 mt-1">
                  ¥{loading ? '-' : stats.pending.toLocaleString()}
                </p>
              </div>
              <div className="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center">
                <DollarSign className="w-5 h-5 text-yellow-500" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-xl p-5 border border-gray-100 shadow-sm">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-500">
                  {timeRange === 'custom' && customStartDate && customEndDate 
                    ? `${customStartDate} ~ ${customEndDate} 合计`
                    : timeRange === 'last_month' ? '上月合计' 
                    : timeRange === 'this_month' ? '本月合计' 
                    : '累计总额'}
                </p>
                <p className="text-2xl font-bold text-blue-600 mt-1">
                  ¥{loading ? '-' : (stats.filteredTotal ?? stats.total).toLocaleString()}
                </p>
              </div>
              <div className="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <DollarSign className="w-5 h-5 text-blue-500" />
              </div>
            </div>
          </div>
        </div>

        {/* 提成明细 */}
        <div className="bg-white rounded-xl border overflow-hidden">
          <div className="px-5 py-4 border-b bg-gray-50">
            <h3 className="font-semibold text-gray-800">提成明细</h3>
          </div>
          {loading ? (
            <div className="flex items-center justify-center h-48 text-gray-400">
              加载中...
            </div>
          ) : commissions.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-48 text-gray-400">
              <DollarSign className="w-12 h-12 mb-4 opacity-50" />
              <p>暂无提成记录</p>
            </div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    项目
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    客户
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    金额
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    状态
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                    时间
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {commissions.map((item) => (
                  <tr 
                    key={item.id} 
                    className="hover:bg-gray-50 cursor-pointer group"
                    onClick={() => navigate(`/project/${item.project_id}`)}
                  >
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-gray-800 group-hover:text-indigo-600">
                          {item.project_name}
                        </span>
                        <ExternalLink className="w-3 h-3 text-gray-300 group-hover:text-indigo-500" />
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {item.customer_name}
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-sm font-semibold text-green-600">
                        ¥{item.amount.toLocaleString()}
                      </span>
                    </td>
                    <td className="px-4 py-3">{getStatusBadge(item.status)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">
                      {item.created_at}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* 技术主管：团队成员提成 */}
        {isManager && teamMembers.length > 0 && (
          <div className="mt-6 bg-white rounded-xl border overflow-hidden">
            <div className="px-5 py-4 border-b bg-gray-50 flex items-center gap-2">
              <Users className="w-5 h-5 text-gray-500" />
              <h3 className="font-semibold text-gray-800">团队成员本月提成</h3>
            </div>
            <div className="p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
              {teamMembers.map((member) => (
                <div
                  key={member.id}
                  className="p-4 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors"
                  onClick={() => setSelectedMember(member.id)}
                >
                  <p className="font-medium text-gray-800">{member.name}</p>
                  <p className="text-lg font-semibold text-green-600 mt-1">
                    ¥{member.thisMonth.toLocaleString()}
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
