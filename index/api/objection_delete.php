<?php
require_once __DIR__ . '/../core/api_init.php';
// 删除异议处理记录

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

// 需要登录
$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id === 0) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

// 检查记录是否存在
$objection = Db::queryOne('SELECT * FROM objection WHERE id = :id', ['id' => $id]);
if (!$objection) {
    echo json_encode(['success' => false, 'message' => '记录不存在']);
    exit;
}

// 权限检查
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $objection['customer_id']]);
$hasPermission = false;

if (canOrAdmin(PermissionCode::OBJECTION_EDIT)) {
    $hasPermission = true;
} elseif (RoleCode::isDeptManagerRole($user['role']) && $customer['department_id'] == $user['department_id']) {
    $hasPermission = true;
} elseif ($customer['owner_user_id'] == $user['id']) {
    $hasPermission = true;
}

if (!$hasPermission) {
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

try {
    Db::execute('DELETE FROM objection WHERE id = :id', ['id' => $id]);
    
    echo json_encode(['success' => true, 'message' => '删除成功']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
