import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User } from '@/types';
import { usePermissionsStore } from './permissions';
import { getStorageKey } from '@/lib/instanceId';

// 获取存储 key 并打印日志
const authStorageKey = getStorageKey('auth-storage');
console.log('[AuthStore] 使用存储 key:', authStorageKey);

interface AuthState {
  token: string | null;
  user: User | null;
  expireAt: number | null;
  isAuthenticated: boolean;
  setAuth: (token: string, user: User, expireAt: number) => void;
  logout: () => void;
  fetchPermissions: (serverUrl: string) => Promise<void>;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      expireAt: null,
      isAuthenticated: false,
      setAuth: (token, user, expireAt) =>
        set({
          token,
          user,
          expireAt,
          isAuthenticated: true,
        }),
      logout: () => {
        usePermissionsStore.getState().clearPermissions();
        set({
          token: null,
          user: null,
          expireAt: null,
          isAuthenticated: false,
        });
      },
      fetchPermissions: async (serverUrl: string) => {
        const { token } = get();
        if (token && serverUrl) {
          await usePermissionsStore.getState().fetchPermissions(serverUrl, token);
        }
      },
    }),
    {
      name: authStorageKey,
    }
  )
);
