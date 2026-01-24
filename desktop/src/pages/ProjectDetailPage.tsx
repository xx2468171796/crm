import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, User, FileText, MessageSquare, Check, Download, ExternalLink, Link2, Lock, RefreshCw, Clipboard, DollarSign, UserPlus, History, Star, CheckCircle, Clock, Phone, Upload, Copy, FolderOpen, Eye, Trash2, AlertTriangle } from 'lucide-react';
import { useAuthStore } from '@/stores/auth';
import { useSettingsStore } from '@/stores/settings';
import { usePermissionsStore } from '@/stores/permissions';
import DetailSidebar, { SidebarTab } from '@/components/DetailSidebar';
import { UserSelector } from '@/components/relational-selector';
import DateEditor from '@/components/DateEditor';
import ConfirmDialog from '@/components/ConfirmDialog';
import StageTimeEditor from '@/components/StageTimeEditor';
import FormDetailModal from '@/components/FormDetailModal';
import InputDialog from '@/components/InputDialog';
import { useToast } from '@/hooks/use-toast';
import { useUploader } from '@/hooks/use-uploader';
import { isManager as checkIsManager } from '@/lib/utils';
import { downloadFileChunked, ensureDirectory, getFileMetadata, previewFile, scanFolderRecursive } from '@/lib/tauri';
import FileTree, { type FileNode } from '@/components/FileTree';
import LocalFileTree from '@/components/LocalFileTree';
import { open } from '@tauri-apps/plugin-dialog';

// 文件分类常量
const FILE_CATEGORIES = ['客户文件', '作品文件', '模型文件'] as const;
const FILE_CATEGORY_COLORS: Record<string, { bg: string; header: string }> = {
  '客户文件': { bg: 'bg-blue-50 border-blue-200', header: 'bg-blue-100 text-blue-800' },
  '作品文件': { bg: 'bg-green-50 border-green-200', header: 'bg-green-100 text-green-800' },
  '模型文件': { bg: 'bg-purple-50 border-purple-200', header: 'bg-purple-100 text-purple-800' },
};

// 项目详情侧边栏Tab配置
const PROJECT_SIDEBAR_TABS: SidebarTab[] = [
  { key: 'overview', label: '概览', icon: <FileText className="w-4 h-4" /> },
  { key: 'forms', label: '动态表单', icon: <Clipboard className="w-4 h-4" /> },
  { key: 'files', label: '交付物', icon: <Download className="w-4 h-4" /> },
  { key: 'messages', label: '沟通记录', icon: <MessageSquare className="w-4 h-4" /> },
  { key: 'timeline', label: '项目记录', icon: <History className="w-4 h-4" /> },
  { key: 'finance', label: '财务', icon: <DollarSign className="w-4 h-4" /> },
];

interface StatusConfig {
  key: string;
  label: string;
  color: string;
  order: number;
}

interface StatusTimeInfo {
  changed_at: string | null;
  changed_by: string | null;
}

interface TechUser {
  id: number;
  assignment_id: number;
  name: string;
  commission: number | null;
  commission_note: string | null;
}

interface DaysInfo {
  total_days: number | null;
  elapsed_days: number | null;
  remaining_days: number | null;
  overall_progress: number;
  is_overdue: boolean;
  overdue_days: number;
  date_range: string | null;
  is_completed?: boolean;
  actual_days?: number | null;
  completed_at?: string | null;
}

interface ProjectData {
  id: number;
  project_code: string;
  start_date: string | null;
  deadline: string | null;
  days_info: DaysInfo | null;
  progress: number;
  project_name: string;
  current_status: string;
  remark: string;
  create_time: string;
  update_time: string;
  completed_at: string | null;
  completed_by: string | null;
}

interface CustomerData {
  id: number;
  name: string;
  group_code: string;
  customer_group_name: string | null;
  alias: string | null;
  phone: string;
  portal_token: string | null;
  portal_password: string | null;
}

interface FormData {
  id: number;
  instance_name: string;
  template_name: string;
  form_type: string;
  fill_token: string;
  status: string;
  requirement_status: string;
  submission_count: number;
  create_time: string;
  update_time: string;
}

interface FileItem {
  id: number;
  filename: string;
  file_path: string;
  storage_key?: string;
  download_url?: string;
  file_size: number;
  approval_status: string;
  uploader_name: string;
  create_time: string;
}

interface FileCategory {
  label: string;
  files: FileItem[];
  tree?: FileNode[];
  count?: number;
}

interface FileData {
  categories: Record<string, FileCategory>;
  total: number;
}

interface MessageData {
  id: number;
  content: string;
  message_type: string;
  sender_name: string;
  sender_role: string;
  create_time: string;
}

interface TimelineItem {
  type: string;
  title: string;
  content: string;
  operator: string | null;
  time: string | null;
}

interface EvaluationData {
  evaluation: {
    id: number;
    rating: number;
    comment: string;
    created_at: string;
    customer_name: string;
  } | null;
  evaluation_form: {
    id: number;
    instance_name: string;
    fill_token: string;
    status: string;
    template_name: string;
  } | null;
  completed_at: string | null;
  completed_by: string | null;
}

type TabType = 'overview' | 'forms' | 'files' | 'messages' | 'timeline';

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token, user } = useAuthStore();
  const { serverUrl, rootDir } = useSettingsStore();
  const isTauri = typeof window !== 'undefined' && '__TAURI__' in window;
  
  // 调试日志
  console.log('[ProjectDetailPage] 接收到项目ID:', id, '类型:', typeof id);
  const { canEditProjectStatus, canViewPortal, canCopyPortalLink, canViewPortalPassword, canAssignProject } = usePermissionsStore();
  const { toast } = useToast();
  
  // 判断当前用户是否可以编辑此项目（有权限或是设计负责人）
  const [canEditThisProject, setCanEditThisProject] = useState(false);
  
  // 判断是否为管理员（可以设置提成）
  const isManager = checkIsManager(user?.role);
  
  // 调试：打印角色和管理员状态
  console.log('[ProjectDetailPage] user.role:', user?.role, 'isManager:', isManager);
  
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<TabType>('overview');
  const [project, setProject] = useState<ProjectData | null>(null);
  const [customer, setCustomer] = useState<CustomerData | null>(null);
  const [techUsers, setTechUsers] = useState<TechUser[]>([]);
  const [statuses, setStatuses] = useState<StatusConfig[]>([]);
  const [currentStatusOrder, setCurrentStatusOrder] = useState(0);
  // statusTimeMap 保留用于未来扩展
  const [, setStatusTimeMap] = useState<Record<string, StatusTimeInfo>>({});
  const [stageDaysMap, setStageDaysMap] = useState<Record<string, { days: number; status: string }>>({});
  
  const [forms, setForms] = useState<FormData[]>([]);
  const [files, setFiles] = useState<FileData | null>(null);
  const [messages, setMessages] = useState<MessageData[]>([]);
  const [timeline, setTimeline] = useState<TimelineItem[]>([]);
  const [localFiles, setLocalFiles] = useState<Record<string, Array<{ name: string; path: string; relative_path: string }>>>({});
  const [tabLoading, setTabLoading] = useState(false);
  
  // 本地文件夹初始化状态
  const [localFolderExists, setLocalFolderExists] = useState<boolean | null>(null);
  const [initProgress, setInitProgress] = useState<{ total: number; current: number; currentFile: string } | null>(null);
  const [isInitializing, setIsInitializing] = useState(false);
  
  // 人员选择器
  const [showUserSelector, setShowUserSelector] = useState(false);
  const [selectedTechIds, setSelectedTechIds] = useState<number[]>([]);
  
  // 日期编辑
  const [showDateEditor, setShowDateEditor] = useState(false);
  
  // 状态变更确认弹窗
  const [showStatusConfirm, setShowStatusConfirm] = useState(false);
  const [pendingStatus, setPendingStatus] = useState<string | null>(null);
  
  // 阶段时间编辑弹窗
  const [showStageTimeEditor, setShowStageTimeEditor] = useState(false);
  
  // 表单详情弹窗
  const [showFormDetail, setShowFormDetail] = useState(false);
  const [selectedFormId, setSelectedFormId] = useState<number | null>(null);
  
  // 提成设置弹窗
  const [showCommissionEditor, setShowCommissionEditor] = useState(false);
  const [editingTech, setEditingTech] = useState<TechUser | null>(null);
  const [commissionAmount, setCommissionAmount] = useState('');
  
  // 文件上传
  const [uploading, setUploading] = useState(false);
  const [uploadCategory, setUploadCategory] = useState<(typeof FILE_CATEGORIES)[number]>('作品文件');
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<{ current: number; total: number; filename: string } | null>(null);
  const [dragOver, setDragOver] = useState(false);
  type PendingUploadItem =
    | { kind: 'web'; file: File }
    | { kind: 'local'; path: string; name: string; size: number };
  const [pendingUploads, setPendingUploads] = useState<PendingUploadItem[]>([]);
  const [commissionNote, setCommissionNote] = useState('');

  // 文件管理（重命名/批量删除）
  const [selectedFileIds, setSelectedFileIds] = useState<Set<number>>(new Set());
  const [showBatchDeleteConfirm, setShowBatchDeleteConfirm] = useState(false);
  const [renameDialogOpen, setRenameDialogOpen] = useState(false);
  const [renameTarget, setRenameTarget] = useState<{ id: number; filename: string; storageKey?: string } | null>(null);
  
  // 门户密码管理
  const [showPasswordEditor, setShowPasswordEditor] = useState(false);
  const [portalPassword, setPortalPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  
  // 别名编辑
  const [showAliasEditor, setShowAliasEditor] = useState(false);
  const [aliasValue, setAliasValue] = useState('');
  
  // 删除项目
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [deleting, setDeleting] = useState(false);
  
  // 评价数据
  const [evaluationData, setEvaluationData] = useState<EvaluationData | null>(null);
  
  // 文件预览状态
  const [previewingFile, setPreviewingFile] = useState<string | null>(null);
  
  // 文件视图模式：list（列表）或 tree（树状）
  const [fileViewMode, setFileViewMode] = useState<'list' | 'tree'>('tree');
  
  // 本地文件选择状态（用于批量上传）
  const [selectedLocalFiles, setSelectedLocalFiles] = useState<Set<string>>(new Set());

  // 多区域链接弹窗
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [regionLinks, setRegionLinks] = useState<Array<{ region_name: string; url: string; is_default: boolean }>>([]);
  const [loadingLinks, setLoadingLinks] = useState(false);

  // 分享上传链接
  const [showShareLinkModal, setShowShareLinkModal] = useState(false);
  const [shareRegions, setShareRegions] = useState<Array<{ id: number; region_name: string; is_default: boolean }>>([]);
  const [selectedShareRegion, setSelectedShareRegion] = useState<number | null>(null);
  const [sharePassword, setSharePassword] = useState('');
  const [shareMaxVisits, setShareMaxVisits] = useState('');
  const [shareExpireDays, setShareExpireDays] = useState('7');
  const [generatingShareLink, setGeneratingShareLink] = useState(false);
  const [generatedShareLink, setGeneratedShareLink] = useState<{ url: string; expires_at: string } | null>(null);

  const { queueUpload } = useUploader();

  const normalizeApprovalStatus = (raw: any): 'pending' | 'approved' | 'rejected' => {
    if (raw === 1 || raw === '1' || raw === 'approved') return 'approved';
    if (raw === 2 || raw === '2' || raw === 'rejected') return 'rejected';
    return 'pending';
  };

  const canManageFile = (file: any) => {
    const uploaderId = Number(file?.uploader_id || 0);
    const approvalStatus = normalizeApprovalStatus(file?.approval_status);
    const isUploader = uploaderId > 0 && uploaderId === (user?.id || 0);
    const manager = checkIsManager(user?.role);

    if (manager) return true;
    if (isUploader && (approvalStatus === 'pending' || approvalStatus === 'rejected')) return true;
    return false;
  };

  const handleBatchApprove = async (filesToApprove: any[]) => {
    const ids = filesToApprove.map((f: any) => Number(f.id)).filter((x: number) => x > 0);
    if (ids.length === 0) {
      toast({ title: '批量通过', description: '请选择待审核的文件', variant: 'destructive' });
      return;
    }
    try {
      const res = await fetch(`${serverUrl}/api/desktop_approval.php?action=batch_approve`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_ids: ids }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '批量通过成功', description: `已通过 ${ids.length} 个文件` });
        clearSelection();
        await loadProject('files');
      } else {
        toast({ title: '批量通过失败', description: data.error || '未知错误', variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '批量通过失败', description: err.message, variant: 'destructive' });
    }
  };

  const handleBatchReject = async (filesToReject: any[]) => {
    const ids = filesToReject.map((f: any) => Number(f.id)).filter((x: number) => x > 0);
    if (ids.length === 0) {
      toast({ title: '批量驳回', description: '请选择待审核的文件', variant: 'destructive' });
      return;
    }
    const reason = prompt('请输入批量驳回原因（可选）：') || '';
    try {
      const res = await fetch(`${serverUrl}/api/desktop_approval.php?action=batch_reject`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_ids: ids, reason }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '批量驳回成功', description: `已驳回 ${ids.length} 个文件` });
        clearSelection();
        await loadProject('files');
      } else {
        toast({ title: '批量驳回失败', description: data.error || '未知错误', variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '批量驳回失败', description: err.message, variant: 'destructive' });
    }
  };

  // 通用文件预览函数
  const handleFilePreview = async (file: any, categoryName: string) => {
    if (!file?.download_url) {
      toast({ title: '无法预览', description: '缺少下载链接', variant: 'destructive' });
      return;
    }
    try {
      setPreviewingFile(file.filename);
      toast({ title: '正在打开...', description: file.filename });
      const localBasePath = `${getLocalBasePath()}/${categoryName}`;
      await previewFile(file.download_url, file.filename, localBasePath, file.relative_path);
    } catch (err: any) {
      toast({ title: '预览失败', description: err.message, variant: 'destructive' });
    } finally {
      setPreviewingFile(null);
    }
  };

  // 通用文件删除函数
  const handleFileDelete = async (file: any) => {
    if (!file) return;
    if (!confirm(`确定要删除文件 "${file.filename}" 吗？`)) return;
    try {
      const fileId = file.id;
      const isByKey = !fileId || Number(fileId) <= 0;
      const payload = isByKey
        ? { action: 'delete_by_key', storage_key: file.storage_key || file.file_path }
        : { action: 'delete', id: fileId };
      const res = await fetch(`${serverUrl}/api/desktop_file_manage.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '删除成功', description: file.filename });
        loadProject('files');
      } else {
        toast({ title: '删除失败', description: data.error, variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '删除失败', description: err.message, variant: 'destructive' });
    }
  };

  // 重新提交被驳回的文件
  const handleResubmitFile = async (file: any, categoryName: string) => {
    if (!file || !project || !customer) return;
    
    // 打开文件选择对话框
    const selectedFiles = await open({
      multiple: false,
      filters: [{ name: '所有文件', extensions: ['*'] }],
      title: `重新提交: ${file.filename}`,
    });
    
    if (!selectedFiles || typeof selectedFiles !== 'string') return;
    
    const localPath = selectedFiles;
    const fileName = localPath.split(/[/\\]/).pop() || file.filename;
    
    try {
      // 先删除旧文件
      const deleteRes = await fetch(`${serverUrl}/api/desktop_file_manage.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'delete', id: file.id }),
      });
      const deleteData = await deleteRes.json();
      if (!deleteData.success) {
        toast({ title: '重新提交失败', description: '无法删除旧文件: ' + (deleteData.error || ''), variant: 'destructive' });
        return;
      }
      
      // 上传新文件
      const groupCode = customer.group_code || `P${project.id}`;
      const assetType = categoryName === '客户文件' ? 'customer' : categoryName === '模型文件' ? 'models' : 'works';
      await queueUpload(groupCode, assetType, localPath, fileName, project.id);
      toast({ title: '重新提交', description: `${fileName} 已添加到上传队列` });
    } catch (err: any) {
      toast({ title: '重新提交失败', description: err.message, variant: 'destructive' });
    }
  };

  // 批量重新提交被驳回的文件
  const handleBatchResubmit = async (rejectedFiles: any[], categoryName: string) => {
    if (rejectedFiles.length === 0 || !project || !customer) return;
    
    // 打开文件选择对话框（多选）
    const selectedFiles = await open({
      multiple: true,
      filters: [{ name: '所有文件', extensions: ['*'] }],
      title: `批量重新提交 ${rejectedFiles.length} 个被驳回的文件`,
    });
    
    if (!selectedFiles || (Array.isArray(selectedFiles) && selectedFiles.length === 0)) return;
    
    const filePaths = Array.isArray(selectedFiles) ? selectedFiles : [selectedFiles];
    
    try {
      // 先批量删除旧文件
      const ids = rejectedFiles.map((f: any) => f.id);
      const deleteRes = await fetch(`${serverUrl}/api/desktop_file_manage.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'batch_delete', ids }),
      });
      const deleteData = await deleteRes.json();
      if (!deleteData.success) {
        toast({ title: '批量重新提交失败', description: '无法删除旧文件: ' + (deleteData.error || ''), variant: 'destructive' });
        return;
      }
      
      // 批量上传新文件
      const groupCode = customer.group_code || `P${project.id}`;
      const assetType = categoryName === '客户文件' ? 'customer' : categoryName === '模型文件' ? 'models' : 'works';
      
      for (const localPath of filePaths) {
        const fileName = localPath.split(/[/\\]/).pop() || 'file';
        await queueUpload(groupCode, assetType, localPath, fileName, project.id);
      }
      
      toast({ title: '批量重新提交', description: `已添加 ${filePaths.length} 个文件到上传队列` });
      clearSelection();
    } catch (err: any) {
      toast({ title: '批量重新提交失败', description: err.message, variant: 'destructive' });
    }
  };

  const toggleSelectFile = (id: number) => {
    setSelectedFileIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const clearSelection = () => setSelectedFileIds(new Set());

  const requestRename = (file: any) => {
    const id = Number(file?.id || 0);
    const filename = String(file?.filename || '');
    const storageKey = String(file?.storage_key || file?.file_path || '');

    if (id <= 0) {
      // 未入库文件：只允许管理员/主管通过 storage_key 重命名
      if (!checkIsManager(user?.role)) {
        toast({ title: '无法重命名', description: '该文件未入库，只有管理员可以重命名', variant: 'destructive' });
        return;
      }
      if (!storageKey) {
        toast({ title: '无法重命名', description: '缺少 storage_key', variant: 'destructive' });
        return;
      }
      setRenameTarget({ id: 0, filename, storageKey });
      setRenameDialogOpen(true);
      return;
    }

    setRenameTarget({ id, filename, storageKey });
    setRenameDialogOpen(true);
  };

  const doRename = async (newName: string) => {
    if (!renameTarget) return;
    const name = (newName || '').trim();
    if (!name || name === renameTarget.filename) return;

    try {
      const isByKey = renameTarget.id <= 0;
      const payload = isByKey
        ? { action: 'rename_by_key', storage_key: renameTarget.storageKey, new_name: name }
        : { action: 'rename', id: renameTarget.id, new_name: name };

      if (isByKey && !renameTarget.storageKey) {
        toast({ title: '重命名失败', description: '缺少 storage_key', variant: 'destructive' });
        return;
      }

      const res = await fetch(`${serverUrl}/api/desktop_file_manage.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '重命名成功', description: name });
        await loadProject('files');
      } else {
        toast({ title: '重命名失败', description: data.error || '重命名失败', variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '重命名失败', description: err.message, variant: 'destructive' });
    }
  };

  const doBatchDelete = async () => {
    const ids = Array.from(selectedFileIds).filter((x) => x > 0);
    if (ids.length === 0) {
      toast({ title: '批量删除', description: '请选择要删除的文件', variant: 'destructive' });
      return;
    }
    try {
      const res = await fetch(`${serverUrl}/api/desktop_file_manage.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'batch_delete', ids }),
      });
      const data = await res.json();
      if (data.success) {
        toast({ title: '批量删除成功', description: `已删除 ${data.data?.deleted_count ?? ids.length} 个文件` });
        clearSelection();
        await loadProject('files');
      } else {
        toast({ title: '批量删除失败', description: data.error || '批量删除失败', variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '批量删除失败', description: err.message, variant: 'destructive' });
    }
  };

  // 桌面端拖拽：使用 Tauri 原生 onDragDropEvent（Web dataTransfer.files 在 Tauri 里经常为空）
  useEffect(() => {
    if (!showUploadModal || !isTauri) return;

    let unlisten: null | (() => void) = null;

    (async () => {
      try {
        const { getCurrentWindow } = await import('@tauri-apps/api/window');
        unlisten = await getCurrentWindow().onDragDropEvent(async (event) => {
          const payload: any = (event as any).payload;
          if (!payload) return;

          if (payload.type === 'enter' || payload.type === 'over') {
            setDragOver(true);
            return;
          }

          if (payload.type === 'leave') {
            setDragOver(false);
            return;
          }

          if (payload.type === 'drop') {
            setDragOver(false);
            const paths: string[] = Array.isArray(payload.paths) ? payload.paths : [];
            if (paths.length === 0) return;

            const metas = await Promise.all(
              paths.map(async (p) => {
                const meta = await getFileMetadata(p).catch(() => null);
                const name = p.split(/[/\\]/).pop() || p;
                return {
                  kind: 'local' as const,
                  path: p,
                  name,
                  size: meta?.size || 0,
                };
              })
            );

            setPendingUploads((prev) => {
              const exists = new Set(prev.filter((x) => x.kind === 'local').map((x: any) => x.path));
              const next = metas.filter((m) => !exists.has(m.path));
              return [...prev, ...next];
            });
          }
        });
      } catch (e) {
        console.error('[UploadModal] 注册 onDragDropEvent 失败:', e);
      }
    })();

    return () => {
      try {
        if (unlisten) unlisten();
      } catch {
        // ignore
      }
    };
  }, [showUploadModal, isTauri]);

  // 加载评价数据
  const loadEvaluation = async () => {
    if (!serverUrl || !id) return;
    try {
      const response = await fetch(`${serverUrl}/api/project_evaluations.php?project_id=${id}`);
      const data = await response.json();
      if (data.success) {
        setEvaluationData(data.data);
      }
    } catch (e) {
      console.error('加载评价数据失败:', e);
    }
  };

  // 加载项目详情
  const loadProject = async (tab: TabType = 'overview') => {
    if (!serverUrl || !token || !id) return;
    
    if (tab === 'overview') {
      setLoading(true);
    } else {
      setTabLoading(true);
    }
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_project_detail.php?id=${id}&tab=${tab}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await response.json();
      
      if (data.success) {
        setProject(data.data.project);
        setCustomer(data.data.customer);
        const techs = data.data.tech_users || [];
        setTechUsers(techs);
        setStatuses(data.data.statuses || []);
        setCurrentStatusOrder(data.data.current_status_order || 0);
        setStatusTimeMap(data.data.status_time_map || {});
        setStageDaysMap(data.data.stage_days_map || {});
        
        // 判断当前用户是否可以编辑此项目
        // 设计师只能看到自己负责的项目，所以默认允许编辑
        const isTechUser = user && techs.some((t: TechUser) => t.id === user.id);
        const isTechRole = user?.role === 'tech';
        const hasStatusEditPermission = canEditProjectStatus();
        const canEdit = hasStatusEditPermission || isTechUser || isTechRole;
        console.log('[ProjectDetail] 权限检查:', { 
          hasStatusEditPermission, 
          isTechUser, 
          isTechRole, 
          canEdit,
          userRole: user?.role,
          userId: user?.id 
        });
        setCanEditThisProject(canEdit);
        
        // 设置 tab 数据
        if (tab === 'forms' && data.data.tab_data) {
          setForms(data.data.tab_data);
        } else if (tab === 'files' && data.data.tab_data) {
          setFiles(data.data.tab_data);
        } else if (tab === 'messages' && data.data.tab_data) {
          setMessages(data.data.tab_data);
        } else if (tab === 'timeline' && data.data.tab_data) {
          setTimeline(data.data.tab_data);
        }
      }
    } catch (error) {
      console.error('加载项目详情失败:', error);
      // 确保 statuses 有默认值
      if (statuses.length === 0) {
        setStatuses([
          { key: '待沟通', label: '待沟通', color: '#6366f1', order: 1 },
          { key: '需求确认', label: '需求确认', color: '#8b5cf6', order: 2 },
          { key: '设计中', label: '设计中', color: '#ec4899', order: 3 },
          { key: '设计核对', label: '设计核对', color: '#f97316', order: 4 },
          { key: '设计完工', label: '设计完工', color: '#14b8a6', order: 5 },
          { key: '设计评价', label: '设计评价', color: '#10b981', order: 6 },
        ]);
      }
    } finally {
      setLoading(false);
      setTabLoading(false);
    }
  };

  // 请求变更状态（打开确认弹窗）
  const requestChangeStatus = (newStatus: string) => {
    setPendingStatus(newStatus);
    setShowStatusConfirm(true);
  };

  // 确认变更状态
  const changeStatus = async (newStatus: string) => {
    if (!serverUrl || !token || !id) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=change_status`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ project_id: parseInt(id), status: newStatus }),
      });
      const data = await response.json();
      if (data.success) {
        loadProject('overview');
      } else {
        const errMsg = typeof data.error === 'object' ? data.error.message : data.error;
        toast({ title: '错误', description: errMsg || '状态变更失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('变更状态失败:', error);
      toast({ title: '错误', description: '状态变更失败', variant: 'destructive' });
    }
  };

  // 手动完工
  const handleManualComplete = async () => {
    if (!serverUrl || !token || !id) return;
    
    if (!window.confirm('确定要手动将此项目标记为完工吗？')) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/project_complete.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ project_id: parseInt(id) }),
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '成功', description: '项目已完工' });
        loadProject('overview');
      } else {
        toast({ title: '错误', description: data.message || '操作失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('手动完工失败:', error);
      toast({ title: '错误', description: '操作失败', variant: 'destructive' });
    }
  };

  // 开始沟通（将状态从 pending 改为 communicating）
  const startFormCommunication = async (instanceId: number) => {
    if (!serverUrl || !token) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_form_instances.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'start_communication', instance_id: instanceId }),
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '成功', description: '已开始沟通' });
        loadProject('forms');
      } else {
        toast({ title: '错误', description: data.message || '操作失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('开始沟通失败:', error);
      toast({ title: '错误', description: '开始沟通失败', variant: 'destructive' });
    }
  };

  // 确认表单需求
  const confirmFormRequirement = async (instanceId: number) => {
    if (!serverUrl || !token) return;
    
    try {
      const response = await fetch(`${serverUrl}/api/desktop_form_instances.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action: 'confirm_requirement', instance_id: instanceId }),
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '成功', description: '需求已确认' });
        loadProject('forms');
      } else {
        toast({ title: '错误', description: data.message || '确认失败', variant: 'destructive' });
      }
    } catch (error) {
      console.error('确认需求失败:', error);
      toast({ title: '错误', description: '确认需求失败', variant: 'destructive' });
    }
  };

  // 更新项目日期
  const updateProjectDates = async (startDate: string | null, deadline: string | null) => {
    if (!serverUrl || !token || !id) return;
    
    const response = await fetch(`${serverUrl}/api/desktop_projects.php?action=update_dates`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ project_id: parseInt(id), start_date: startDate, deadline: deadline }),
    });
    const data = await response.json();
    if (data.success) {
      toast({ title: '成功', description: '日期已更新' });
      loadProject('overview');
    } else {
      throw new Error(data.error || '更新失败');
    }
  };

  // 删除项目
  const deleteProject = async () => {
    if (!serverUrl || !token || !id || !project) return;
    setDeleting(true);
    try {
      const response = await fetch(`${serverUrl}/api/projects.php?id=${id}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (data.success) {
        toast({ title: '项目已删除', description: `${project.project_name} 已移至回收站，15天后自动永久删除` });
        setShowDeleteConfirm(false);
        navigate('/project-kanban');
      } else {
        toast({ title: data.message || '删除失败', variant: 'destructive' });
      }
    } catch (e) {
      console.error('删除项目失败:', e);
      toast({ title: '删除失败', variant: 'destructive' });
    } finally {
      setDeleting(false);
    }
  };

  useEffect(() => {
    loadProject('overview');
    loadEvaluation();
  }, [id, serverUrl, token]);

  // 加载多区域链接并打开弹窗
  const handleOpenLinkModal = async () => {
    console.log('[PORTAL_LINK_DEBUG] handleOpenLinkModal called', { serverUrl, token: !!token, portal_token: customer?.portal_token });
    if (!serverUrl || !token || !customer?.portal_token) {
      console.log('[PORTAL_LINK_DEBUG] Missing required params, returning');
      return;
    }
    
    console.log('[PORTAL_LINK_DEBUG] Opening modal...');
    setShowLinkModal(true);
    setLoadingLinks(true);
    
    try {
      const apiUrl = `${serverUrl}/api/portal_link.php?action=get_region_urls&token=${customer.portal_token}`;
      console.log('[PORTAL_LINK_DEBUG] Fetching:', apiUrl);
      const res = await fetch(apiUrl, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      console.log('[PORTAL_LINK_DEBUG] API Response:', JSON.stringify(data));
      
      if (data.success && data.regions && data.regions.length > 0) {
        // 为每个链接添加项目ID参数
        const linksWithProject = data.regions.map((r: any) => ({
          ...r,
          url: r.url + (r.url.includes('?') ? '&' : '?') + `project_id=${project?.id}`
        }));
        setRegionLinks(linksWithProject);
      } else {
        // 无区域配置，使用默认链接
        const defaultUrl = `${serverUrl}/portal.php?token=${customer.portal_token}&project_id=${project?.id}`;
        setRegionLinks([{ region_name: '默认', url: defaultUrl, is_default: true }]);
      }
    } catch (err) {
      console.error('加载区域链接失败:', err);
      const defaultUrl = `${serverUrl}/portal.php?token=${customer.portal_token}&project_id=${project?.id}`;
      setRegionLinks([{ region_name: '默认', url: defaultUrl, is_default: true }]);
    } finally {
      setLoadingLinks(false);
    }
  };

  // 复制链接到剪贴板
  const copyRegionLink = (url: string, regionName: string) => {
    navigator.clipboard.writeText(url);
    toast({ title: '成功', description: `${regionName}链接已复制到剪贴板` });
  };

  // 打开分享链接生成弹窗
  const handleOpenShareLinkModal = async () => {
    if (!serverUrl || !token || !project) return;
    
    setShowShareLinkModal(true);
    setGeneratedShareLink(null);
    setSharePassword('');
    setShareMaxVisits('');
    setShareExpireDays('7');
    setSelectedShareRegion(null);
    
    // 加载分享节点列表
    try {
      const res = await fetch(`${serverUrl}/api/share_regions.php?action=list`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });
      const data = await res.json();
      if (data.success && data.data) {
        setShareRegions(data.data);
        // 默认选择默认节点
        const defaultRegion = data.data.find((r: any) => r.is_default);
        if (defaultRegion) {
          setSelectedShareRegion(defaultRegion.id);
        }
      }
    } catch (err) {
      console.error('加载分享节点失败:', err);
    }
  };

  // 生成分享链接
  const generateShareLink = async () => {
    if (!serverUrl || !token || !project) return;
    
    setGeneratingShareLink(true);
    try {
      const res = await fetch(`${serverUrl}/api/file_share_create.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          project_id: project.id,
          region_id: selectedShareRegion,
          password: sharePassword || undefined,
          max_visits: shareMaxVisits ? parseInt(shareMaxVisits) : undefined,
          expires_in_days: parseInt(shareExpireDays) || 7,
        }),
      });
      const data = await res.json();
      if (data.success && data.data) {
        setGeneratedShareLink({
          url: data.data.share_url,
          expires_at: data.data.expires_at,
        });
        toast({ title: '成功', description: '分享链接已生成' });
      } else {
        toast({ title: '错误', description: data.error || '生成失败', variant: 'destructive' });
      }
    } catch (err: any) {
      toast({ title: '错误', description: err.message || '生成失败', variant: 'destructive' });
    } finally {
      setGeneratingShareLink(false);
    }
  };

  // 复制分享链接
  const copyShareLink = () => {
    if (generatedShareLink?.url) {
      navigator.clipboard.writeText(generatedShareLink.url);
      toast({ title: '成功', description: '分享链接已复制到剪贴板' });
    }
  };

  // 切换 tab 时加载数据
  const handleTabChange = (tab: TabType) => {
    setActiveTab(tab);
    if (tab !== 'overview') {
      loadProject(tab);
    }
  };

  // 格式化文件大小
  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  const parseJsonResponse = async (res: Response, step: string) => {
    const status = res.status;
    const text = await res.text();
    const preview = text.length > 800 ? `${text.slice(0, 800)}...` : text;
    console.log(`[Upload] ${step} HTTP ${status}`, preview);
    try {
      return JSON.parse(text);
    } catch (e: any) {
      throw new Error(`${step} 响应不是JSON（HTTP ${status}）：${preview}`);
    }
  };

  // 上传文件核心逻辑（使用分片上传 API，支持进度回调）
  const uploadFile = async (file: File, category: (typeof FILE_CATEGORIES)[number]) => {
    if (!serverUrl || !token || !project) {
      console.log('[Upload] 缺少必要参数:', { serverUrl: !!serverUrl, token: !!token, project: !!project });
      throw new Error('缺少必要参数');
    }
    
    const groupCode = customer?.group_code || `P${project.id}`;
    const assetType = category === '客户文件' ? 'customer' : category === '模型文件' ? 'models' : 'works';
    const relPath = file.name;
    const mimeType = file.type || 'application/octet-stream';
    
    console.log('[Upload] 开始分片上传:', { fileName: file.name, size: file.size, category, groupCode });
    setUploadProgress({ current: 0, total: 1, filename: file.name });
    
    // 1. 初始化分片上传
    console.log('[Upload] 步骤1: 初始化分片上传...');
    const initRes = await fetch(`${serverUrl}/api/desktop_upload_init.php`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        group_code: groupCode,
        project_id: project.id,
        asset_type: assetType,
        rel_path: relPath,
        filename: file.name,
        filesize: file.size,
        mime_type: mimeType,
      }),
    });
    const initData = await parseJsonResponse(initRes, '步骤1-init');
    console.log('[Upload] 步骤1 解析后的JSON:', initData);
    
    if (!initData.success || !initData.data) {
      throw new Error(initData.error || '初始化上传失败');
    }
    
    const { upload_id, storage_key, part_size, total_parts } = initData.data;
    const parts: Array<{ PartNumber: number; ETag: string }> = [];
    setUploadProgress({ current: 0, total: total_parts, filename: file.name });
    
    // 2. 分片上传（带重试）
    for (let partNumber = 1; partNumber <= total_parts; partNumber++) {
      console.log(`[Upload] 步骤2: 上传分片 ${partNumber}/${total_parts}...`);
      
      let retries = 3;
      let lastError: Error | null = null;
      
      while (retries > 0) {
        try {
          // 获取分片预签名 URL
          const partUrlRes = await fetch(`${serverUrl}/api/desktop_upload_part_url.php`, {
            method: 'POST',
            headers: {
              'Authorization': `Bearer ${token}`,
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              upload_id,
              storage_key,
              part_number: partNumber,
            }),
          });
          const partUrlData = await parseJsonResponse(partUrlRes, `步骤2-part_url-${partNumber}`);
          
          if (!partUrlData.success || !partUrlData.data) {
            throw new Error(partUrlData.error || '获取分片URL失败');
          }
          
          // 读取分片数据（使用 Blob 而不是一次性 arrayBuffer，减少内存压力）
          const start = (partNumber - 1) * part_size;
          const end = Math.min(start + part_size, file.size);
          const chunk = file.slice(start, end);
          
          // 上传分片
          const uploadRes = await fetch(partUrlData.data.presigned_url, {
            method: 'PUT',
            body: chunk,
          });
          
          if (!uploadRes.ok) {
            throw new Error(`分片 ${partNumber} 上传失败: HTTP ${uploadRes.status}`);
          }

          const etagRaw = uploadRes.headers.get('ETag') || uploadRes.headers.get('etag') || '';
          const etag = etagRaw.replace(/"/g, '');
          console.log(`[Upload] 分片 ${partNumber} 上传响应:`, {
            status: uploadRes.status,
            etagRaw,
            etag,
          });
          parts.push({ PartNumber: partNumber, ETag: etag });
          console.log(`[Upload] 分片 ${partNumber} 完成, ETag: ${etag}`);
          
          // 更新进度
          setUploadProgress({ current: partNumber, total: total_parts, filename: file.name });
          break; // 成功，退出重试循环
        } catch (err: any) {
          lastError = err;
          retries--;
          if (retries > 0) {
            console.warn(`[Upload] 分片 ${partNumber} 失败，剩余重试次数: ${retries}`, err);
            await new Promise(r => setTimeout(r, 1000)); // 等待1秒后重试
          }
        }
      }
      
      if (retries === 0 && lastError) {
        throw lastError;
      }
    }
    
    // 3. 完成分片上传
    console.log('[Upload] 步骤3: 完成分片上传...');
    const completeRes = await fetch(`${serverUrl}/api/desktop_upload_complete.php`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        upload_id,
        storage_key,
        parts,
      }),
    });
    const completeData = await parseJsonResponse(completeRes, '步骤3-complete');
    console.log('[Upload] 步骤3 解析后的JSON:', completeData);
    
    if (!completeData.success) {
      throw new Error(completeData.error || '完成上传失败');
    }
    
    return completeData;
  };

  // 弹窗内拖拽处理
  const handleModalDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragOver(true);
  };

  const handleModalDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragOver(false);
  };

  const handleModalDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setDragOver(false);
    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) {
      setPendingUploads((prev) => {
        const next = files.map((f) => ({ kind: 'web' as const, file: f }));
        return [...prev, ...next];
      });
    }
  };

  // 选择文件
  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    if (files.length > 0) {
      setPendingUploads((prev) => {
        const next = files.map((f) => ({ kind: 'web' as const, file: f }));
        return [...prev, ...next];
      });
    }
    e.target.value = '';
  };

  // 开始上传（从弹窗触发）
  const startUploadFromModal = async () => {
    if (pendingUploads.length === 0) return;

    // 如果是桌面端拖拽/本地路径：走队列上传（更稳定，也能在文件日志看到进度）
    if (pendingUploads.some((x) => x.kind === 'local')) {
      if (!project) {
        toast({ title: '上传失败', description: '项目未加载', variant: 'destructive' });
        return;
      }
      const groupCode = customer?.group_code || `P${project.id}`;
      const projectId = project.id;
      const assetType = uploadCategory === '客户文件' ? 'customer' : uploadCategory === '模型文件' ? 'models' : 'works';

      let queued = 0;
      for (const item of pendingUploads) {
        if (item.kind !== 'local') continue;
        try {
          await queueUpload(groupCode, assetType, item.path, item.name, projectId);
          queued++;
        } catch (err: any) {
          toast({ title: '加入队列失败', description: `${item.name}: ${err.message}`, variant: 'destructive' });
        }
      }

      setPendingUploads([]);
      setShowUploadModal(false);
      setDragOver(false);
      if (queued > 0) {
        toast({ title: '已加入上传队列', description: `共 ${queued} 个文件` });
      }
      return;
    }
    
    setUploading(true);
    let successCount = 0;
    let failCount = 0;
    
    for (const item of pendingUploads) {
      if (item.kind !== 'web') continue;
      const file = item.file;
      try {
        await uploadFile(file, uploadCategory);
        successCount++;
      } catch (err: any) {
        console.error('[Upload] 上传失败:', err);
        failCount++;
        toast({ title: '上传失败', description: `${file.name}: ${err.message}`, variant: 'destructive' });
      }
    }
    
    setUploading(false);
    setUploadProgress(null);
    setPendingUploads([]);
    setShowUploadModal(false);
    
    if (successCount > 0) {
      toast({ title: '上传完成', description: `成功 ${successCount} 个${failCount > 0 ? `，失败 ${failCount} 个` : ''}` });
      loadProject('files');
    }
  };

  // 关闭上传弹窗
  const closeUploadModal = () => {
    if (uploading) return; // 上传中不能关闭
    setShowUploadModal(false);
    setPendingUploads([]);
    setUploadProgress(null);
    setDragOver(false);
  };

  // 移除待上传文件
  const removePendingFile = (index: number) => {
    setPendingUploads(prev => prev.filter((_, i) => i !== index));
  };

  // 页面级拖拽已移除，改为弹窗内拖拽上传

  const getGroupFolderName = () => {
    const groupCode = customer?.group_code || (project?.id ? `P${project.id}` : 'P0');
    // 与看板页保持一致：优先使用customer_group_name，备选customer.name
    const groupName = (customer?.customer_group_name || customer?.name || '').replace(/[\/\\:*?"<>|]/g, '_');
    return groupName ? `${groupCode}_${groupName}` : groupCode;
  };
  
  // 获取项目名称（用于本地文件夹）
  const getProjectFolderName = () => {
    const name = project?.project_name || project?.project_code || `项目${project?.id}`;
    return name.replace(/[\/\\:*?"<>|]/g, '_');
  };
  
  // 获取完整的本地路径（与 use-auto-sync.ts 一致）
  const getLocalBasePath = () => {
    const groupCode = getGroupFolderName();
    const projectName = getProjectFolderName();
    return `${rootDir}/${groupCode}/${projectName}`;
  };

  // 打开本地文件夹（使用 Rust 命令，与悬浮窗一致）
  const openLocalFolder = async (categoryName: string) => {
    console.log('[OpenFolder] rootDir from store:', rootDir);
    if (!rootDir) {
      toast({ title: '请先在设置中配置同步根目录', variant: 'destructive' });
      return;
    }
    
    const groupFolderName = getGroupFolderName();
    const projectName = getProjectFolderName();
    const invokeParams = {
      workDir: rootDir,
      projectName: `${groupFolderName}/${projectName}`,
      subFolder: categoryName || null,
    };
    console.log('[OpenFolder] invoke 参数:', JSON.stringify(invokeParams, null, 2));
    
    try {
      const { invoke } = await import('@tauri-apps/api/core');
      // 使用 Rust 命令打开文件夹（会自动创建文件夹）
      // 路径结构: {rootDir}/{groupCode}/{projectName}/{category}
      await invoke('open_project_folder', invokeParams);
    } catch (err) {
      console.error('[OpenFolder] 打开文件夹失败:', err);
      toast({ title: '打开文件夹失败', description: String(err), variant: 'destructive' });
    }
  };

  // 扫描本地文件夹（递归）
  const scanLocalFiles = async () => {
    if (!rootDir || !customer) return;
    
    const basePath = getLocalBasePath();
    
    try {
      const result: Record<string, Array<{ name: string; path: string; relative_path: string }>> = {};
      
      for (const category of ['客户文件', '作品文件', '模型文件']) {
        const folderPath = `${basePath}/${category}`;
        try {
          // 使用递归扫描获取所有文件（包括子文件夹）
          const files = await scanFolderRecursive(folderPath);
          result[category] = files.map(f => ({
            name: f.name,
            path: f.absolute_path,
            relative_path: f.relative_path,
          }));
          console.log(`[LocalFiles] ${category} 扫描到 ${files.length} 个文件`);
        } catch {
          result[category] = [];
        }
      }
      
      setLocalFiles(result);
      console.log('[LocalFiles] 扫描完成:', result);
    } catch (err) {
      console.error('[LocalFiles] 扫描失败:', err);
    }
  };

  // 检测本地文件夹是否存在
  const checkLocalFolderExists = async () => {
    if (!rootDir || !customer) {
      setLocalFolderExists(false);
      return;
    }
    
    try {
      const { invoke } = await import('@tauri-apps/api/core');
      const folderExists = await invoke<boolean>('project_folder_exists', {
        workDir: rootDir,
        projectName: `${getGroupFolderName()}/${getProjectFolderName()}`,
      });
      setLocalFolderExists(folderExists);
      console.log('[LocalFolder] 检测结果:', folderExists);
    } catch (err) {
      console.error('[LocalFolder] 检测失败:', err);
      setLocalFolderExists(false);
    }
  };

  // 初始化本地文件夹并下载云端文件
  const initLocalFolder = async () => {
    if (!rootDir) {
      toast({ title: '请先在设置中配置同步根目录', variant: 'destructive' });
      return;
    }
    if (!customer || !project) return;
    
    setIsInitializing(true);
    setInitProgress({ total: 0, current: 0, currentFile: '创建文件夹...' });
    
    const basePath = getLocalBasePath();
    
    try {
      // 1. 创建文件夹结构
      for (const category of ['客户文件', '作品文件', '模型文件']) {
        const folderPath = `${basePath}/${category}`;
        await ensureDirectory(folderPath).catch(() => {});
      }
      
      // 2. 获取云端文件列表
      setInitProgress(prev => ({ ...prev!, currentFile: '获取云端文件列表...' }));
      
      const allFiles: Array<{ filename: string; storage_key?: string; download_url?: string; file_size?: number; category: string }> = [];
      
      if (files?.categories) {
        for (const [category, data] of Object.entries(files.categories)) {
          for (const file of (data.files || [])) {
            allFiles.push({
              filename: file.filename,
              storage_key: file.storage_key || '',
              download_url: file.download_url || '',
              file_size: file.file_size || 0,
              category,
            });
          }
        }
      }
      
      if (allFiles.length === 0) {
        setLocalFolderExists(true);
        setIsInitializing(false);
        setInitProgress(null);
        toast({ title: '初始化完成', description: '文件夹已创建，云端暂无文件' });
        await scanLocalFiles();
        return;
      }
      
      // 3. 下载文件
      setInitProgress({ total: allFiles.length, current: 0, currentFile: '' });
      
      let downloadedCount = 0;
      let skippedCount = 0;

      for (let i = 0; i < allFiles.length; i++) {
        const file = allFiles[i];
        setInitProgress({ total: allFiles.length, current: i + 1, currentFile: file.filename });
        
        try {
          const localPath = `${basePath}/${file.category}/${file.filename}`;
          try {
            const meta = await getFileMetadata(localPath);
            const expectedSize = file.file_size || 0;
            if (meta?.is_file && expectedSize > 0 && meta.size === expectedSize) {
              skippedCount++;
              console.log('[InitFolder] 跳过下载（本地已存在且大小一致）:', { filename: file.filename, localPath, size: expectedSize });
              continue;
            }
            if (meta?.is_file && expectedSize <= 0) {
              skippedCount++;
              console.log('[InitFolder] 跳过下载（本地已存在，未知云端大小）:', { filename: file.filename, localPath, localSize: meta.size });
              continue;
            }
          } catch {}

          let downloadUrl = file.download_url;
          const storageKey = file.storage_key;

          if (!downloadUrl && storageKey) {
            const downloadRes = await fetch(`${serverUrl}/api/desktop_download.php?action=get_url&storage_key=${encodeURIComponent(storageKey)}`, {
              headers: { 'Authorization': `Bearer ${token}` },
            });
            const downloadData = await downloadRes.json();
            if (downloadData.success && downloadData.data?.url) {
              downloadUrl = downloadData.data.url;
            }
          }

          if (!downloadUrl) {
            console.warn('[InitFolder] 跳过下载（缺少 download_url/storage_key）:', file.filename);
            continue;
          }

          const taskId = `init-folder-${Date.now()}-${i}`;
          const result = await downloadFileChunked(taskId, downloadUrl, localPath);
          if (result.success) {
            downloadedCount++;
            console.log('[InitFolder] 下载成功:', file.filename);
          } else {
            console.warn('[InitFolder] 下载失败:', file.filename, result.error);
          }
        } catch (err) {
          console.error('[InitFolder] 下载失败:', file.filename, err);
        }
      }
      
      setInitProgress({ total: allFiles.length, current: allFiles.length, currentFile: '完成!' });
      setLocalFolderExists(true);
      await scanLocalFiles();
      
      toast({ title: '初始化完成', description: `已下载 ${downloadedCount} 个文件，跳过 ${skippedCount} 个` });
    } catch (err) {
      console.error('[InitFolder] 初始化失败:', err);
      toast({ title: '初始化失败', description: String(err), variant: 'destructive' });
    } finally {
      setIsInitializing(false);
      setTimeout(() => setInitProgress(null), 2000);
    }
  };

  // 当切换到文件标签时扫描本地文件
  useEffect(() => {
    if (activeTab === 'files' && rootDir && customer) {
      scanLocalFiles();
      checkLocalFolderExists();
    }
  }, [activeTab, rootDir, customer]);

  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center text-gray-400">
        加载中...
      </div>
    );
  }

  if (!project) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center text-gray-400">
        <p>项目不存在或无权访问</p>
        <p className="text-sm mt-2">请确认项目ID正确且您有权限访问</p>
        <button
          onClick={() => navigate('/project-kanban')}
          className="mt-4 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"
        >
          返回看板
        </button>
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col h-full bg-gray-50 overflow-auto">
      {/* 头部 */}
      <div className="bg-gradient-to-r from-indigo-600 to-purple-600 text-white p-6">
        <div className="flex items-center gap-4 mb-4">
          <button
            onClick={() => navigate('/project-kanban')}
            className="p-2 hover:bg-white/20 rounded-lg transition-colors"
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div className="flex-1">
            <h1 className="text-2xl font-bold">{project.project_name}</h1>
            <div className="flex items-center gap-4 mt-2 text-white/80 text-sm">
              <span className="font-mono">{project.project_code}</span>
              <span>•</span>
              <span>{customer?.name}</span>
              <span>•</span>
              <span>创建于 {project.create_time}</span>
            </div>
          </div>
          
          {/* 门户操作按钮 */}
          <div className="flex items-center gap-2">
            <button
              onClick={() => loadProject()}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg transition-colors"
            >
              <RefreshCw className="w-4 h-4" />
              刷新
            </button>
            {canViewPortal() && customer?.portal_token && (
              <button
                onClick={async () => {
                  const url = `${serverUrl}/portal.php?token=${customer.portal_token}&project_id=${project.id}`;
                  try {
                    // 尝试使用 Tauri shell API
                    const { open } = await import('@tauri-apps/plugin-shell');
                    await open(url);
                  } catch {
                    // 回退到 window.open
                    window.open(url, '_blank');
                  }
                }}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg transition-colors"
              >
                <ExternalLink className="w-4 h-4" />
                客户门户
              </button>
            )}

            <InputDialog
              open={renameDialogOpen}
              onClose={() => {
                setRenameDialogOpen(false);
                setRenameTarget(null);
              }}
              title="重命名文件"
              placeholder="请输入新文件名"
              defaultValue={renameTarget?.filename || ''}
              onConfirm={doRename}
              confirmText="重命名"
            />

            <ConfirmDialog
              open={showBatchDeleteConfirm}
              onClose={() => setShowBatchDeleteConfirm(false)}
              onConfirm={async () => {
                setShowBatchDeleteConfirm(false);
                await doBatchDelete();
              }}
              title="批量删除"
              message="确定要批量删除所选文件吗？"
              type="warning"
              confirmText="删除"
              cancelText="取消"
            />
            {canCopyPortalLink() && customer?.portal_token && (
              <button
                onClick={handleOpenLinkModal}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg transition-colors"
              >
                <Link2 className="w-4 h-4" />
                复制链接
              </button>
            )}
            <button
              onClick={handleOpenShareLinkModal}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-green-500/80 hover:bg-green-600 rounded-lg transition-colors"
            >
              <Upload className="w-4 h-4" />
              生成传输链接
            </button>
            {canViewPortalPassword() && customer && (
              <button
                onClick={async () => {
                  // 从 portal_password.php API 获取当前密码
                  try {
                    const res = await fetch(`${serverUrl}/api/portal_password.php?customer_id=${customer.id}`, {
                      headers: { 'Authorization': `Bearer ${token}` },
                    });
                    const data = await res.json();
                    if (data.success && data.data) {
                      setPortalPassword(data.data.current_password || '');
                    } else {
                      setPortalPassword('');
                    }
                  } catch (e) {
                    console.error('获取密码失败:', e);
                    setPortalPassword('');
                  }
                  setShowPassword(false);
                  setShowPasswordEditor(true);
                }}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-white/20 hover:bg-white/30 rounded-lg transition-colors"
              >
                <Lock className="w-4 h-4" />
                密码
              </button>
            )}
            {isManager && (
              <button
                onClick={() => setShowDeleteConfirm(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-red-500/80 hover:bg-red-600 rounded-lg transition-colors"
              >
                <Trash2 className="w-4 h-4" />
                删除
              </button>
            )}
          </div>
        </div>
      </div>

      {/* 状态步骤条 */}
      <div className="bg-white border-b px-6 py-4">
        <div className="flex items-center justify-between">
          {(statuses || []).map((status, index) => {
            // 项目完工时，所有阶段都视为已完成
            const isProjectCompleted = !!project.completed_at || project.days_info?.is_completed;
            const isCompleted = isProjectCompleted ? true : (status.order < currentStatusOrder);
            const isCurrent = isProjectCompleted ? false : (status.key === project.current_status);
            
            return (
              <div key={status.key} className="flex items-center flex-1">
                <button
                  onClick={() => canEditThisProject && requestChangeStatus(status.key)}
                  disabled={!canEditThisProject}
                  className={`flex flex-col items-center group ${
                    canEditThisProject ? 'cursor-pointer' : 'cursor-default'
                  } ${isCurrent ? 'scale-110' : ''}`}
                >
                  <div
                    className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
                      isCompleted
                        ? 'bg-green-500 text-white'
                        : isCurrent
                        ? 'bg-indigo-600 text-white ring-4 ring-indigo-200'
                        : 'bg-gray-200 text-gray-400 group-hover:bg-gray-300'
                    }`}
                  >
                    {isCompleted ? (
                      <Check className="w-5 h-5" />
                    ) : (
                      <span className="text-sm font-bold">{status.order}</span>
                    )}
                  </div>
                  <span
                    className={`mt-2 text-xs font-medium ${
                      isCurrent ? 'text-indigo-600' : isCompleted ? 'text-green-600' : 'text-gray-400'
                    }`}
                  >
                    {status.label}
                  </span>
                  {/* 阶段天数显示 */}
                  {stageDaysMap[status.key] && (
                    <span className="text-[10px] mt-0.5 text-gray-500">
                      {stageDaysMap[status.key].days}天
                    </span>
                  )}
                </button>
                
                {index < statuses.length - 1 && (
                  <div
                    className={`flex-1 h-1 mx-2 rounded ${
                      isProjectCompleted || status.order < currentStatusOrder ? 'bg-green-500' : 'bg-gray-200'
                    }`}
                  />
                )}
              </div>
            );
          })}
        </div>
        
        {/* 项目周期卡片 */}
        {project.days_info?.total_days && project.days_info.total_days > 0 && (
          <div className="mt-4 p-4 bg-gray-50 rounded-lg">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium text-gray-700">📊 项目周期</span>
              <span className="text-xs text-gray-500">
                {project.days_info?.date_range || `${project.start_date || '未设置'} ~ ${project.deadline || '未设置'}`}
              </span>
            </div>
            
            {/* 天数统计 */}
            <div className="grid grid-cols-3 gap-3 mb-3">
              <div className="text-center p-2 bg-white rounded-lg">
                <div className="text-xl font-bold text-indigo-600">
                  {project.days_info?.total_days ?? '-'}
                </div>
                <div className="text-xs text-gray-500">
                  {project.days_info?.is_completed ? '计划天数' : '总天数'}
                </div>
              </div>
              <div className="text-center p-2 bg-white rounded-lg">
                <div className="text-xl font-bold text-green-600">
                  {project.days_info?.is_completed 
                    ? (project.days_info?.actual_days ?? project.days_info?.elapsed_days ?? '-')
                    : (project.days_info?.elapsed_days ?? '-')}
                </div>
                <div className="text-xs text-gray-500">
                  {project.days_info?.is_completed ? '实际用时' : '已进行'}
                </div>
              </div>
              <div className="text-center p-2 bg-white rounded-lg">
                {project.days_info?.is_completed ? (
                  <>
                    <div className="text-xl font-bold text-green-600">✓</div>
                    <div className="text-xs text-green-600">已完工</div>
                  </>
                ) : (
                  <>
                    <div className={`text-xl font-bold ${project.days_info?.is_overdue ? 'text-red-600' : 'text-orange-500'}`}>
                      {project.days_info?.is_overdue 
                        ? `-${project.days_info.overdue_days}` 
                        : project.days_info?.remaining_days ?? '-'}
                    </div>
                    <div className="text-xs text-gray-500">
                      {project.days_info?.is_overdue ? '已超期' : '剩余'}
                    </div>
                  </>
                )}
              </div>
            </div>
            
            {/* 时间进度条 */}
            {project.days_info?.total_days && project.days_info?.total_days > 0 && (
              <div>
                <div className="flex justify-between text-xs mb-1">
                  <span className={project.days_info?.is_completed ? 'text-green-600' : 'text-gray-500'}>
                    {project.days_info?.is_completed ? '项目已完成' : '时间进度'}
                  </span>
                  <span className={project.days_info?.is_completed ? 'text-green-600' : 'text-gray-500'}>
                    {project.days_info?.overall_progress ?? 0}%
                  </span>
                </div>
                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div
                    className={`h-full rounded-full transition-all ${
                      project.days_info?.is_completed ? 'bg-green-500' : 
                      project.days_info?.is_overdue ? 'bg-red-500' : 'bg-green-500'
                    }`}
                    style={{ width: `${project.days_info?.overall_progress ?? 0}%` }}
                  />
                </div>
              </div>
            )}
            
            {/* 操作按钮 */}
            {canEditThisProject && (
              <div className="mt-3 flex justify-end gap-2">
                {project.current_status === '设计评价' && !project.completed_at && (
                  <button
                    onClick={handleManualComplete}
                    className="px-3 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded transition-colors border border-orange-200"
                  >
                    手动完工
                  </button>
                )}
                <button
                  onClick={() => setShowStageTimeEditor(true)}
                  className="px-3 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded transition-colors border border-indigo-200"
                >
                  调整阶段时间
                </button>
              </div>
            )}
          </div>
        )}
        
        {/* 简化周期显示（无周期数据时） */}
        {!(project.days_info?.total_days && project.days_info.total_days > 0) && (
          <div className="mt-4 flex items-center gap-4">
            <span className="text-sm text-gray-500">项目周期: 未设置</span>
            {canEditThisProject && (
              <button
                onClick={() => setShowStageTimeEditor(true)}
                className="px-2 py-0.5 text-xs text-indigo-600 hover:bg-indigo-50 rounded transition-colors"
              >
                设置阶段时间
              </button>
            )}
          </div>
        )}
      </div>

      {/* 主体：侧边栏 + 内容 */}
      <div className="flex-1 flex overflow-hidden">
        {/* 侧边栏 */}
        <DetailSidebar
          tabs={PROJECT_SIDEBAR_TABS}
          activeTab={activeTab}
          onTabChange={(key) => handleTabChange(key as TabType)}
        />

        {/* Tab 内容 */}
        <div className="flex-1 p-6 overflow-auto">
        {tabLoading ? (
          <div className="flex items-center justify-center h-32 text-gray-400">加载中...</div>
        ) : activeTab === 'overview' ? (
          /* 概览 */
          <div className="grid grid-cols-3 gap-6">
            {/* 项目信息 */}
            <div className="bg-white rounded-xl p-6 border">
              <h3 className="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <FileText className="w-4 h-4 text-indigo-600" />
                项目信息
              </h3>
              <div className="space-y-4">
                <div>
                  <label className="text-xs text-gray-400 uppercase">项目编号</label>
                  <p className="text-sm font-mono text-gray-800">{project.project_code}</p>
                </div>
                <div>
                  <label className="text-xs text-gray-400 uppercase">当前状态</label>
                  <p className="text-sm">
                    <span className="px-2 py-1 bg-indigo-100 text-indigo-700 rounded text-xs">
                      {project.current_status}
                    </span>
                  </p>
                </div>
                <div>
                  <label className="text-xs text-gray-400 uppercase">更新时间</label>
                  <p className="text-sm text-gray-800">{project.update_time}</p>
                </div>
                {/* 项目周期 - 动态计算 */}
                <div className="grid grid-cols-2 gap-4 pt-3 border-t">
                  <div>
                    <label className="text-xs text-gray-400 uppercase">项目周期</label>
                    <p className="text-sm text-gray-800">
                      {project.days_info?.date_range || '-'}
                    </p>
                  </div>
                  <div>
                    <label className="text-xs text-gray-400 uppercase">进度</label>
                    <p className={`text-sm ${
                      project.days_info?.is_overdue
                        ? 'text-red-600 font-medium'
                        : 'text-gray-800'
                    }`}>
                      {project.days_info?.is_completed 
                        ? `已完工 (实际${project.days_info?.actual_days || 0}天)`
                        : project.days_info?.total_days 
                          ? `${project.days_info?.elapsed_days || 0}/${project.days_info?.total_days}天`
                          : '-'
                      }
                      {project.days_info?.is_overdue && ` (超期${project.days_info?.overdue_days}天)`}
                    </p>
                  </div>
                </div>
                {project.remark && (
                  <div>
                    <label className="text-xs text-gray-400 uppercase">备注</label>
                    <p className="text-sm text-gray-600">{project.remark}</p>
                  </div>
                )}
              </div>
            </div>

            {/* 客户信息 */}
            <div className="bg-white rounded-xl p-6 border">
              <h3 className="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <User className="w-4 h-4 text-indigo-600" />
                客户信息
              </h3>
              <div className="space-y-4">
                <div>
                  <label className="text-xs text-gray-400 uppercase">客户名称</label>
                  <div className="flex items-center gap-2">
                    <p className="text-sm text-gray-800">{customer?.name}</p>
                    {customer?.name && (
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(customer.name || '');
                          toast({ title: '已复制', description: '客户名称已复制到剪贴板' });
                        }}
                        className="text-gray-400 hover:text-indigo-600"
                        title="复制客户名称"
                      >
                        <Copy className="w-3.5 h-3.5" />
                      </button>
                    )}
                  </div>
                </div>
                {customer?.group_code && (
                  <div>
                    <label className="text-xs text-gray-400 uppercase">群码</label>
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-mono text-gray-800">{customer.group_code}</p>
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(customer.group_code || '');
                          toast({ title: '已复制', description: '群码已复制到剪贴板' });
                        }}
                        className="text-gray-400 hover:text-indigo-600"
                        title="复制群码"
                      >
                        <Copy className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                )}
                {customer?.customer_group_name && (
                  <div>
                    <label className="text-xs text-gray-400 uppercase">客户群名称</label>
                    <div className="flex items-center gap-2">
                      <p className="text-sm text-gray-800">{customer.customer_group_name}</p>
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(customer.customer_group_name || '');
                          toast({ title: '已复制', description: '客户群名称已复制到剪贴板' });
                        }}
                        className="text-gray-400 hover:text-indigo-600"
                        title="复制客户群名称"
                      >
                        <Copy className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                )}
                <div>
                  <label className="text-xs text-gray-400 uppercase">客户别名</label>
                  <div className="flex items-center gap-2">
                    <p className="text-sm text-gray-800">{customer?.alias || '-'}</p>
                    <button
                      onClick={() => {
                        setAliasValue(customer?.alias || '');
                        setShowAliasEditor(true);
                      }}
                      className="text-xs text-indigo-600 hover:text-indigo-700"
                    >
                      编辑
                    </button>
                  </div>
                </div>
                {customer?.phone && (
                  <div>
                    <label className="text-xs text-gray-400 uppercase">联系电话</label>
                    <p className="text-sm text-gray-800">{customer.phone}</p>
                  </div>
                )}
                {/* 门户信息 */}
                {customer?.portal_token && (
                  <div className="col-span-2 pt-3 border-t mt-3">
                    <label className="text-xs text-gray-400 uppercase mb-2 block">客户门户</label>
                    <div className="flex items-center gap-2 flex-wrap">
                      <a
                        href={`${serverUrl}/portal.php?token=${customer.portal_token}&project_id=${project.id}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700 transition-colors"
                      >
                        <ExternalLink className="w-3.5 h-3.5" />
                        打开门户
                      </a>
                      <button
                        onClick={() => {
                          const url = `${serverUrl}/portal.php?token=${customer.portal_token}&project_id=${project.id}`;
                          navigator.clipboard.writeText(url);
                          toast({ title: '成功', description: '链接已复制' });
                        }}
                        className="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-700 text-xs rounded-lg hover:bg-gray-200 transition-colors"
                      >
                        <Link2 className="w-3.5 h-3.5" />
                        复制链接
                      </button>
                      <button
                        onClick={async () => {
                          // 从 portal_password.php API 获取当前密码
                          try {
                            const res = await fetch(`${serverUrl}/api/portal_password.php?customer_id=${customer.id}`, {
                              headers: { 'Authorization': `Bearer ${token}` },
                            });
                            const data = await res.json();
                            if (data.success && data.data) {
                              setPortalPassword(data.data.current_password || '');
                            } else {
                              setPortalPassword('');
                            }
                          } catch (e) {
                            console.error('获取密码失败:', e);
                            setPortalPassword('');
                          }
                          setShowPassword(false);
                          setShowPasswordEditor(true);
                        }}
                        className="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-700 text-xs rounded-lg hover:bg-gray-200 transition-colors"
                      >
                        <Lock className="w-3.5 h-3.5" />
                        管理密码
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* 设计负责人 */}
            <div className="bg-white rounded-xl p-6 border">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                  <User className="w-4 h-4 text-indigo-600" />
                  设计负责人
                </h3>
                {canAssignProject() && (
                  <button
                    onClick={() => {
                      setSelectedTechIds((techUsers || []).map(t => t.id));
                      setShowUserSelector(true);
                    }}
                    className="flex items-center gap-1 px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded transition-colors"
                  >
                    <UserPlus className="w-3.5 h-3.5" />
                    分配
                  </button>
                )}
              </div>
              {techUsers.length === 0 ? (
                <p className="text-sm text-gray-400">暂无分配</p>
              ) : (
                <div className="space-y-3">
                  {(techUsers || []).map((tech) => {
                    // 只有管理员或本人才能看到提成
                    const canSeeCommission = isManager || tech.id === user?.id;
                    return (
                      <div key={tech.id} className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center font-semibold">
                            {tech.name.charAt(0)}
                          </div>
                          <div>
                            <p className="text-sm font-medium text-gray-800">{tech.name}</p>
                            {canSeeCommission && tech.commission !== null ? (
                              <p className="text-xs text-green-600">提成: ¥{tech.commission}</p>
                            ) : canSeeCommission ? (
                              <p className="text-xs text-gray-400">未设置提成</p>
                            ) : null}
                          </div>
                        </div>
                        {canAssignProject() && (
                          <button
                            onClick={() => {
                              setEditingTech(tech);
                              setCommissionAmount(tech.commission?.toString() || '');
                              setCommissionNote(tech.commission_note || '');
                              setShowCommissionEditor(true);
                            }}
                            className="text-xs text-indigo-600 hover:text-indigo-800"
                          >
                            <DollarSign className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
            
            {/* 客户评价（仅在设计评价阶段或已完工时显示） */}
            {(project.current_status === '设计评价' || project.completed_at) && (
              <div className="bg-white rounded-xl p-6 border col-span-3">
                <h3 className="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2">
                  <Star className="w-4 h-4 text-yellow-500" />
                  客户评价
                </h3>
                {project.completed_at ? (
                  <div className="space-y-3">
                    <div className="flex items-center gap-2 text-sm text-green-600">
                      <CheckCircle className="w-4 h-4" />
                      <span>项目已完工</span>
                      {project.completed_by === 'auto' && <span className="text-gray-400">（超时自动完工）</span>}
                      {project.completed_by === 'admin' && <span className="text-gray-400">（管理员手动完工）</span>}
                      {project.completed_by === 'customer' && <span className="text-gray-400">（客户评价后完工）</span>}
                    </div>
                    
                    {/* 评价详情 */}
                    {evaluationData?.evaluation && (
                      <div className="mt-3 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                        <div className="flex items-center gap-2 mb-2">
                          <span className="text-sm font-medium text-gray-700">评分：</span>
                          <div className="flex gap-0.5">
                            {[1, 2, 3, 4, 5].map((star) => (
                              <Star
                                key={star}
                                className={`w-4 h-4 ${star <= evaluationData.evaluation!.rating ? 'text-yellow-500 fill-yellow-500' : 'text-gray-300'}`}
                              />
                            ))}
                          </div>
                          <span className="text-sm text-gray-500">({evaluationData.evaluation.rating}/5)</span>
                        </div>
                        {evaluationData.evaluation.comment && (
                          <div className="text-sm text-gray-600">
                            <span className="font-medium">评价内容：</span>
                            <p className="mt-1 text-gray-700">{evaluationData.evaluation.comment}</p>
                          </div>
                        )}
                        <div className="mt-2 text-xs text-gray-400">
                          评价时间：{evaluationData.evaluation.created_at}
                        </div>
                      </div>
                    )}
                    
                    {/* 评价表单 */}
                    {evaluationData?.evaluation_form && (
                      <div className="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                        <div className="flex items-center justify-between">
                          <div>
                            <div className="text-sm font-medium text-gray-700">评价表单：{evaluationData.evaluation_form.template_name}</div>
                            <div className="text-xs text-gray-500 mt-1">
                              状态：{evaluationData.evaluation_form.status === 'submitted' ? '已提交' : '待填写'}
                            </div>
                          </div>
                          {evaluationData.evaluation_form.status === 'submitted' && (
                            <button
                              onClick={() => {
                                setSelectedFormId(evaluationData.evaluation_form!.id);
                                setShowFormDetail(true);
                              }}
                              className="px-3 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700"
                            >
                              查看详情
                            </button>
                          )}
                        </div>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="text-sm text-orange-600 flex items-center gap-2">
                    <Clock className="w-4 h-4" />
                    等待客户评价
                  </div>
                )}
              </div>
            )}
            
            {/* 人员选择器弹窗 */}
            <UserSelector
              open={showUserSelector}
              onClose={() => setShowUserSelector(false)}
              mode="multiple"
              value={selectedTechIds}
              onChange={async (ids, items) => {
                console.log('选择的设计师:', ids, items);
                setShowUserSelector(false);
                // 调用 API 保存分配
                try {
                  const res = await fetch(`${serverUrl}/api/desktop_projects.php?action=assign_tech`, {
                    method: 'POST',
                    headers: {
                      'Authorization': `Bearer ${token}`,
                      'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                      project_id: parseInt(id || '0'),
                      tech_user_ids: ids,
                    }),
                  });
                  const data = await res.json();
                  if (data.success) {
                    toast({ title: '成功', description: `设计师分配成功` });
                    // 刷新项目数据
                    loadProject('overview');
                  } else {
                    toast({ title: '错误', description: data.error || '分配失败', variant: 'destructive' });
                  }
                } catch (e) {
                  console.error('分配设计师失败:', e);
                  toast({ title: '错误', description: '分配失败', variant: 'destructive' });
                }
              }}
              roleFilter="tech"
            />
            
            {/* 日期编辑弹窗 */}
            <DateEditor
              open={showDateEditor}
              onClose={() => setShowDateEditor(false)}
              startDate={project.start_date}
              deadline={project.deadline}
              onSave={updateProjectDates}
            />
          </div>
        ) : activeTab === 'forms' ? (
          /* 动态表单 */
          <div className="bg-white rounded-xl border">
            {forms.length === 0 ? (
              <div className="p-8 text-center text-gray-400">
                <FileText className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>暂无动态表单</p>
              </div>
            ) : (
              <div className="divide-y">
                {(forms || []).map((form) => (
                  <div key={form.id} className="p-4 hover:bg-gray-50">
                    <div className="flex items-center justify-between">
                      <div className="flex-1">
                        <p className="font-medium text-gray-800">{form.instance_name}</p>
                        <p className="text-xs text-gray-400 mt-1">{form.template_name} · 创建于 {form.create_time}</p>
                      </div>
                      <div className="flex items-center gap-2">
                        {/* 需求状态 */}
                        <span className={`px-2 py-1 text-xs rounded ${
                          form.requirement_status === 'confirmed' ? 'bg-green-100 text-green-700' :
                          form.requirement_status === 'communicating' ? 'bg-blue-100 text-blue-700' :
                          'bg-yellow-100 text-yellow-700'
                        }`}>
                          {form.requirement_status === 'confirmed' ? '已确认' : 
                           form.requirement_status === 'communicating' ? '沟通中' : '待沟通'}
                        </span>
                        {form.submission_count > 0 && (
                          <span className="px-2 py-1 text-xs rounded bg-indigo-100 text-indigo-700">
                            {form.submission_count}次提交
                          </span>
                        )}
                      </div>
                    </div>
                    {/* 操作按钮 */}
                    <div className="flex items-center gap-2 mt-3">
                      <button
                        onClick={() => {
                          const url = `${serverUrl}/form_fill.php?token=${form.fill_token}`;
                          navigator.clipboard.writeText(url);
                          toast({ title: '成功', description: '填写链接已复制' });
                        }}
                        className="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors flex items-center gap-1"
                      >
                        <Link2 className="w-3.5 h-3.5" />
                        复制链接
                      </button>
                      <button
                        onClick={() => {
                          setSelectedFormId(form.id);
                          setShowFormDetail(true);
                        }}
                        className="px-3 py-1.5 text-xs bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded transition-colors flex items-center gap-1"
                      >
                        <FileText className="w-3.5 h-3.5" />
                        查看详情
                        {form.submission_count > 0 && (
                          <span className="ml-1 px-1.5 py-0.5 bg-indigo-500 text-white rounded-full text-[10px]">
                            {form.submission_count}
                          </span>
                        )}
                      </button>
                      {(!form.requirement_status || form.requirement_status === 'pending') && (
                        <button
                          onClick={() => startFormCommunication(form.id)}
                          className="px-3 py-1.5 text-xs bg-blue-500 hover:bg-blue-600 text-white rounded transition-colors flex items-center gap-1"
                        >
                          <Phone className="w-3.5 h-3.5" />
                          开始沟通
                        </button>
                      )}
                      {form.requirement_status === 'communicating' && (
                        <button
                          onClick={() => confirmFormRequirement(form.id)}
                          className="px-3 py-1.5 text-xs bg-green-500 hover:bg-green-600 text-white rounded transition-colors flex items-center gap-1"
                        >
                          <Check className="w-3.5 h-3.5" />
                          确认需求
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : activeTab === 'files' ? (
          /* 交付物 - 三个板块分类展示 */
          <div className="space-y-4">
            {/* 上传按钮 */}
            <div className="flex justify-end">
              <button
                type="button"
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  setShowUploadModal(true);
                }}
                disabled={uploading}
                className={`flex items-center gap-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors ${uploading ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <Upload className="w-4 h-4" />
                {uploading ? '上传中...' : '上传文件'}
              </button>
            </div>
            
            {/* 本地文件夹状态卡片 - 紧凑版 */}
            {rootDir && (
              <div className={`mb-3 px-3 py-2 rounded-lg border flex items-center justify-between ${localFolderExists ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200'}`}>
                <div className="flex items-center gap-2 text-sm">
                  <span>📁</span>
                  <span className="text-gray-600">
                    {localFolderExists === null ? '检测中...' : localFolderExists ? '✅ 已同步' : '❌ 未创建'}
                  </span>
                  <span className="text-xs text-gray-400 truncate max-w-xs" title={getLocalBasePath()}>
                    {`${getGroupFolderName()}/${getProjectFolderName()}`}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  {localFolderExists ? (
                    <>
                      <button onClick={() => openLocalFolder('')} className="px-2 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-50">📂 打开</button>
                      <button onClick={initLocalFolder} disabled={isInitializing} className="px-2 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50">🔄 同步</button>
                    </>
                  ) : (
                    <button onClick={initLocalFolder} disabled={isInitializing} className="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50">
                      📥 {isInitializing ? '初始化中...' : '初始化本地文件夹'}
                    </button>
                  )}
                </div>
              </div>
            )}
            {/* 初始化进度条 */}
            {initProgress && (
              <div className="mb-3 px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg">
                <div className="flex items-center justify-between text-xs text-gray-600 mb-1">
                  <span className="truncate max-w-xs">{initProgress.currentFile}</span>
                  <span>{initProgress.current}/{initProgress.total}</span>
                </div>
                <div className="w-full h-1.5 bg-gray-200 rounded-full overflow-hidden">
                  <div className="h-full bg-blue-500 transition-all duration-300" style={{ width: `${initProgress.total > 0 ? (initProgress.current / initProgress.total) * 100 : 0}%` }} />
                </div>
              </div>
            )}
            
            {/* 三个板块：客户文件、作品文件、模型文件 */}
            {FILE_CATEGORIES.map((categoryName) => {
              const categoryFiles = files?.categories?.[categoryName]?.files || [];
              const colors = FILE_CATEGORY_COLORS[categoryName];
              const deletableSelected = categoryFiles
                .filter((f: any) => selectedFileIds.has(Number(f.id)) && Number(f.id) > 0)
                .filter((f: any) => canManageFile(f));
              const pendingSelected = categoryFiles
                .filter((f: any) => selectedFileIds.has(Number(f.id)) && Number(f.id) > 0)
                .filter((f: any) => normalizeApprovalStatus(f.approval_status) === 'pending');
              return (
                <div 
                  key={categoryName} 
                  className={`bg-white rounded-xl border ${colors.bg}`}
                >
                  <div className={`px-4 py-3 border-b rounded-t-xl ${colors.header}`}>
                    <div className="flex items-center justify-between">
                      <h4 className="font-medium">{categoryName}</h4>
                      <div className="flex items-center gap-2">
                        <span className="text-xs opacity-70">
                          {categoryFiles.length} 个文件
                        </span>
                        <div className="flex items-center gap-1 bg-white/50 rounded p-0.5">
                          <button
                            type="button"
                            onClick={() => setFileViewMode('list')}
                            className={`px-2 py-0.5 text-xs rounded ${fileViewMode === 'list' ? 'bg-white shadow' : 'hover:bg-white/50'}`}
                            title="列表视图"
                          >
                            列表
                          </button>
                          <button
                            type="button"
                            onClick={() => setFileViewMode('tree')}
                            className={`px-2 py-0.5 text-xs rounded ${fileViewMode === 'tree' ? 'bg-white shadow' : 'hover:bg-white/50'}`}
                            title="树状视图"
                          >
                            树状
                          </button>
                        </div>
                        {/* 全选/取消全选按钮 */}
                        {categoryFiles.filter((f: any) => canManageFile(f) && Number(f.id) > 0).length > 0 && (
                          <button
                            type="button"
                            className="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200"
                            onClick={() => {
                              const manageableFiles = categoryFiles.filter((f: any) => canManageFile(f) && Number(f.id) > 0);
                              const allSelected = manageableFiles.every((f: any) => selectedFileIds.has(Number(f.id)));
                              if (allSelected) {
                                // 取消全选
                                setSelectedFileIds(prev => {
                                  const next = new Set(prev);
                                  manageableFiles.forEach((f: any) => next.delete(Number(f.id)));
                                  return next;
                                });
                              } else {
                                // 全选
                                setSelectedFileIds(prev => {
                                  const next = new Set(prev);
                                  manageableFiles.forEach((f: any) => next.add(Number(f.id)));
                                  return next;
                                });
                              }
                            }}
                            title={categoryFiles.filter((f: any) => canManageFile(f) && Number(f.id) > 0).every((f: any) => selectedFileIds.has(Number(f.id))) ? '取消全选' : '全选'}
                          >
                            {categoryFiles.filter((f: any) => canManageFile(f) && Number(f.id) > 0).every((f: any) => selectedFileIds.has(Number(f.id))) ? '取消全选' : '全选'}
                          </button>
                        )}
                        {deletableSelected.length > 0 && (
                          <button
                            type="button"
                            className="px-2 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600"
                            onClick={() => setShowBatchDeleteConfirm(true)}
                            title="批量删除"
                          >
                            批量删除({deletableSelected.length})
                          </button>
                        )}
                        {isManager && pendingSelected.length > 0 && (
                          <>
                            <button
                              type="button"
                              className="px-2 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600"
                              onClick={() => handleBatchApprove(pendingSelected)}
                              title="批量通过"
                            >
                              批量通过({pendingSelected.length})
                            </button>
                            <button
                              type="button"
                              className="px-2 py-1 text-xs bg-orange-500 text-white rounded hover:bg-orange-600"
                              onClick={() => handleBatchReject(pendingSelected)}
                              title="批量驳回"
                            >
                              批量驳回({pendingSelected.length})
                            </button>
                          </>
                        )}
                        {(() => {
                          const rejectedSelected = categoryFiles
                            .filter((f: any) => selectedFileIds.has(Number(f.id)) && normalizeApprovalStatus(f.approval_status) === 'rejected');
                          return rejectedSelected.length > 0 ? (
                            <button
                              type="button"
                              className="px-2 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600"
                              onClick={() => handleBatchResubmit(rejectedSelected, categoryName)}
                              title="批量重新提交"
                            >
                              批量重新提交({rejectedSelected.length})
                            </button>
                          ) : null;
                        })()}
                        <button
                          onClick={() => openLocalFolder(categoryName)}
                          className="p-1 hover:bg-white/50 rounded"
                          title={`打开本地${categoryName}文件夹`}
                        >
                          <FolderOpen className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                  <div className="divide-y">
                    {/* 本地文件 - 树状视图 */}
                    {(localFiles[categoryName] || []).length > 0 && (
                      <LocalFileTree
                        files={localFiles[categoryName] || []}
                        cloudFiles={categoryFiles.map((f: any) => ({ 
                          filename: f.filename, 
                          relative_path: f.relative_path 
                        }))}
                        onUploadFile={async (file) => {
                          try {
                            if (!project) throw new Error('项目未加载');
                            const groupCode = customer?.group_code || `P${project.id}`;
                            const projectId = project.id;
                            const assetType = categoryName === '客户文件' ? 'customer' : categoryName === '模型文件' ? 'models' : 'works';
                            await queueUpload(groupCode, assetType, file.path, file.name, projectId);
                            toast({ title: '已添加到上传队列', description: file.name });
                          } catch (err: any) {
                            toast({ title: '上传失败', description: err.message, variant: 'destructive' });
                          }
                        }}
                        onUploadFolder={async (_folderPath, files) => {
                          try {
                            if (!project) throw new Error('项目未加载');
                            const groupCode = customer?.group_code || `P${project.id}`;
                            const projectId = project.id;
                            const assetType = categoryName === '客户文件' ? 'customer' : categoryName === '模型文件' ? 'models' : 'works';
                            for (const file of files) {
                              await queueUpload(groupCode, assetType, file.path, file.name, projectId);
                            }
                            toast({ title: '已添加到上传队列', description: `${files.length} 个文件` });
                          } catch (err: any) {
                            toast({ title: '上传失败', description: err.message, variant: 'destructive' });
                          }
                        }}
                        selectedFiles={selectedLocalFiles}
                        onToggleSelect={(filePath) => {
                          setSelectedLocalFiles(prev => {
                            const newSet = new Set(prev);
                            if (newSet.has(filePath)) {
                              newSet.delete(filePath);
                            } else {
                              newSet.add(filePath);
                            }
                            return newSet;
                          });
                        }}
                        onSelectAll={(files) => {
                          setSelectedLocalFiles(prev => {
                            const allSelected = files.every(f => prev.has(f.path));
                            if (allSelected) {
                              return new Set();
                            } else {
                              return new Set(files.map(f => f.path));
                            }
                          });
                        }}
                      />
                    )}
                    
                    {/* 云端文件 - 树状视图 */}
                    {fileViewMode === 'tree' && categoryFiles.length > 0 && (
                      <FileTree
                        nodes={(() => {
                          // 优先使用API返回的tree，否则从files构建
                          const apiTree = files?.categories?.[categoryName]?.tree;
                          if (apiTree && apiTree.length > 0) {
                            return apiTree as FileNode[];
                          }
                          // 从平铺的files构建树状结构
                          return categoryFiles.map((f: any) => ({
                            name: f.filename || f.relative_path?.split('/').pop() || '未知文件',
                            type: 'file' as const,
                            path: f.relative_path || f.filename,
                            file: {
                              id: f.id,
                              filename: f.filename,
                              relative_path: f.relative_path || f.filename,
                              file_size: f.file_size || 0,
                              storage_key: f.storage_key || f.file_path || '',
                              download_url: f.download_url || '',
                              thumbnail_url: f.thumbnail_url,
                              last_modified: f.last_modified,
                              approval_status: f.approval_status,
                              uploader_name: f.uploader_name,
                              create_time: f.create_time,
                            },
                          }));
                        })()}
                        onPreview={(file) => file && handleFilePreview(file, categoryName)}
                        onDownload={async (file) => {
                          if (!file?.download_url) {
                            toast({ title: '下载失败', description: '缺少下载链接', variant: 'destructive' });
                            return;
                          }
                          try {
                            const downloadDir = await open({ directory: true, title: '选择保存位置' });
                            if (!downloadDir) return;
                            const savePath = `${downloadDir}/${file.filename}`;
                            await downloadFileChunked(`download-${Date.now()}`, file.download_url, savePath);
                            toast({ title: '下载完成', description: file.filename });
                          } catch (err: any) {
                            toast({ title: '下载失败', description: err.message, variant: 'destructive' });
                          }
                        }}
                        onDelete={(file) => file && handleFileDelete(file)}
                        onRename={(file) => {
                          if (!file) return;
                          // 找到对应的原始文件对象
                          const originalFile = categoryFiles.find((f: any) => f.storage_key === file.storage_key || f.filename === file.filename);
                          if (originalFile) {
                            requestRename(originalFile);
                          }
                        }}
                        canManageFile={(file) => {
                          if (!file) return false;
                          // 管理员可以管理所有文件
                          if (checkIsManager(user?.role)) return true;
                          // 上传者可以管理待审核或被驳回的文件
                          const originalFile = categoryFiles.find((f: any) => 
                            f.storage_key === file.storage_key || f.filename === file.filename
                          ) as any;
                          if (!originalFile) return false;
                          const uploaderId = Number(originalFile?.uploader_id || 0);
                          const approvalStatus = normalizeApprovalStatus(originalFile?.approval_status);
                          const isUploader = uploaderId > 0 && uploaderId === (user?.id || 0);
                          return isUploader && (approvalStatus === 'pending' || approvalStatus === 'rejected');
                        }}
                        previewingFile={previewingFile}
                      />
                    )}
                    
                    {/* 云端文件 - 列表视图 */}
                    {fileViewMode === 'list' && categoryFiles.map((file: any) => (
                      <div key={file.id} className="p-4 hover:bg-gray-50 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          {canManageFile(file) && Number(file.id) > 0 && (
                            <input
                              type="checkbox"
                              checked={selectedFileIds.has(Number(file.id))}
                              onChange={() => toggleSelectFile(Number(file.id))}
                              className="w-4 h-4"
                              title="选择"
                            />
                          )}
                          <div 
                            className={`w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden cursor-pointer ${previewingFile === file.filename ? 'ring-2 ring-blue-500' : ''}`}
                            onClick={async () => {
                              if (!file.download_url) {
                                toast({ title: '无法预览', description: '缺少下载链接', variant: 'destructive' });
                                return;
                              }
                              try {
                                setPreviewingFile(file.filename);
                                toast({ title: '正在打开...', description: file.filename });
                                const localBasePath = `${getLocalBasePath()}/${categoryName}`;
                                await previewFile(file.download_url, file.filename, localBasePath, file.relative_path);
                              } catch (err: any) {
                                toast({ title: '预览失败', description: err.message, variant: 'destructive' });
                              } finally {
                                setPreviewingFile(null);
                              }
                            }}
                          >
                            {(() => {
                              const ext = (file.filename || '').split('.').pop()?.toLowerCase() || '';
                              const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext);
                              const isVideo = ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext);
                              
                              if (isImage && file.thumbnail_url) {
                                return (
                                  <img 
                                    src={file.thumbnail_url} 
                                    alt={file.filename}
                                    className="w-full h-full object-cover"
                                    onError={(e) => {
                                      (e.target as HTMLImageElement).style.display = 'none';
                                      const parent = (e.target as HTMLImageElement).parentElement;
                                      if (parent) {
                                        const icon = parent.querySelector('.fallback-icon');
                                        if (icon) icon.classList.remove('hidden');
                                      }
                                    }}
                                  />
                                );
                              }
                              if (isVideo) {
                                return (
                                  <div className="w-full h-full bg-gray-200 flex items-center justify-center relative">
                                    <svg className="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 24 24">
                                      <path d="M8 5v14l11-7z"/>
                                    </svg>
                                  </div>
                                );
                              }
                              return null;
                            })()}
                            <FileText className={`fallback-icon w-5 h-5 text-gray-400 ${(file.thumbnail_url && ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes((file.filename || '').split('.').pop()?.toLowerCase() || '')) || ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes((file.filename || '').split('.').pop()?.toLowerCase() || '') ? 'hidden' : ''}`} />
                          </div>
                          <div>
                            <div className="flex items-center gap-2">
                              <p className="font-medium text-gray-800">{file.filename ? (file.filename.includes('/') || file.filename.includes('\\') ? file.filename.split(/[/\\]/).pop() : file.filename) : '未知文件'}</p>
                              <span className="px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded text-[10px]">云端</span>
                              {(localFiles[categoryName] || []).some(lf => lf.name === file.filename) && (
                                <span className="px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded text-[10px]">已同步</span>
                              )}
                            </div>
                            <p className="text-xs text-gray-400">
                              {formatFileSize(file.file_size)} • {file.uploader_name} • {file.create_time}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {normalizeApprovalStatus(file.approval_status) === 'pending' && (
                            <>
                              <span className="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded text-xs">待审核</span>
                              {isManager && (
                                <>
                                  <button
                                    type="button"
                                    onClick={async () => {
                                      try {
                                        const res = await fetch(`${serverUrl}/api/desktop_approval.php?action=approve`, {
                                          method: 'POST',
                                          headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
                                          body: JSON.stringify({ file_id: file.id }),
                                        });
                                        const data = await res.json();
                                        if (data.success) {
                                          toast({ title: '审批通过', description: file.filename });
                                          loadProject('files');
                                        } else {
                                          toast({ title: '审批失败', description: data.error || '未知错误', variant: 'destructive' });
                                        }
                                      } catch (err: any) {
                                        toast({ title: '审批失败', description: err.message, variant: 'destructive' });
                                      }
                                    }}
                                    className="px-2 py-0.5 bg-green-500 hover:bg-green-600 text-white rounded text-xs"
                                    title="通过"
                                  >
                                    通过
                                  </button>
                                  <button
                                    type="button"
                                    onClick={async () => {
                                      const reason = prompt('请输入驳回原因（可选）：');
                                      try {
                                        const res = await fetch(`${serverUrl}/api/desktop_approval.php?action=reject`, {
                                          method: 'POST',
                                          headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
                                          body: JSON.stringify({ file_id: file.id, reason: reason || '' }),
                                        });
                                        const data = await res.json();
                                        if (data.success) {
                                          toast({ title: '已驳回', description: file.filename });
                                          loadProject('files');
                                        } else {
                                          toast({ title: '驳回失败', description: data.error || '未知错误', variant: 'destructive' });
                                        }
                                      } catch (err: any) {
                                        toast({ title: '驳回失败', description: err.message, variant: 'destructive' });
                                      }
                                    }}
                                    className="px-2 py-0.5 bg-red-500 hover:bg-red-600 text-white rounded text-xs"
                                    title="驳回"
                                  >
                                    驳回
                                  </button>
                                </>
                              )}
                            </>
                          )}
                          {normalizeApprovalStatus(file.approval_status) === 'approved' && (
                            <span className="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">已通过</span>
                          )}
                          {normalizeApprovalStatus(file.approval_status) === 'rejected' && (
                            <>
                              <span className="px-2 py-0.5 bg-red-100 text-red-700 rounded text-xs">已驳回</span>
                              <button
                                type="button"
                                onClick={() => handleResubmitFile(file, categoryName)}
                                className="px-2 py-0.5 bg-blue-500 hover:bg-blue-600 text-white rounded text-xs"
                                title="重新提交"
                              >
                                重新提交
                              </button>
                            </>
                          )}
                          <button 
                            type="button"
                            disabled={previewingFile === file.filename}
                            onClick={() => handleFilePreview(file, categoryName)}
                            className="p-2 hover:bg-gray-100 rounded-lg" 
                            title="预览"
                          >
                            <Eye className={`w-4 h-4 ${previewingFile === file.filename ? 'text-gray-300 animate-pulse' : 'text-blue-500'}`} />
                          </button>
                          <button 
                            type="button"
                            onClick={async () => {
                              try {
                                const storageKey = file.storage_key || file.file_path;
                                if (!storageKey) {
                                  toast({ title: '下载失败', description: '缺少文件存储路径', variant: 'destructive' });
                                  return;
                                }
                                
                                const savePath = await open({
                                  defaultPath: file.filename,
                                  filters: [{ name: '所有文件', extensions: ['*'] }],
                                  title: '选择保存位置',
                                });
                                
                                if (!savePath || typeof savePath !== 'string') return;
                                
                                const downloadRes = await fetch(`${serverUrl}/api/desktop_download.php`, {
                                  method: 'POST',
                                  headers: {
                                    'Authorization': `Bearer ${token}`,
                                    'Content-Type': 'application/json',
                                  },
                                  body: JSON.stringify({ storage_key: storageKey }),
                                });
                                const downloadData = await downloadRes.json();
                                if (!downloadData.success || !downloadData.data?.presigned_url) {
                                  throw new Error(downloadData.error?.message || '获取下载链接失败');
                                }
                                
                                const taskId = `download-${Date.now()}`;
                                const result = await downloadFileChunked(taskId, downloadData.data.presigned_url, savePath);
                                
                                if (result.success) {
                                  toast({ title: '下载成功', description: file.filename });
                                } else {
                                  throw new Error(result.error || '下载失败');
                                }
                              } catch (err: any) {
                                toast({ title: '下载失败', description: err.message, variant: 'destructive' });
                              }
                            }}
                            className="p-2 hover:bg-gray-100 rounded-lg" 
                            title="下载"
                          >
                            <Download className="w-4 h-4 text-gray-500" />
                          </button>
                          {canManageFile(file) && (
                            <button
                              type="button"
                              onClick={() => requestRename(file)}
                              className="p-2 hover:bg-gray-100 rounded-lg"
                              title="重命名"
                            >
                              <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                              </svg>
                            </button>
                          )}
                          {/* 删除按钮：使用统一的权限判断 */}
                          {canManageFile(file) && (
                            <button 
                              onClick={() => handleFileDelete(file)}
                              className="p-2 hover:bg-red-100 rounded-lg" 
                              title="删除"
                            >
                              <svg className="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          )}
                        </div>
                      </div>
                    ))}
                    
                    {/* 空状态 */}
                    {categoryFiles.length === 0 && (localFiles[categoryName] || []).length === 0 && (
                      <div className="p-6 text-center text-gray-400 text-sm">
                        暂无{categoryName}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
            
            {/* 上传弹窗（支持拖拽、多文件、分类选择、进度显示） */}
            {showUploadModal && (
              <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                <div className="bg-white rounded-xl p-6 w-[480px] max-h-[80vh] overflow-y-auto">
                  <div className="flex items-center justify-between mb-4">
                    <h3 className="text-lg font-semibold">上传文件</h3>
                    {!uploading && (
                      <button type="button" onClick={closeUploadModal} className="text-gray-400 hover:text-gray-600">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    )}
                  </div>
                  
                  {/* 拖拽区域 */}
                  {!uploading && (
                    <div
                      onDragOver={handleModalDragOver}
                      onDragLeave={handleModalDragLeave}
                      onDrop={handleModalDrop}
                      className={`border-2 border-dashed rounded-xl p-8 text-center transition-colors mb-4 ${
                        dragOver ? 'border-blue-500 bg-blue-50' : 'border-gray-300 hover:border-gray-400'
                      }`}
                    >
                      <Upload className="w-10 h-10 mx-auto mb-3 text-gray-400" />
                      <p className="text-gray-600 mb-2">拖拽文件到此处</p>
                      <p className="text-gray-400 text-sm mb-3">或</p>
                      <label className="inline-block px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 cursor-pointer">
                        选择文件
                        <input type="file" multiple className="hidden" onChange={handleFileSelect} />
                      </label>
                    </div>
                  )}
                  
                  {/* 待上传文件列表 */}
                  {pendingUploads.length > 0 && !uploading && (
                    <div className="mb-4">
                      <p className="text-sm text-gray-500 mb-2">待上传文件 ({pendingUploads.length})</p>
                      <div className="space-y-2 max-h-32 overflow-y-auto">
                        {pendingUploads.map((item, index) => (
                          <div key={index} className="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                            <div className="flex-1 min-w-0">
                              <p className="text-sm font-medium text-gray-800 truncate">{item.kind === 'web' ? item.file.name : item.name}</p>
                              <p className="text-xs text-gray-400">
                                {formatFileSize(item.kind === 'web' ? item.file.size : item.size || 0)}
                              </p>
                            </div>
                            <button onClick={() => removePendingFile(index)} className="ml-2 text-gray-400 hover:text-red-500">
                              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                  
                  {/* 上传进度 */}
                  {uploading && uploadProgress && (
                    <div className="mb-4 p-4 bg-blue-50 rounded-xl">
                      <div className="flex items-center justify-between mb-2">
                        <p className="text-sm font-medium text-blue-700">正在上传</p>
                        <p className="text-sm text-blue-600">{uploadProgress.current}/{uploadProgress.total} 分片</p>
                      </div>
                      <p className="text-xs text-blue-500 mb-2 truncate">{uploadProgress.filename}</p>
                      <div className="w-full bg-blue-200 rounded-full h-2">
                        <div 
                          className="bg-blue-500 h-2 rounded-full transition-all duration-300"
                          style={{ width: `${Math.round((uploadProgress.current / uploadProgress.total) * 100)}%` }}
                        />
                      </div>
                      <p className="text-xs text-blue-500 mt-1 text-right">
                        {Math.round((uploadProgress.current / uploadProgress.total) * 100)}%
                      </p>
                    </div>
                  )}
                  
                  {/* 分类选择 */}
                  {!uploading && (
                    <div className="mb-4">
                      <p className="text-sm text-gray-500 mb-2">选择上传目录</p>
                      <div className="grid grid-cols-3 gap-2">
                        {FILE_CATEGORIES.map((cat) => {
                          const colors = FILE_CATEGORY_COLORS[cat];
                          const isSelected = uploadCategory === cat;
                          return (
                            <button
                              key={cat}
                              type="button"
                              onClick={() => setUploadCategory(cat)}
                              className={`p-3 rounded-lg border-2 transition-all ${
                                isSelected 
                                  ? `${colors.bg} border-current` 
                                  : 'border-gray-200 hover:border-gray-300'
                              }`}
                            >
                              <div className={`text-sm font-medium ${isSelected ? colors.header.split(' ')[1] : 'text-gray-700'}`}>
                                {cat}
                              </div>
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  )}
                  
                  {/* 操作按钮 */}
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={closeUploadModal}
                      disabled={uploading}
                      className={`flex-1 px-4 py-2 rounded-lg ${
                        uploading ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-100'
                      }`}
                    >
                      取消
                    </button>
                    <button
                      type="button"
                      onClick={startUploadFromModal}
                      disabled={uploading || pendingUploads.length === 0}
                      className={`flex-1 px-4 py-2 rounded-lg ${
                        uploading || pendingUploads.length === 0
                          ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                          : 'bg-blue-500 text-white hover:bg-blue-600'
                      }`}
                    >
                      {uploading ? '上传中...' : `上传 (${pendingUploads.length})`}
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        ) : activeTab === 'messages' ? (
          /* 沟通记录 */
          <div className="bg-white rounded-xl border">
            {messages.length === 0 ? (
              <div className="p-8 text-center text-gray-400">
                <MessageSquare className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>暂无沟通记录</p>
              </div>
            ) : (
              <div className="divide-y">
                {(messages || []).map((msg) => (
                  <div key={msg.id} className="p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <span className="font-medium text-gray-800">{msg.sender_name}</span>
                      <span className="text-xs text-gray-400">{msg.create_time}</span>
                    </div>
                    <p className="text-sm text-gray-600">{msg.content}</p>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : activeTab === 'timeline' ? (
          /* 项目记录/时间线 */
          <div className="bg-white rounded-xl border">
            {timeline.length === 0 ? (
              <div className="p-8 text-center text-gray-400">
                <History className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>暂无项目记录</p>
              </div>
            ) : (
              <div className="p-4">
                <div className="relative border-l-2 border-indigo-200 ml-4">
                  {(timeline || []).map((item, index) => (
                    <div key={index} className="mb-6 ml-6 relative">
                      <div className="absolute -left-[29px] w-4 h-4 bg-indigo-500 rounded-full border-2 border-white" />
                      <div className="bg-gray-50 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-medium text-gray-800">{item.title}</span>
                          <span className="text-xs text-gray-400">{item.time}</span>
                        </div>
                        <p className="text-sm text-gray-600">{item.content}</p>
                        {item.operator && (
                          <p className="text-xs text-gray-400 mt-2">操作人: {item.operator}</p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        ) : activeTab === 'finance' ? (
          /* 财务/提成设置 */
          <div className="bg-white rounded-xl border">
            <div className="p-4 border-b">
              <h3 className="font-semibold text-gray-800">设计师提成</h3>
              <p className="text-sm text-gray-500 mt-1">设置项目设计师的提成金额</p>
            </div>
            {techUsers.length === 0 ? (
              <div className="p-8 text-center text-gray-400">
                <DollarSign className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>暂无设计师分配</p>
              </div>
            ) : (
              <div className="divide-y">
                {techUsers.map((tech) => {
                  // 只有管理员或本人才能看到提成
                  const canSeeCommission = isManager || tech.id === user?.id;
                  return (
                    <div key={tech.id} className="p-4 flex items-center justify-between hover:bg-gray-50">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center font-medium">
                          {tech.name.charAt(0)}
                        </div>
                        <div>
                          <p className="font-medium text-gray-800">{tech.name}</p>
                          {canSeeCommission && tech.commission !== null ? (
                            <p className="text-sm text-green-600">提成: ¥{tech.commission}</p>
                          ) : canSeeCommission ? (
                            <p className="text-sm text-gray-400">未设置提成</p>
                          ) : null}
                          {canSeeCommission && tech.commission_note && (
                            <p className="text-xs text-gray-400 mt-1">备注: {tech.commission_note}</p>
                          )}
                        </div>
                      </div>
                      {isManager && (
                        <button
                          onClick={() => {
                            setEditingTech(tech);
                            setCommissionAmount(tech.commission?.toString() || '');
                            setCommissionNote(tech.commission_note || '');
                            setShowCommissionEditor(true);
                          }}
                          className="px-4 py-2 text-sm bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors"
                        >
                          设置提成
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        ) : null}
        </div>
      </div>
      
      {/* 状态变更确认弹窗 */}
      <ConfirmDialog
        open={showStatusConfirm}
        onClose={() => {
          setShowStatusConfirm(false);
          setPendingStatus(null);
        }}
        onConfirm={() => {
          if (pendingStatus) {
            changeStatus(pendingStatus);
          }
          setShowStatusConfirm(false);
          setPendingStatus(null);
        }}
        title="确认变更状态"
        message={`确定要将项目状态变更为"${pendingStatus}"吗？`}
        type="confirm"
        confirmText="确定"
        cancelText="取消"
      />
      
      {/* 阶段时间编辑弹窗 */}
      {serverUrl && token && id && (
        <StageTimeEditor
          open={showStageTimeEditor}
          onClose={() => setShowStageTimeEditor(false)}
          projectId={parseInt(id)}
          serverUrl={serverUrl}
          token={token}
          onSaved={() => loadProject('overview')}
        />
      )}
      
      {/* 表单详情弹窗 */}
      {selectedFormId && (
        <FormDetailModal
          open={showFormDetail}
          onClose={() => {
            setShowFormDetail(false);
            setSelectedFormId(null);
          }}
          instanceId={selectedFormId}
          onStatusChange={() => loadProject('forms')}
        />
      )}
      
      {/* 提成设置弹窗 */}
      {showCommissionEditor && editingTech && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-[400px] shadow-2xl">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">
              设置提成 - {editingTech.name}
            </h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">提成金额 (元)</label>
                <input
                  type="number"
                  value={commissionAmount}
                  onChange={(e) => setCommissionAmount(e.target.value)}
                  placeholder="请输入提成金额"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">备注</label>
                <textarea
                  value={commissionNote}
                  onChange={(e) => setCommissionNote(e.target.value)}
                  placeholder="可选，填写提成说明"
                  rows={3}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => {
                  setShowCommissionEditor(false);
                  setEditingTech(null);
                }}
                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
              >
                取消
              </button>
              <button
                onClick={async () => {
                  if (!serverUrl || !token || !editingTech) return;
                  try {
                    const res = await fetch(`${serverUrl}/api/desktop_tech_commission.php?action=set_commission`, {
                      method: 'POST',
                      headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json',
                      },
                      body: JSON.stringify({
                        assignment_id: editingTech.assignment_id,
                        commission_amount: parseFloat(commissionAmount) || 0,
                        commission_note: commissionNote,
                      }),
                    });
                    const data = await res.json();
                    if (data.success) {
                      toast({ title: '提成设置成功', variant: 'success' });
                      setShowCommissionEditor(false);
                      setEditingTech(null);
                      loadProject('overview');
                    } else {
                      toast({ title: data.error || '设置失败', variant: 'destructive' });
                    }
                  } catch (e) {
                    console.error('设置提成失败:', e);
                    toast({ title: '设置失败', variant: 'destructive' });
                  }
                }}
                className="px-4 py-2 text-sm text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg"
              >
                保存
              </button>
            </div>
          </div>
        </div>
      )}
      
      {/* 门户密码管理弹窗 */}
      {showPasswordEditor && customer && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-96 max-w-[90vw]">
            <h3 className="text-lg font-semibold mb-4">门户密码管理</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm text-gray-600 mb-1">当前密码</label>
                <div className="flex items-center gap-2">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={portalPassword}
                    onChange={(e) => setPortalPassword(e.target.value)}
                    placeholder="留空表示无密码"
                    className="flex-1 px-3 py-2 border rounded-lg text-sm"
                  />
                  <button
                    onClick={() => setShowPassword(!showPassword)}
                    className="px-3 py-2 text-gray-500 hover:text-gray-700"
                  >
                    {showPassword ? '隐藏' : '显示'}
                  </button>
                </div>
              </div>
              {portalPassword && (
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(portalPassword);
                    toast({ title: '成功', description: '密码已复制到剪贴板' });
                  }}
                  className="w-full px-3 py-2 text-sm text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg"
                >
                  复制当前密码
                </button>
              )}
            </div>
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowPasswordEditor(false)}
                className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
              >
                取消
              </button>
              <button
                onClick={async () => {
                  try {
                    const res = await fetch(`${serverUrl}/api/portal_password.php`, {
                      method: 'POST',
                      headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json',
                      },
                      body: JSON.stringify({
                        customer_id: customer.id,
                        password: portalPassword,
                      }),
                    });
                    const data = await res.json();
                    if (data.success) {
                      toast({ title: '成功', description: data.message });
                      setShowPasswordEditor(false);
                      loadProject('overview');
                    } else {
                      toast({ title: '错误', description: data.error || '操作失败', variant: 'destructive' });
                    }
                  } catch (e) {
                    console.error('更新密码失败:', e);
                    toast({ title: '错误', description: '更新失败', variant: 'destructive' });
                  }
                }}
                className="px-4 py-2 text-sm text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg"
              >
                保存
              </button>
            </div>
          </div>
        </div>
      )}
      
      {/* 别名编辑弹窗 */}
      {showAliasEditor && customer && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-96 max-w-[90vw]">
            <h3 className="text-lg font-semibold mb-4">编辑客户别名</h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm text-gray-600 mb-1">客户别名</label>
                <input
                  type="text"
                  value={aliasValue}
                  onChange={(e) => setAliasValue(e.target.value)}
                  placeholder="如：王先生"
                  className="w-full px-3 py-2 border rounded-lg text-sm"
                />
                <p className="text-xs text-gray-400 mt-1">客户在门户中看到的显示名称</p>
              </div>
            </div>
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowAliasEditor(false)}
                className="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg"
              >
                取消
              </button>
              <button
                onClick={async () => {
                  try {
                    const res = await fetch(`${serverUrl}/api/customer_update.php`, {
                      method: 'POST',
                      headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json',
                      },
                      body: JSON.stringify({
                        customer_id: customer.id,
                        alias: aliasValue,
                      }),
                    });
                    const data = await res.json();
                    if (data.success) {
                      toast({ title: '成功', description: '别名已更新' });
                      setShowAliasEditor(false);
                      loadProject('overview');
                    } else {
                      toast({ title: '错误', description: data.error || '操作失败', variant: 'destructive' });
                    }
                  } catch (e) {
                    console.error('更新别名失败:', e);
                    toast({ title: '错误', description: '更新失败', variant: 'destructive' });
                  }
                }}
                className="px-4 py-2 text-sm text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg"
              >
                保存
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 多区域链接弹窗 */}
      {showLinkModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[500px] max-h-[80vh] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between">
              <h3 className="text-lg font-semibold flex items-center gap-2">
                <Link2 className="w-5 h-5 text-blue-600" />
                复制门户链接
              </h3>
              <button
                onClick={() => setShowLinkModal(false)}
                className="p-1.5 hover:bg-gray-100 rounded-lg text-gray-400 hover:text-gray-600"
              >
                ✕
              </button>
            </div>
            
            <div className="p-6 overflow-auto max-h-[60vh]">
              {loadingLinks ? (
                <div className="text-center py-8 text-gray-400">
                  加载中...
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-sm text-blue-700">
                      选择合适的区域链接发送给客户，⭐ 表示默认节点
                    </p>
                  </div>
                  
                  <div className="space-y-2">
                    {regionLinks.map((link, idx) => (
                      <div key={idx} className="flex items-center gap-2 p-3 bg-gray-50 rounded-lg border">
                        <span className="text-sm font-medium min-w-[80px]">
                          {link.is_default ? '⭐ ' : ''}{link.region_name}
                        </span>
                        <input
                          type="text"
                          value={link.url}
                          readOnly
                          className="flex-1 text-xs bg-white border rounded px-2 py-1.5 text-gray-600"
                        />
                        <button
                          onClick={() => copyRegionLink(link.url, link.region_name)}
                          className="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 flex items-center gap-1"
                        >
                          <Copy className="w-3.5 h-3.5" />
                          复制
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
            
            <div className="px-6 py-4 border-t bg-gray-50">
              <button
                onClick={() => setShowLinkModal(false)}
                className="w-full py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
              >
                关闭
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 分享链接生成弹窗 */}
      {showShareLinkModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl w-[500px] max-h-[80vh] overflow-hidden shadow-2xl">
            <div className="px-6 py-4 border-b flex items-center justify-between bg-gradient-to-r from-green-500 to-emerald-600">
              <h3 className="text-lg font-semibold flex items-center gap-2 text-white">
                <Upload className="w-5 h-5" />
                生成文件传输链接
              </h3>
              <button
                onClick={() => setShowShareLinkModal(false)}
                className="p-1.5 hover:bg-white/20 rounded-lg text-white/80 hover:text-white"
              >
                ✕
              </button>
            </div>
            
            <div className="p-6 space-y-4">
              {!generatedShareLink ? (
                <>
                  <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-sm text-blue-700">
                      生成的链接可以发送给客户，客户无需登录即可上传文件到此项目的"客户文件"分类中。
                    </p>
                  </div>
                  
                  {/* 分享节点选择 */}
                  {shareRegions.length > 0 && (
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">分享节点</label>
                      <select
                        value={selectedShareRegion || ''}
                        onChange={(e) => setSelectedShareRegion(e.target.value ? parseInt(e.target.value) : null)}
                        className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                      >
                        <option value="">默认节点</option>
                        {shareRegions.map((r) => (
                          <option key={r.id} value={r.id}>
                            {r.is_default ? '⭐ ' : ''}{r.region_name}
                          </option>
                        ))}
                      </select>
                    </div>
                  )}
                  
                  {/* 有效期 */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">有效期（天）</label>
                    <input
                      type="number"
                      value={shareExpireDays}
                      onChange={(e) => setShareExpireDays(e.target.value)}
                      min="1"
                      max="365"
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                      placeholder="默认7天"
                    />
                  </div>
                  
                  {/* 访问密码 */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">访问密码（可选）</label>
                    <input
                      type="text"
                      value={sharePassword}
                      onChange={(e) => setSharePassword(e.target.value)}
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                      placeholder="留空表示不设置密码"
                    />
                  </div>
                  
                  {/* 访问次数限制 */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">访问次数限制（可选）</label>
                    <input
                      type="number"
                      value={shareMaxVisits}
                      onChange={(e) => setShareMaxVisits(e.target.value)}
                      min="1"
                      className="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                      placeholder="留空表示不限制"
                    />
                  </div>
                </>
              ) : (
                <>
                  <div className="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <div className="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                      <Check className="w-6 h-6 text-green-600" />
                    </div>
                    <h4 className="font-semibold text-green-800 mb-2">链接已生成</h4>
                    <p className="text-sm text-green-700 mb-1">
                      有效期至: {new Date(generatedShareLink.expires_at).toLocaleString('zh-CN')}
                    </p>
                    {sharePassword && (
                      <p className="text-sm text-green-700">访问密码: {sharePassword}</p>
                    )}
                  </div>
                  
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={generatedShareLink.url}
                      readOnly
                      className="flex-1 px-3 py-2 border rounded-lg text-sm bg-gray-50"
                    />
                    <button
                      onClick={copyShareLink}
                      className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-1"
                    >
                      <Copy className="w-4 h-4" />
                      复制
                    </button>
                  </div>
                </>
              )}
            </div>
            
            <div className="px-6 py-4 border-t bg-gray-50 flex gap-3">
              {!generatedShareLink ? (
                <>
                  <button
                    onClick={() => setShowShareLinkModal(false)}
                    className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                  >
                    取消
                  </button>
                  <button
                    onClick={generateShareLink}
                    disabled={generatingShareLink}
                    className="flex-1 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    {generatingShareLink ? (
                      <>
                        <RefreshCw className="w-4 h-4 animate-spin" />
                        生成中...
                      </>
                    ) : (
                      <>
                        <Upload className="w-4 h-4" />
                        生成链接
                      </>
                    )}
                  </button>
                </>
              ) : (
                <button
                  onClick={() => {
                    setGeneratedShareLink(null);
                    setShowShareLinkModal(false);
                  }}
                  className="w-full py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                >
                  关闭
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* 删除项目确认弹窗 */}
      {showDeleteConfirm && project && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-[400px] max-w-[90vw] shadow-xl">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <AlertTriangle className="w-5 h-5 text-red-600" />
              </div>
              <h3 className="text-lg font-semibold text-gray-800">确认删除项目</h3>
            </div>
            <div className="mb-6">
              <p className="text-gray-600 mb-2">
                确定要删除项目 <span className="font-semibold text-gray-800">{project.project_name}</span> 吗？
              </p>
              <p className="text-sm text-gray-500">
                项目编号：{project.project_code}
              </p>
              <div className="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p className="text-sm text-yellow-700">
                  ⚠️ 删除后项目及相关交付物将移至回收站，15天后自动永久删除。
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => setShowDeleteConfirm(false)}
                disabled={deleting}
                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
              >
                取消
              </button>
              <button
                onClick={deleteProject}
                disabled={deleting}
                className="px-4 py-2 text-sm bg-red-500 text-white rounded-lg hover:bg-red-600 disabled:opacity-50 flex items-center gap-2"
              >
                {deleting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    删除中...
                  </>
                ) : (
                  <>
                    <Trash2 className="w-4 h-4" />
                    确认删除
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
