import { useState, useEffect } from 'react';
import { FileText, Search, Eye, Edit2, Calendar, User, Maximize2 } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

interface Requirement {
  id: number;
  customer_id: number;
  customer_name: string;
  customer_code: string;
  group_code: string;
  content_preview: string;
  version: number;
  project_count: number;
  create_time: string | null;
  update_time: string | null;
  last_sync_time: string | null;
  creator_name: string | null;
  updater_name: string | null;
}

interface RequirementDetail {
  id: number;
  customer_id: number;
  customer_name: string;
  customer_code: string;
  group_code: string;
  content: string;
  version: number;
  create_time: string | null;
  update_time: string | null;
  last_sync_time: string | null;
  creator_name: string | null;
  updater_name: string | null;
}

export default function RequirementsPage() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const [requirements, setRequirements] = useState<Requirement[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedRequirement, setSelectedRequirement] = useState<RequirementDetail | null>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [editContent, setEditContent] = useState('');
  const [saving, setSaving] = useState(false);

  const loadRequirements = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (searchTerm) params.append('search', searchTerm);

      const response = await fetch(`${serverUrl}/api/desktop_requirements.php?action=list&${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setRequirements(data.data.requirements || []);
      }
    } catch (error) {
      console.error('加载需求文档失败:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (serverUrl && token) {
      loadRequirements();
    }
  }, [serverUrl, token]);

  const handleSearch = () => {
    loadRequirements();
  };

  const handleViewDetail = async (customerId: number) => {
    try {
      const response = await fetch(`${serverUrl}/api/desktop_requirements.php?action=get&customer_id=${customerId}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        setSelectedRequirement(data.data);
        setShowDetailModal(true);
      }
    } catch (error) {
      console.error('加载需求详情失败:', error);
    }
  };

  const handleEdit = () => {
    if (selectedRequirement) {
      setEditContent(selectedRequirement.content);
      setShowDetailModal(false);
      setShowEditModal(true);
    }
  };

  const handleSave = async () => {
    if (!selectedRequirement) return;

    setSaving(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_requirements.php?action=save`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          customer_id: selectedRequirement.customer_id,
          content: editContent,
        }),
      });
      const data = await response.json();
      if (data.success) {
        setShowEditModal(false);
        loadRequirements();
        // 刷新详情
        handleViewDetail(selectedRequirement.customer_id);
      } else {
        alert('保存失败: ' + data.error);
      }
    } catch (error) {
      console.error('保存失败:', error);
      alert('保存失败，请稍后重试');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="h-screen flex flex-col bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <FileText className="w-6 h-6 text-blue-600" />
            <h1 className="text-2xl font-bold text-gray-800">需求管理</h1>
          </div>
          <div className="flex items-center gap-3">
            <div className="relative">
              <input
                type="text"
                placeholder="搜索客户名称或编号..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                onKeyPress={(e) => e.key === 'Enter' && handleSearch()}
                className="pl-10 pr-4 py-2 border rounded-lg w-80 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <Search className="w-5 h-5 text-gray-400 absolute left-3 top-2.5" />
            </div>
            <button
              onClick={handleSearch}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            >
              搜索
            </button>
          </div>
        </div>
      </div>

      {/* 内容区域 */}
      <div className="flex-1 overflow-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-64">
            <div className="text-gray-500">加载中...</div>
          </div>
        ) : requirements.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-gray-500">
            <FileText className="w-16 h-16 mb-4 opacity-50" />
            <p>暂无需求文档</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {requirements.map((req) => (
              <div
                key={req.id}
                className="bg-white rounded-lg border hover:shadow-lg transition-shadow cursor-pointer"
                onClick={() => handleViewDetail(req.customer_id)}
              >
                <div className="p-4">
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex-1">
                      <h3 className="font-semibold text-lg text-gray-800 mb-1">
                        {req.customer_name}
                      </h3>
                      <div className="flex items-center gap-2 text-sm text-gray-500">
                        {req.customer_code && (
                          <span className="px-2 py-0.5 bg-gray-100 rounded">
                            {req.customer_code}
                          </span>
                        )}
                        {req.project_count > 0 && (
                          <span className="text-blue-600">
                            {req.project_count} 个项目
                          </span>
                        )}
                      </div>
                    </div>
                    <Eye className="w-5 h-5 text-gray-400" />
                  </div>

                  <p className="text-sm text-gray-600 line-clamp-3 mb-3">
                    {req.content_preview || '暂无内容'}
                  </p>

                  <div className="flex items-center justify-between text-xs text-gray-500 pt-3 border-t">
                    <div className="flex items-center gap-1">
                      <Calendar className="w-3.5 h-3.5" />
                      <span>{req.update_time || req.create_time || '-'}</span>
                    </div>
                    {req.updater_name && (
                      <div className="flex items-center gap-1">
                        <User className="w-3.5 h-3.5" />
                        <span>{req.updater_name}</span>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* 查看详情模态框 */}
      {showDetailModal && selectedRequirement && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg w-full max-w-5xl max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b">
              <div>
                <h2 className="text-xl font-bold text-gray-800">
                  {selectedRequirement.customer_name}
                </h2>
                <p className="text-sm text-gray-500 mt-1">
                  {selectedRequirement.customer_code}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={handleEdit}
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2"
                >
                  <Edit2 className="w-4 h-4" />
                  编辑
                </button>
                <button
                  onClick={() => setShowDetailModal(false)}
                  className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                >
                  关闭
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-auto p-6">
              {selectedRequirement.content ? (
                <div className="prose prose-slate max-w-none">
                  <ReactMarkdown remarkPlugins={[remarkGfm]}>
                    {selectedRequirement.content}
                  </ReactMarkdown>
                </div>
              ) : (
                <div className="text-center text-gray-500 py-12">
                  暂无内容
                </div>
              )}
            </div>

            {selectedRequirement.update_time && (
              <div className="px-6 py-3 border-t bg-gray-50 text-sm text-gray-600">
                最后更新: {selectedRequirement.update_time}
                {selectedRequirement.updater_name && ` by ${selectedRequirement.updater_name}`}
              </div>
            )}
          </div>
        </div>
      )}

      {/* 编辑模态框 */}
      {showEditModal && selectedRequirement && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg w-full max-w-6xl max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b">
              <div>
                <h2 className="text-xl font-bold text-gray-800">
                  编辑需求文档 - {selectedRequirement.customer_name}
                </h2>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={handleSave}
                  disabled={saving}
                  className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50"
                >
                  {saving ? '保存中...' : '保存'}
                </button>
                <button
                  onClick={() => {
                    setShowEditModal(false);
                    setShowDetailModal(true);
                  }}
                  className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                >
                  取消
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-hidden flex">
              {/* 编辑器 */}
              <div className="flex-1 flex flex-col border-r">
                <div className="px-4 py-2 bg-gray-50 border-b text-sm font-medium text-gray-700">
                  Markdown 编辑器
                </div>
                <textarea
                  value={editContent}
                  onChange={(e) => setEditContent(e.target.value)}
                  className="flex-1 p-4 font-mono text-sm resize-none focus:outline-none"
                  placeholder="在此输入 Markdown 格式的需求文档..."
                />
              </div>

              {/* 预览 */}
              <div className="flex-1 flex flex-col">
                <div className="px-4 py-2 bg-gray-50 border-b text-sm font-medium text-gray-700">
                  实时预览
                </div>
                <div className="flex-1 overflow-auto p-4 bg-gray-50">
                  {editContent ? (
                    <div className="prose prose-slate max-w-none">
                      <ReactMarkdown remarkPlugins={[remarkGfm]}>
                        {editContent}
                      </ReactMarkdown>
                    </div>
                  ) : (
                    <div className="text-center text-gray-400 py-12">
                      预览将在此处显示...
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
