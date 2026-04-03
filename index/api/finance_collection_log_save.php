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

$installmentId = (int)($_POST['installment_id'] ?? 0);
$method = trim($_POST['method'] ?? '');
$result = trim($_POST['result'] ?? '');
$note = trim($_POST['note'] ?? '');

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择分期'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($method) > 30) {
    echo json_encode(['success' => false, 'message' => '方式太长'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($result) > 50) {
    echo json_encode(['success' => false, 'message' => '结果太长'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($note) > 255) {
    echo json_encode(['success' => false, 'message' => '备注最多255字'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $inst = Db::queryOne(
        'SELECT i.id, i.customer_id, i.contract_id, c.sales_user_id
         FROM finance_installments i
         INNER JOIN finance_contracts c ON c.id = i.contract_id
         WHERE i.id = :id AND i.deleted_at IS NULL
         LIMIT 1',
        ['id' => $installmentId]
    );
    if (!$inst) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($user['role'] === 'sales' && (int)($inst['sales_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '无权限操作该分期'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();

    Db::execute(
        'INSERT INTO finance_collection_logs (customer_id, contract_id, installment_id, actor_user_id, action_time, method, result, note)
         VALUES (:customer_id, :contract_id, :installment_id, :actor_user_id, :action_time, :method, :result, :note)',
        [
            'customer_id' => (int)$inst['customer_id'],
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => $installmentId,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'action_time' => $now,
            'method' => $method !== '' ? $method : null,
            'result' => $result !== '' ? $result : null,
            'note' => $note !== '' ? $note : null,
        ]
    );

    $id = (int)Db::lastInsertId();

    Db::commit();

    echo json_encode([
        'success' => true,
        'message' => '已记录',
        'data' => [
            'id' => $id,
            'action_time' => $now,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
