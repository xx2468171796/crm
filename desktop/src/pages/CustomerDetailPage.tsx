import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, User, Phone, MessageSquare, FileText, DollarSign, Folder, ChevronRight, Link2, Save, UserPlus, RefreshCw, Copy } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import DetailSidebar, { SidebarTab } from '@/components/DetailSidebar';

interface CustomerData {
  id: number;
  name: string;
  group_code: string;
  group_name: string;
  customer_group: string;
  phone: string;
  email: string;
  address: string;
  remark: string;
  create_time: string;
}

interface ProjectData {
  id: number;
  project_code: string;
  project_name: string;
  current_status: string;
  create_time: string;
  update_time: string;
}

interface Stats {
  total_projects: number;
  in_progress: number;
  completed: number;
}

interface RegionLink {
  region_name: string;
  url: string;
  is_default: boolean;
}

interface PortalInfo {
  token: string;
  enabled: boolean;
  access_count: number;
  last_access?: string;
}

// 侧边栏Tab配置
const SIDEBAR_TABS: SidebarTab[] = [
  { key: 'first_contact', label: '首通', icon: <Phone className="w-4 h-4" /> },
  { key: 'objection', label: '异议处理', icon: <MessageSquare className="w-4 h-4" /> },
  { key: 'deal', label: '敲定成交', icon: <FileText className="w-4 h-4" /> },
  { key: 'service', label: '正式服务', icon: <User className="w-4 h-4" /> },
  { key: 'feedback', label: '客户回访', icon: <MessageSquare className="w-4 h-4" /> },
  { key: 'files', label: '文件管理', icon: <Folder className="w-4 h-4" /> },
  { key: 'finance', label: '财务', icon: <DollarSign className="w-4 h-4" /> },
  { key: 'projects', label: '项目', icon: <Folder className="w-4 h-4" /> },
];

export default function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuthStore();
  const { toast } = useToast();
  const { serverUrl } = useSettingsStore();
  const { canEditCustomer, isAdmin } = usePermissionsStore();
  
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('first_contact');
  const [customer, setCustomer] = useState<CustomerData | null>(null);
  const [projects, setProjects] = useState<ProjectData[]>([]);
  const [stats, setStats] = useState<Stats>({ total_projects: 0, in_progress: 0, completed: 0 });
  const [saving] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [portalInfo, setPortalInfo] = useState<PortalInfo | null>(null);
  const [regionLinks, setRegionLinks] = useState<RegionLink[]>([]);
  const [loadingLinks, setLoadingLinks] = useState(false);

  // 加载客户详情
  const loadCustomer = async () => {
    if (!serverUrl || !token || !id) return;
    setLoading(true);
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_customers.php?id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      
      if (data.success) {
        setCustomer(data.data.customer);
        setProjects(data.data.projects || []);
        setStats(data.data.stats || { total_projects: 0, in_progress: 0, completed: 0 });
      }
    } catch (error) {
      console.error('加载客户详情失败:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadCustomer();
  }, [id, serverUrl, token]);

  // 加载门户信息和区域链接
  const loadPortalLinks = useCallback(async () => {
    if (!serverUrl || !token || !id) return;
    setLoadingLinks(true);
    
    try {
      // 获取门户信息
      const portalRes = await fetch(`${serverUrl}/api/portal_password.php?customer_id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const portalData = await portalRes.json();
      
      if (portalData.success && portalData.data?.token) {
        setPortalInfo(portalData.data);
        
        // 获取多区域链接
        const regionRes = await fetch(`${serverUrl}/api/portal_link.php?action=get_region_urls&token=${portalData.data.token}`, {
          headers: { 'Authorization': `Bearer ${token}` },
        });
        const regionData = await regionRes.json();
        
        if (regionData.success && regionData.regions) {
          setRegionLinks(regionData.regions);
        } else {
          // 无区域配置，使用默认链接
          setRegionLinks([{
            region_name: '默认',
            url: `${serverUrl}/portal.php?token=${portalData.data.token}`,
            is_default: true
          }]);
        }
      } else {
        setPortalInfo(null);
        setRegionLinks([]);
      }
    } catch (error) {
      console.error('加载门户链接失败:', error);
      toast({ title: '加载失败', description: '无法获取门户链接信息', variant: 'destructive' });
    } finally {
      setLoadingLinks(false);
    }
  }, [serverUrl, token, id, toast]);

  // 打开链接管理弹窗
  const handleOpenLinkModal = () => {
    setShowLinkModal(true);
    loadPortalLinks();
  };

  // 复制链接到剪贴板
  const copyToClipboard = (url: string, regionName: string) => {
    navigator.clipboard.writeText(url);
    toast({ title: '已复制', description: `${regionName}链接已复制到剪贴板` });
  };

  // 获取状态颜色
  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      '待沟通': 'bg-gray-100 text-gray-700',
      '需求确认': 'bg-purple-100 text-purple-700',
      '设计中': 'bg-pink-100 text-pink-700',
      '设计核对': 'bg-orange-100 text-orange-700',
      '设计完工': 'bg-teal-100 text-teal-700',
      '设计评价': 'bg-green-100 text-green-700',
    };
    return colors[status] || 'bg-gray-100 text-gray-700';
  };

  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center text-gray-400">
        加载中...
      </div>
    );
  }

  if (!customer) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center text-gray-400">
        <p>客户不存在</p>
        <button
          onClick={() => navigate('/project-kanban')}
          className="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"
        >
          返回看板
        </button>
      </div>
    );
  }

  // 渲染Tab内容
  const renderTabContent = () => {
    switch (activeTab) {
      case 'first_contact':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">首通记录</h3>
            <p className="text-gray-500">首通记录功能开发中...</p>
          </div>
        );
      case 'objection':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">异议处理</h3>
            <p className="text-gray-500">异议处理功能开发中...</p>
          </div>
        );
      case 'deal':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">敲定成交</h3>
            <p className="text-gray-500">敲定成交功能开发中...</p>
          </div>
        );
      case 'service':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">正式服务</h3>
            <p className="text-gray-500">正式服务功能开发中...</p>
          </div>
        );
      case 'feedback':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">客户回访</h3>
            <p className="text-gray-500">客户回访功能开发中...</p>
          </div>
        );
      case 'files':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">文件管理</h3>
            <p className="text-gray-500">文件管理功能开发中...</p>
          </div>
        );
      case 'finance':
        return (
          <div className="bg-white rounded-xl p-6 border">
            <h3 className="text-lg font-semibold mb-4">财务记录</h3>
            <p className="text-gray-500">财务记录功能开发中...</p>
          </div>
        );
      case 'projects':
        return (
          <div className="bg-white rounded-xl border">
            <div className="p-4 border-b flex items-center justify-between">
              <h3 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                <Folder className="w-4 h-4 text-blue-600" />
                项目列表
              </h3>
              <span className="text-xs text-gray-400">{projects.length} 个项目</span>
            </div>
            {projects.length === 0 ? (
              <div className="p-8 text-center text-gray-400">
                <Folder className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>暂无项目</p>
              </div>
            ) : (
              <div className="divide-y">
                {projects.map((project) => (
                  <div
                    key={project.id}
                    onClick={() => navigate(`/project/${project.id}`)}
                    className="p-4 hover:bg-gray-50 cursor-pointer flex items-center justify-between group"
                  >
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-gray-800">{project.project_name}</span>
                        <span className={`px-2 py-0.5 text-xs rounded ${getStatusColor(project.current_status)}`}>
                          {project.current_status || '待处理'}
                        </span>
                      </div>
                      <div className="flex items-center gap-3 mt-1 text-xs text-gray-400">
                        <span className="font-mono">{project.project_code}</span>
                        <span>•</span>
                        <span>更新于 {project.update_time}</span>
                      </div>
                    </div>
                    <ChevronRight className="w-4 h-4 text-gray-300 group-hover:text-gray-500" />
                  </div>
                ))}
              </div>
            )}
          </div>
        );
      default:
        return null;
    }
  };

  const canEdit = canEditCustomer() || isAdmin();

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50 overflow-hidden">
      {/* 顶部栏 */}
      <div className="bg-white border-b px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            onClick={() => navigate(-1)}
            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
          >
            <ArrowLeft className="w-5 h-5 text-gray-600" />
          </button>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-cyan-500 text-white flex items-center justify-center font-semibold">
              {customer.name.charAt(0)}
            </div>
            <div>
              <h1 className="text-lg font-bold text-gray-800">{customer.name}</h1>
              <div className="flex items-center gap-2 text-xs text-gray-500">
                {customer.group_code && <span>客户群名称: {customer.group_code}</span>}
              </div>
            </div>
          </div>
        </div>
        
        {/* 操作按钮 */}
        <div className="flex items-center gap-2">
          <button
            onClick={loadCustomer}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
          >
            <RefreshCw className="w-4 h-4" />
            刷新
          </button>
          {canEdit && (
            <>
              <button
                onClick={handleOpenLinkModal}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
              >
                <Link2 className="w-4 h-4" />
                链接管理
              </button>
              <button className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg">
                <UserPlus className="w-4 h-4" />
                分配技术
              </button>
              <button
                disabled={saving}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
              >
                <Save className="w-4 h-4" />
                {saving ? '保存中...' : '保存'}
              </button>
            </>
          )}
        </div>
      </div>

      {/* 主体：侧边栏 + 内容 */}
      <div className="flex-1 flex overflow-hidden">
        {/* 侧边栏 */}
        <DetailSidebar
          tabs={SIDEBAR_TABS}
          activeTab={activeTab}
          onTabChange={setActiveTab}
        />

        {/* 内容区 */}
        <div className="flex-1 p-6 overflow-auto">
          {/* 客户信息卡片 */}
          <div className="grid grid-cols-4 gap-4 mb-6">
            <div className="bg-white rounded-xl p-4 border">
              <p className="text-xs text-gray-400 mb-1">联系方式</p>
              <p className="text-sm font-medium text-gray-800">{customer.phone || '-'}</p>
            </div>
            <div className="bg-white rounded-xl p-4 border">
              <p className="text-xs text-gray-400 mb-1">客户群名称</p>
              <div className="flex items-center gap-2">
                <p className="text-sm font-medium text-gray-800">{customer.customer_group || '-'}</p>
                {customer.customer_group && (
                  <button
                    onClick={() => {
                      navigator.clipboard.writeText(customer.customer_group);
                      toast({ title: '已复制', description: '客户群名称已复制到剪贴板' });
                    }}
                    className="text-gray-400 hover:text-indigo-600"
                    title="复制客户群名称"
                  >
                    <Copy className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>
            <div className="bg-white rounded-xl p-4 border">
              <p className="text-xs text-gray-400 mb-1">项目数</p>
              <p className="text-sm font-medium text-gray-800">{stats.total_projects}</p>
            </div>
            <div className="bg-white rounded-xl p-4 border">
              <p className="text-xs text-gray-400 mb-1">进行中</p>
              <p className="text-sm font-medium text-blue-600">{stats.in_progress}</p>
            </div>
          </div>

          {/* Tab内容 */}
          {renderTabContent()}
        </div>
      </div>

      {/* 链接管理弹窗 */}
      {showLinkModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[500px] max-h-[80vh] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between">
              <h3 className="text-lg font-semibold flex items-center gap-2">
                <Link2 className="w-5 h-5 text-blue-600" />
                访问地址管理
              </h3>
              <button
                onClick={() => setShowLinkModal(false)}
                className="p-1.5 hover:bg-gray-100 rounded-lg text-gray-400 hover:text-gray-600"
              >
                ✕
              </button>
            </div>
            
            <div className="p-6 overflow-auto max-h-[60vh]">
              {loadingLinks ? (
                <div className="text-center py-8 text-gray-400">
                  加载中...
                </div>
              ) : !portalInfo ? (
                <div className="text-center py-8">
                  <div className="text-gray-400 mb-2">该客户尚未创建门户</div>
                  <p className="text-sm text-gray-400">请在网页端门户设置中创建</p>
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-sm text-blue-700">
                      选择合适的区域链接发送给客户，⭐ 表示默认节点
                    </p>
                  </div>
                  
                  <div className="space-y-2">
                    {regionLinks.map((link, idx) => (
                      <div key={idx} className="flex items-center gap-2 p-3 bg-gray-50 rounded-lg border">
                        <span className="text-sm font-medium min-w-[80px]">
                          {link.is_default && '⭐ '}{link.region_name}
                        </span>
                        <input
                          type="text"
                          value={link.url}
                          readOnly
                          className="flex-1 text-xs bg-white border rounded px-2 py-1.5 text-gray-600"
                        />
                        <button
                          onClick={() => copyToClipboard(link.url, link.region_name)}
                          className="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 flex items-center gap-1"
                        >
                          <Copy className="w-3.5 h-3.5" />
                          复制
                        </button>
                      </div>
                    ))}
                  </div>
                  
                  {portalInfo && (
                    <div className="mt-4 pt-4 border-t text-xs text-gray-400">
                      <p>访问次数: {portalInfo.access_count || 0}</p>
                      {portalInfo.last_access && <p>最后访问: {portalInfo.last_access}</p>}
                    </div>
                  )}
                </div>
              )}
            </div>
            
            <div className="px-6 py-4 border-t bg-gray-50">
              <button
                onClick={() => setShowLinkModal(false)}
                className="w-full py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
              >
                关闭
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
