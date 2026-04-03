import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { getStorageKey } from '@/lib/instanceId';

export interface LocalGroup {
  groupCode: string;
  groupName: string;
  path: string;
  hasWorks: boolean;
  hasModels: boolean;
  hasCustomer: boolean;
}

export interface UploadTask {
  id: string;
  groupCode: string;
  assetType: 'works' | 'models' | 'customer';
  relPath: string;
  filename: string;
  localPath: string;
  filesize: number;
  projectId?: number;
  uploadId?: string;
  storageKey?: string;
  partSize?: number;
  status: 'pending' | 'uploading' | 'paused' | 'completed' | 'failed';
  progress: number;
  uploadedParts: number;
  totalParts: number;
  speed: number;
  error?: string;
  createdAt: number;
  updatedAt: number;
}

export interface DownloadTask {
  id: string;
  groupCode: string;
  relPath: string;
  filename: string;
  localPath: string;
  filesize: number;
  status: 'pending' | 'downloading' | 'paused' | 'completed' | 'failed';
  progress: number;
  speed: number;
  error?: string;
  createdAt: number;
  updatedAt: number;
}

export interface ConflictInfo {
  localPath: string;
  relPath: string;
  localSize: number;
  localModified: number;
  remoteSize: number;
  remoteModified: string;
  groupCode: string;
  assetType: 'works' | 'models';
}

interface SyncConfig {
  syncRoot: string;
  scanInterval: number;
  autoUpload: boolean;
  autoUploadWorks: boolean;
  autoUploadModels: boolean;
  autoDownload: boolean;
}

interface SyncState {
  config: SyncConfig;
  localGroups: LocalGroup[];
  uploadTasks: UploadTask[];
  downloadTasks: DownloadTask[];
  conflicts: ConflictInfo[];
  
  setConfig: (config: Partial<SyncConfig>) => void;
  setLocalGroups: (groups: LocalGroup[]) => void;
  
  addUploadTask: (task: UploadTask) => void;
  updateUploadTask: (id: string, updates: Partial<UploadTask>) => void;
  removeUploadTask: (id: string) => void;
  clearCompletedUploads: () => void;
  
  addDownloadTask: (task: DownloadTask) => void;
  updateDownloadTask: (id: string, updates: Partial<DownloadTask>) => void;
  removeDownloadTask: (id: string) => void;
  clearCompletedDownloads: () => void;
  
  setConflicts: (conflicts: ConflictInfo[]) => void;
  addConflict: (conflict: ConflictInfo) => void;
  removeConflict: (localPath: string) => void;
  clearConflicts: () => void;
}

export const useSyncStore = create<SyncState>()(
  persist(
    (set) => ({
      config: {
        syncRoot: '',
        scanInterval: 10,
        autoUpload: true,
        autoUploadWorks: true,
        autoUploadModels: true,
        autoDownload: true,
      },
      localGroups: [],
      uploadTasks: [],
      downloadTasks: [],
      conflicts: [],
      
      setConfig: (newConfig) => set((state) => ({
        config: { ...state.config, ...newConfig },
      })),
      
      setLocalGroups: (groups) => set({ localGroups: groups }),
      
      addUploadTask: (task) =>
        set((state) => ({
          uploadTasks: [...state.uploadTasks, task],
        })),
      
      updateUploadTask: (id, updates) =>
        set((state) => ({
          uploadTasks: state.uploadTasks.map((t) =>
            t.id === id ? { ...t, ...updates, updatedAt: Date.now() } : t
          ),
        })),
      
      removeUploadTask: (id) =>
        set((state) => ({
          uploadTasks: state.uploadTasks.filter((t) => t.id !== id),
        })),
      
      clearCompletedUploads: () =>
        set((state) => ({
          uploadTasks: state.uploadTasks.filter((t) => t.status !== 'completed'),
        })),
      
      addDownloadTask: (task) =>
        set((state) => ({
          downloadTasks: [...state.downloadTasks, task],
        })),
      
      updateDownloadTask: (id, updates) =>
        set((state) => ({
          downloadTasks: state.downloadTasks.map((t) =>
            t.id === id ? { ...t, ...updates, updatedAt: Date.now() } : t
          ),
        })),
      
      removeDownloadTask: (id) =>
        set((state) => ({
          downloadTasks: state.downloadTasks.filter((t) => t.id !== id),
        })),
      
      clearCompletedDownloads: () =>
        set((state) => ({
          downloadTasks: state.downloadTasks.filter((t) => t.status !== 'completed'),
        })),
      
      setConflicts: (conflicts) => set({ conflicts }),
      
      addConflict: (conflict) =>
        set((state) => ({
          conflicts: [...state.conflicts, conflict],
        })),
      
      removeConflict: (localPath) =>
        set((state) => ({
          conflicts: state.conflicts.filter((c) => c.localPath !== localPath),
        })),
      
      clearConflicts: () => set({ conflicts: [] }),
    }),
    {
      name: getStorageKey('sync-storage'),
    }
  )
);
