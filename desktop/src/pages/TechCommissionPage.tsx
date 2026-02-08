import React, { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { DollarSign, Users, FolderKanban, Download, ChevronDown, ChevronUp, Filter, X, ExternalLink } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useToast } from '@/hooks/use-toast';

interface TechUserSummary {
  tech_user_id: number;
  tech_username: string;
  total_commission: number;
  project_count: number;
  projects?: ProjectCommission[];
}

interface ProjectCommission {
  assignment_id: number;
  project_id: number;
  project_code: string;
  project_name: string;
  current_status: string;
  customer_name: string;
  commission_amount: number;
  commission_note: string;
  assigned_at: string;
}

export default function TechCommissionPage() {
  const navigate = useNavigate();
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { toast } = useToast();
  
  const [loading, setLoading] = useState(true);
  const [allData, setAllData] = useState<TechUserSummary[]>([]); // 原始数据
  const [expandedUser, setExpandedUser] = useState<number | null>(null);
  
  // 时间范围筛选
  const [dateRange, setDateRange] = useState<'all' | 'month' | 'quarter' | 'year' | 'custom'>('all');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  
  // 人员筛选
  const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
  const [showUserFilter, setShowUserFilter] = useState(false);
  
  // 加载数据
  const loadData = async () => {
    if (!serverUrl || !token) return;
    
    setLoading(true);
    try {
      let url = `${serverUrl}/api/desktop_tech_commission.php?action=team_summary`;
      if (startDate) url += `&start_date=${startDate}`;
      if (endDate) url += `&end_date=${endDate}`;
      
      const res = await fetch(url, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      
      if (data.success) {
        setAllData(data.data.by_user || []);
      } else {
        toast({ title: data.error || '加载失败', variant: 'destructive' });
      }
    } catch (e) {
      console.error('加载提成数据失败:', e);
      toast({ title: '加载失败', variant: 'destructive' });
    } finally {
      setLoading(false);
    }
  };
  
  // 根据人员筛选过滤数据
  const filteredData = useMemo(() => {
    if (selectedUserIds.length === 0) return allData;
    return allData.filter(u => selectedUserIds.includes(u.tech_user_id));
  }, [allData, selectedUserIds]);
  
  // 动态计算汇总（根据筛选结果）
  const summary = useMemo(() => {
    let totalAmount = 0;
    let totalProjects = 0;
    filteredData.forEach(u => {
      totalAmount += u.total_commission;
      totalProjects += u.project_count;
    });
    return {
      total_amount: totalAmount,
      total_projects: totalProjects,
      total_users: filteredData.length,
    };
  }, [filteredData]);
  
  // 设置时间范围
  const handleDateRangeChange = (range: 'all' | 'month' | 'quarter' | 'year' | 'custom') => {
    setDateRange(range);
    const now = new Date();
    
    switch (range) {
      case 'month':
        setStartDate(new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0]);
        setEndDate(new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0]);
        break;
      case 'quarter':
        const quarter = Math.floor(now.getMonth() / 3);
        setStartDate(new Date(now.getFullYear(), quarter * 3, 1).toISOString().split('T')[0]);
        setEndDate(new Date(now.getFullYear(), quarter * 3 + 3, 0).toISOString().split('T')[0]);
        break;
      case 'year':
        setStartDate(new Date(now.getFullYear(), 0, 1).toISOString().split('T')[0]);
        setEndDate(new Date(now.getFullYear(), 11, 31).toISOString().split('T')[0]);
        break;
      case 'custom':
        // 保持当前日期不变，让用户自己选择
        break;
      default:
        setStartDate('');
        setEndDate('');
    }
  };
  
  // 切换人员选择
  const toggleUserSelection = (userId: number) => {
    setSelectedUserIds(prev => 
      prev.includes(userId) 
        ? prev.filter(id => id !== userId)
        : [...prev, userId]
    );
  };
  
  // 清除人员筛选
  const clearUserFilter = () => {
    setSelectedUserIds([]);
    setShowUserFilter(false);
  };
  
  // 导出 CSV
  const handleExport = () => {
    const headers = ['设计师', '项目数', '总提成'];
    const rows = filteredData.map(u => [u.tech_username, u.project_count, u.total_commission]);
    
    const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `技术提成汇总_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    
    toast({ title: '导出成功', variant: 'success' });
  };
  
  useEffect(() => {
    loadData();
  }, [serverUrl, token, startDate, endDate]);
  
  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <header className="h-14 flex items-center justify-between px-6 border-b border-border-light bg-surface-light">
        <h1 className="text-lg font-semibold text-text-main">技术提成管理</h1>
        <button
          onClick={handleExport}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-indigo-600 hover:bg-indigo-50 rounded-lg"
        >
          <Download className="w-4 h-4" />
          导出CSV
        </button>
      </header>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6">
        {/* 统计卡片 */}
        <div className="grid grid-cols-3 gap-4 mb-6">
          <div className="bg-white rounded-xl p-5 border shadow-sm">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                <DollarSign className="w-5 h-5 text-green-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">总提成</p>
                <p className="text-xl font-bold text-gray-800">¥{summary.total_amount.toLocaleString()}</p>
              </div>
            </div>
          </div>
          
          <div className="bg-white rounded-xl p-5 border shadow-sm">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <FolderKanban className="w-5 h-5 text-blue-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">项目数</p>
                <p className="text-xl font-bold text-gray-800">{summary.total_projects}</p>
              </div>
            </div>
          </div>
          
          <div className="bg-white rounded-xl p-5 border shadow-sm">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                <Users className="w-5 h-5 text-purple-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">设计师</p>
                <p className="text-xl font-bold text-gray-800">{summary.total_users}</p>
              </div>
            </div>
          </div>
        </div>
        
        {/* 筛选区域 */}
        <div className="bg-white rounded-xl p-4 border shadow-sm mb-6 space-y-4">
          {/* 时间范围筛选 */}
          <div className="flex items-center gap-4 flex-wrap">
            <span className="text-sm text-gray-600 font-medium">时间范围:</span>
            <div className="flex gap-2">
              {[
                { key: 'all', label: '全部' },
                { key: 'month', label: '本月' },
                { key: 'quarter', label: '本季度' },
                { key: 'year', label: '本年' },
                { key: 'custom', label: '自定义' },
              ].map(item => (
                <button
                  key={item.key}
                  onClick={() => handleDateRangeChange(item.key as typeof dateRange)}
                  className={`px-3 py-1.5 text-sm rounded-lg transition-colors ${
                    dateRange === item.key
                      ? 'bg-indigo-600 text-white'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                  }`}
                >
                  {item.label}
                </button>
              ))}
            </div>
            {/* 自定义日期选择器 */}
            {dateRange === 'custom' && (
              <div className="flex items-center gap-2">
                <input
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="px-2 py-1 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <span className="text-gray-400">~</span>
                <input
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  className="px-2 py-1 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            )}
            {dateRange !== 'all' && dateRange !== 'custom' && (
              <span className="text-sm text-gray-400">
                {startDate} ~ {endDate}
              </span>
            )}
          </div>
          
          {/* 人员筛选 */}
          <div className="flex items-center gap-4 flex-wrap">
            <span className="text-sm text-gray-600 font-medium">人员筛选:</span>
            <div className="relative">
              <button
                onClick={() => setShowUserFilter(!showUserFilter)}
                className={`flex items-center gap-2 px-3 py-1.5 text-sm rounded-lg transition-colors ${
                  selectedUserIds.length > 0
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
              >
                <Filter className="w-4 h-4" />
                {selectedUserIds.length > 0 ? `已选 ${selectedUserIds.length} 人` : '选择人员'}
              </button>
              
              {/* 人员选择下拉 */}
              {showUserFilter && (
                <div className="absolute top-full left-0 mt-1 w-64 bg-white rounded-lg shadow-lg border z-10 max-h-64 overflow-auto">
                  <div className="p-2 border-b flex justify-between items-center">
                    <span className="text-xs text-gray-500">选择设计师</span>
                    {selectedUserIds.length > 0 && (
                      <button
                        onClick={clearUserFilter}
                        className="text-xs text-red-500 hover:text-red-700"
                      >
                        清除
                      </button>
                    )}
                  </div>
                  {allData.map(user => (
                    <label
                      key={user.tech_user_id}
                      className="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer"
                    >
                      <input
                        type="checkbox"
                        checked={selectedUserIds.includes(user.tech_user_id)}
                        onChange={() => toggleUserSelection(user.tech_user_id)}
                        className="rounded text-indigo-600"
                      />
                      <span className="text-sm text-gray-700">{user.tech_username}</span>
                      <span className="text-xs text-gray-400 ml-auto">¥{user.total_commission.toLocaleString()}</span>
                    </label>
                  ))}
                </div>
              )}
            </div>
            
            {/* 已选人员标签 */}
            {selectedUserIds.length > 0 && (
              <div className="flex items-center gap-2 flex-wrap">
                {allData
                  .filter(u => selectedUserIds.includes(u.tech_user_id))
                  .map(user => (
                    <span
                      key={user.tech_user_id}
                      className="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 text-indigo-700 text-xs rounded-full"
                    >
                      {user.tech_username}
                      <button
                        onClick={() => toggleUserSelection(user.tech_user_id)}
                        className="hover:text-indigo-900"
                      >
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  ))}
              </div>
            )}
          </div>
        </div>
        
        {/* 设计师列表 */}
        <div className="bg-white rounded-xl border shadow-sm overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">设计师</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600">项目数</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">总提成</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {loading ? (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-gray-400">
                    加载中...
                  </td>
                </tr>
              ) : filteredData.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-gray-400">
                    暂无数据
                  </td>
                </tr>
              ) : (
                filteredData.map(user => (
                  <React.Fragment key={user.tech_user_id}>
                    <tr className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center text-sm font-medium">
                            {user.tech_username?.charAt(0) || '?'}
                          </div>
                          <span className="text-sm font-medium text-gray-800">{user.tech_username}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-center text-sm text-gray-600">
                        {user.project_count}
                      </td>
                      <td className="px-4 py-3 text-right text-sm font-medium text-green-600">
                        ¥{user.total_commission.toLocaleString()}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <button
                          onClick={() => setExpandedUser(expandedUser === user.tech_user_id ? null : user.tech_user_id)}
                          className="text-indigo-600 hover:text-indigo-800"
                        >
                          {expandedUser === user.tech_user_id ? (
                            <ChevronUp className="w-4 h-4" />
                          ) : (
                            <ChevronDown className="w-4 h-4" />
                          )}
                        </button>
                      </td>
                    </tr>
                    {expandedUser === user.tech_user_id && user.projects && (
                      <tr>
                        <td colSpan={4} className="px-4 py-2 bg-gray-50">
                          <div className="pl-11 space-y-2">
                            {user.projects.map(p => (
                              <div 
                                key={p.assignment_id} 
                                className="flex items-center justify-between text-sm py-2 px-3 rounded-lg hover:bg-white cursor-pointer transition-colors group"
                                onClick={() => navigate(`/project/${p.project_id}`)}
                              >
                                <div className="flex items-center gap-2">
                                  <span className="text-gray-800 group-hover:text-indigo-600">{p.project_name}</span>
                                  <span className="text-gray-400">({p.customer_name})</span>
                                  <ExternalLink className="w-3 h-3 text-gray-300 group-hover:text-indigo-500" />
                                </div>
                                <span className="text-green-600 font-medium">¥{(p.commission_amount || 0).toLocaleString()}</span>
                              </div>
                            ))}
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
