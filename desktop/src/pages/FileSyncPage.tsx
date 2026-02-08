import { useState, useEffect, useCallback } from 'react';
import { 
  FolderSync, Download, CheckCircle, Clock, 
  RefreshCw, FolderOpen, HardDrive, AlertTriangle, FileText, ChevronRight
} from 'lucide-react';
import { invoke } from '@tauri-apps/api/core';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { useToast } from '@/hooks/use-toast';

interface SyncStatus {
  pending_downloads: number;
  pending_approvals: number;
  to_review: number;
  project_count: number;
}

interface ProjectInfo {
  id: number;
  group_name: string | null;
  group_code?: string | null;
  project_name?: string | null;
  project_code?: string | null;
}

interface RecentFile {
  id: number;
  original_name: string;
  folder_type: string;
  file_size: number;
  created_at: string;
  group_name: string;
  uploader_name: string;
}

interface FileApproval {
  approval_id: number;
  file_id: number;
  approval_status: string;
  original_name: string;
  file_size: number;
  folder_type: string;
  project_name: string;
  customer_name: string;
  group_name: string;
  submitter_name: string;
  submit_time: string;
}

interface CacheProject {
  id: number;
  code: string;
  name: string;
  status: string;
  customer_name: string;
  group_name: string;
  group_code?: string | null;
  file_count: number;
  total_size: number;
}

interface Submitter {
  id: number;
  name: string;
}

interface FolderGroup {
  folder_key: string;
  project_id: number;
  project_name: string;
  project_code: string;
  customer_name: string;
  group_name: string;
  group_code?: string | null;
  folder_type: string;
  file_count: number;
  files: FileApproval[];
}

function sanitizeFolderName(name: string): string {
  return (name || '').replace(/[\/\\:*?"<>|]/g, '_');
}

function buildGroupFolderName(groupCode?: string | null, groupName?: string | null): string {
  const code = groupCode || '';
  const name = sanitizeFolderName(groupName || '');
  if (code && name) return `${code}_${name}`;
  return code || name || '';
}

function toWindowsPath(p: string): string {
  return p.replace(/\//g, '\\');
}

// 预设驳回原因
const REJECT_REASONS = [
  '文件格式不正确',
  '文件质量不达标',
  '需要修改后重新提交',
  '文件内容不完整',
  '其他',
];

export default function FileSyncPage() {
  const { token } = useAuthStore();
  const { serverUrl } = useSettingsStore();
  const { toast } = useToast();
  
  const [loading, setLoading] = useState(true);
  const [syncStatus, setSyncStatus] = useState<SyncStatus | null>(null);
  const [projects, setProjects] = useState<ProjectInfo[]>([]);
  const [recentFiles, setRecentFiles] = useState<RecentFile[]>([]);
  const [approvals, setApprovals] = useState<FileApproval[]>([]);
  const [cacheProjects, setCacheProjects] = useState<CacheProject[]>([]);
  const [activeTab, setActiveTab] = useState<'sync' | 'approval' | 'cache'>('sync');
  const [downloadingFiles, setDownloadingFiles] = useState<Set<number>>(new Set());
  
  // 筛选和驳回
  const [submitters, setSubmitters] = useState<Submitter[]>([]);
  const [selectedSubmitter, setSelectedSubmitter] = useState<number>(0);
  const [departments, setDepartments] = useState<{id: number; name: string}[]>([]);
  const [selectedDepartment, setSelectedDepartment] = useState<number>(0);
  const [folderTypes, setFolderTypes] = useState<string[]>([]);
  const [selectedFolderType, setSelectedFolderType] = useState('');
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [rejectingApprovalId, setRejectingApprovalId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [customReason, setCustomReason] = useState('');
  
  // 文件夹分组
  const [folders, setFolders] = useState<FolderGroup[]>([]);
  const [expandedFolders, setExpandedFolders] = useState<Set<string>>(new Set());
  const [batchRejectFolder, setBatchRejectFolder] = useState<FolderGroup | null>(null);
  const isDownloading = downloadingFiles.size > 0;

  // 加载同步状态
  const loadSyncStatus = useCallback(async () => {
    if (!serverUrl || !token) return;
    try {
      const res = await fetch(`${serverUrl}/api/desktop_sync_status.php`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success) {
        setSyncStatus(data.sync_status);
        setProjects(data.projects || []);
        setRecentFiles(data.recent_files || []);
      }
    } catch (e) {
      console.error('加载同步状态失败:', e);
    }
  }, [serverUrl, token]);

  // 加载待审批文件
  const loadApprovals = useCallback(async (filters?: {submitterId?: number; departmentId?: number; folderType?: string}) => {
    if (!serverUrl || !token) return;
    try {
      const params = new URLSearchParams({ status: 'pending' });
      if (filters?.submitterId && filters.submitterId > 0) {
        params.append('submitter_id', String(filters.submitterId));
      }
      if (filters?.departmentId && filters.departmentId > 0) {
        params.append('department_id', String(filters.departmentId));
      }
      if (filters?.folderType) {
        params.append('folder_type', filters.folderType);
      }
      const res = await fetch(`${serverUrl}/api/desktop_file_approval.php?${params}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success) {
        setApprovals(data.approvals || []);
        setFolders(data.folders || []);
        if (data.submitters) setSubmitters(data.submitters);
        if (data.departments) setDepartments(data.departments);
        if (data.folder_types) setFolderTypes(data.folder_types);
      }
    } catch (e) {
      console.error('加载审批列表失败:', e);
    }
  }, [serverUrl, token]);

  // 加载可缓存项目
  const loadCacheProjects = useCallback(async () => {
    if (!serverUrl || !token) return;
    try {
      const res = await fetch(`${serverUrl}/api/desktop_cache_project.php`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success) {
        setCacheProjects(data.projects || []);
      }
    } catch (e) {
      console.error('加载缓存项目失败:', e);
    }
  }, [serverUrl, token]);

  // 初始加载
  useEffect(() => {
    const load = async () => {
      setLoading(true);
      await Promise.all([loadSyncStatus(), loadApprovals(), loadCacheProjects()]);
      setLoading(false);
    };
    load();
  }, [loadSyncStatus, loadApprovals, loadCacheProjects]);

  // 打开项目文件夹
  const openProjectFolder = async (folderName: string, subFolder?: string) => {
    try {
      const workDir = useSettingsStore.getState().rootDir;
      
      await invoke('open_project_folder', {
        workDir,
        projectName: folderName,
        subFolder: subFolder || null,
      });
      
      toast({ title: '已打开文件夹', description: subFolder ? `${folderName}/${subFolder}` : folderName });
    } catch (e) {
      console.error('打开文件夹失败:', e);
      toast({ title: '打开文件夹失败', variant: 'destructive' });
    }
  };

  // 审批文件
  const handleApproval = async (approvalId: number, action: 'approve' | 'reject', note?: string) => {
    if (!serverUrl || !token) return;
    try {
      const res = await fetch(`${serverUrl}/api/desktop_file_approval.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action, approval_id: approvalId, note }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: data.message });
        loadApprovals({submitterId: selectedSubmitter, departmentId: selectedDepartment, folderType: selectedFolderType});
        loadSyncStatus();
      } else {
        toast({ title: data.error, variant: 'destructive' });
      }
    } catch (e) {
      console.error('审批失败:', e);
      toast({ title: '审批失败', variant: 'destructive' });
    }
  };

  // 打开驳回弹窗
  const openRejectModal = (approvalId: number) => {
    setRejectingApprovalId(approvalId);
    setRejectReason('');
    setCustomReason('');
    setShowRejectModal(true);
  };

  // 确认驳回
  const confirmReject = () => {
    if (!rejectingApprovalId) return;
    const reason = rejectReason === '其他' ? customReason : rejectReason;
    handleApproval(rejectingApprovalId, 'reject', reason);
    setShowRejectModal(false);
    setRejectingApprovalId(null);
  };

  // 批量审批（文件夹级别）
  const handleBatchApproval = async (folder: FolderGroup, action: 'batch_approve' | 'batch_reject', note?: string) => {
    if (!serverUrl || !token) return;
    const approvalIds = folder.files.map(f => f.approval_id);
    try {
      const res = await fetch(`${serverUrl}/api/desktop_file_approval.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action, approval_ids: approvalIds, note }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: data.message });
        loadApprovals({submitterId: selectedSubmitter, departmentId: selectedDepartment, folderType: selectedFolderType});
      } else {
        toast({ title: data.error, variant: 'destructive' });
      }
    } catch (e) {
      console.error('批量审批失败:', e);
      toast({ title: '批量审批失败', variant: 'destructive' });
    }
  };

  // 展开/收起文件夹
  const toggleFolder = (folderKey: string) => {
    setExpandedFolders(prev => {
      const newSet = new Set(prev);
      if (newSet.has(folderKey)) {
        newSet.delete(folderKey);
      } else {
        newSet.add(folderKey);
      }
      return newSet;
    });
  };

  // 筛选变化时重新加载
  const handleFilterChange = (type: 'submitter' | 'department' | 'folderType', value: string) => {
    const newSubmitter = type === 'submitter' ? parseInt(value, 10) : selectedSubmitter;
    const newDepartment = type === 'department' ? parseInt(value, 10) : selectedDepartment;
    const newFolderType = type === 'folderType' ? value : selectedFolderType;
    
    if (type === 'submitter') setSelectedSubmitter(newSubmitter);
    if (type === 'department') setSelectedDepartment(newDepartment);
    if (type === 'folderType') setSelectedFolderType(newFolderType);
    
    loadApprovals({submitterId: newSubmitter, departmentId: newDepartment, folderType: newFolderType});
  };

  // 缓存项目文件
  const cacheProjectFiles = async (project: CacheProject) => {
    if (!serverUrl || !token) return;
    
    try {
      toast({ title: '开始下载', description: `正在获取 ${project.name} 的文件列表...` });
      
      const res = await fetch(`${serverUrl}/api/desktop_cache_project.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ 
          project_id: project.id,
          folder_types: ['作品文件', '模型文件'],
        }),
      });
      const data = await res.json();
      
      if (!data.success) {
        toast({ title: data.error, variant: 'destructive' });
        return;
      }
      
      const files = data.files || [];
      if (files.length === 0) {
        toast({ title: '没有可下载的文件' });
        return;
      }
      
      // 获取工作目录
      const workDir = useSettingsStore.getState().rootDir;
      const groupName = buildGroupFolderName(project.group_code, project.group_name || project.customer_name);
      const projectFolder = sanitizeFolderName(project.name || project.code || `项目${project.id}`);
      const projectPathName = groupName ? `${groupName}/${projectFolder}` : projectFolder;
      
      // 创建文件夹
      await invoke('create_project_folders', {
        workDir,
        projectName: projectPathName,
      });
      
      // 下载文件
      let downloaded = 0;
      for (const file of files) {
        try {
          setDownloadingFiles(prev => new Set(prev).add(file.id));
          
          const savePath = toWindowsPath(`${workDir}/${projectPathName}/${file.folder_type}/${file.name}`);
          await invoke('download_file', {
            taskId: `cache-${file.id}`,
            url: file.download_url,
            savePath,
          });
          
          downloaded++;
        } catch (e) {
          console.error(`下载文件 ${file.name} 失败:`, e);
        } finally {
          setDownloadingFiles(prev => {
            const next = new Set(prev);
            next.delete(file.id);
            return next;
          });
        }
      }
      
      toast({ 
        title: '下载完成', 
        description: `成功下载 ${downloaded}/${files.length} 个文件` 
      });
      
    } catch (e) {
      console.error('缓存项目失败:', e);
      toast({ title: '缓存失败', variant: 'destructive' });
    }
  };

  // 格式化文件大小
  const formatSize = (bytes: number) => {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50">
      {/* 头部 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <FolderSync className="w-6 h-6 text-blue-500" />
            <h1 className="text-lg font-semibold text-gray-800">文件同步</h1>
          </div>
          <button
            onClick={() => {
              loadSyncStatus();
              loadApprovals();
              loadCacheProjects();
            }}
            className="flex items-center gap-2 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition-colors"
          >
            <RefreshCw className="w-4 h-4" />
            刷新
          </button>
        </div>
        
        {/* 状态卡片 */}
        {syncStatus && (
          <div className="grid grid-cols-4 gap-4 mt-4">
            <div className="bg-blue-50 rounded-lg p-3">
              <div className="text-2xl font-bold text-blue-600">{syncStatus.project_count}</div>
              <div className="text-sm text-blue-600">负责项目</div>
            </div>
            <div className="bg-yellow-50 rounded-lg p-3">
              <div className="text-2xl font-bold text-yellow-600">{syncStatus.pending_downloads}</div>
              <div className="text-sm text-yellow-600">待下载文件</div>
            </div>
            <div className="bg-purple-50 rounded-lg p-3">
              <div className="text-2xl font-bold text-purple-600">{syncStatus.pending_approvals}</div>
              <div className="text-sm text-purple-600">我的待审批</div>
            </div>
            <div className="bg-red-50 rounded-lg p-3">
              <div className="text-2xl font-bold text-red-600">{syncStatus.to_review}</div>
              <div className="text-sm text-red-600">需要审批</div>
            </div>
          </div>
        )}
      </div>
      
      {/* Tab 切换 */}
      <div className="bg-white border-b px-6">
        <div className="flex gap-6">
          <button
            onClick={() => setActiveTab('sync')}
            className={`py-3 border-b-2 text-sm font-medium transition-colors ${
              activeTab === 'sync' 
                ? 'border-blue-500 text-blue-600' 
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <Download className="w-4 h-4 inline-block mr-1" />
            文件同步
          </button>
          <button
            onClick={() => setActiveTab('approval')}
            className={`py-3 border-b-2 text-sm font-medium transition-colors ${
              activeTab === 'approval' 
                ? 'border-blue-500 text-blue-600' 
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <Clock className="w-4 h-4 inline-block mr-1" />
            待审批 ({approvals.length})
          </button>
          <button
            onClick={() => setActiveTab('cache')}
            className={`py-3 border-b-2 text-sm font-medium transition-colors ${
              activeTab === 'cache' 
                ? 'border-blue-500 text-blue-600' 
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <HardDrive className="w-4 h-4 inline-block mr-1" />
            选择性缓存
          </button>
        </div>
      </div>
      
      {/* 内容区 */}
      <div className="flex-1 overflow-auto p-6">
        {loading ? (
          <div className="flex items-center justify-center h-64 text-gray-500">
            加载中...
          </div>
        ) : activeTab === 'sync' ? (
          <div className="space-y-6">
            {/* 我的项目 */}
            <div className="bg-white rounded-lg border p-4">
              <h3 className="font-semibold text-gray-800 mb-3">我负责的项目</h3>
              {projects.length === 0 ? (
                <p className="text-gray-500 text-sm">暂无负责的项目</p>
              ) : (
                <div className="grid grid-cols-3 gap-3">
                  {projects.map((p) => (
                    <div
                      key={p.id}
                      className="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
                    >
                      <span className="text-sm text-gray-700">{p.project_name || p.group_name || `项目 #${p.id}`}</span>
                      <button
                        onClick={() => {
                          const groupFolder = buildGroupFolderName(p.group_code, p.group_name);
                          const projectFolder = sanitizeFolderName(p.project_name || p.project_code || `项目${p.id}`);
                          const target = groupFolder ? `${groupFolder}/${projectFolder}` : projectFolder;
                          openProjectFolder(target);
                        }}
                        className="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors"
                        title="打开文件夹"
                      >
                        <FolderOpen className="w-4 h-4" />
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
            
            {/* 最近文件 */}
            <div className="bg-white rounded-lg border p-4">
              <h3 className="font-semibold text-gray-800 mb-3">最近文件</h3>
              {recentFiles.length === 0 ? (
                <p className="text-gray-500 text-sm">暂无文件</p>
              ) : (
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500">文件名</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500">类型</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500">大小</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500">上传者</th>
                      <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500">时间</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {recentFiles.map((file) => (
                      <tr key={file.id} className="hover:bg-gray-50">
                        <td className="px-3 py-2 text-sm text-gray-700">{file.original_name}</td>
                        <td className="px-3 py-2 text-xs text-gray-500">{file.folder_type}</td>
                        <td className="px-3 py-2 text-xs text-gray-500">{formatSize(file.file_size)}</td>
                        <td className="px-3 py-2 text-xs text-gray-500">{file.uploader_name}</td>
                        <td className="px-3 py-2 text-xs text-gray-500">{file.created_at}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        ) : activeTab === 'approval' ? (
          <div className="space-y-4">
            {/* 筛选栏 */}
            <div className="flex flex-wrap items-center gap-4 bg-white rounded-lg border p-4">
              <div className="flex items-center gap-2">
                <label className="text-sm text-gray-600">提交人：</label>
                <select
                  value={selectedSubmitter}
                  onChange={(e) => handleFilterChange('submitter', e.target.value)}
                  className="px-3 py-1.5 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  title="按提交人筛选"
                >
                  <option value="0">全部</option>
                  {submitters.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>
              
              <div className="flex items-center gap-2">
                <label className="text-sm text-gray-600">部门：</label>
                <select
                  value={selectedDepartment}
                  onChange={(e) => handleFilterChange('department', e.target.value)}
                  className="px-3 py-1.5 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  title="按部门筛选"
                >
                  <option value="0">全部</option>
                  {departments.map((d) => (
                    <option key={d.id} value={d.id}>{d.name}</option>
                  ))}
                </select>
              </div>
              
              <div className="flex items-center gap-2">
                <label className="text-sm text-gray-600">分类：</label>
                <select
                  value={selectedFolderType}
                  onChange={(e) => handleFilterChange('folderType', e.target.value)}
                  className="px-3 py-1.5 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  title="按文件分类筛选"
                >
                  <option value="">全部</option>
                  {folderTypes.map((t) => (
                    <option key={t} value={t}>{t}</option>
                  ))}
                </select>
              </div>
            </div>
            
            <div className="bg-white rounded-lg border">
              {folders.length === 0 ? (
                <div className="p-8 text-center text-gray-500">
                  <CheckCircle className="w-12 h-12 mx-auto mb-3 text-green-300" />
                  <p>暂无待审批文件</p>
                </div>
              ) : (
                <div className="divide-y">
                  {folders.map((folder) => (
                    <div key={folder.folder_key}>
                      {/* 文件夹行 */}
                      <div className="flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 cursor-pointer" onClick={() => toggleFolder(folder.folder_key)}>
                        <div className="flex items-center gap-3">
                          <ChevronRight className={`w-4 h-4 text-gray-400 transition-transform ${expandedFolders.has(folder.folder_key) ? 'rotate-90' : ''}`} />
                          <FolderOpen className="w-5 h-5 text-yellow-500" />
                          <div>
                            <div className="font-medium text-gray-800">{folder.project_name} / {folder.folder_type}</div>
                            <div className="text-xs text-gray-500">{folder.customer_name} · {folder.file_count} 个文件</div>
                          </div>
                        </div>
                        <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                          <button
                            onClick={() => {
                              const groupFolder = buildGroupFolderName(folder.group_code, folder.group_name || folder.customer_name);
                              const projectFolder = sanitizeFolderName(folder.project_name || folder.project_code || `项目${folder.project_id}`);
                              const target = groupFolder ? `${groupFolder}/${projectFolder}` : projectFolder;
                              openProjectFolder(target, folder.folder_type);
                            }}
                            className="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors"
                            title="打开文件夹"
                          >
                            <FolderOpen className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => handleBatchApproval(folder, 'batch_approve')}
                            className="px-3 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                          >
                            全部通过
                          </button>
                          <button
                            onClick={() => setBatchRejectFolder(folder)}
                            className="px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                          >
                            全部驳回
                          </button>
                        </div>
                      </div>
                      {/* 展开的文件列表 */}
                      {expandedFolders.has(folder.folder_key) && (
                        <div className="bg-white">
                          {folder.files.map((approval) => (
                            <div key={approval.approval_id} className="flex items-center justify-between px-4 py-2 pl-12 border-t hover:bg-gray-50">
                              <div className="flex items-center gap-2">
                                <FileText className="w-4 h-4 text-gray-400" />
                                <div>
                                  <div className="text-sm text-gray-700">{approval.original_name}</div>
                                  <div className="text-xs text-gray-500">{formatSize(approval.file_size)} · {approval.submitter_name} · {approval.submit_time}</div>
                                </div>
                              </div>
                              <div className="flex items-center gap-2">
                                <button
                                  onClick={() => handleApproval(approval.approval_id, 'approve')}
                                  className="px-2 py-0.5 text-xs bg-green-500 text-white rounded hover:bg-green-600"
                                >
                                  通过
                                </button>
                                <button
                                  onClick={() => openRejectModal(approval.approval_id)}
                                  className="px-2 py-0.5 text-xs bg-red-500 text-white rounded hover:bg-red-600"
                                >
                                  驳回
                                </button>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
            
            {/* 批量驳回弹窗 */}
            {batchRejectFolder && (
              <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                <div className="bg-white rounded-lg p-6 w-96 max-w-[90vw]">
                  <h3 className="text-lg font-semibold mb-4">批量驳回 - {batchRejectFolder.file_count} 个文件</h3>
                  <div className="space-y-3 mb-4">
                    <p className="text-sm text-gray-600">选择驳回原因：</p>
                    {['文件格式不正确', '内容不符合要求', '质量不达标', '其他'].map((reason) => (
                      <label key={reason} className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="radio"
                          name="batchRejectReason"
                          value={reason}
                          checked={rejectReason === reason}
                          onChange={(e) => setRejectReason(e.target.value)}
                          className="text-blue-500"
                        />
                        <span className="text-sm">{reason}</span>
                      </label>
                    ))}
                    {rejectReason === '其他' && (
                      <input
                        type="text"
                        value={customReason}
                        onChange={(e) => setCustomReason(e.target.value)}
                        placeholder="请输入自定义原因"
                        className="w-full px-3 py-2 border rounded-lg text-sm"
                      />
                    )}
                  </div>
                  <div className="flex justify-end gap-2">
                    <button
                      onClick={() => { setBatchRejectFolder(null); setRejectReason(''); setCustomReason(''); }}
                      className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
                    >
                      取消
                    </button>
                    <button
                      onClick={() => {
                        const reason = rejectReason === '其他' ? customReason : rejectReason;
                        handleBatchApproval(batchRejectFolder, 'batch_reject', reason);
                        setBatchRejectFolder(null);
                        setRejectReason('');
                        setCustomReason('');
                      }}
                      className="px-4 py-2 text-sm bg-red-500 text-white rounded-lg hover:bg-red-600"
                      disabled={!rejectReason || (rejectReason === '其他' && !customReason)}
                    >
                      确认驳回
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        ) : (
          <div className="bg-white rounded-lg border">
            <div className="p-4 border-b">
              <div className="flex items-center gap-2 text-sm text-gray-500">
                <AlertTriangle className="w-4 h-4 text-yellow-500" />
                点击"缓存"按钮将下载该项目的作品文件和模型文件到本地
              </div>
            </div>
            {cacheProjects.length === 0 ? (
              <div className="p-8 text-center text-gray-500">
                暂无可缓存的项目
              </div>
            ) : (
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">项目</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">客户</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">文件数</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">总大小</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500">操作</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {cacheProjects.map((project) => (
                    <tr key={project.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <div className="text-sm font-medium text-gray-700">{project.name}</div>
                        <div className="text-xs text-gray-500">{project.code}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="text-sm text-gray-700">{project.customer_name}</div>
                        <div className="text-xs text-gray-500">{project.group_name}</div>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600">{project.file_count} 个</td>
                      <td className="px-4 py-3 text-sm text-gray-600">{formatSize(project.total_size || 0)}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => cacheProjectFiles(project)}
                            disabled={project.file_count === 0 || isDownloading}
                            className="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                          >
                            <Download className="w-3 h-3 inline-block mr-1" />
                            {isDownloading ? '下载中...' : '缓存'}
                          </button>
                          <button
                            onClick={() => {
                              const groupName = buildGroupFolderName(project.group_code, project.group_name || project.customer_name);
                              const projectFolder = sanitizeFolderName(project.name || project.code || `项目${project.id}`);
                              const projectPathName = groupName ? `${groupName}/${projectFolder}` : projectFolder;
                              openProjectFolder(projectPathName);
                            }}
                            className="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors"
                            title="打开文件夹"
                          >
                            <FolderOpen className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </div>
      
      {/* 驳回原因弹窗 */}
      {showRejectModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-[400px] max-w-[90vw]">
            <div className="px-6 py-4 border-b">
              <h3 className="text-lg font-semibold text-gray-800">驳回原因</h3>
            </div>
            <div className="p-6 space-y-4">
              <div className="space-y-2">
                {REJECT_REASONS.map((reason) => (
                  <label key={reason} className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name="rejectReason"
                      value={reason}
                      checked={rejectReason === reason}
                      onChange={(e) => setRejectReason(e.target.value)}
                      className="w-4 h-4 text-blue-500"
                    />
                    <span className="text-sm text-gray-700">{reason}</span>
                  </label>
                ))}
              </div>
              {rejectReason === '其他' && (
                <textarea
                  value={customReason}
                  onChange={(e) => setCustomReason(e.target.value)}
                  placeholder="请输入驳回原因..."
                  className="w-full px-3 py-2 border rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              )}
            </div>
            <div className="px-6 py-4 border-t flex justify-end gap-3">
              <button
                onClick={() => setShowRejectModal(false)}
                className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
              >
                取消
              </button>
              <button
                onClick={confirmReject}
                disabled={!rejectReason || (rejectReason === '其他' && !customReason)}
                className="px-4 py-2 text-sm bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                确认驳回
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
