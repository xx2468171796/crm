<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 权限 API
 * 
 * GET ?action=check&permission=xxx - 检查单个权限
 * GET ?action=list - 获取用户所有权限
 * GET ?action=roles - 获取角色常量
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handleList($user);
            break;
        case 'check':
            handleCheck($user);
            break;
        case 'roles':
            handleRoles();
            break;
        case 'codes':
            handleCodes();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误']);
}

/**
 * 获取用户所有权限和角色信息
 */
function handleList($user) {
    $permissions = Permission::getUserPermissions($user['id']);
    $roles = Permission::getUserRoles($user['id']);
    $primaryRole = Permission::getPrimaryRoleCode($user['id']);
    
    // 角色能力判断
    $isAdmin = RoleCode::isAdminRole($user['role']);
    
    // 调试日志
    error_log("[desktop_permissions] user_id={$user['id']}, role={$user['role']}, isAdmin=" . ($isAdmin ? 'true' : 'false'));
    $isTechManager = $user['role'] === 'tech_manager';
    $isDesignManager = $user['role'] === 'design_manager'; // 设计师主管
    $isTech = $user['role'] === 'tech';
    $isDeptManager = RoleCode::isDeptManagerRole($user['role']);
    
    // 项目管理角色（可分配项目、设置提成、审批文件）
    $isProjectManager = $isAdmin || $isTechManager || $isDesignManager;
    
    // 项目相关权限（design_manager拥有项目管理权限）
    $canViewProject = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::PROJECT_VIEW);
    $canEditProject = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::PROJECT_EDIT);
    $canCreateProject = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::PROJECT_CREATE);
    $canDeleteProject = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::PROJECT_DELETE); // 删除仍需管理员
    $canEditProjectStatus = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::PROJECT_STATUS_EDIT);
    $canAssignProject = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::PROJECT_ASSIGN);
    
    // 文件相关权限
    $canUploadFile = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::FILE_UPLOAD);
    $canDeleteFile = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::FILE_DELETE);
    
    // 门户相关权限（管理员和技术人员都有基本门户权限）
    $canViewPortal = $isAdmin || $isTech || Permission::hasPermission($user['id'], PermissionCode::PORTAL_VIEW);
    $canCopyPortalLink = $isAdmin || $isTech || Permission::hasPermission($user['id'], PermissionCode::PORTAL_COPY_LINK);
    $canViewPortalPassword = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::PORTAL_VIEW_PASSWORD);
    $canEditPortalPassword = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::PORTAL_EDIT_PASSWORD);
    
    // 审批权限（管理员、技术主管、设计师主管可审批）
    $canApproveFiles = $isProjectManager;
    
    // 客户相关权限（design_manager可编辑客户）
    $canEditCustomer = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::CUSTOMER_EDIT);
    $canDeleteCustomer = $isAdmin || Permission::hasPermission($user['id'], PermissionCode::CUSTOMER_DELETE); // 删除仍需管理员
    $canTransferCustomer = $isProjectManager || Permission::hasPermission($user['id'], PermissionCode::CUSTOMER_TRANSFER);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'primary_role' => $primaryRole,
            'permissions' => $permissions,
            'roles' => $roles,
            'abilities' => [
                'is_admin' => $isAdmin,
                'is_tech_manager' => $isTechManager,
                'is_design_manager' => $isDesignManager,
                'is_project_manager' => $isProjectManager,
                'is_tech' => $isTech,
                'is_dept_manager' => $isDeptManager,
                'can_manage_all_projects' => $isProjectManager,
                'can_approve_files' => $canApproveFiles,
                'can_view_finance' => $isAdmin || $isTechManager, // design_manager无财务权限
            ],
            'project' => [
                'view' => $canViewProject,
                'edit' => $canEditProject,
                'create' => $canCreateProject,
                'delete' => $canDeleteProject,
                'status_edit' => $canEditProjectStatus,
                'assign' => $canAssignProject,
            ],
            'file' => [
                'upload' => $canUploadFile,
                'delete' => $canDeleteFile,
            ],
            'portal' => [
                'view' => $canViewPortal,
                'copy_link' => $canCopyPortalLink,
                'view_password' => $canViewPortalPassword,
                'edit_password' => $canEditPortalPassword,
            ],
            'customer' => [
                'edit' => $canEditCustomer,
                'delete' => $canDeleteCustomer,
                'transfer' => $canTransferCustomer,
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 检查单个权限
 */
function handleCheck($user) {
    $permission = $_GET['permission'] ?? '';
    
    if (empty($permission)) {
        echo json_encode(['success' => true, 'data' => ['has_permission' => false]]);
        return;
    }
    
    $hasPermission = RoleCode::isAdminRole($user['role']) || Permission::hasPermission($user['id'], $permission);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'permission' => $permission,
            'has_permission' => $hasPermission,
        ]
    ]);
}

/**
 * 获取角色常量
 */
function handleRoles() {
    echo json_encode([
        'success' => true,
        'data' => [
            'SUPER_ADMIN' => RoleCode::SUPER_ADMIN,
            'ADMIN' => RoleCode::ADMIN,
            'DEPT_LEADER' => RoleCode::DEPT_LEADER,
            'DEPT_ADMIN' => RoleCode::DEPT_ADMIN,
            'SALES' => RoleCode::SALES,
            'SERVICE' => RoleCode::SERVICE,
            'TECH' => RoleCode::TECH,
            'FINANCE' => RoleCode::FINANCE,
            'VIEWER' => RoleCode::VIEWER,
            'TECH_MANAGER' => 'tech_manager',
            'DESIGN_MANAGER' => RoleCode::DESIGN_MANAGER,
        ]
    ]);
}

/**
 * 获取权限代码常量
 */
function handleCodes() {
    echo json_encode([
        'success' => true,
        'data' => [
            'project' => [
                'VIEW' => PermissionCode::PROJECT_VIEW,
                'CREATE' => PermissionCode::PROJECT_CREATE,
                'EDIT' => PermissionCode::PROJECT_EDIT,
                'DELETE' => PermissionCode::PROJECT_DELETE,
                'STATUS_EDIT' => PermissionCode::PROJECT_STATUS_EDIT,
                'ASSIGN' => PermissionCode::PROJECT_ASSIGN,
            ],
            'file' => [
                'UPLOAD' => PermissionCode::FILE_UPLOAD,
                'DELETE' => PermissionCode::FILE_DELETE,
            ],
            'portal' => [
                'VIEW' => PermissionCode::PORTAL_VIEW,
                'COPY_LINK' => PermissionCode::PORTAL_COPY_LINK,
                'VIEW_PASSWORD' => PermissionCode::PORTAL_VIEW_PASSWORD,
                'EDIT_PASSWORD' => PermissionCode::PORTAL_EDIT_PASSWORD,
            ],
        ]
    ]);
}
