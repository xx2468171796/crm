import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, Search, RefreshCw, Clock, CheckCircle, AlertCircle, ChevronRight, Eye, Phone, Check } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import TimeRangeFilter, { TimeRange } from '@/components/TimeRangeFilter';
import FormDetailModal from '@/components/FormDetailModal';

interface FormItem {
  id: number;
  project_id: number;
  project_name: string;
  project_code: string;
  customer_name: string;
  form_type: string;
  form_type_name: string;
  status: string;
  create_time: string | null;
  update_time: string | null;
}

interface FormStats {
  total: number;
  pending: number;
  in_progress: number;
  completed: number;
}

const STATUS_CONFIG: Record<string, { label: string; color: string; icon: any }> = {
  pending: { label: '待沟通', color: 'bg-yellow-100 text-yellow-700', icon: AlertCircle },
  in_progress: { label: '沟通中', color: 'bg-blue-100 text-blue-700', icon: Clock },
  completed: { label: '已确认', color: 'bg-green-100 text-green-700', icon: CheckCircle },
};

interface FilterUser {
  id: number;
  name: string;
}

export default function FormListPage() {
  const navigate = useNavigate();
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { canManageProjects } = usePermissionsStore();

  const [loading, setLoading] = useState(true);
  const [forms, setForms] = useState<FormItem[]>([]);
  const [stats, setStats] = useState<FormStats>({ total: 0, pending: 0, in_progress: 0, completed: 0 });
  const [search, setSearch] = useState('');
  const [filterStatus, setFilterStatus] = useState('');
  const [filterFormType, setFilterFormType] = useState(''); // requirement / evaluation
  
  // 表单详情弹窗
  const [selectedFormId, setSelectedFormId] = useState<number | null>(null);
  const [showFormDetail, setShowFormDetail] = useState(false);
  
  // 时间筛选
  const [timeRange, setTimeRange] = useState<TimeRange>('all');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  
  // 人员筛选（管理员专用）
  const [filterUserId, setFilterUserId] = useState('');
  const [filterUsers, setFilterUsers] = useState<FilterUser[]>([]);
  
  // 排序
  const [sortBy, setSortBy] = useState<'create_time' | 'update_time'>('create_time');
  const [sortOrder, setSortOrder] = useState<'desc' | 'asc'>('desc');
  
  const isManager = canManageProjects();

  const loadForms = useCallback(async () => {
    if (!serverUrl || !token) return;
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (search) params.append('search', search);
      if (filterStatus) params.append('status', filterStatus);
      if (filterFormType) params.append('form_type', filterFormType);
      if (startDate) params.append('start_date', startDate);
      if (endDate) params.append('end_date', endDate);
      if (filterUserId) params.append('user_id', filterUserId);
      params.append('sort_by', sortBy);
      params.append('sort_order', sortOrder);

      const response = await fetch(`${serverUrl}/api/desktop_forms.php?action=list&${params}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setForms(data.data.forms || []);
        setStats(data.data.stats || { total: 0, pending: 0, in_progress: 0, completed: 0 });
      }
    } catch (error) {
      console.error('加载表单失败:', error);
    } finally {
      setLoading(false);
    }
  }, [serverUrl, token, search, filterStatus, filterFormType, startDate, endDate, filterUserId, sortBy, sortOrder]);
  
  // 加载人员列表（管理员专用）
  const loadFilterUsers = useCallback(async () => {
    if (!serverUrl || !token || !isManager) return;
    try {
      const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=filters&user_type=tech`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      if (data.success) {
        setFilterUsers(data.data.users || []);
      }
    } catch (error) {
      console.error('加载人员列表失败:', error);
    }
  }, [serverUrl, token, isManager]);

  useEffect(() => {
    loadForms();
  }, [loadForms]);
  
  useEffect(() => {
    loadFilterUsers();
  }, [loadFilterUsers]);

  // 处理表单状态
  const handleStatusChange = async (formId: number, newStatus: string) => {
    if (!serverUrl || !token) return;
    try {
      const response = await fetch(`${serverUrl}/api/desktop_forms.php?action=process`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ form_id: formId, status: newStatus }),
      });
      const data = await response.json();
      if (data.success) {
        loadForms();
      }
    } catch (error) {
      console.error('更新状态失败:', error);
    }
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <h1 className="text-lg font-semibold text-gray-800">表单处理</h1>
            
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

            {/* 表单类型筛选 */}
            <select
              value={filterFormType}
              onChange={(e) => setFilterFormType(e.target.value)}
              className="px-3 py-1.5 border rounded-lg text-sm"
              title="表单类型"
            >
              <option value="">全部表单</option>
              <option value="requirement">需求表单</option>
              <option value="evaluation">评价表单</option>
            </select>

            {/* 状态筛选 */}
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="px-3 py-1.5 border rounded-lg text-sm"
              title="状态筛选"
            >
              <option value="">所有状态</option>
              <option value="pending">待沟通</option>
              <option value="in_progress">沟通中</option>
              <option value="completed">已确认</option>
            </select>
            
            {/* 时间筛选 */}
            <TimeRangeFilter
              value={timeRange}
              onChange={(range, start, end) => {
                setTimeRange(range);
                setStartDate(start);
                setEndDate(end);
              }}
            />
            
            {/* 排序 */}
            <select
              value={`${sortBy}_${sortOrder}`}
              onChange={(e) => {
                const [by, order] = e.target.value.split('_') as ['create_time' | 'update_time', 'desc' | 'asc'];
                setSortBy(by);
                setSortOrder(order);
              }}
              className="px-3 py-1.5 border rounded-lg text-sm"
              title="排序方式"
            >
              <option value="create_time_desc">创建时间 ↓</option>
              <option value="create_time_asc">创建时间 ↑</option>
              <option value="update_time_desc">更新时间 ↓</option>
              <option value="update_time_asc">更新时间 ↑</option>
            </select>
            
            {/* 人员筛选（管理员专用） */}
            {isManager && filterUsers.length > 0 && (
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
            )}

            {/* 刷新 */}
            <button
              onClick={() => loadForms()}
              className="p-1.5 text-gray-500 hover:text-blue-500 hover:bg-blue-50 rounded transition-colors"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>
          </div>
        </div>

        {/* 统计 - 可点击切换状态 */}
        <div className="flex gap-2 mt-3">
          <button
            onClick={() => setFilterStatus('')}
            className={`px-3 py-1 text-sm rounded-full transition-colors ${
              filterStatus === '' 
                ? 'bg-gray-800 text-white' 
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            全部 <b>{stats.total}</b>
          </button>
          <button
            onClick={() => setFilterStatus('pending')}
            className={`px-3 py-1 text-sm rounded-full transition-colors ${
              filterStatus === 'pending' 
                ? 'bg-yellow-500 text-white' 
                : 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100'
            }`}
          >
            待沟通 <b>{stats.pending}</b>
          </button>
          <button
            onClick={() => setFilterStatus('in_progress')}
            className={`px-3 py-1 text-sm rounded-full transition-colors ${
              filterStatus === 'in_progress' 
                ? 'bg-blue-500 text-white' 
                : 'bg-blue-50 text-blue-700 hover:bg-blue-100'
            }`}
          >
            沟通中 <b>{stats.in_progress}</b>
          </button>
          <button
            onClick={() => setFilterStatus('completed')}
            className={`px-3 py-1 text-sm rounded-full transition-colors ${
              filterStatus === 'completed' 
                ? 'bg-green-500 text-white' 
                : 'bg-green-50 text-green-700 hover:bg-green-100'
            }`}
          >
            已确认 <b>{stats.completed}</b>
          </button>
        </div>
      </div>

      {/* 表单列表 */}
      <div className="flex-1 overflow-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-32 text-gray-400">加载中...</div>
        ) : forms.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-48 text-gray-400">
            <FileText className="w-12 h-12 mb-2 opacity-50" />
            <p>暂无表单</p>
          </div>
        ) : (
          <div className="space-y-3">
            {forms.map((form) => {
              const statusConfig = STATUS_CONFIG[form.status] || STATUS_CONFIG.pending;
              const StatusIcon = statusConfig.icon;
              return (
                <div
                  key={form.id}
                  className="bg-white rounded-lg border p-4 hover:shadow-md transition-shadow"
                >
                  <div className="flex items-center justify-between">
                    <div className="flex-1">
                      <div className="flex items-center gap-3">
                        <span className={`px-2 py-0.5 text-xs rounded ${statusConfig.color}`}>
                          <StatusIcon className="w-3 h-3 inline mr-1" />
                          {statusConfig.label}
                        </span>
                        <span className="text-xs text-gray-400 font-mono">{form.project_code}</span>
                      </div>
                      <h3 className="font-medium text-gray-800 mt-2">{form.form_type_name}</h3>
                      <div className="flex items-center gap-4 mt-1 text-sm text-gray-500">
                        <span>{form.project_name}</span>
                        <span>•</span>
                        <span>{form.customer_name}</span>
                        <span>•</span>
                        <span>{form.create_time}</span>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      {/* 查看详情按钮 */}
                      <button
                        onClick={() => {
                          setSelectedFormId(form.id);
                          setShowFormDetail(true);
                        }}
                        className="px-3 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200 flex items-center gap-1"
                      >
                        <Eye className="w-3 h-3" />
                        查看
                      </button>
                      {form.status === 'pending' && (
                        <button
                          onClick={() => handleStatusChange(form.id, 'in_progress')}
                          className="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 flex items-center gap-1"
                        >
                          <Phone className="w-3 h-3" />
                          开始沟通
                        </button>
                      )}
                      {form.status === 'in_progress' && (
                        <button
                          onClick={() => handleStatusChange(form.id, 'completed')}
                          className="px-3 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600 flex items-center gap-1"
                        >
                          <Check className="w-3 h-3" />
                          确认需求
                        </button>
                      )}
                      <button
                        onClick={() => navigate(`/project/${form.project_id}`)}
                        className="p-1 text-gray-400 hover:text-gray-600"
                        title="查看项目"
                      >
                        <ChevronRight className="w-5 h-5" />
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
      
      {/* 表单详情弹窗 */}
      {selectedFormId && (
        <FormDetailModal
          open={showFormDetail}
          onClose={() => {
            setShowFormDetail(false);
            setSelectedFormId(null);
          }}
          instanceId={selectedFormId}
          onStatusChange={() => loadForms()}
        />
      )}
    </div>
  );
}
