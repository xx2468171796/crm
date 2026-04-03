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

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$salesUserId = (int)($_GET['sales_user_id'] ?? 0);
$groupBy = trim($_GET['group_by'] ?? 'method'); // method 或 user

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

// 根据分组类型构建不同的 SQL
if ($groupBy === 'user') {
    // 按销售人员分组
    $sql = "SELECT 
        c.sales_user_id AS user_id,
        u.realname AS user_name,
        u.username,
        SUM(r.amount_applied) AS total_amount,
        COUNT(*) AS count
    FROM finance_receipts r
    LEFT JOIN finance_installments i ON r.installment_id = i.id
    LEFT JOIN finance_contracts c ON i.contract_id = c.id
    LEFT JOIN users u ON c.sales_user_id = u.id
    WHERE r.received_date BETWEEN :start_date AND :end_date
      AND r.amount_applied > 0";
    
    if ($salesUserId > 0) {
        $sql .= " AND c.sales_user_id = :sales_user_id";
        $params['sales_user_id'] = $salesUserId;
    }
    
    $sql .= " GROUP BY c.sales_user_id, u.realname, u.username ORDER BY total_amount DESC";
} else {
    // 按收款方式分组（默认）
    $sql = "SELECT 
        r.method,
        SUM(r.amount_applied) AS total_amount,
        COUNT(*) AS count
    FROM finance_receipts r
    LEFT JOIN finance_installments i ON r.installment_id = i.id
    LEFT JOIN finance_contracts c ON i.contract_id = c.id
    WHERE r.received_date BETWEEN :start_date AND :end_date
      AND r.amount_applied > 0";

    if ($salesUserId > 0) {
        $sql .= " AND c.sales_user_id = :sales_user_id";
        $params['sales_user_id'] = $salesUserId;
    }

    $sql .= " GROUP BY r.method ORDER BY total_amount DESC";
}

$rows = Db::query($sql, $params);

$summary = [];
$totalAmount = 0.0;
$totalCount = 0;

foreach ($rows as $row) {
    $amount = (float)($row['total_amount'] ?? 0);
    $count = (int)($row['count'] ?? 0);
    
    if ($groupBy === 'user') {
        $summary[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'user_name' => (string)($row['user_name'] ?: $row['username'] ?? '未知'),
            'total_amount' => $amount,
            'count' => $count,
        ];
    } else {
        $method = (string)($row['method'] ?? '');
        $methodLabel = $method !== '' ? getPaymentMethodLabel($method) : '';
        if ($methodLabel === '' || $methodLabel === $method) {
            $methodLabel = $method !== '' ? $method : '未填写';
        }
        $summary[] = [
            'method' => $method,
            'method_label' => $methodLabel,
            'total_amount' => $amount,
            'count' => $count,
        ];
    }
    
    $totalAmount += $amount;
    $totalCount += $count;
}

echo json_encode([
    'success' => true,
    'data' => [
        'summary' => $summary,
        'total' => [
            'amount' => $totalAmount,
            'count' => $totalCount,
        ],
    ],
]);
