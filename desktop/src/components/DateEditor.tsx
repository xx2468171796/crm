import { useState, useEffect } from 'react';
import { X, Calendar } from 'lucide-react';

interface DateEditorProps {
  open: boolean;
  onClose: () => void;
  startDate: string | null;
  deadline: string | null;
  onSave: (startDate: string | null, deadline: string | null) => Promise<void>;
}

export default function DateEditor({
  open,
  onClose,
  startDate,
  deadline,
  onSave,
}: DateEditorProps) {
  const [newStartDate, setNewStartDate] = useState(startDate || '');
  const [newDeadline, setNewDeadline] = useState(deadline || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setNewStartDate(startDate || '');
      setNewDeadline(deadline || '');
      setError(null);
    }
  }, [open, startDate, deadline]);

  const handleSave = async () => {
    // 验证日期
    if (newStartDate && newDeadline && newStartDate > newDeadline) {
      setError('开始日期不能晚于截止日期');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await onSave(newStartDate || null, newDeadline || null);
      onClose();
    } catch (err: any) {
      setError(err.message || '保存失败');
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      
      <div className="relative bg-white rounded-xl shadow-2xl w-[420px] max-w-[90vw]">
        {/* 头部 */}
        <div className="flex items-center justify-between p-4 border-b">
          <h3 className="text-lg font-semibold text-gray-800 flex items-center gap-2">
            <Calendar className="w-5 h-5 text-indigo-600" />
            调整项目周期
          </h3>
          <button
            onClick={onClose}
            className="p-1 text-gray-400 hover:text-gray-600 rounded transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* 内容 */}
        <div className="p-6 space-y-4">
          {error && (
            <div className="p-3 bg-red-50 text-red-600 text-sm rounded-lg">
              {error}
            </div>
          )}
          
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              开始日期
            </label>
            <input
              type="date"
              value={newStartDate}
              onChange={(e) => setNewStartDate(e.target.value)}
              className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              截止日期
            </label>
            <input
              type="date"
              value={newDeadline}
              onChange={(e) => setNewDeadline(e.target.value)}
              className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          {/* 快捷设置 */}
          <div className="flex flex-wrap gap-2 pt-2">
            <span className="text-xs text-gray-500">快捷设置:</span>
            <button
              onClick={() => {
                const today = new Date().toISOString().split('T')[0];
                setNewStartDate(today);
              }}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded"
            >
              今天开始
            </button>
            <button
              onClick={() => {
                const date = new Date();
                date.setDate(date.getDate() + 7);
                setNewDeadline(date.toISOString().split('T')[0]);
              }}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded"
            >
              7天后截止
            </button>
            <button
              onClick={() => {
                const date = new Date();
                date.setDate(date.getDate() + 14);
                setNewDeadline(date.toISOString().split('T')[0]);
              }}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded"
            >
              14天后截止
            </button>
            <button
              onClick={() => {
                const date = new Date();
                date.setMonth(date.getMonth() + 1);
                setNewDeadline(date.toISOString().split('T')[0]);
              }}
              className="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded"
            >
              1个月后截止
            </button>
          </div>
        </div>

        {/* 按钮 */}
        <div className="flex gap-3 p-4 border-t bg-gray-50 rounded-b-xl">
          <button
            onClick={onClose}
            disabled={saving}
            className="flex-1 px-4 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 disabled:opacity-50"
          >
            取消
          </button>
          <button
            onClick={handleSave}
            disabled={saving}
            className="flex-1 px-4 py-2 text-sm text-white bg-indigo-500 rounded-lg hover:bg-indigo-600 disabled:opacity-50"
          >
            {saving ? '保存中...' : '保存'}
          </button>
        </div>
      </div>
    </div>
  );
}
