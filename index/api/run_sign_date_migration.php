<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 只允许管理员执行
if (!in_array($user['role'], ['admin', 'system_admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => '无权限执行迁移']);
    exit;
}

try {
    // 修改字段类型从DATE到DATETIME
    Db::exec('ALTER TABLE finance_contracts MODIFY COLUMN sign_date DATETIME DEFAULT NULL');
    
    echo json_encode(['success' => true, 'message' => 'sign_date字段已改为DATETIME类型']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
