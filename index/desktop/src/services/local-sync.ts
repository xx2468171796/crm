import { readDir, exists, mkdir, writeFile } from '@tauri-apps/plugin-fs';
import { useSettingsStore } from '@/stores/settings';
import { useSyncStore } from '@/stores/sync';

export interface LocalFile {
  name: string;
  path: string;
  size: number;
  isDirectory: boolean;
  modifiedAt?: number;
  source: 'local' | 'cloud';
}

export interface SyncStatus {
  localOnly: LocalFile[];
  cloudOnly: string[];
  synced: string[];
}

export class LocalSyncService {
  private intervalId: NodeJS.Timeout | null = null;
  private isScanning = false;

  async scanLocalFolder(folderPath: string): Promise<LocalFile[]> {
    try {
      const exists_ = await exists(folderPath);
      if (!exists_) {
        console.log('[LocalSync] 文件夹不存在:', folderPath);
        return [];
      }

      const entries = await readDir(folderPath);
      const files: LocalFile[] = [];

      for (const entry of entries) {
        if (entry.isFile) {
          files.push({
            name: entry.name,
            path: `${folderPath}/${entry.name}`,
            size: 0,
            isDirectory: false,
            source: 'local',
          });
        }
      }

      return files;
    } catch (err) {
      console.error('[LocalSync] 扫描文件夹失败:', err);
      return [];
    }
  }

  async scanProjectFolder(rootDir: string, groupName: string): Promise<{
    works: LocalFile[];
    models: LocalFile[];
    customer: LocalFile[];
  }> {
    const basePath = `${rootDir}/${groupName}`;
    
    const [works, models, customer] = await Promise.all([
      this.scanLocalFolder(`${basePath}/作品文件`),
      this.scanLocalFolder(`${basePath}/模型文件`),
      this.scanLocalFolder(`${basePath}/客户文件`),
    ]);

    return { works, models, customer };
  }

  async compareWithCloud(
    localFiles: LocalFile[],
    cloudFiles: Array<{ filename: string; file_size: number }>
  ): Promise<SyncStatus> {
    const localNames = new Set(localFiles.map(f => f.name));
    const cloudNames = new Set(cloudFiles.map(f => f.filename));

    const localOnly = localFiles.filter(f => !cloudNames.has(f.name));
    const cloudOnly = cloudFiles
      .filter(f => !localNames.has(f.filename))
      .map(f => f.filename);
    const synced = localFiles
      .filter(f => cloudNames.has(f.name))
      .map(f => f.name);

    return { localOnly, cloudOnly, synced };
  }

  startAutoSync(intervalSeconds: number = 10) {
    if (this.intervalId) {
      this.stopAutoSync();
    }

    console.log(`[LocalSync] 启动自动同步，间隔 ${intervalSeconds} 秒`);
    
    this.intervalId = setInterval(async () => {
      if (this.isScanning) {
        console.log('[LocalSync] 上次扫描未完成，跳过');
        return;
      }

      this.isScanning = true;
      try {
        await this.performSync();
      } catch (err) {
        console.error('[LocalSync] 同步失败:', err);
      } finally {
        this.isScanning = false;
      }
    }, intervalSeconds * 1000);
  }

  stopAutoSync() {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
      console.log('[LocalSync] 停止自动同步');
    }
  }

  private async performSync() {
    const { rootDir, autoSync } = useSettingsStore.getState();
    const { config } = useSyncStore.getState();
    
    if (!autoSync || !rootDir) {
      return;
    }

    console.log('[LocalSync] 开始扫描...', { rootDir, config });
    // 扫描逻辑需要在 React 组件中实现，因为需要 token 和 serverUrl
  }

  async ensureFolderExists(folderPath: string): Promise<boolean> {
    try {
      const folderExists = await exists(folderPath);
      if (!folderExists) {
        await mkdir(folderPath, { recursive: true });
        console.log('[LocalSync] 创建文件夹:', folderPath);
      }
      return true;
    } catch (err) {
      console.error('[LocalSync] 创建文件夹失败:', err);
      return false;
    }
  }

  async downloadFile(url: string, localPath: string): Promise<boolean> {
    try {
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      
      const arrayBuffer = await response.arrayBuffer();
      await writeFile(localPath, new Uint8Array(arrayBuffer));
      console.log('[LocalSync] 下载完成:', localPath);
      return true;
    } catch (err) {
      console.error('[LocalSync] 下载失败:', err);
      return false;
    }
  }
}

export const localSyncService = new LocalSyncService();
