<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 角色管理 API
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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'list':
            default:
                // 获取角色列表
                $includeDisabled = isset($_GET['include_disabled']) && $_GET['include_disabled'] === '1';
                $roles = RoleService::getAll($includeDisabled);
                
                // 添加用户数量
                foreach ($roles as &$role) {
                    $role['user_count'] = RoleService::getUserCount($role['id']);
                    $role['permissions_array'] = json_decode($role['permissions'] ?? '[]', true) ?: [];
                }
                unset($role);
                
                echo json_encode([
                    'success' => true,
                    'data' => $roles
                ]);
                break;
                
            case 'get':
                // 获取单个角色
                $id = intval($_GET['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的角色ID');
                }
                
                $role = RoleService::getById($id);
                if (!$role) {
                    throw new Exception('角色不存在');
                }
                
                $role['permissions_array'] = json_decode($role['permissions'] ?? '[]', true) ?: [];
                $role['data_permissions'] = RoleService::getDataPermissions($id);
                $role['user_count'] = RoleService::getUserCount($id);
                
                echo json_encode([
                    'success' => true,
                    'data' => $role
                ]);
                break;
                
            case 'permissions':
                // 获取所有权限定义（按模块分组）
                $grouped = RoleService::getPermissionsByModule();
                
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
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? $action;
        
        switch ($action) {
            case 'create':
                // 创建角色
                $id = RoleService::create([
                    'name' => $input['name'] ?? '',
                    'code' => $input['code'] ?? '',
                    'description' => $input['description'] ?? '',
                    'permissions' => $input['permissions'] ?? []
                ]);
                
                // 设置数据权限
                if (isset($input['data_permissions'])) {
                    RoleService::setDataPermissions($id, $input['data_permissions']);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $id],
                    'message' => '角色创建成功'
                ]);
                break;
                
            case 'update':
                // 更新角色
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的角色ID');
                }
                
                RoleService::update($id, [
                    'name' => $input['name'] ?? null,
                    'code' => $input['code'] ?? null,
                    'description' => $input['description'] ?? null,
                    'permissions' => $input['permissions'] ?? null,
                    'status' => $input['status'] ?? null
                ]);
                
                // 设置数据权限
                if (isset($input['data_permissions'])) {
                    RoleService::setDataPermissions($id, $input['data_permissions']);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => '角色更新成功'
                ]);
                break;
                
            case 'delete':
                // 删除角色
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的角色ID');
                }
                
                RoleService::delete($id);
                
                echo json_encode([
                    'success' => true,
                    'message' => '角色删除成功'
                ]);
                break;
                
            case 'set_data_permissions':
                // 设置数据权限
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的角色ID');
                }
                
                $dataPermissions = $input['data_permissions'] ?? [];
                RoleService::setDataPermissions($id, $dataPermissions);
                
                echo json_encode([
                    'success' => true,
                    'message' => '数据权限设置成功'
                ]);
                break;
                
            default:
                throw new Exception('无效的操作');
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '不支持的请求方法']]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'ERROR', 'message' => $e->getMessage()]
    ]);
}
