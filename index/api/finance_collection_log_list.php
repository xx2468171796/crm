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
$limit = (int)($_GET['limit'] ?? 20);
if ($limit <= 0 || $limit > 100) {
    $limit = 20;
}

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inst = Db::queryOne(
    'SELECT i.id, i.contract_id, i.customer_id, c.sales_user_id
     FROM finance_installments i
     INNER JOIN finance_contracts c ON c.id = i.contract_id
     WHERE i.id = :id AND i.deleted_at IS NULL LIMIT 1',
    ['id' => $installmentId]
);

if (!$inst) {
    echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($user['role'] === 'sales' && (int)$inst['sales_user_id'] !== (int)$user['id']) {
    echo json_encode(['success' => false, 'message' => '无权限查看该分期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = Db::query(
    'SELECT
        l.id,
        l.customer_id,
        l.contract_id,
        l.installment_id,
        l.actor_user_id,
        l.action_time,
        l.method,
        l.result,
        l.note,
        u.realname AS actor_name
     FROM finance_collection_logs l
     INNER JOIN finance_installments i ON i.id = l.installment_id AND i.deleted_at IS NULL
     LEFT JOIN users u ON u.id = l.actor_user_id
     WHERE l.installment_id = :iid
     ORDER BY l.id DESC
     LIMIT ' . (int)$limit,
    ['iid' => $installmentId]
);

$data = [];
foreach ($rows as $r) {
    $data[] = [
        'id' => (int)($r['id'] ?? 0),
        'action_time' => (int)($r['action_time'] ?? 0),
        'method' => $r['method'],
        'result' => $r['result'],
        'note' => $r['note'],
        'actor_user_id' => (int)($r['actor_user_id'] ?? 0),
        'actor_name' => $r['actor_name'] ?? '',
    ];
}

echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
