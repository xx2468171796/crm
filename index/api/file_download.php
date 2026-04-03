<?php
require_once __DIR__ . '/../core/api_init.php';
// 文件下载API

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$fileId = intval($_GET['id'] ?? 0);

if ($fileId === 0) {
    die('文件ID无效');
}

// 获取文件信息
$file = Db::queryOne('SELECT * FROM files WHERE id = :id', ['id' => $fileId]);

if (!$file) {
    die('文件不存在');
}

// 权限检查（外部访问也可以下载）
$user = current_user();
if ($user) {
    // 内部用户权限检查
    $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $file['customer_id']]);
    
    $hasPermission = false;
    if ($user['role'] === 'system_admin') {
        $hasPermission = true;
    } elseif ($user['role'] === 'dept_admin' && $customer['department_id'] == $user['department_id']) {
        $hasPermission = true;
    } elseif ($customer['owner_user_id'] == $user['id']) {
        $hasPermission = true;
    }
    
    if (!$hasPermission) {
        die('无权限下载');
    }
}

// 文件路径
$filePath = __DIR__ . '/../uploads/customer_' . $file['customer_id'] . '/' . $file['file_path'];

if (!file_exists($filePath)) {
    die('文件不存在');
}

// 设置下载头
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: must-revalidate');
header('Pragma: public');

// 输出文件
readfile($filePath);
exit;
