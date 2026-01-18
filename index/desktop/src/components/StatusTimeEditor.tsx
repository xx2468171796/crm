import { useState, useEffect } from 'react';
import { X, Clock, Calendar, User } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';

interface StatusTimeInfo {
  status: string;
  label: string;
  changed_at: string | null;
  changed_by: string | null;
}

interface StatusTimeEditorProps {
  open: boolean;
  onClose: () => void;
  statusInfo: StatusTimeInfo | null;
  onSave: (statusKey: string, newTime: string) => Promise<void>;
}

export default function StatusTimeEditor({
  open,
  onClose,
  statusInfo,
  onSave,
}: StatusTimeEditorProps) {
  const { toast } = useToast();
  const [dateTime, setDateTime] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open && statusInfo?.changed_at) {
      // 将 "MM-DD HH:mm" 转换为 datetime-local 格式
      const now = new Date();
      const year = now.getFullYear();
      // 假设是今年
      const [monthDay, time] = statusInfo.changed_at.split(' ');
      const [month, day] = monthDay.split('-');
      const formatted = `${year}-${month}-${day}T${time}`;
      setDateTime(formatted);
    } else {
      setDateTime('');
    }
  }, [open, statusInfo]);

  const handleSave = async () => {
    if (!statusInfo || !dateTime) return;
    setSaving(true);
    try {
      await onSave(statusInfo.status, dateTime);
      onClose();
    } catch (error) {
      console.error('保存失败:', error);
      toast({ title: '错误', description: '保存失败', variant: 'destructive' });
    } finally {
      setSaving(false);
    }
  };

  if (!open || !statusInfo) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      
      <div className="relative bg-white rounded-xl shadow-2xl w-[400px]">
        {/* 头部 */}
        <div className="flex items-center justify-between px-6 py-4 border-b">
          <h2 className="text-lg font-semibold text-gray-800">
            编辑状态时间
          </h2>
          <button
            onClick={onClose}
            className="p-1 text-gray-400 hover:text-gray-600 rounded transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* 内容 */}
        <div className="p-6 space-y-4">
          {/* 状态信息 */}
          <div className="bg-gray-50 rounded-lg p-4">
            <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
              <Clock className="w-4 h-4" />
              状态
            </div>
            <p className="font-medium text-gray-800">{statusInfo.label}</p>
          </div>

          {/* 时间选择 */}
          <div>
            <label className="flex items-center gap-2 text-sm text-gray-500 mb-2">
              <Calendar className="w-4 h-4" />
              变更时间
            </label>
            <input
              type="datetime-local"
              value={dateTime}
              onChange={(e) => setDateTime(e.target.value)}
              className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          {/* 操作人 */}
          {statusInfo.changed_by && (
            <div className="flex items-center gap-2 text-sm text-gray-500">
              <User className="w-4 h-4" />
              操作人：{statusInfo.changed_by}
            </div>
          )}
        </div>

        {/* 底部按钮 */}
        <div className="flex justify-end gap-3 px-6 py-4 border-t bg-gray-50">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50"
          >
            取消
          </button>
          <button
            onClick={handleSave}
            disabled={saving || !dateTime}
            className="px-4 py-2 text-sm text-white bg-blue-500 rounded-lg hover:bg-blue-600 disabled:opacity-50"
          >
            {saving ? '保存中...' : '保存'}
          </button>
        </div>
      </div>
    </div>
  );
}
