import { create } from 'zustand';

export type ToastType = 'success' | 'warning' | 'error' | 'info';

export interface Toast {
  id: string;
  type: ToastType;
  title: string;
  message?: string;
  duration?: number;
}

// 独立弹窗通知（居中显示，更醒目）
export interface PopupNotification {
  id: string;
  type: ToastType;
  title: string;
  message: string;
  autoClose?: boolean; // 是否自动关闭，默认 true
  duration?: number;   // 自动关闭时间，默认 5000ms
}

interface ToastStore {
  toasts: Toast[];
  popupNotifications: PopupNotification[];
  addToast: (toast: Omit<Toast, 'id'>) => void;
  removeToast: (id: string) => void;
  addPopupNotification: (notification: Omit<PopupNotification, 'id'>) => void;
  removePopupNotification: (id: string) => void;
  clearAllPopups: () => void;
}

export const useToastStore = create<ToastStore>((set) => ({
  toasts: [],
  popupNotifications: [],
  addToast: (toast) => {
    const id = Date.now().toString() + Math.random().toString(36).substr(2, 9);
    const newToast = { ...toast, id, duration: toast.duration || 3000 };
    
    set((state) => ({
      toasts: [...state.toasts, newToast],
    }));
    
    // 自动移除
    setTimeout(() => {
      set((state) => ({
        toasts: state.toasts.filter((t) => t.id !== id),
      }));
    }, newToast.duration);
  },
  removeToast: (id) => {
    set((state) => ({
      toasts: state.toasts.filter((t) => t.id !== id),
    }));
  },
  addPopupNotification: (notification) => {
    const id = Date.now().toString() + Math.random().toString(36).substr(2, 9);
    const newNotification = { 
      ...notification, 
      id, 
      autoClose: notification.autoClose !== false,
      duration: notification.duration || 5000 
    };
    
    set((state) => ({
      popupNotifications: [...state.popupNotifications, newNotification],
    }));
    
    // 自动关闭（如果启用）
    if (newNotification.autoClose) {
      setTimeout(() => {
        set((state) => ({
          popupNotifications: state.popupNotifications.filter((n) => n.id !== id),
        }));
      }, newNotification.duration);
    }
  },
  removePopupNotification: (id) => {
    set((state) => ({
      popupNotifications: state.popupNotifications.filter((n) => n.id !== id),
    }));
  },
  clearAllPopups: () => {
    set({ popupNotifications: [] });
  },
}));

// 便捷方法 - 小 toast（右上角）
export const toast = {
  success: (title: string, message?: string) => 
    useToastStore.getState().addToast({ type: 'success', title, message }),
  warning: (title: string, message?: string) => 
    useToastStore.getState().addToast({ type: 'warning', title, message }),
  error: (title: string, message?: string) => 
    useToastStore.getState().addToast({ type: 'error', title, message }),
  info: (title: string, message?: string) => 
    useToastStore.getState().addToast({ type: 'info', title, message }),
};

// 便捷方法 - 独立弹窗通知（居中显示）
export const popup = {
  success: (title: string, message: string, autoClose = true) => 
    useToastStore.getState().addPopupNotification({ type: 'success', title, message, autoClose }),
  warning: (title: string, message: string, autoClose = true) => 
    useToastStore.getState().addPopupNotification({ type: 'warning', title, message, autoClose }),
  error: (title: string, message: string, autoClose = true) => 
    useToastStore.getState().addPopupNotification({ type: 'error', title, message, autoClose }),
  info: (title: string, message: string, autoClose = true) => 
    useToastStore.getState().addPopupNotification({ type: 'info', title, message, autoClose }),
};
