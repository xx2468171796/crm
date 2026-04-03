<?php
require_once __DIR__ . '/../core/api_init.php';
// 文件删除API

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

$fileId = intval($_POST['id'] ?? 0);

if ($fileId === 0) {
    echo json_encode(['success' => false, 'message' => '文件ID无效']);
    exit;
}

// 获取文件信息
$file = Db::queryOne('SELECT * FROM files WHERE id = :id', ['id' => $fileId]);

if (!$file) {
    echo json_encode(['success' => false, 'message' => '文件不存在']);
    exit;
}

// 权限检查
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $file['customer_id']]);

$hasPermission = false;
if (canOrAdmin(PermissionCode::FILE_DELETE) || canOrAdmin(PermissionCode::CUSTOMER_EDIT)) {
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
    // 删除物理文件
    $filePath = __DIR__ . '/../uploads/customer_' . $file['customer_id'] . '/' . $file['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // 删除数据库记录
    Db::execute('DELETE FROM files WHERE id = :id', ['id' => $fileId]);
    
    echo json_encode([
        'success' => true,
        'message' => '文件已删除'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '删除失败: ' . $e->getMessage()
    ]);
}
