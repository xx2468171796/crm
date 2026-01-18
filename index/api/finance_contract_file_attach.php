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

$contractId = (int)($_POST['contract_id'] ?? 0);
$fileIdsRaw = $_POST['file_ids'] ?? [];

if ($contractId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：contract_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileIds = [];
if (is_string($fileIdsRaw)) {
    $decoded = json_decode($fileIdsRaw, true);
    if (is_array($decoded)) {
        $fileIds = $decoded;
    } else {
        $fileIds = [$fileIdsRaw];
    }
} elseif (is_array($fileIdsRaw)) {
    $fileIds = $fileIdsRaw;
}

$fileIds = array_values(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0));
$fileIds = array_values(array_unique($fileIds));

if (empty($fileIds)) {
    echo json_encode(['success' => false, 'message' => '请选择至少一个文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contract = Db::queryOne(
    'SELECT c.id, c.customer_id, c.sales_user_id, cu.owner_user_id
     FROM finance_contracts c
     INNER JOIN customers cu ON cu.id = c.customer_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $contractId]
);

if (!$contract) {
    echo json_encode(['success' => false, 'message' => '合同不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($user['role'] ?? '') === 'sales') {
    if ((int)($contract['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        echo json_encode(['success' => false, 'message' => '无权限：只能操作自己名下客户的合同'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$customerId = (int)$contract['customer_id'];
$uid = (int)($user['id'] ?? 0);
$now = time();

try {
    Db::beginTransaction();

    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $files = Db::query(
        'SELECT id, customer_id, category, deleted_at
         FROM customer_files
         WHERE id IN (' . $placeholders . ')',
        $fileIds
    );

    $valid = [];
    foreach ($files as $f) {
        if ((int)($f['customer_id'] ?? 0) !== $customerId) {
            continue;
        }
        if (($f['category'] ?? '') !== 'internal_solution') {
            continue;
        }
        if (!empty($f['deleted_at'])) {
            continue;
        }
        $valid[] = (int)$f['id'];
    }

    if (empty($valid)) {
        throw new Exception('所选文件无效（需为该客户的公司文件且未删除）');
    }

    foreach ($valid as $fid) {
        Db::execute(
            'INSERT IGNORE INTO finance_contract_files (contract_id, customer_id, file_id, created_by, created_at)
             VALUES (:contract_id, :customer_id, :file_id, :created_by, :created_at)',
            [
                'contract_id' => $contractId,
                'customer_id' => $customerId,
                'file_id' => $fid,
                'created_by' => $uid,
                'created_at' => $now,
            ]
        );
    }

    Db::commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'contract_id' => $contractId,
            'attached_file_ids' => $valid,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    Db::rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
