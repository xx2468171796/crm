<?php
require_once __DIR__ . '/../core/api_init.php';
// 客户转移API
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$currentUser = current_user();

if (!canOrAdmin(PermissionCode::CUSTOMER_TRANSFER)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = intval($_POST['customer_id'] ?? 0);
$toUserId = intval($_POST['to_user_id'] ?? $_POST['owner_user_id'] ?? 0);

if ($customerId <= 0 || $toUserId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $now = time();
    
    // 同时更新create_user_id和owner_user_id，确保权限完全转移
    Db::execute('UPDATE customers SET 
                 create_user_id = :create_user_id, 
                 owner_user_id = :owner_user_id, 
                 update_time = :update_time,
                 update_user_id = :update_user_id
                 WHERE id = :id', [
        'create_user_id' => $toUserId,
        'owner_user_id' => $toUserId,
        'update_time' => $now,
        'update_user_id' => $currentUser['id'],
        'id' => $customerId
    ]);
    
    error_log("客户转移成功: customer_id={$customerId}, from_user_id={$currentUser['id']}, to_user_id={$toUserId}");
    
    echo json_encode(['success' => true, 'message' => '客户转移成功'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Customer transfer error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '转移失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
