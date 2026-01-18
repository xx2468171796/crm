<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 权限管理 API
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/RoleService.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => '请先登录']]);
    exit;
}

// 检查权限
if (!canOrAdmin(PermissionCode::ROLE_MANAGE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '您没有权限管理角色']]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            // 获取所有权限定义
            $permissions = RoleService::getAllPermissions();
            echo json_encode([
                'success' => true,
                'data' => $permissions
            ]);
            break;
            
        case 'grouped':
            // 获取按模块分组的权限定义
            $grouped = RoleService::getPermissionsByModule();
            
            // 模块名称映射
            $moduleNames = [
                'customer' => '客户管理',
                'finance' => '财务管理',
                'tech_resource' => '技术资源',
                'system' => '系统管理',
                'data_scope' => '数据范围'
            ];
            
            $result = [];
            foreach ($grouped as $module => $perms) {
                $result[] = [
                    'module' => $module,
                    'module_name' => $moduleNames[$module] ?? $module,
                    'permissions' => $perms
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_ACTION', 'message' => '无效的操作']]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]
    ]);
}
