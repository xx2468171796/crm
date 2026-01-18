<?php
/**
 * 部门权限检查辅助函数（兼容层）
 * 
 * 此文件保留用于向后兼容，新代码请使用 rbac.php 中的 DataPermissionBuilder 类
 */

require_once __DIR__ . '/rbac.php';

/**
 * 检查用户是否有权限访问指定资源
 * 
 * @param array $user 当前用户信息，必须包含 role 和 department_id
 * @param array $resource 资源信息，必须包含 department_id 或 owner_user_id
 * @return bool 是否有权限
 * @throws Exception 当部门管理员未分配部门时抛出异常
 */
function checkDepartmentPermission($user, $resource) {
    // 使用 RBAC 系统检查
    if (RoleCode::isAdminRole($user['role'] ?? '')) {
        return true;
    }
    
    // 部门管理员权限检查
    if (RoleCode::isDeptManagerRole($user['role'] ?? '')) {
        if (empty($user['department_id'])) {
            throw new Exception('您未分配部门，请联系管理员');
        }
        
        if (!empty($resource['department_id']) && $resource['department_id'] == $user['department_id']) {
            return true;
        }
        
        return false;
    }
    
    // 资源所有者有权限
    if (!empty($resource['owner_user_id']) && $resource['owner_user_id'] == $user['id']) {
        return true;
    }
    
    return false;
}

/**
 * 检查用户是否有权限访问指定资源（简化版）
 * 不抛出异常，只返回布尔值
 * 
 * @param array $user 当前用户信息
 * @param array $resource 资源信息
 * @return bool 是否有权限
 */
function hasDepartmentPermission($user, $resource) {
    try {
        return checkDepartmentPermission($user, $resource);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 断言用户有权限访问资源，无权限时抛出异常
 * 
 * @param array $user 当前用户信息
 * @param array $resource 资源信息
 * @param string $resourceName 资源名称，用于错误提示
 * @throws Exception 无权限时抛出异常
 */
function assertDepartmentPermission($user, $resource, $resourceName = '此资源') {
    if (!checkDepartmentPermission($user, $resource)) {
        throw new Exception("您没有权限访问{$resourceName}");
    }
}

/**
 * 获取用户可访问的部门ID列表
 * 
 * @param array $user 当前用户信息
 * @return array|null 部门ID数组，管理员返回null表示所有部门
 */
function getUserAccessibleDepartments($user) {
    // 系统管理员可以访问所有部门
    if (RoleCode::isAdminRole($user['role'] ?? '')) {
        return null; // null 表示所有部门
    }
    
    // 部门管理员只能访问自己的部门
    if (RoleCode::isDeptManagerRole($user['role'] ?? '') && !empty($user['department_id'])) {
        return [$user['department_id']];
    }
    
    // 其他角色返回空数组
    return [];
}

/**
 * 构建部门权限的SQL WHERE条件
 * @deprecated 请使用 DataPermissionBuilder::build() 代替
 * 
 * @param array $user 当前用户信息
 * @param string $tableAlias 表别名，默认为空
 * @return string SQL WHERE条件，如果无限制返回空字符串
 */
function buildDepartmentWhereClause($user, $tableAlias = '') {
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    
    // 系统管理员无限制
    if (RoleCode::isAdminRole($user['role'] ?? '')) {
        return '';
    }
    
    // 部门管理员限制
    if (RoleCode::isDeptManagerRole($user['role'] ?? '') && !empty($user['department_id'])) {
        return " AND {$prefix}department_id = " . intval($user['department_id']);
    }
    
    // 普通用户限制为自己的数据
    return " AND {$prefix}owner_user_id = " . intval($user['id']);
}
