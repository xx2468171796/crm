<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$receiptId = (int)($_GET['receipt_id'] ?? 0);

if ($receiptId <= 0) {
    echo json_encode(['success' => false, 'message' => '收款记录ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $receipt = Db::queryOne(
        'SELECT r.id, r.customer_id FROM finance_receipts r WHERE r.id = :id LIMIT 1',
        ['id' => $receiptId]
    );
    
    if (!$receipt) {
        echo json_encode(['success' => false, 'message' => '收款记录不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $customerId = (int)($receipt['customer_id'] ?? 0);
    
    if (($user['role'] ?? '') === 'sales') {
        $cust = Db::queryOne('SELECT owner_user_id FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
        if ((int)($cust['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    $files = Db::query(
        'SELECT rf.id, rf.file_id, rf.created_at, cf.filename, cf.storage_key, cf.mime_type, cf.filesize
         FROM finance_receipt_files rf
         INNER JOIN customer_files cf ON cf.id = rf.file_id
         WHERE rf.receipt_id = :receipt_id AND cf.deleted_at IS NULL
         ORDER BY rf.created_at DESC',
        ['receipt_id' => $receiptId]
    );
    
    $data = [];
    foreach ($files as $f) {
        $data[] = [
            'id' => (int)($f['id'] ?? 0),
            'file_id' => (int)($f['file_id'] ?? 0),
            'filename' => (string)($f['filename'] ?? ''),
            'url' => (string)($f['storage_key'] ?? ''),
            'file_type' => (string)($f['mime_type'] ?? ''),
            'file_size' => (int)($f['filesize'] ?? 0),
            'created_at' => (int)($f['created_at'] ?? 0),
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
