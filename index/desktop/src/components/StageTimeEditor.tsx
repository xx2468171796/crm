import { useState, useEffect } from 'react';
import { X, Clock, Check, Minus, ArrowRight } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';

interface StageData {
  id: number;
  stage_from: string;
  stage_to: string;
  stage_order: number;
  planned_days: number;
  planned_start_date: string | null;
  planned_end_date: string | null;
  status: 'pending' | 'in_progress' | 'completed';
  remaining_days: number | null;
}

interface StageTimeEditorProps {
  open: boolean;
  onClose: () => void;
  projectId: number;
  serverUrl: string;
  token: string;
  onSaved: () => void;
}

export default function StageTimeEditor({
  open,
  onClose,
  projectId,
  serverUrl,
  token,
  onSaved,
}: StageTimeEditorProps) {
  const { toast } = useToast();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [stages, setStages] = useState<StageData[]>([]);
  const [editedDays, setEditedDays] = useState<Record<number, number>>({});
  const [startDate, setStartDate] = useState<string>('');

  useEffect(() => {
    if (open && projectId) {
      loadStageTimes();
    }
  }, [open, projectId]);

  const loadStageTimes = async () => {
    setLoading(true);
    try {
      const response = await fetch(
        `${serverUrl}/api/desktop_stage_times.php?project_id=${projectId}`,
        { headers: { 'Authorization': `Bearer ${token}` } }
      );
      const data = await response.json();
      if (data.success) {
        setStages(data.data.stages || []);
        // 初始化编辑天数
        const days: Record<number, number> = {};
        data.data.stages?.forEach((stage: StageData) => {
          days[stage.id] = stage.planned_days;
        });
        setEditedDays(days);
        // 初始化开始日期
        setStartDate(data.data.start_date || '');
      }
    } catch (error) {
      console.error('加载阶段时间失败:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleDaysChange = (stageId: number, value: number) => {
    setEditedDays(prev => ({ ...prev, [stageId]: Math.max(1, Math.min(365, value)) }));
  };

  const handleSave = async () => {
    const changes = stages.map(stage => ({
      id: stage.id,
      stage_order: stage.stage_order,
      planned_days: editedDays[stage.id] || stage.planned_days,
    }));

    setSaving(true);
    try {
      const response = await fetch(`${serverUrl}/api/desktop_stage_times.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ project_id: projectId, changes, start_date: startDate || null }),
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '成功', description: data.message || '保存成功' });
        onSaved();
        onClose();
      } else {
        toast({ title: '错误', description: data.message || '保存失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('保存失败:', error);
      toast({ title: '错误', description: '保存失败', variant: 'destructive' });
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      
      <div className="relative bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[80vh] overflow-hidden">
        {/* 头部 */}
        <div className="flex items-center justify-between px-6 py-4 border-b">
          <div className="flex items-center gap-2">
            <Clock className="w-5 h-5 text-indigo-600" />
            <h3 className="text-lg font-semibold text-gray-800">调整阶段时间</h3>
          </div>
          <button
            onClick={onClose}
            className="p-1 hover:bg-gray-100 rounded-full transition-colors"
            title="关闭"
          >
            <X className="w-5 h-5 text-gray-400" />
          </button>
        </div>

        {/* 内容 */}
        <div className="px-6 py-4 max-h-[50vh] overflow-y-auto">
          {/* 开始日期设置 */}
          <div className="mb-4 p-3 bg-indigo-50 rounded-lg border border-indigo-200">
            <label className="block text-sm font-medium text-indigo-800 mb-2">
              📅 项目开始日期
            </label>
            <div className="flex items-center gap-2">
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="flex-1 px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                title="项目开始日期"
                placeholder="选择开始日期"
              />
              <button
                onClick={() => {
                  const today = new Date();
                  const year = today.getFullYear();
                  const month = String(today.getMonth() + 1).padStart(2, '0');
                  const day = String(today.getDate()).padStart(2, '0');
                  setStartDate(`${year}-${month}-${day}`);
                }}
                className="px-3 py-2 text-xs bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded-lg"
              >
                今天
              </button>
            </div>
            <p className="text-xs text-indigo-600 mt-1">
              设置开始日期后，各阶段时间将自动计算
            </p>
          </div>

          {loading ? (
            <div className="py-8 text-center text-gray-400">加载中...</div>
          ) : stages.length === 0 ? (
            <div className="py-8 text-center text-gray-400">
              <p>暂无阶段时间数据</p>
              <p className="text-xs mt-2">请在后台启用项目时间线</p>
            </div>
          ) : (
            <div className="space-y-3">
              {stages.map((stage) => (
                <div
                  key={stage.id}
                  className={`flex items-center gap-3 p-3 rounded-lg border ${
                    stage.status === 'in_progress'
                      ? 'bg-indigo-50 border-indigo-200'
                      : stage.status === 'completed'
                      ? 'bg-green-50 border-green-200'
                      : 'bg-gray-50 border-gray-200'
                  }`}
                >
                  {/* 状态图标 */}
                  <div
                    className={`w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${
                      stage.status === 'completed'
                        ? 'bg-green-500 text-white'
                        : stage.status === 'in_progress'
                        ? 'bg-indigo-500 text-white'
                        : 'bg-gray-300 text-white'
                    }`}
                  >
                    {stage.status === 'completed' ? (
                      <Check className="w-4 h-4" />
                    ) : stage.status === 'in_progress' ? (
                      <span className="text-xs font-bold">{stage.stage_order}</span>
                    ) : (
                      <Minus className="w-4 h-4" />
                    )}
                  </div>

                  {/* 阶段信息 */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1 text-sm font-medium text-gray-800">
                      <span>{stage.stage_from}</span>
                      <ArrowRight className="w-3 h-3 text-gray-400" />
                      <span>{stage.stage_to}</span>
                      {stage.status === 'in_progress' && (
                        <span className="ml-1 px-1.5 py-0.5 bg-indigo-500 text-white text-xs rounded">进行中</span>
                      )}
                      {stage.status === 'completed' && (
                        <span className="ml-1 px-1.5 py-0.5 bg-green-500 text-white text-xs rounded">已完成</span>
                      )}
                    </div>
                    <div className="text-xs text-gray-400 mt-0.5">
                      {stage.planned_start_date || '-'} ~ {stage.planned_end_date || '-'}
                    </div>
                  </div>

                  {/* 天数编辑 */}
                  <div className="flex items-center gap-1">
                    <input
                      type="number"
                      value={editedDays[stage.id] || stage.planned_days}
                      onChange={(e) => handleDaysChange(stage.id, parseInt(e.target.value) || 1)}
                      min={1}
                      max={365}
                      className="w-16 px-2 py-1.5 text-sm text-center border rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      title={`${stage.stage_from} → ${stage.stage_to} 计划天数`}
                    />
                    <span className="text-sm text-gray-500">天</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* 底部按钮 */}
        <div className="flex justify-end gap-3 px-6 py-4 bg-gray-50 border-t">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          >
            取消
          </button>
          <button
            onClick={handleSave}
            disabled={saving || loading}
            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
          >
            {saving ? '保存中...' : '保存更改'}
          </button>
        </div>
      </div>
    </div>
  );
}
