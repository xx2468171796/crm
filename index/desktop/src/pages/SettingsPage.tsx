import { useState, useEffect } from 'react';
import { FolderOpen, Save, Zap } from 'lucide-react';
import { useSettingsStore } from '@/stores/settings';
import { useSyncStore } from '@/stores/sync';
import { toast } from '@/hooks/use-toast';
import { selectDirectory, scanRootDirectory } from '@/lib/tauri';
import { syncSettings, onEvent, EVENTS } from '@/lib/windowEvents';

interface AccelerationNode {
  id: number;
  node_name: string;
  endpoint_url: string;
  region_code: string | null;
  is_default: number;
}

export default function SettingsPage() {
  const settings = useSettingsStore();
  const { setLocalGroups, config: syncConfig, setConfig: setSyncConfig } = useSyncStore();
  
  const [rootDir, setRootDir] = useState(settings.rootDir);
  const [serverUrl, setServerUrl] = useState(settings.serverUrl);
  const [autoSync, setAutoSync] = useState(settings.autoSync);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [maxConcurrentUploads, setMaxConcurrentUploads] = useState(settings.maxConcurrentUploads);
  const [partSize, setPartSize] = useState(settings.partSize);
  const [, setScanning] = useState(false);
  const [accelerationNodes, setAccelerationNodes] = useState<AccelerationNode[]>([]);
  const [selectedNodeId, setSelectedNodeId] = useState<number | null>(settings.accelerationNodeId);
  const [loadingNodes, setLoadingNodes] = useState(false);

  const handleSave = () => {
    settings.setRootDir(rootDir);
    settings.setServerUrl(serverUrl);
    settings.setAutoSync(autoSync);
    settings.setSyncInterval(syncInterval);
    settings.setMaxConcurrentUploads(maxConcurrentUploads);
    settings.setPartSize(partSize);
    
    // 保存加速节点设置
    if (selectedNodeId) {
      const node = accelerationNodes.find(n => n.id === selectedNodeId);
      if (node) {
        settings.setAccelerationNode(node.id, node.endpoint_url, node.node_name);
      }
    } else {
      settings.setAccelerationNode(null, '', '');
    }
    
    // 同步设置到悬浮窗
    syncSettings({ serverUrl, rootDir });
    
    toast({ title: '设置已保存', variant: 'success' });
  };
  
  // 加载加速节点列表
  const loadAccelerationNodes = async () => {
    if (!serverUrl) return;
    
    setLoadingNodes(true);
    try {
      const res = await fetch(`${serverUrl}/api/s3_acceleration_nodes.php?action=list`);
      const data = await res.json();
      if (data.success && data.data) {
        setAccelerationNodes(data.data);
      }
    } catch (err) {
      console.error('[SETTINGS] 加载加速节点失败:', err);
    } finally {
      setLoadingNodes(false);
    }
  };
  
  // 当服务器地址变化时重新加载节点
  useEffect(() => {
    if (serverUrl) {
      loadAccelerationNodes();
    }
  }, [serverUrl]);
  
  // 监听悬浮窗的设置请求
  useEffect(() => {
    let unlisten: (() => void) | null = null;
    
    const setupListener = async () => {
      unlisten = await onEvent(EVENTS.REQUEST_SETTINGS, () => {
        console.log('[SettingsPage] 收到设置请求，发送当前设置');
        syncSettings({ 
          serverUrl: settings.serverUrl, 
          rootDir: settings.rootDir 
        });
      });
    };
    
    setupListener();
    return () => {
      if (unlisten) unlisten();
    };
  }, [settings.serverUrl, settings.rootDir]);

  const handleSelectFolder = async () => {
    try {
      const selected = await selectDirectory();
      if (selected) {
        setRootDir(selected);
        settings.setRootDir(selected);
        await handleScanDirectory(selected);
      }
    } catch (error) {
      console.error('[SYNC_DEBUG] 选择目录失败:', error);
      toast({ title: '选择目录失败', variant: 'destructive' });
    }
  };
  
  const handleScanDirectory = async (dir?: string) => {
    const targetDir = dir || rootDir;
    if (!targetDir) {
      toast({ title: '请先选择同步目录', variant: 'destructive' });
      return;
    }
    
    setScanning(true);
    try {
      const groups = await scanRootDirectory(targetDir);
      setLocalGroups(groups.map(g => ({
        groupCode: g.group_code,
        groupName: g.group_name,
        path: g.path,
        hasWorks: g.has_works,
        hasModels: g.has_models,
        hasCustomer: g.has_customer,
      })));
      toast({ 
        title: `扫描完成`, 
        description: `发现 ${groups.length} 个群目录`,
        variant: 'success' 
      });
    } catch (error) {
      console.error('[SYNC_DEBUG] 扫描目录失败:', error);
      toast({ title: '扫描目录失败', variant: 'destructive' });
    } finally {
      setScanning(false);
    }
  };

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <header className="h-14 flex items-center justify-between px-6 border-b border-border-light bg-surface-light">
        <h1 className="text-lg font-semibold text-text-main">设置</h1>
        <button
          onClick={handleSave}
          className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white text-sm rounded-lg hover:bg-primary/90"
        >
          <Save className="w-4 h-4" />
          保存
        </button>
      </header>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6">
        <div className="max-w-2xl space-y-6">
          {/* 同步目录 */}
          <section className="bg-surface-light border border-border-light rounded-lg p-5">
            <h2 className="font-medium text-text-main mb-4">同步目录</h2>
            <div className="flex gap-2">
              <input
                type="text"
                value={rootDir}
                onChange={(e) => setRootDir(e.target.value)}
                placeholder="选择本地根目录"
                className="flex-1 px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
                readOnly
              />
              <button
                onClick={handleSelectFolder}
                className="flex items-center gap-1.5 px-4 py-2 border border-border-light rounded-lg text-sm hover:bg-background-light"
              >
                <FolderOpen className="w-4 h-4" />
                选择
              </button>
            </div>
            <p className="text-xs text-text-secondary mt-2">
              根目录下应包含以 "群码_群名" 命名的文件夹，如 "Q2025122001_张三"
            </p>
          </section>

          {/* 服务器 */}
          <section className="bg-surface-light border border-border-light rounded-lg p-5">
            <h2 className="font-medium text-text-main mb-4">服务器</h2>
            <input
              type="url"
              value={serverUrl}
              onChange={(e) => setServerUrl(e.target.value)}
              placeholder="http://192.168.1.100:8080"
              className="w-full px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
            />
            <p className="text-xs text-text-secondary mt-2">
              请输入完整地址，包含端口号（如 http://192.168.110.251:8080）
            </p>
          </section>

          {/* 加速节点 */}
          <section className="bg-surface-light border border-border-light rounded-lg p-5">
            <h2 className="font-medium text-text-main mb-4 flex items-center gap-2">
              <Zap className="w-4 h-4 text-yellow-500" />
              上传/下载加速
            </h2>
            <select
              value={selectedNodeId || ''}
              onChange={(e) => setSelectedNodeId(e.target.value ? Number(e.target.value) : null)}
              className="w-full px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
              disabled={loadingNodes}
            >
              <option value="">直连（不加速）</option>
              {accelerationNodes.map(node => (
                <option key={node.id} value={node.id}>
                  {node.is_default ? '⭐ ' : ''}{node.node_name}
                  {node.region_code ? ` (${node.region_code})` : ''}
                </option>
              ))}
            </select>
            {selectedNodeId && (
              <p className="text-xs text-green-600 mt-2">
                ✓ 已选择加速节点，上传/下载将通过代理加速
              </p>
            )}
            {!selectedNodeId && (
              <p className="text-xs text-text-secondary mt-2">
                选择加速节点可提升不同区域的上传/下载速度
              </p>
            )}
            {accelerationNodes.length === 0 && !loadingNodes && serverUrl && (
              <p className="text-xs text-orange-500 mt-2">
                暂无可用加速节点，请联系管理员配置
              </p>
            )}
          </section>

          {/* 同步设置 */}
          <section className="bg-surface-light border border-border-light rounded-lg p-5">
            <h2 className="font-medium text-text-main mb-4">同步设置</h2>
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-text-main">自动同步</p>
                  <p className="text-xs text-text-secondary">定时检查并上传变更文件</p>
                </div>
                <button
                  onClick={() => setAutoSync(!autoSync)}
                  className={`w-11 h-6 rounded-full transition-colors ${
                    autoSync ? 'bg-primary' : 'bg-border-light'
                  }`}
                >
                  <span
                    className={`block w-5 h-5 bg-white rounded-full shadow transition-transform ${
                      autoSync ? 'translate-x-5' : 'translate-x-0.5'
                    }`}
                  />
                </button>
              </div>

              {autoSync && (
                <div>
                  <label className="text-sm text-text-main">扫描间隔（秒）</label>
                  <input
                    type="number"
                    value={syncInterval}
                    onChange={(e) => setSyncInterval(Number(e.target.value))}
                    min={5}
                    max={300}
                    className="mt-1 w-32 px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
                  />
                  <p className="text-xs text-text-secondary mt-1">
                    建议 10-30 秒，默认 10 秒
                  </p>
                </div>
              )}
              
              <div className="pt-2 border-t border-border-light">
                <p className="text-sm text-text-main mb-3">自动上传/下载</p>
                <div className="space-y-3 pl-2">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={syncConfig.autoUploadWorks}
                      onChange={(e) => setSyncConfig({ autoUploadWorks: e.target.checked })}
                      className="w-4 h-4 rounded border-border-light text-primary focus:ring-primary"
                    />
                    <span className="text-sm text-text-main">作品文件自动上传</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={syncConfig.autoUploadModels}
                      onChange={(e) => setSyncConfig({ autoUploadModels: e.target.checked })}
                      className="w-4 h-4 rounded border-border-light text-primary focus:ring-primary"
                    />
                    <span className="text-sm text-text-main">模型文件自动上传</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={syncConfig.autoDownload}
                      onChange={(e) => setSyncConfig({ autoDownload: e.target.checked })}
                      className="w-4 h-4 rounded border-border-light text-primary focus:ring-primary"
                    />
                    <span className="text-sm text-text-main">客户文件自动下载</span>
                  </label>
                </div>
              </div>

              <div>
                <label className="text-sm text-text-main">并发上传数</label>
                <input
                  type="number"
                  value={maxConcurrentUploads}
                  onChange={(e) => setMaxConcurrentUploads(Number(e.target.value))}
                  min={1}
                  max={10}
                  className="mt-1 w-32 px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
                />
              </div>

              <div>
                <label className="text-sm text-text-main">分片大小（MB）</label>
                <input
                  type="number"
                  value={partSize}
                  onChange={(e) => setPartSize(Number(e.target.value))}
                  min={5}
                  max={100}
                  className="mt-1 w-32 px-3 py-2 border border-border-light rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/50"
                />
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}
