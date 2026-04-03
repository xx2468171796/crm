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

$installmentId = (int)($_POST['installment_id'] ?? 0);

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '分期ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['files']) && empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => '请选择文件上传'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 支持单文件和多文件上传
$uploadFiles = [];
if (!empty($_FILES['files']['name'][0])) {
    // 多文件上传
    $count = count($_FILES['files']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $uploadFiles[] = [
                'name' => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size' => $_FILES['files']['size'][$i],
                'error' => $_FILES['files']['error'][$i],
            ];
        }
    }
} elseif (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    // 单文件上传
    $uploadFiles[] = $_FILES['file'];
}

if (empty($uploadFiles)) {
    echo json_encode(['success' => false, 'message' => '没有有效的文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxSize = 10 * 1024 * 1024;
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

try {
    $inst = Db::queryOne(
        'SELECT i.id, i.customer_id, i.contract_id FROM finance_installments i WHERE i.id = :id AND i.deleted_at IS NULL LIMIT 1',
        ['id' => $installmentId]
    );
    
    if (!$inst) {
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $customerId = (int)($inst['customer_id'] ?? 0);
    $contractId = (int)($inst['contract_id'] ?? 0);
    
    if (($user['role'] ?? '') === 'sales') {
        $cust = Db::queryOne('SELECT owner_user_id FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
        if ((int)($cust['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            echo json_encode(['success' => false, 'message' => '无权限：只能操作自己客户的分期'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 查找该分期下最近的收款记录，如果没有则创建一个0金额的记录用于绑定凭证
    $receipt = Db::queryOne(
        'SELECT id FROM finance_receipts WHERE installment_id = :installment_id ORDER BY id DESC LIMIT 1',
        ['installment_id' => $installmentId]
    );
    
    $now = time();
    $uid = (int)($user['id'] ?? 0);
    
    Db::beginTransaction();
    
    if (!$receipt) {
        // 创建一个0金额的收款记录用于绑定凭证
        Db::execute(
            'INSERT INTO finance_receipts (contract_id, installment_id, customer_id, received_date, amount_received, amount_applied, method, note, created_by, create_time, update_time)
             VALUES (:contract_id, :installment_id, :customer_id, :received_date, 0, 0, :method, :note, :created_by, :create_time, :update_time)',
            [
                'contract_id' => $contractId,
                'installment_id' => $installmentId,
                'customer_id' => $customerId,
                'received_date' => date('Y-m-d'),
                'method' => '',
                'note' => '仅上传凭证',
                'created_by' => $uid,
                'create_time' => $now,
                'update_time' => $now,
            ]
        );
        $receiptId = (int)Db::lastInsertId();
    } else {
        $receiptId = (int)$receipt['id'];
    }
    
    $service = new CustomerFileService();

    // 构造过滤后的 filesPayload（符合 CustomerFileService->uploadFiles 期望格式）
    $filesPayload = [
        'name' => [],
        'type' => [],
        'tmp_name' => [],
        'error' => [],
        'size' => [],
    ];
    
    foreach ($uploadFiles as $file) {
        if ($file['size'] > $maxSize) {
            continue; // 跳过过大的文件
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            continue; // 跳过不支持的类型
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            continue;
        }

        $filesPayload['name'][] = $file['name'];
        $filesPayload['type'][] = $mimeType;
        $filesPayload['tmp_name'][] = $file['tmp_name'];
        $filesPayload['error'][] = $file['error'] ?? UPLOAD_ERR_OK;
        $filesPayload['size'][] = $file['size'];
    }

    if (empty($filesPayload['name'])) {
        throw new RuntimeException('没有成功上传的文件（请检查文件大小和格式）');
    }

    $created = $service->uploadFiles(
        $customerId,
        $user,
        $filesPayload,
        [
            'category' => 'internal_solution',
            'notes' => '收款凭证',
        ]
    );

    if (empty($created)) {
        throw new RuntimeException('上传失败');
    }

    $uploadedFiles = [];
    foreach ($created as $createdFile) {
        $fileId = (int)($createdFile['id'] ?? 0);
        if ($fileId <= 0) {
            continue;
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

        $uploadedFiles[] = [
            'file_id' => $fileId,
            'filename' => $createdFile['filename'] ?? '',
            'storage_key' => $createdFile['storage_key'] ?? null,
        ];
    }
    
    Db::commit();
    
    if (empty($uploadedFiles)) {
        throw new RuntimeException('没有成功上传的文件（请检查文件大小和格式）');
    }
    
    echo json_encode([
        'success' => true,
        'message' => '上传成功',
        'data' => [
            'receipt_id' => $receiptId,
            'files' => $uploadedFiles,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
