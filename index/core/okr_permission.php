<?php
/**
 * OKR 权限检查辅助函数
 * 统一处理 OKR 模块的权限检查
 */

require_once __DIR__ . '/department_permission.php';

/**
 * 检查用户是否有权限访问 OKR 容器
 * 
 * @param array $user 当前用户信息
 * @param array $container OKR容器信息（需包含 user_id, level, department_id）
 * @return bool 是否有权限
 */
function checkOkrContainerPermission($user, $container) {
    if (!$user || !$container) {
        return false;
    }
    
    // 系统管理员有所有权限
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return true;
    }
    
    // 容器负责人有权限
    if ($container['user_id'] == $user['id']) {
        return true;
    }
    
    // 部门管理员权限检查
    if ($user['role'] === 'dept_admin') {
        // 公司级 OKR：部门管理员可查看
        if ($container['level'] === 'company') {
            return true;
        }
        
        // 部门级 OKR：必须是同一部门
        if ($container['level'] === 'department' && 
            !empty($container['department_id']) && 
            $container['department_id'] == $user['department_id']) {
            return true;
        }
    }
    
    // 个人级 OKR：只能查看自己的
    if ($container['level'] === 'personal' && $container['user_id'] == $user['id']) {
        return true;
    }
    
    // 公司级 OKR：所有登录用户可查看
    if ($container['level'] === 'company') {
        return true;
    }
    
    return false;
}

/**
 * 检查用户是否有权限操作 OKR 容器（创建/编辑/删除）
 * 
 * @param array $user 当前用户信息
 * @param array $container OKR容器信息（可选，创建时可为null）
 * @param string $level 要创建的层级（创建时使用）
 * @param int|null $departmentId 部门ID（创建部门级OKR时使用）
 * @return bool 是否有权限
 */
function checkOkrContainerOperationPermission($user, $container = null, $level = 'personal', $departmentId = null) {
    if (!$user) {
        return false;
    }
    
    // 系统管理员有所有权限
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return true;
    }
    
    // 创建权限检查
    if ($container === null) {
        // 创建公司级 OKR：仅系统管理员
        if ($level === 'company') {
            return $user['role'] === 'admin' || $user['role'] === 'system_admin';
        }
        
        // 创建部门级 OKR：部门管理员
        if ($level === 'department') {
            return $user['role'] === 'dept_admin' && 
                   !empty($user['department_id']) && 
                   ($departmentId === null || $departmentId == $user['department_id']);
        }
        
        // 创建个人级 OKR：所有用户
        if ($level === 'personal') {
            return true;
        }
    }
    
    // 编辑/删除权限检查
    if ($container) {
        // 容器负责人有权限
        if ($container['user_id'] == $user['id']) {
            return true;
        }
        
        // 系统管理员有权限
        if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
            return true;
        }
        
        // 部门管理员可以管理本部门的部门级 OKR
        if ($user['role'] === 'dept_admin' && 
            $container['level'] === 'department' &&
            !empty($container['department_id']) &&
            $container['department_id'] == $user['department_id']) {
            return true;
        }
    }
    
    return false;
}

/**
 * 检查用户是否有权限访问任务
 * 
 * @param array $user 当前用户信息
 * @param array $task 任务信息（需包含 executor_id, assigner_id, department_id, level）
 * @return bool 是否有权限
 */
function checkOkrTaskPermission($user, $task) {
    if (!$user || !$task) {
        return false;
    }
    
    // 系统管理员有所有权限
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return true;
    }
    
    // 任务执行人有权限
    if ($task['executor_id'] == $user['id']) {
        return true;
    }
    
    // 任务指派人有权限
    if (!empty($task['assigner_id']) && $task['assigner_id'] == $user['id']) {
        return true;
    }
    
    // 部门管理员可以查看本部门的任务
    if ($user['role'] === 'dept_admin' && 
        !empty($task['department_id']) && 
        $task['department_id'] == $user['department_id']) {
        return true;
    }
    
    // 公司级任务：所有登录用户可查看
    if ($task['level'] === 'company') {
        return true;
    }
    
    return false;
}

/**
 * 检查用户是否有权限操作任务（创建/编辑/删除）
 * 
 * @param array $user 当前用户信息
 * @param array $task 任务信息（可选，创建时可为null）
 * @return bool 是否有权限
 */
function checkOkrTaskOperationPermission($user, $task = null) {
    if (!$user) {
        return false;
    }
    
    // 系统管理员有所有权限
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return true;
    }
    
    // 创建任务：所有用户都可以创建
    if ($task === null) {
        return true;
    }
    
    // 编辑/删除任务：执行人或指派人有权限
    if ($task['executor_id'] == $user['id'] || 
        (!empty($task['assigner_id']) && $task['assigner_id'] == $user['id'])) {
        return true;
    }
    
    // 部门管理员可以管理本部门的任务
    if ($user['role'] === 'dept_admin' && 
        !empty($task['department_id']) && 
        $task['department_id'] == $user['department_id']) {
        return true;
    }
    
    return false;
}

/**
 * 构建 OKR 容器的 SQL WHERE 条件（基于权限）
 * 
 * @param array $user 当前用户信息
 * @param string $tableAlias 表别名，默认为空
 * @return string SQL WHERE条件
 */
function buildOkrContainerWhereClause($user, $tableAlias = '') {
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    
    // 系统管理员无限制
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return '';
    }
    
    // 部门管理员：可查看公司级 + 本部门的部门级 + 自己的个人级
    if ($user['role'] === 'dept_admin' && !empty($user['department_id'])) {
        return " AND ({$prefix}level = 'company' OR " .
               "({$prefix}level = 'department' AND {$prefix}department_id = " . intval($user['department_id']) . ") OR " .
               "({$prefix}level = 'personal' AND {$prefix}user_id = " . intval($user['id']) . "))";
    }
    
    // 普通用户：可查看公司级 + 自己的个人级
    return " AND ({$prefix}level = 'company' OR ({$prefix}level = 'personal' AND {$prefix}user_id = " . intval($user['id']) . "))";
}

/**
 * 构建任务的 SQL WHERE 条件（基于权限）
 * 
 * @param array $user 当前用户信息
 * @param string $tableAlias 表别名，默认为空
 * @return string SQL WHERE条件
 */
function buildOkrTaskWhereClause($user, $tableAlias = '') {
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    
    // 系统管理员无限制
    if ($user['role'] === 'admin' || $user['role'] === 'system_admin') {
        return '';
    }
    
    // 部门管理员：可查看公司级 + 本部门的任务 + 自己执行/指派的任务
    if ($user['role'] === 'dept_admin' && !empty($user['department_id'])) {
        return " AND ({$prefix}level = 'company' OR " .
               "({$prefix}department_id = " . intval($user['department_id']) . ") OR " .
               "{$prefix}executor_id = " . intval($user['id']) . " OR " .
               "{$prefix}assigner_id = " . intval($user['id']) . ")";
    }
    
    // 普通用户：可查看公司级 + 自己执行/指派的任务
    return " AND ({$prefix}level = 'company' OR " .
           "{$prefix}executor_id = " . intval($user['id']) . " OR " .
           "{$prefix}assigner_id = " . intval($user['id']) . ")";
}

