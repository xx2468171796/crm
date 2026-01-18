<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

class CustomerFilePolicy
{
    /**
     * 检查用户是否可以查看文件
     * 
     * @param array $actor 当前用户
     * @param array $customer 客户信息
     * @param array|null $link 链接信息（可选，用于检查链接权限）
     * @return bool
     */
    public static function canView(array $actor, array $customer, ?array $link = null): bool
    {
        $role = $actor['role'] ?? '';
        if (in_array($role, ['admin', 'system_admin'], true)) {
            return true;
        }

        // 财务权限用户允许查看（财务合同/收款附件会复用 customer_files）
        if (function_exists('canOrAdmin') && class_exists('PermissionCode')) {
            if (canOrAdmin(PermissionCode::FINANCE_VIEW) || canOrAdmin(PermissionCode::FINANCE_EDIT)) {
                return true;
            }
        }

        if ($role === 'dept_admin' &&
            isset($actor['department_id'], $customer['department_id']) &&
            (int)$actor['department_id'] === (int)$customer['department_id']) {
            return true;
        }

        $isOwner = isset($customer['owner_user_id']) && (int)$customer['owner_user_id'] === (int)$actor['id'];
        $isCreator = isset($customer['create_user_id']) && (int)$customer['create_user_id'] === (int)$actor['id'];

        // 基础权限检查
        if ($isOwner || $isCreator) {
            return true;
        }

        // 检查链接权限（如果提供了链接信息）
        if ($link && $link['enabled']) {
            $customerId = (int)$customer['id'];
            $password = getShareSessionPassword($customerId);

            $linkPermission = checkLinkPermission($link, $actor, $password);
            if ($linkPermission === 'edit' || $linkPermission === 'view') {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查用户是否可以编辑文件（上传/删除）
     * 
     * @param array $actor 当前用户
     * @param array $customer 客户信息
     * @param array|null $link 链接信息（可选，用于检查链接权限）
     * @return bool
     */
    public static function canEdit(array $actor, array $customer, ?array $link = null): bool
    {
        $role = $actor['role'] ?? '';
        if (in_array($role, ['admin', 'system_admin'], true)) {
            return true;
        }

        // 财务编辑权限允许上传/删除/重命名（财务凭证等）
        if (function_exists('canOrAdmin') && class_exists('PermissionCode')) {
            if (canOrAdmin(PermissionCode::FINANCE_EDIT)) {
                return true;
            }
        }

        if ($role === 'dept_admin' &&
            isset($actor['department_id'], $customer['department_id']) &&
            (int)$actor['department_id'] === (int)$customer['department_id']) {
            return true;
        }

        $isOwner = isset($customer['owner_user_id']) && (int)$customer['owner_user_id'] === (int)$actor['id'];

        // 基础权限检查
        if ($isOwner) {
            return true;
        }

        // 检查链接权限（如果提供了链接信息）
        if ($link && $link['enabled']) {
            $customerId = (int)$customer['id'];
            $password = getShareSessionPassword($customerId);

            $linkPermission = checkLinkPermission($link, $actor, $password);
            if ($linkPermission === 'edit') {
                return true;
            }
        }

        return false;
    }

    /**
     * 授权检查，如果无权限则抛出异常
     * 
     * @param array $actor 当前用户
     * @param array $customer 客户信息
     * @param string $action 操作类型：'view' 或 'edit'
     * @param array|null $link 链接信息（可选）
     * @return void
     * @throws RuntimeException
     */
    public static function authorize(array $actor, array $customer, string $action, ?array $link = null): void
    {
        switch ($action) {
            case 'edit':
                $allowed = self::canEdit($actor, $customer, $link);
                break;
            default:
                $allowed = self::canView($actor, $customer, $link);
                break;
        }

        if (!$allowed) {
            throw new RuntimeException('无权限访问此客户文件');
        }
    }
}

