<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

$method = trim($_GET['method'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$salesUserId = (int)($_GET['sales_user_id'] ?? 0);
$groupBy = trim($_GET['group_by'] ?? 'method');

if ($startDate === '' || $endDate === '') {
    echo json_encode(['success' => false, 'message' => '请提供日期范围']);
    exit;
}

if ($user['role'] === 'sales') {
    $salesUserId = (int)$user['id'];
}

$params = [
    'start_date' => $startDate,
    'end_date' => $endDate,
];

$sql = "SELECT 
    r.id AS receipt_id,
    c.id AS contract_id,
    c.contract_no,
    cu.name AS customer_name,
    i.installment_no,
    r.amount_applied AS amount,
    r.received_date,
    r.create_time AS receipt_time,
    r.method,
    u.realname AS sales_name
FROM finance_receipts r
LEFT JOIN finance_installments i ON r.installment_id = i.id
LEFT JOIN finance_contracts c ON i.contract_id = c.id
LEFT JOIN customers cu ON c.customer_id = cu.id
LEFT JOIN users u ON c.sales_user_id = u.id
WHERE r.received_date BETWEEN :start_date AND :end_date
  AND r.amount_applied > 0";

// 根据分组类型添加筛选条件
if ($groupBy === 'user') {
    // 按人员查询时，userId 是分组 key
    if ($userId > 0) {
        $sql .= " AND c.sales_user_id = :user_id";
        $params['user_id'] = $userId;
    } else {
        $sql .= " AND (c.sales_user_id IS NULL OR c.sales_user_id = 0)";
    }
} else {
    // 按收款方式查询
    if ($method === '' || $method === null) {
        $sql .= " AND (r.method IS NULL OR r.method = '')";
    } else {
        $sql .= " AND r.method = :method";
        $params['method'] = $method;
    }
    // 只在按收款方式分组时应用 salesUserId 筛选
    if ($salesUserId > 0) {
        $sql .= " AND c.sales_user_id = :sales_user_id";
        $params['sales_user_id'] = $salesUserId;
    }
}

$sql .= " ORDER BY r.received_date DESC, r.id DESC";

$rows = Db::query($sql, $params);

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'receipt_id' => (int)($row['receipt_id'] ?? 0),
        'contract_id' => (int)($row['contract_id'] ?? 0),
        'contract_no' => (string)($row['contract_no'] ?? ''),
        'customer_name' => (string)($row['customer_name'] ?? ''),
        'installment_no' => (int)($row['installment_no'] ?? 0),
        'amount' => (float)($row['amount'] ?? 0),
        'received_date' => (string)($row['received_date'] ?? ''),
        'receipt_time' => !empty($row['receipt_time']) ? date('Y-m-d H:i', (int)$row['receipt_time']) : '',
        'method' => (string)($row['method'] ?? ''),
        'method_label' => getPaymentMethodLabel($row['method'] ?? ''),
        'sales_name' => (string)($row['sales_name'] ?? ''),
    ];
}

echo json_encode([
    'success' => true,
    'data' => $data,
]);
