<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$receiptId = (int)($_POST['receipt_id'] ?? 0);

if ($receiptId <= 0) {
    echo json_encode(['success' => false, 'message' => '收款记录ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '请选择文件上传'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['file'];
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => '文件大小不能超过10MB'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'message' => '只支持jpg、png、gif、pdf格式'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    echo json_encode(['success' => false, 'message' => '文件扩展名不支持'], JSON_UNESCAPED_UNICODE);
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
            echo json_encode(['success' => false, 'message' => '无权限：只能操作自己客户的收款记录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    $now = time();
    $uid = (int)($user['id'] ?? 0);
    
    Db::beginTransaction();

    // 使用客户文件服务统一上传逻辑（统一 storage_key 规则 + 预览/删除一致）
    $service = new CustomerFileService();
    $created = $service->uploadFiles(
        $customerId,
        $user,
        $_FILES['file'],
        [
            'category' => 'internal_solution',
            'notes' => '收款凭证',
        ]
    );

    if (empty($created)) {
        throw new RuntimeException('上传失败');
    }

    $createdFile = $created[0];
    $fileId = (int)($createdFile['id'] ?? 0);
    if ($fileId <= 0) {
        throw new RuntimeException('上传失败：未返回文件ID');
    }
    
    Db::execute(
        'INSERT INTO finance_receipt_files (receipt_id, file_id, created_at, created_by)
         VALUES (:receipt_id, :file_id, :created_at, :created_by)',
        [
            'receipt_id' => $receiptId,
            'file_id' => $fileId,
            'created_at' => $now,
            'created_by' => $uid,
        ]
    );
    
    Db::commit();
    
    echo json_encode([
        'success' => true,
        'message' => '上传成功',
        'data' => [
            'file_id' => $fileId,
            'filename' => $file['name'],
            'storage_key' => $createdFile['storage_key'] ?? null,
            'file_type' => $mimeType,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
