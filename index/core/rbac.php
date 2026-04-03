<?php
/**
 * 企业权限管理核心模块 (RBAC)
 * Role-Based Access Control
 */

require_once __DIR__ . '/db.php';

/**
 * 角色代码常量
 */
class RoleCode {
    const SUPER_ADMIN = 'super_admin';
    const ADMIN = 'admin';
    const DEPT_LEADER = 'dept_leader';
    const DEPT_ADMIN = 'dept_admin';
    const SALES = 'sales';
    const SERVICE = 'service';
    const TECH = 'tech';
    const FINANCE = 'finance';
    const VIEWER = 'viewer';
    const DESIGN_MANAGER = 'design_manager'; // 设计师主管
    
    // 管理员级别角色
    public static function isAdminRole(string $role): bool {
        return in_array($role, [self::SUPER_ADMIN, self::ADMIN]);
    }
    
    // 部门管理角色
    public static function isDeptManagerRole(string $role): bool {
        return in_array($role, [self::DEPT_LEADER, self::DEPT_ADMIN]);
    }
    
    // 技术角色（可访问所有技术资源）
    public static function isTechRole(string $role): bool {
        return in_array($role, [self::SUPER_ADMIN, self::ADMIN, self::TECH, self::DEPT_LEADER, self::DESIGN_MANAGER]);
    }
    
    // 设计师主管角色（可管理项目、审批、设置提成，但无财务权限）
    public static function isDesignManagerRole(string $role): bool {
        return $role === self::DESIGN_MANAGER;
    }
    
    // 项目管理角色（可分配项目、设置提成、审批文件）
    public static function isProjectManagerRole(string $role): bool {
        return in_array($role, [self::SUPER_ADMIN, self::ADMIN, 'tech_manager', 'manager', self::DESIGN_MANAGER]);
    }
}

/**
 * 权限代码常量
 */
class PermissionCode {
    // 客户模块
    const CUSTOMER_VIEW = 'customer_view';
    const CUSTOMER_EDIT = 'customer_edit';
    const CUSTOMER_EDIT_BASIC = 'customer_edit_basic';
    const CUSTOMER_DELETE = 'customer_delete';
    const CUSTOMER_TRANSFER = 'customer_transfer';
    const CUSTOMER_EXPORT = 'customer_export';
    const FILE_UPLOAD = 'file_upload';
    const FILE_DELETE = 'file_delete';
    const DEAL_MANAGE = 'deal_manage';
    
    // 财务模块
    const FINANCE_VIEW = 'finance_view';
    const FINANCE_VIEW_OWN = 'finance_view_own';
    const FINANCE_EDIT = 'finance_edit';
    const FINANCE_STATUS_EDIT = 'finance_status_edit';
    const FINANCE_DASHBOARD = 'finance_dashboard';       // 财务工作台
    const FINANCE_PAYMENT_SUMMARY = 'finance_payment_summary'; // 收款统计
    const FINANCE_PREPAY = 'finance_prepay';             // 预收管理
    const FINANCE_MANAGE = 'finance_manage';             // 财务管理（汇率等）
    const CONTRACT_VIEW = 'contract_view';
    const CONTRACT_EDIT = 'contract_edit';
    const CONTRACT_CREATE = 'contract_create';           // 创建合同/订单
    
    // 项目模块
    const PROJECT_VIEW = 'project_view';           // 查看项目
    const PROJECT_CREATE = 'project_create';       // 创建项目
    const PROJECT_EDIT = 'project_edit';           // 编辑项目
    const PROJECT_DELETE = 'project_delete';       // 删除项目
    const PROJECT_STATUS_EDIT = 'project_status_edit'; // 修改项目状态
    const PROJECT_ASSIGN = 'project_assign';       // 分配项目技术人员
    
    // 异议模块
    const OBJECTION_VIEW = 'objection_view';
    const OBJECTION_EDIT = 'objection_edit';
    
    // 数据分析模块
    const ANALYTICS_VIEW = 'analytics_view';
    
    // 数据范围
    const ALL_DATA_VIEW = 'all_data_view';
    const DEPT_DATA_VIEW = 'dept_data_view';
    
    // 系统管理模块
    const USER_MANAGE = 'user_manage';
    const ROLE_MANAGE = 'role_manage';
    const DEPT_MANAGE = 'dept_manage';
    const DEPT_MEMBER_MANAGE = 'dept_member_manage';
    const FIELD_MANAGE = 'field_manage';
    
    // 客户门户模块
    const PORTAL_VIEW = 'portal_view';                 // 查看客户门户
    const PORTAL_COPY_LINK = 'portal_copy_link';       // 复制门户链接
    const PORTAL_VIEW_PASSWORD = 'portal_view_password'; // 查看门户密码
    const PORTAL_EDIT_PASSWORD = 'portal_edit_password'; // 修改门户密码
}

/**
 * 数据范围常量
 */
class DataScope {
    const ALL = 'all';           // 全部数据
    const DEPT_TREE = 'dept_tree'; // 本部门及下级
    const DEPT = 'dept';         // 仅本部门
    const SELF = 'self';         // 仅自己
}

/**
 * 权限检查类
 */
class Permission {
    private static $userPermissions = [];
    private static $userDataScopes = [];
    
    /**
     * 获取用户的所有权限代码
     */
    public static function getUserPermissions(int $userId): array {
        if (isset(self::$userPermissions[$userId])) {
            return self::$userPermissions[$userId];
        }
        
        $pdo = Db::pdo();
        
        // 获取用户的所有角色的权限
        $sql = "
            SELECT DISTINCT r.permissions
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $permissions = [];
        foreach ($rows as $row) {
            $rolePerms = json_decode($row['permissions'] ?? '[]', true) ?: [];
            // 检查是否有通配符权限
            if (in_array('*', $rolePerms)) {
                self::$userPermissions[$userId] = ['*'];
                return ['*'];
            }
            $permissions = array_merge($permissions, $rolePerms);
        }
        
        // 如果没有通过新表获取到权限，回退到旧的 role 字段
        if (empty($permissions)) {
            $sql = "SELECT u.role FROM users u WHERE u.id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $sql = "SELECT permissions FROM roles WHERE code = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$user['role']]);
                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($role) {
                    $permissions = json_decode($role['permissions'] ?? '[]', true) ?: [];
                }
            }
        }
        
        self::$userPermissions[$userId] = array_unique($permissions);
        return self::$userPermissions[$userId];
    }
    
    /**
     * 检查用户是否有指定权限
     */
    public static function hasPermission(int $userId, string $permissionCode): bool {
        $permissions = self::getUserPermissions($userId);
        
        // 通配符权限
        if (in_array('*', $permissions)) {
            return true;
        }
        
        return in_array($permissionCode, $permissions);
    }
    
    /**
     * 检查用户是否有任一权限
     */
    public static function hasAnyPermission(int $userId, array $permissionCodes): bool {
        foreach ($permissionCodes as $code) {
            if (self::hasPermission($userId, $code)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检查用户是否有所有权限
     */
    public static function hasAllPermissions(int $userId, array $permissionCodes): bool {
        foreach ($permissionCodes as $code) {
            if (!self::hasPermission($userId, $code)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 获取用户在指定模块的数据范围
     */
    public static function getDataScope(int $userId, string $module): string {
        $cacheKey = $userId . '_' . $module;
        if (isset(self::$userDataScopes[$cacheKey])) {
            return self::$userDataScopes[$cacheKey];
        }
        
        $pdo = Db::pdo();
        
        // 优先从 data_permissions 表获取
        $sql = "
            SELECT dp.scope
            FROM user_roles ur
            JOIN data_permissions dp ON ur.role_id = dp.role_id
            WHERE ur.user_id = ? AND dp.module = ?
            ORDER BY FIELD(dp.scope, 'all', 'dept_tree', 'dept', 'self')
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $module]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            self::$userDataScopes[$cacheKey] = $row['scope'];
            return $row['scope'];
        }
        
        // 回退到基于角色的默认范围
        $sql = "SELECT role FROM users WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $role = $user['role'];
            if (RoleCode::isAdminRole($role)) {
                $scope = DataScope::ALL;
            } elseif (RoleCode::isDeptManagerRole($role)) {
                $scope = DataScope::DEPT;
            } else {
                $scope = DataScope::SELF;
            }
            self::$userDataScopes[$cacheKey] = $scope;
            return $scope;
        }
        
        self::$userDataScopes[$cacheKey] = DataScope::SELF;
        return DataScope::SELF;
    }
    
    /**
     * 清除用户权限缓存
     */
    public static function clearCache(int $userId = null): void {
        if ($userId === null) {
            self::$userPermissions = [];
            self::$userDataScopes = [];
        } else {
            unset(self::$userPermissions[$userId]);
            foreach (self::$userDataScopes as $key => $value) {
                if (strpos($key, $userId . '_') === 0) {
                    unset(self::$userDataScopes[$key]);
                }
            }
        }
    }
    
    /**
     * 获取用户的所有角色
     */
    public static function getUserRoles(int $userId): array {
        $pdo = Db::pdo();
        
        // 从 user_roles 表获取
        $sql = "
            SELECT r.id, r.code, r.name, r.description, r.is_system
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.status = 1
            ORDER BY r.id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 如果没有记录，回退到 users.role
        if (empty($roles)) {
            $sql = "SELECT role FROM users WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['role']) {
                $sql = "SELECT id, code, name, description, is_system FROM roles WHERE code = ? AND status = 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$user['role']]);
                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($role) {
                    $roles = [$role];
                }
            }
        }
        
        return $roles;
    }
    
    /**
     * 获取用户的主要角色代码
     */
    public static function getPrimaryRoleCode(int $userId): string {
        $roles = self::getUserRoles($userId);
        if (!empty($roles)) {
            return $roles[0]['code'];
        }
        return RoleCode::VIEWER;
    }
    
    /**
     * 检查用户是否拥有指定角色
     */
    public static function hasRole(int $userId, string $roleCode): bool {
        $roles = self::getUserRoles($userId);
        foreach ($roles as $role) {
            if ($role['code'] === $roleCode) {
                return true;
            }
        }
        return false;
    }
}

/**
 * 数据权限查询构建器
 */
class DataPermissionBuilder {
    /**
     * 构建数据权限 WHERE 条件
     * 
     * @param array $user 用户信息 (必须包含 id, role, department_id)
     * @param string $module 模块名
     * @param string $ownerField 数据所有者字段名
     * @param string $deptField 部门字段名
     * @param string $tableAlias 表别名
     * @return array ['where' => string, 'params' => array]
     */
    public static function build(
        array $user,
        string $module,
        string $ownerField = 'owner_user_id',
        string $deptField = 'department_id',
        string $tableAlias = ''
    ): array {
        $prefix = $tableAlias ? $tableAlias . '.' : '';
        $userId = $user['id'];
        $role = $user['role'];
        $deptId = $user['department_id'] ?? null;
        
        // 管理员角色无限制
        if (RoleCode::isAdminRole($role)) {
            return ['where' => '', 'params' => []];
        }
        
        // 获取数据范围
        $scope = Permission::getDataScope($userId, $module);
        
        switch ($scope) {
            case DataScope::ALL:
                return ['where' => '', 'params' => []];
                
            case DataScope::DEPT_TREE:
                if ($deptId) {
                    // 获取部门路径
                    $pdo = Db::pdo();
                    $stmt = $pdo->prepare("SELECT path FROM departments WHERE id = ?");
                    $stmt->execute([$deptId]);
                    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($dept && $dept['path']) {
                        // 查找所有下级部门
                        $stmt = $pdo->prepare("SELECT id FROM departments WHERE path LIKE ?");
                        $stmt->execute([$dept['path'] . '%']);
                        $deptIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($deptIds)) {
                            $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
                            return [
                                'where' => " AND ({$prefix}{$deptField} IN ({$placeholders}) OR {$prefix}{$ownerField} = ?)",
                                'params' => array_merge($deptIds, [$userId])
                            ];
                        }
                    }
                }
                // 回退到本部门
                if ($deptId) {
                    return [
                        'where' => " AND ({$prefix}{$deptField} = ? OR {$prefix}{$ownerField} = ?)",
                        'params' => [$deptId, $userId]
                    ];
                }
                break;
                
            case DataScope::DEPT:
                if ($deptId) {
                    return [
                        'where' => " AND ({$prefix}{$deptField} = ? OR {$prefix}{$ownerField} = ?)",
                        'params' => [$deptId, $userId]
                    ];
                }
                break;
                
            case DataScope::SELF:
            default:
                // 仅自己的数据 + 创建者
                return [
                    'where' => " AND ({$prefix}{$ownerField} = ? OR {$prefix}create_user_id = ?)",
                    'params' => [$userId, $userId]
                ];
        }
        
        // 默认返回仅自己的数据
        return [
            'where' => " AND {$prefix}{$ownerField} = ?",
            'params' => [$userId]
        ];
    }
    
    /**
     * 检查用户是否有权限访问指定资源
     */
    public static function canAccess(
        array $user,
        array $resource,
        string $module,
        string $ownerField = 'owner_user_id',
        string $deptField = 'department_id'
    ): bool {
        $userId = $user['id'];
        $role = $user['role'];
        $userDeptId = $user['department_id'] ?? null;
        
        // 管理员角色
        if (RoleCode::isAdminRole($role)) {
            return true;
        }
        
        // 资源所有者
        if (isset($resource[$ownerField]) && $resource[$ownerField] == $userId) {
            return true;
        }
        
        // 资源创建者
        if (isset($resource['create_user_id']) && $resource['create_user_id'] == $userId) {
            return true;
        }
        
        // 获取数据范围
        $scope = Permission::getDataScope($userId, $module);
        
        switch ($scope) {
            case DataScope::ALL:
                return true;
                
            case DataScope::DEPT_TREE:
                if ($userDeptId && isset($resource[$deptField])) {
                    $pdo = Db::pdo();
                    $stmt = $pdo->prepare("SELECT path FROM departments WHERE id = ?");
                    $stmt->execute([$userDeptId]);
                    $userDept = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare("SELECT path FROM departments WHERE id = ?");
                    $stmt->execute([$resource[$deptField]]);
                    $resourceDept = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($userDept && $resourceDept) {
                        // 检查资源部门是否在用户部门的子树中
                        if (strpos($resourceDept['path'], $userDept['path']) === 0) {
                            return true;
                        }
                    }
                }
                // 继续检查本部门
                
            case DataScope::DEPT:
                if ($userDeptId && isset($resource[$deptField])) {
                    if ($resource[$deptField] == $userDeptId) {
                        return true;
                    }
                }
                break;
        }
        
        return false;
    }
}

/**
 * 获取用户的主要角色（兼容旧代码）
 */
function getUserPrimaryRole(array $user): string {
    return $user['role'] ?? RoleCode::VIEWER;
}

/**
 * 检查用户是否是管理员
 */
function isAdmin(array $user): bool {
    return RoleCode::isAdminRole($user['role'] ?? '');
}

/**
 * 检查用户是否可以访问技术资源（所有群）
 */
function canAccessAllTechResources(array $user): bool {
    return RoleCode::isTechRole($user['role'] ?? '');
}

/**
 * 快捷权限检查函数
 */
function can(string $permission): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    return Permission::hasPermission($user['id'], $permission);
}

/**
 * 权限检查或管理员放行
 * 简化 can('xxx') || RoleCode::isAdminRole($user['role']) 的重复写法
 */
function canOrAdmin(string $permission): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    return can($permission) || RoleCode::isAdminRole($user['role'] ?? '');
}

/**
 * 要求权限，否则终止
 */
function requirePermission(string $permission, bool $isJson = true): void {
    if (!can($permission)) {
        if ($isJson) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => '您没有此操作的权限'
                ]
            ]);
        } else {
            http_response_code(403);
            echo '您没有此操作的权限';
        }
        exit;
    }
}

/**
 * 检查用户是否有项目权限
 */
function hasProjectPermission(array $user, string $permission): bool {
    // 管理员拥有所有权限
    if (isAdmin($user)) {
        return true;
    }
    
    // 检查RBAC权限
    return Permission::hasPermission($user['id'], $permission);
}

/**
 * 检查用户是否可以查看项目
 */
function canViewProject(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_VIEW);
}

/**
 * 检查用户是否可以创建项目
 */
function canCreateProject(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_CREATE);
}

/**
 * 检查用户是否可以编辑项目
 */
function canEditProject(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_EDIT);
}

/**
 * 检查用户是否可以删除项目
 */
function canDeleteProject(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_DELETE);
}

/**
 * 检查用户是否可以修改项目状态
 */
function canEditProjectStatus(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_STATUS_EDIT);
}

/**
 * 检查用户是否可以分配项目
 */
function canAssignProject(array $user): bool {
    return hasProjectPermission($user, PermissionCode::PROJECT_ASSIGN);
}
