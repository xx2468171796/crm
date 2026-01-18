import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { Settings } from '@/types';
import { getStorageKey } from '@/lib/instanceId';

interface SettingsState extends Settings {
  lastSyncTime: number;
  autoSyncEnabled: boolean;
  autoSyncInterval: number;
  accelerationNodeId: number | null;
  accelerationNodeUrl: string;
  accelerationNodeName: string;
  
  setRootDir: (dir: string) => void;
  setServerUrl: (url: string) => void;
  setAutoSync: (enabled: boolean) => void;
  setSyncInterval: (minutes: number) => void;
  setMaxConcurrentUploads: (count: number) => void;
  setMaxConcurrentDownloads: (count: number) => void;
  setPartSize: (mb: number) => void;
  setStartOnBoot: (enabled: boolean) => void;
  setMinimizeToTray: (enabled: boolean) => void;
  setLastSyncTime: (time: number) => void;
  setAutoSyncEnabled: (enabled: boolean) => void;
  setAutoSyncInterval: (minutes: number) => void;
  setAccelerationNode: (id: number | null, url: string, name: string) => void;
  reset: () => void;
}

const defaultSettings: Settings = {
  rootDir: '',
  serverUrl: '',
  autoSync: false,
  syncInterval: 30,
  maxConcurrentUploads: 3,
  maxConcurrentDownloads: 5,
  partSize: 16,
  startOnBoot: false,
  minimizeToTray: true,
};

export const useSettingsStore = create<SettingsState>()(
  persist(
    (set) => ({
      ...defaultSettings,
      lastSyncTime: 0,
      autoSyncEnabled: false,
      autoSyncInterval: 30,
      accelerationNodeId: null,
      accelerationNodeUrl: '',
      accelerationNodeName: '',
      setRootDir: (dir) => set({ rootDir: dir }),
      setServerUrl: (url) => set({ serverUrl: url }),
      setAutoSync: (enabled) => set({ autoSync: enabled }),
      setSyncInterval: (minutes) => set({ syncInterval: minutes }),
      setMaxConcurrentUploads: (count) => set({ maxConcurrentUploads: count }),
      setMaxConcurrentDownloads: (count) => set({ maxConcurrentDownloads: count }),
      setPartSize: (mb) => set({ partSize: mb }),
      setStartOnBoot: (enabled) => set({ startOnBoot: enabled }),
      setMinimizeToTray: (enabled) => set({ minimizeToTray: enabled }),
      setLastSyncTime: (time) => set({ lastSyncTime: time }),
      setAutoSyncEnabled: (enabled) => set({ autoSyncEnabled: enabled }),
      setAutoSyncInterval: (minutes) => set({ autoSyncInterval: minutes }),
      setAccelerationNode: (id, url, name) => set({ accelerationNodeId: id, accelerationNodeUrl: url, accelerationNodeName: name }),
      reset: () => set({ ...defaultSettings, lastSyncTime: 0, autoSyncEnabled: false, autoSyncInterval: 30, accelerationNodeId: null, accelerationNodeUrl: '', accelerationNodeName: '' }),
    }),
    {
      name: getStorageKey('settings-storage'),
    }
  )
);
