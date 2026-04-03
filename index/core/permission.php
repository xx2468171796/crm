<?php
/**
 * 权限检查函数（兼容层）
 * 
 * 此文件保留用于向后兼容，新代码请使用 rbac.php 中的 Permission 类
 */

require_once __DIR__ . '/rbac.php';

/**
 * 检查用户是否有权限操作客户
 * @param array $user 当前用户
 * @param array $customer 客户信息
 * @return bool
 */
function has_customer_permission($user, $customer) {
    if (!$user || !$customer) {
        return false;
    }
    
    // 使用 RBAC 数据权限检查
    return DataPermissionBuilder::canAccess($user, $customer, 'customer');
}

/**
 * 要求用户有客户操作权限，否则返回错误
 * @param array $user 当前用户
 * @param array $customer 客户信息
 * @param bool $isJson 是否返回JSON格式错误
 */
function require_customer_permission($user, $customer, $isJson = true) {
    if (!has_customer_permission($user, $customer)) {
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
            exit;
        } else {
            die('无权限操作此客户');
        }
    }
}

/**
 * 检查用户是否有权限管理客户链接
 * 比 has_customer_permission 更宽松，支持链接授权用户
 * 
 * @param array $user 当前用户
 * @param array $customer 客户信息
 * @param array|null $link 链接信息（可选，用于检查授权用户）
 * @return bool
 */
function has_customer_link_permission($user, $customer, $link = null) {
    if (!$user || !$customer) {
        return false;
    }
    
    // 1. 基础权限检查（使用 RBAC）
    if (has_customer_permission($user, $customer)) {
        return true;
    }
    
    // 2. 部门管理员权限（dept_admin/dept_leader 可以管理同部门的客户）
    if (RoleCode::isDeptManagerRole($user['role'] ?? '') && 
        isset($user['department_id']) && 
        isset($customer['department_id']) && 
        $user['department_id'] == $customer['department_id']) {
        return true;
    }
    
    // 3. 链接授权用户权限（如果提供了链接信息）
    if ($link) {
        $allowedEditUsers = json_decode($link['allowed_edit_users'] ?? '[]', true) ?: [];
        if (in_array($user['id'], $allowedEditUsers)) {
            return true;
        }
    }
    
    return false;
}
