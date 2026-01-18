import { useToastStore, ToastType } from '@/stores/toast';
import { X, CheckCircle2, AlertTriangle, XCircle, Info } from 'lucide-react';

const iconMap: Record<ToastType, React.ReactNode> = {
  success: <CheckCircle2 className="w-5 h-5 text-green-400" />,
  warning: <AlertTriangle className="w-5 h-5 text-yellow-400" />,
  error: <XCircle className="w-5 h-5 text-red-400" />,
  info: <Info className="w-5 h-5 text-blue-400" />,
};

const bgMap: Record<ToastType, string> = {
  success: 'bg-green-900/90 border-green-700',
  warning: 'bg-yellow-900/90 border-yellow-700',
  error: 'bg-red-900/90 border-red-700',
  info: 'bg-blue-900/90 border-blue-700',
};

export default function ToastContainer() {
  const { toasts, removeToast } = useToastStore();

  if (toasts.length === 0) return null;

  return (
    <div className="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-xs">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={`flex items-start gap-3 p-3 rounded-lg border shadow-lg animate-slide-in ${bgMap[toast.type]}`}
        >
          {iconMap[toast.type]}
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-white">{toast.title}</p>
            {toast.message && (
              <p className="text-xs text-slate-300 mt-0.5 truncate">{toast.message}</p>
            )}
          </div>
          <button
            onClick={() => removeToast(toast.id)}
            className="text-slate-400 hover:text-white transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      ))}
    </div>
  );
}
