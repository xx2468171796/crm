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

$installmentId = (int)($_GET['installment_id'] ?? 0);

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '分期ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $inst = Db::queryOne(
        'SELECT i.id, i.customer_id FROM finance_installments i WHERE i.id = :id LIMIT 1',
        ['id' => $installmentId]
    );
    
    if (!$inst) {
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $customerId = (int)($inst['customer_id'] ?? 0);
    
    if (($user['role'] ?? '') === 'sales') {
        $cust = Db::queryOne('SELECT owner_user_id FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
        if ((int)($cust['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    $files = Db::query(
        'SELECT rf.id, rf.file_id, rf.created_at, cf.filename, cf.storage_key, cf.mime_type, cf.filesize
         FROM finance_receipts r
         INNER JOIN finance_receipt_files rf ON rf.receipt_id = r.id
         INNER JOIN customer_files cf ON cf.id = rf.file_id
         WHERE r.installment_id = :installment_id AND cf.deleted_at IS NULL
         ORDER BY rf.created_at DESC',
        ['installment_id' => $installmentId]
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
