<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 用户管理 API
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/RoleService.php';
require_once __DIR__ . '/../core/services/UserRoleService.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => '请先登录']]);
    exit;
}

// 检查权限
if (!canOrAdmin(PermissionCode::USER_MANAGE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '您没有权限管理用户']]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'list':
            default:
                // 获取用户列表
                $departmentId = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
                $roleId = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
                $keyword = trim($_GET['keyword'] ?? '');
                
                $where = ['1=1'];
                $params = [];
                
                if ($departmentId > 0) {
                    $where[] = 'u.department_id = ?';
                    $params[] = $departmentId;
                }
                
                if ($roleId > 0) {
                    $where[] = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = ?)';
                    $params[] = $roleId;
                }
                
                if ($keyword !== '') {
                    $where[] = '(u.username LIKE ? OR u.realname LIKE ? OR u.mobile LIKE ?)';
                    $likeKeyword = '%' . $keyword . '%';
                    $params[] = $likeKeyword;
                    $params[] = $likeKeyword;
                    $params[] = $likeKeyword;
                }
                
                $whereClause = implode(' AND ', $where);
                
                $sql = "
                    SELECT u.id, u.username, u.realname, u.role, u.mobile, u.email, 
                           u.status, u.create_time, u.department_id,
                           d.name as department_name
                    FROM users u
                    LEFT JOIN departments d ON u.department_id = d.id
                    WHERE {$whereClause}
                    ORDER BY u.id DESC
                ";
                
                $pdo = Db::pdo();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 获取每个用户的角色
                foreach ($users as &$u) {
                    $u['roles'] = Permission::getUserRoles($u['id']);
                    $u['create_time_formatted'] = $u['create_time'] ? date('Y-m-d H:i', $u['create_time']) : '';
                }
                unset($u);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'items' => $users,
                        'total' => count($users)
                    ]
                ]);
                break;
                
            case 'get':
                // 获取单个用户
                $id = intval($_GET['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的用户ID');
                }
                
                $pdo = Db::pdo();
                $stmt = $pdo->prepare("
                    SELECT u.*, d.name as department_name
                    FROM users u
                    LEFT JOIN departments d ON u.department_id = d.id
                    WHERE u.id = ?
                ");
                $stmt->execute([$id]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userData) {
                    throw new Exception('用户不存在');
                }
                
                // 获取用户角色
                $userData['roles'] = Permission::getUserRoles($id);
                $userData['role_ids'] = UserRoleService::getUserRoleIds($id);
                
                // 移除密码
                unset($userData['password']);
                
                echo json_encode([
                    'success' => true,
                    'data' => $userData
                ]);
                break;
                
            case 'roles':
                // 获取所有可用角色
                $roles = RoleService::getAll();
                echo json_encode([
                    'success' => true,
                    'data' => $roles
                ]);
                break;
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? $action;
        
        switch ($action) {
            case 'create':
                // 创建用户
                $username = trim($input['username'] ?? '');
                $password = $input['password'] ?? '';
                $realname = trim($input['realname'] ?? '');
                $mobile = trim($input['mobile'] ?? '');
                $email = trim($input['email'] ?? '');
                $departmentId = intval($input['department_id'] ?? 0);
                $roleIds = $input['role_ids'] ?? [];
                $status = intval($input['status'] ?? 1);
                
                if (empty($username) || empty($password) || empty($realname)) {
                    throw new Exception('用户名、密码和姓名不能为空');
                }
                
                // 检查用户名是否已存在
                $pdo = Db::pdo();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    throw new Exception('用户名已存在');
                }
                
                // 确定主要角色
                $primaryRole = 'viewer';
                if (!empty($roleIds)) {
                    $stmt = $pdo->prepare("SELECT code FROM roles WHERE id = ?");
                    $stmt->execute([$roleIds[0]]);
                    $role = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($role) {
                        $primaryRole = $role['code'];
                    }
                }
                
                $now = time();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, realname, role, mobile, email, department_id, status, create_time, update_time)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $passwordHash, $realname, $primaryRole, $mobile, $email, $departmentId ?: null, $status, $now, $now]);
                
                $userId = (int)$pdo->lastInsertId();
                
                // 设置角色
                if (!empty($roleIds)) {
                    UserRoleService::setUserRoles($userId, $roleIds);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $userId],
                    'message' => '用户创建成功'
                ]);
                break;
                
            case 'update':
                // 更新用户
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的用户ID');
                }
                
                $pdo = Db::pdo();
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existingUser) {
                    throw new Exception('用户不存在');
                }
                
                $updates = [];
                $params = [];
                
                if (isset($input['realname'])) {
                    $updates[] = 'realname = ?';
                    $params[] = trim($input['realname']);
                }
                
                if (isset($input['mobile'])) {
                    $updates[] = 'mobile = ?';
                    $params[] = trim($input['mobile']);
                }
                
                if (isset($input['email'])) {
                    $updates[] = 'email = ?';
                    $params[] = trim($input['email']);
                }
                
                if (isset($input['department_id'])) {
                    $updates[] = 'department_id = ?';
                    $params[] = intval($input['department_id']) ?: null;
                }
                
                if (isset($input['status'])) {
                    $updates[] = 'status = ?';
                    $params[] = intval($input['status']);
                }
                
                if (!empty($input['password'])) {
                    $updates[] = 'password = ?';
                    $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
                }
                
                if (!empty($updates)) {
                    $updates[] = 'update_time = ?';
                    $params[] = time();
                    $params[] = $id;
                    
                    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
                
                // 更新角色
                if (isset($input['role_ids'])) {
                    UserRoleService::setUserRoles($id, $input['role_ids']);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => '用户更新成功'
                ]);
                break;
                
            case 'delete':
                // 删除用户（软删除）
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('无效的用户ID');
                }
                
                // 不能删除自己
                if ($id == $user['id']) {
                    throw new Exception('不能删除当前登录用户');
                }
                
                $pdo = Db::pdo();
                $stmt = $pdo->prepare("UPDATE users SET status = 0, update_time = ? WHERE id = ?");
                $stmt->execute([time(), $id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '用户已禁用'
                ]);
                break;
                
            case 'set_roles':
                // 设置用户角色
                $id = intval($input['id'] ?? 0);
                $roleIds = $input['role_ids'] ?? [];
                
                if ($id <= 0) {
                    throw new Exception('无效的用户ID');
                }
                
                UserRoleService::setUserRoles($id, $roleIds);
                
                echo json_encode([
                    'success' => true,
                    'message' => '角色设置成功'
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
