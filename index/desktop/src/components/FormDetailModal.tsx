import { useState, useEffect } from 'react';
import { X, Copy, Save, Edit2, Clock, User, FileText } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';

interface FormField {
  name: string;
  label: string;
  type?: string;
  required?: boolean;
}

interface Submission {
  id: number;
  submitted_at_formatted: string;
  submitter_name: string | null;
  ip_address: string | null;
  submission_data: Record<string, any>;
}

interface StatusLog {
  id: number;
  create_time_formatted: string;
  operator_name: string | null;
  event_data: {
    from_status?: string;
    to_status?: string;
  };
}

interface InstanceDetail {
  id: number;
  instance_name: string;
  template_name: string;
  form_type: string;
  version_number: string;
  fill_token: string;
  status: string;
  requirement_status: string;
  purpose: string; // 'requirement' | 'evaluation'
  project_id: number;
  project_name: string;
  customer_name: string;
  create_time: string;
  update_time: string;
}

interface FormDetailModalProps {
  open: boolean;
  onClose: () => void;
  instanceId: number;
  onStatusChange?: () => void;
}

const STATUS_LABELS: Record<string, string> = {
  pending: '待填写',
  communicating: '沟通中',
  confirmed: '已确认',
  modifying: '修改中',
};

const STATUS_COLORS: Record<string, string> = {
  pending: 'bg-gray-100 text-gray-700',
  communicating: 'bg-yellow-100 text-yellow-700',
  confirmed: 'bg-green-100 text-green-700',
  modifying: 'bg-red-100 text-red-700',
};

export default function FormDetailModal({ open, onClose, instanceId, onStatusChange }: FormDetailModalProps) {
  const { toast } = useToast();
  const serverUrl = useSettingsStore((state) => state.serverUrl);
  const token = useAuthStore((state) => state.token);
  
  const [loading, setLoading] = useState(true);
  const [instance, setInstance] = useState<InstanceDetail | null>(null);
  const [schema, setSchema] = useState<FormField[]>([]);
  const [submissions, setSubmissions] = useState<Submission[]>([]);
  const [statusLogs, setStatusLogs] = useState<StatusLog[]>([]);
  const [editMode, setEditMode] = useState(false);
  const [editData, setEditData] = useState<Record<string, any>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open && instanceId) {
      loadDetail();
    }
  }, [open, instanceId]);

  const loadDetail = async () => {
    if (!serverUrl || !token) return;
    
    setLoading(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_form_submissions.php?instance_id=${instanceId}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      
      if (data.success) {
        setInstance(data.data.instance);
        setSchema(data.data.schema || []);
        setSubmissions(data.data.submissions || []);
        setStatusLogs(data.data.status_logs || []);
        
        // 初始化编辑数据
        if (data.data.submissions?.length > 0) {
          setEditData(data.data.submissions[0].submission_data || {});
        }
      } else {
        toast({ title: '错误', description: data.message || '加载失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('加载表单详情失败:', error);
      toast({ title: '错误', description: '加载失败', variant: 'destructive' });
    } finally {
      setLoading(false);
    }
  };

  const handleStatusChange = async (newStatus: string) => {
    if (!serverUrl || !token || !instance) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_form_submissions.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'change_status',
          instance_id: instance.id,
          status: newStatus,
        }),
      });
      const data = await response.json();
      
      if (data.success) {
        toast({ title: '成功', description: '状态已更新' });
        loadDetail();
        onStatusChange?.();
      } else {
        toast({ title: '错误', description: data.message || '更新失败', variant: 'destructive' });
      }
    } catch (error) {
      toast({ title: '错误', description: '更新失败', variant: 'destructive' });
    }
  };

  const handleSaveEdit = async () => {
    if (!serverUrl || !token || !instance) return;
    
    setSaving(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_form_submissions.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'save_edit',
          instance_id: instance.id,
          form_data: editData,
        }),
      });
      const data = await response.json();
      
      if (data.success) {
        toast({ title: '成功', description: '保存成功' });
        setEditMode(false);
        loadDetail();
      } else {
        toast({ title: '错误', description: data.message || '保存失败', variant: 'destructive' });
      }
    } catch (error) {
      toast({ title: '错误', description: '保存失败', variant: 'destructive' });
    } finally {
      setSaving(false);
    }
  };

  const copyFillLink = () => {
    if (!instance || !serverUrl) return;
    const url = `${serverUrl}/form_fill.php?token=${instance.fill_token}`;
    navigator.clipboard.writeText(url);
    toast({ title: '成功', description: '链接已复制' });
  };

  const latestSubmission = submissions[0];
  const submissionData = latestSubmission?.submission_data || {};

  // 构建字段列表（无论是否有提交数据，都根据 schema 生成）
  const fieldsToRender: { name: string; label: string; value: any }[] = [];
  if (schema.length > 0) {
    for (const f of schema) {
      const value = submissionData[f.name] ?? '';
      fieldsToRender.push({ name: f.name, label: f.label || f.name, value });
    }
  } else if (Object.keys(submissionData).length > 0) {
    for (const [key, val] of Object.entries(submissionData)) {
      fieldsToRender.push({ name: key, label: key, value: val });
    }
  }
  
  // 判断是否可以编辑/填写（非评价表单都可以编辑）
  const canEdit = instance?.purpose !== 'evaluation';

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-xl shadow-2xl w-[900px] max-h-[90vh] overflow-hidden flex flex-col">
        {/* 头部 */}
        <div className="bg-gradient-to-r from-indigo-600 to-purple-600 text-white p-6">
          <div className="flex items-start justify-between">
            <div>
              <h2 className="text-xl font-semibold">{instance?.instance_name || '加载中...'}</h2>
              <div className="flex items-center gap-4 mt-2 text-sm text-white/80">
                <span><FileText className="w-4 h-4 inline mr-1" />{instance?.template_name} v{instance?.version_number}</span>
                <span>{instance?.project_name}</span>
                <span>{instance?.customer_name}</span>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {instance && (
                <span className={`px-3 py-1 rounded-full text-sm font-medium ${STATUS_COLORS[instance.requirement_status]}`}>
                  {STATUS_LABELS[instance.requirement_status]}
                </span>
              )}
              <button onClick={onClose} className="p-1 hover:bg-white/20 rounded">
                <X className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>

        {/* 操作栏 */}
        <div className="px-6 py-3 bg-gray-50 border-b flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm">
            <span className="text-gray-500">填写链接：</span>
            <code className="bg-white px-2 py-1 rounded text-xs border">{instance?.fill_token?.slice(0, 16)}...</code>
            <button onClick={copyFillLink} className="text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
              <Copy className="w-4 h-4" /> 复制
            </button>
          </div>
          {/* 需求状态下拉框（评价表单不显示） */}
          {instance?.purpose !== 'evaluation' && (
            <div className="flex items-center gap-2">
              <span className="text-sm text-gray-500">需求状态：</span>
              <select
                value={instance?.requirement_status || 'pending'}
                onChange={(e) => handleStatusChange(e.target.value)}
                className="text-sm border rounded px-2 py-1"
              >
                <option value="pending">待填写</option>
                <option value="communicating">沟通中</option>
                <option value="confirmed">已确认</option>
                <option value="modifying">修改中</option>
              </select>
            </div>
          )}
        </div>

        {/* 内容 */}
        <div className="flex-1 overflow-auto p-6">
          {loading ? (
            <div className="flex items-center justify-center h-40">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>
          ) : (
            <div className="grid grid-cols-3 gap-6">
              {/* 左侧：客户填写内容 */}
              <div className="col-span-2">
                <div className="bg-white rounded-lg border p-4">
                  <div className="flex items-center justify-between mb-4 pb-3 border-b">
                    <h3 className="font-medium text-gray-800">
                      {instance?.purpose === 'evaluation' ? '客户评价内容' : '需求内容'}
                    </h3>
                    {/* 允许管理员/设计师/主管填写和编辑（非评价表单） */}
                    {canEdit && !editMode && (
                      <button
                        onClick={() => {
                          setEditMode(true);
                          // 初始化编辑数据
                          if (!latestSubmission && schema.length > 0) {
                            const initData: Record<string, any> = {};
                            schema.forEach(f => { initData[f.name] = ''; });
                            setEditData(initData);
                          }
                        }}
                        className="text-sm text-indigo-600 hover:text-indigo-700 flex items-center gap-1"
                      >
                        <Edit2 className="w-4 h-4" /> {latestSubmission ? '编辑需求' : '填写需求'}
                      </button>
                    )}
                  </div>

                  {/* 编辑模式或有提交数据时显示表单 */}
                  {(editMode || latestSubmission || fieldsToRender.length > 0) ? (
                    <>
                      <div className="space-y-3">
                        {fieldsToRender.map((field) => (
                          <div key={field.name} className="p-3 bg-gray-50 rounded-lg">
                            <label className="text-xs text-gray-500 block mb-1">{field.label}</label>
                            {editMode ? (
                              (String(field.value).length > 50 || String(field.value).includes('\n')) ? (
                                <textarea
                                  value={editData[field.name] ?? ''}
                                  onChange={(e) => setEditData({ ...editData, [field.name]: e.target.value })}
                                  className="w-full p-2 border rounded text-sm"
                                  rows={3}
                                  placeholder={`请输入${field.label}`}
                                />
                              ) : (
                                <input
                                  type="text"
                                  value={editData[field.name] ?? ''}
                                  onChange={(e) => setEditData({ ...editData, [field.name]: e.target.value })}
                                  className="w-full p-2 border rounded text-sm"
                                  placeholder={`请输入${field.label}`}
                                />
                              )
                            ) : (
                              <div className="text-sm text-gray-800 whitespace-pre-wrap">
                                {Array.isArray(field.value) ? field.value.join(', ') : (field.value || <span className="text-gray-400">未填写</span>)}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>

                      {editMode && (
                        <div className="flex items-center gap-3 mt-4 pt-4 border-t">
                          <button
                            onClick={() => setEditMode(false)}
                            className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded"
                          >
                            取消
                          </button>
                          <button
                            onClick={handleSaveEdit}
                            disabled={saving}
                            className="px-4 py-2 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700 flex items-center gap-1"
                          >
                            <Save className="w-4 h-4" />
                            {saving ? '保存中...' : '保存'}
                          </button>
                        </div>
                      )}

                      {latestSubmission && !editMode && (
                        <div className="mt-4 pt-4 border-t text-xs text-gray-500 flex items-center gap-4">
                          <span><User className="w-3 h-3 inline mr-1" />提交人：{latestSubmission.submitter_name || '匿名'}</span>
                          <span><Clock className="w-3 h-3 inline mr-1" />提交时间：{latestSubmission.submitted_at_formatted}</span>
                          <span>IP：{latestSubmission.ip_address || '-'}</span>
                        </div>
                      )}
                    </>
                  ) : (
                    <div className="text-center py-8 text-gray-400">
                      <p className="mb-2">暂无需求内容</p>
                      {canEdit && (
                        <button
                          onClick={() => setEditMode(true)}
                          className="text-indigo-600 hover:text-indigo-700"
                        >
                          点击填写需求
                        </button>
                      )}
                    </div>
                  )}
                </div>
              </div>

              {/* 右侧：信息面板 */}
              <div className="space-y-4">
                {/* 基本信息 */}
                <div className="bg-white rounded-lg border p-4">
                  <h3 className="font-medium text-gray-800 mb-3 pb-2 border-b">基本信息</h3>
                  <div className="space-y-3 text-sm">
                    <div>
                      <label className="text-xs text-gray-500">表单状态</label>
                      <div>
                        <span className={`px-2 py-0.5 rounded text-xs ${instance?.status === 'submitted' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                          {instance?.status === 'submitted' ? '已提交' : '待填写'}
                        </span>
                      </div>
                    </div>
                    <div>
                      <label className="text-xs text-gray-500">提交次数</label>
                      <div>{submissions.length} 次</div>
                    </div>
                    <div>
                      <label className="text-xs text-gray-500">创建时间</label>
                      <div>{instance?.create_time}</div>
                    </div>
                    <div>
                      <label className="text-xs text-gray-500">最后更新</label>
                      <div>{instance?.update_time}</div>
                    </div>
                  </div>
                </div>

                {/* 状态变更记录 */}
                <div className="bg-white rounded-lg border p-4">
                  <h3 className="font-medium text-gray-800 mb-3 pb-2 border-b">状态变更记录</h3>
                  {statusLogs.length > 0 ? (
                    <div className="space-y-2 max-h-40 overflow-auto">
                      {statusLogs.map((log) => (
                        <div key={log.id} className="p-2 bg-gray-50 rounded text-xs">
                          <div className="flex items-center gap-1">
                            {log.event_data.from_status && (
                              <>
                                <span className={`px-1.5 py-0.5 rounded ${STATUS_COLORS[log.event_data.from_status]}`}>
                                  {STATUS_LABELS[log.event_data.from_status]}
                                </span>
                                <span className="text-gray-400">→</span>
                              </>
                            )}
                            <span className={`px-1.5 py-0.5 rounded ${STATUS_COLORS[log.event_data.to_status || '']}`}>
                              {STATUS_LABELS[log.event_data.to_status || '']}
                            </span>
                          </div>
                          <div className="text-gray-500 mt-1">
                            {log.operator_name || '系统'} · {log.create_time_formatted}
                          </div>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="text-xs text-gray-400">暂无变更记录</div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
