/**
 * 独立弹窗通知组件
 * 在屏幕右上角显示醒目的通知弹窗，支持自动关闭和手动关闭
 */

import { useEffect, useState } from 'react';
import { X, CheckCircle2, AlertTriangle, XCircle, Info } from 'lucide-react';
import { useToastStore, ToastType, PopupNotification as PopupNotificationType } from '@/stores/toast';

const iconMap: Record<ToastType, React.ReactNode> = {
  success: <CheckCircle2 className="w-6 h-6" />,
  warning: <AlertTriangle className="w-6 h-6" />,
  error: <XCircle className="w-6 h-6" />,
  info: <Info className="w-6 h-6" />,
};

const colorMap: Record<ToastType, { bg: string; border: string; icon: string; progress: string }> = {
  success: { 
    bg: 'bg-green-900/95', 
    border: 'border-green-500', 
    icon: 'text-green-400',
    progress: 'bg-green-400'
  },
  warning: { 
    bg: 'bg-yellow-900/95', 
    border: 'border-yellow-500', 
    icon: 'text-yellow-400',
    progress: 'bg-yellow-400'
  },
  error: { 
    bg: 'bg-red-900/95', 
    border: 'border-red-500', 
    icon: 'text-red-400',
    progress: 'bg-red-400'
  },
  info: { 
    bg: 'bg-blue-900/95', 
    border: 'border-blue-500', 
    icon: 'text-blue-400',
    progress: 'bg-blue-400'
  },
};

function PopupItem({ notification, onClose }: { notification: PopupNotificationType; onClose: () => void }) {
  const [progress, setProgress] = useState(100);
  const [isPaused, setIsPaused] = useState(false);
  const colors = colorMap[notification.type];
  const duration = notification.duration || 5000;

  useEffect(() => {
    if (!notification.autoClose || isPaused) return;

    const startTime = Date.now();
    const interval = setInterval(() => {
      const elapsed = Date.now() - startTime;
      const remaining = Math.max(0, 100 - (elapsed / duration) * 100);
      setProgress(remaining);
      
      if (remaining <= 0) {
        clearInterval(interval);
      }
    }, 50);

    return () => clearInterval(interval);
  }, [notification.autoClose, duration, isPaused]);

  return (
    <div
      className={`relative w-96 rounded-lg border-2 shadow-2xl overflow-hidden animate-slide-in ${colors.bg} ${colors.border}`}
      onMouseEnter={() => setIsPaused(true)}
      onMouseLeave={() => setIsPaused(false)}
    >
      {/* 关闭按钮 */}
      <button
        onClick={onClose}
        className="absolute top-2 right-2 p-1.5 rounded-full hover:bg-white/20 transition-colors z-10"
        title="关闭"
      >
        <X className="w-5 h-5 text-white/80 hover:text-white" />
      </button>

      {/* 内容区 */}
      <div className="p-5 pr-12">
        <div className="flex items-start gap-4">
          <div className={`flex-shrink-0 mt-0.5 ${colors.icon}`}>
            {iconMap[notification.type]}
          </div>
          <div className="flex-1 min-w-0">
            <h4 className="text-base font-bold text-white mb-2">
              {notification.title}
            </h4>
            <p className="text-sm text-white/90 whitespace-pre-wrap break-words leading-relaxed">
              {notification.message}
            </p>
            <p className="text-xs text-white/50 mt-3">
              {new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' })}
            </p>
          </div>
        </div>
      </div>

      {/* 进度条（自动关闭时显示） */}
      {notification.autoClose && (
        <div className="h-1.5 bg-white/10">
          <div
            className={`h-full transition-all duration-100 ${colors.progress}`}
            style={{ width: `${progress}%` }}
          />
        </div>
      )}
    </div>
  );
}

export default function PopupNotificationContainer() {
  const { popupNotifications, removePopupNotification } = useToastStore();

  if (popupNotifications.length === 0) return null;

  return (
    <div className="fixed top-4 right-4 z-[9999] flex flex-col gap-3">
      {popupNotifications.map((notification) => (
        <PopupItem
          key={notification.id}
          notification={notification}
          onClose={() => removePopupNotification(notification.id)}
        />
      ))}
    </div>
  );
}
