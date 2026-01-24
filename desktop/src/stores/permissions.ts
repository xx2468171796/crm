import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { getStorageKey } from '@/lib/instanceId';
import { http } from '@/lib/http';

interface Abilities {
  is_admin: boolean;
  is_tech_manager: boolean;
  is_tech: boolean;
  is_dept_manager: boolean;
  can_manage_all_projects: boolean;
  can_approve_files: boolean;
}

interface ProjectPermissions {
  view: boolean;
  edit: boolean;
  create: boolean;
  delete: boolean;
  status_edit: boolean;
  assign: boolean;
}

interface FilePermissions {
  upload: boolean;
  delete: boolean;
}

interface PortalPermissions {
  view: boolean;
  copy_link: boolean;
  view_password: boolean;
  edit_password: boolean;
}

interface CustomerPermissions {
  edit: boolean;
  delete: boolean;
  transfer: boolean;
}

interface PermissionsData {
  user_id: number;
  role: string;
  primary_role: string;
  permissions: string[];
  abilities: Abilities;
  project: ProjectPermissions;
  file: FilePermissions;
  portal: PortalPermissions;
  customer: CustomerPermissions;
}

interface PermissionsState {
  data: PermissionsData | null;
  loading: boolean;
  error: string | null;
  
  // Actions
  fetchPermissions: (serverUrl: string, token: string) => Promise<void>;
  clearPermissions: () => void;
  
  // Permission checks
  can: (permission: string) => boolean;
  isAdmin: () => boolean;
  isTechManager: () => boolean;
  canManageProjects: () => boolean;
  canApproveFiles: () => boolean;
  canEditProjectStatus: () => boolean;
  canAssignProject: () => boolean;
  canEditCustomer: () => boolean;
  canViewPortal: () => boolean;
  canCopyPortalLink: () => boolean;
  canViewPortalPassword: () => boolean;
  canEditPortalPassword: () => boolean;
}


export const usePermissionsStore = create<PermissionsState>()(
  persist(
    (set, get) => ({
      data: null,
      loading: false,
      error: null,

      fetchPermissions: async (serverUrl: string, token: string) => {
        set({ loading: true, error: null });
        try {
          void serverUrl;
          void token;
          const result: any = await http.get('desktop_permissions.php?action=list');
          console.log('[Permissions] API 返回:', result);
          if (result.success) {
            console.log('[Permissions] 权限数据:', {
              is_admin: result.data?.abilities?.is_admin,
              status_edit: result.data?.project?.status_edit,
            });
            set({ data: result.data, loading: false });
          } else {
            set({ error: result.error || '获取权限失败', loading: false });
          }
        } catch (error) {
          console.error('[Permissions] 获取权限失败:', error);
          set({ error: '网络错误', loading: false });
        }
      },

      clearPermissions: () => {
        set({ data: null, error: null });
      },

      can: (permission: string) => {
        const { data } = get();
        if (!data) return false;
        if (data.abilities.is_admin) return true;
        return data.permissions.includes(permission) || data.permissions.includes('*');
      },

      isAdmin: () => {
        const { data } = get();
        return data?.abilities.is_admin || false;
      },

      isTechManager: () => {
        const { data } = get();
        return data?.abilities.is_tech_manager || false;
      },

      canManageProjects: () => {
        const { data } = get();
        return data?.abilities.can_manage_all_projects || false;
      },

      canApproveFiles: () => {
        const { data } = get();
        return data?.abilities.can_approve_files || false;
      },

      canEditProjectStatus: () => {
        const { data } = get();
        // 管理员直接允许
        if (data?.abilities.is_admin) return true;
        return data?.project.status_edit || false;
      },

      canAssignProject: () => {
        const { data } = get();
        return data?.project.assign || false;
      },

      canEditCustomer: () => {
        const { data } = get();
        return data?.customer?.edit || false;
      },

      canViewPortal: () => {
        const { data } = get();
        return data?.portal?.view || false;
      },

      canCopyPortalLink: () => {
        const { data } = get();
        return data?.portal?.copy_link || false;
      },

      canViewPortalPassword: () => {
        const { data } = get();
        return data?.portal?.view_password || false;
      },

      canEditPortalPassword: () => {
        const { data } = get();
        return data?.portal?.edit_password || false;
      },
    }),
    {
      name: getStorageKey('permissions-storage'),
      partialize: (state) => ({ data: state.data }),
    }
  )
);

// 角色代码常量
export const RoleCode = {
  SUPER_ADMIN: 'super_admin',
  ADMIN: 'admin',
  DEPT_LEADER: 'dept_leader',
  DEPT_ADMIN: 'dept_admin',
  SALES: 'sales',
  SERVICE: 'service',
  TECH: 'tech',
  TECH_MANAGER: 'tech_manager',
  DESIGN_MANAGER: 'design_manager',
  FINANCE: 'finance',
  VIEWER: 'viewer',
} as const;

// 权限代码常量
export const PermissionCode = {
  PROJECT_VIEW: 'project_view',
  PROJECT_CREATE: 'project_create',
  PROJECT_EDIT: 'project_edit',
  PROJECT_DELETE: 'project_delete',
  PROJECT_STATUS_EDIT: 'project_status_edit',
  PROJECT_ASSIGN: 'project_assign',
  FILE_UPLOAD: 'file_upload',
  FILE_DELETE: 'file_delete',
  PORTAL_VIEW: 'portal_view',
  PORTAL_COPY_LINK: 'portal_copy_link',
  PORTAL_VIEW_PASSWORD: 'portal_view_password',
  PORTAL_EDIT_PASSWORD: 'portal_edit_password',
} as const;

// 管理员角色列表
export const ADMIN_ROLES = [RoleCode.SUPER_ADMIN, RoleCode.ADMIN];
export const MANAGER_ROLES = [RoleCode.SUPER_ADMIN, RoleCode.ADMIN, RoleCode.TECH_MANAGER, RoleCode.DESIGN_MANAGER, 'manager'];

// 辅助函数
export const isAdminRole = (role: string) => ADMIN_ROLES.includes(role as any);
export const isManagerRole = (role: string) => MANAGER_ROLES.includes(role);
