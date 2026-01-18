import { AlertTriangle, Info, CheckCircle, X } from 'lucide-react';

type DialogType = 'confirm' | 'warning' | 'info' | 'success';

interface ConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: string;
  type?: DialogType;
  confirmText?: string;
  cancelText?: string;
  loading?: boolean;
}

const ICONS: Record<DialogType, any> = {
  confirm: Info,
  warning: AlertTriangle,
  info: Info,
  success: CheckCircle,
};

const COLORS: Record<DialogType, string> = {
  confirm: 'bg-blue-100 text-blue-600',
  warning: 'bg-yellow-100 text-yellow-600',
  info: 'bg-blue-100 text-blue-600',
  success: 'bg-green-100 text-green-600',
};

export default function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  type = 'confirm',
  confirmText = '确定',
  cancelText = '取消',
  loading = false,
}: ConfirmDialogProps) {
  if (!open) return null;

  const Icon = ICONS[type];
  const colorClass = COLORS[type];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      
      <div className="relative bg-white rounded-xl shadow-2xl w-[400px] max-w-[90vw]">
        {/* 关闭按钮 */}
        <button
          onClick={onClose}
          className="absolute top-4 right-4 p-1 text-gray-400 hover:text-gray-600 rounded transition-colors"
        >
          <X className="w-5 h-5" />
        </button>

        {/* 内容 */}
        <div className="p-6 pt-8 text-center">
          <div className={`w-12 h-12 mx-auto rounded-full ${colorClass} flex items-center justify-center mb-4`}>
            <Icon className="w-6 h-6" />
          </div>
          
          <h3 className="text-lg font-semibold text-gray-800 mb-2">{title}</h3>
          <p className="text-sm text-gray-500">{message}</p>
        </div>

        {/* 按钮 */}
        <div className="flex gap-3 p-4 border-t bg-gray-50 rounded-b-xl">
          <button
            onClick={onClose}
            disabled={loading}
            className="flex-1 px-4 py-2 text-sm text-gray-600 bg-white border rounded-lg hover:bg-gray-50 disabled:opacity-50"
          >
            {cancelText}
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className={`flex-1 px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50 ${
              type === 'warning' 
                ? 'bg-yellow-500 hover:bg-yellow-600' 
                : 'bg-blue-500 hover:bg-blue-600'
            }`}
          >
            {loading ? '处理中...' : confirmText}
          </button>
        </div>
      </div>
    </div>
  );
}

// 简化的 alert 替代函数
export function showAlert(message: string, type: 'error' | 'success' | 'info' = 'info') {
  // 使用 toaster 或其他通知系统
  // 这里暂时用 console 代替
  console.log(`[${type}] ${message}`);
}
