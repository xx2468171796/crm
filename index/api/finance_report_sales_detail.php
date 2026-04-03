<?php
require_once __DIR__ . '/../core/api_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../core/db.php';
    require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
    require_once __DIR__ . '/../core/dict.php';

    auth_require();
    $user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$salesUserId = (int)($_GET['sales_user_id'] ?? 0);
if ($salesUserId <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少销售人员ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recvStart = trim($_GET['recv_start'] ?? '');
$recvEnd = trim($_GET['recv_end'] ?? '');
$recvMethod = trim($_GET['recv_method'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');

$sql = "SELECT 
    r.id AS receipt_id,
    c.id AS contract_id,
    c.contract_no,
    cu.name AS customer_name,
    i.installment_no,
    r.amount_applied AS amount,
    r.received_date,
    r.method
FROM finance_receipts r
JOIN finance_installments i ON r.installment_id = i.id
JOIN finance_contracts c ON i.contract_id = c.id
JOIN customers cu ON c.customer_id = cu.id
WHERE c.sales_user_id = :sales_user_id
  AND r.amount_applied > 0
  AND i.deleted_at IS NULL";

$params = ['sales_user_id' => $salesUserId];

if ($recvStart !== '') {
    $sql .= " AND r.received_date >= :recv_start";
    $params['recv_start'] = $recvStart;
}
if ($recvEnd !== '') {
    $sql .= " AND r.received_date <= :recv_end";
    $params['recv_end'] = $recvEnd;
}
if ($recvMethod !== '') {
    $sql .= " AND r.method = :recv_method";
    $params['recv_method'] = $recvMethod;
}
if ($activityTag !== '') {
    $sql .= " AND c.activity_tag = :activity_tag";
    $params['activity_tag'] = $activityTag;
}

$sql .= " ORDER BY r.received_date DESC, r.id DESC";

$rows = Db::query($sql, $params);

$totalAmount = 0;
$totalCount = 0;

$paymentMethods = getDictOptions('payment_method');
$methodMap = [];
foreach ($paymentMethods as $opt) {
    $methodMap[$opt['value']] = $opt['label'];
}

$data = [];
foreach ($rows as $row) {
    $methodLabel = $methodMap[$row['method'] ?? ''] ?? ($row['method'] ?? '');
    $data[] = [
        'receipt_id' => (int)($row['receipt_id'] ?? 0),
        'contract_id' => (int)($row['contract_id'] ?? 0),
        'contract_no' => $row['contract_no'] ?? '',
        'customer_name' => $row['customer_name'] ?? '',
        'installment_no' => (int)($row['installment_no'] ?? 0),
        'amount' => (float)($row['amount'] ?? 0),
        'received_date' => $row['received_date'] ?? '',
        'method' => $row['method'] ?? '',
        'method_label' => $methodLabel,
    ];
    $totalAmount += (float)($row['amount'] ?? 0);
    $totalCount++;
}

echo json_encode([
    'success' => true,
    'data' => $data,
    'total' => [
        'amount' => $totalAmount,
        'count' => $totalCount,
    ],
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()], JSON_UNESCAPED_UNICODE);
}
