<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    http_response_code(403);
    echo '无权限';
    exit;
}

// 获取筛选参数
$signStart = trim($_GET['sign_start'] ?? '');
$signEnd = trim($_GET['sign_end'] ?? '');
$recvStart = trim($_GET['recv_start'] ?? '');
$recvEnd = trim($_GET['recv_end'] ?? '');
$dueStart = trim($_GET['due_start'] ?? '');
$dueEnd = trim($_GET['due_end'] ?? '');
$recvMethod = trim($_GET['recv_method'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$salesUserId = (int)($_GET['sales_user_id'] ?? 0);

// 基础条件
$baseWhere = ' WHERE 1=1';
$baseParams = [];

if ($activityTag !== '') {
    $baseWhere .= ' AND cu.activity_tag = :activity_tag';
    $baseParams['activity_tag'] = $activityTag;
}

// 获取所有销售人员
$userRows = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname');
$nameMap = [];
foreach ($userRows as $u) {
    $nameMap[(int)$u['id']] = $u['realname'];
}

// 1) 签约额
$signedSql = 'SELECT c.sales_user_id, SUM(c.net_amount) AS signed_amount
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id'
    . $baseWhere
    . ' AND c.sign_date >= :sign_start AND c.sign_date <= :sign_end'
    . ' GROUP BY c.sales_user_id';

$signedParams = $baseParams;
$signedParams['sign_start'] = $signStart;
$signedParams['sign_end'] = $signEnd;
$signedRows = Db::query($signedSql, $signedParams);
$signedMap = [];
foreach ($signedRows as $r) {
    $signedMap[(int)$r['sales_user_id']] = (float)($r['signed_amount'] ?? 0);
}

// 2) 已收款
$recvSql = 'SELECT c.sales_user_id, SUM(r.amount_applied) AS received_amount
    FROM finance_receipts r
    INNER JOIN finance_contracts c ON c.id = r.contract_id
    INNER JOIN customers cu ON cu.id = r.customer_id'
    . $baseWhere
    . ' AND r.received_date >= :recv_start AND r.received_date <= :recv_end'
    . ($recvMethod !== '' ? ' AND r.method = :recv_method' : '')
    . ' GROUP BY c.sales_user_id';

$recvParams = $baseParams;
$recvParams['recv_start'] = $recvStart;
$recvParams['recv_end'] = $recvEnd;
if ($recvMethod !== '') {
    $recvParams['recv_method'] = $recvMethod;
}
$recvRows = Db::query($recvSql, $recvParams);
$recvMap = [];
foreach ($recvRows as $r) {
    $recvMap[(int)$r['sales_user_id']] = (float)($r['received_amount'] ?? 0);
}

// 3) 应收/逾期
$arSql = 'SELECT c.sales_user_id,
    SUM(CASE WHEN i.due_date >= :due_start AND i.due_date <= :due_end THEN GREATEST(i.amount_due - i.amount_paid, 0) ELSE 0 END) AS receivable,
    SUM(CASE WHEN i.due_date < CURDATE() THEN GREATEST(i.amount_due - i.amount_paid, 0) ELSE 0 END) AS overdue
    FROM finance_installments i
    INNER JOIN finance_contracts c ON c.id = i.contract_id
    INNER JOIN customers cu ON cu.id = i.customer_id'
    . $baseWhere . ' AND i.deleted_at IS NULL'
    . ' GROUP BY c.sales_user_id';

$arParams = $baseParams;
$arParams['due_start'] = $dueStart;
$arParams['due_end'] = $dueEnd;
$arRows = Db::query($arSql, $arParams);
$arMap = [];
foreach ($arRows as $r) {
    $arMap[(int)$r['sales_user_id']] = [
        'receivable' => (float)($r['receivable'] ?? 0),
        'overdue' => (float)($r['overdue'] ?? 0),
    ];
}

// 汇总所有销售ID
$allSalesIds = array_unique(array_merge(
    array_keys($signedMap),
    array_keys($recvMap),
    array_keys($arMap)
));

// 如果指定了销售人员，只导出该销售
if ($salesUserId > 0) {
    $allSalesIds = array_intersect($allSalesIds, [$salesUserId]);
}

// 准备导出数据
$exportData = [];
foreach ($allSalesIds as $sid) {
    $signed = (float)($signedMap[$sid] ?? 0);
    $recv = (float)($recvMap[$sid] ?? 0);
    $ar = (float)($arMap[$sid]['receivable'] ?? 0);
    $overdue = (float)($arMap[$sid]['overdue'] ?? 0);
    
    if ($signed == 0 && $recv == 0 && $ar == 0 && $overdue == 0) {
        continue;
    }
    
    $exportData[] = [
        'sales_name' => $nameMap[$sid] ?? ('ID=' . $sid),
        'signed' => $signed,
        'received' => $recv,
        'receivable' => $ar,
        'overdue' => $overdue,
    ];
}

// 按签约额降序排序
usort($exportData, function($a, $b) {
    return $b['signed'] <=> $a['signed'];
});

// 输出CSV
$filename = 'finance_report_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

// 表头
fputcsv($out, ['销售', '签约额(折后)', '已收款', '应收(到期区间)', '逾期(当前)']);

// 数据
$sumSigned = 0;
$sumReceived = 0;
$sumReceivable = 0;
$sumOverdue = 0;

foreach ($exportData as $row) {
    fputcsv($out, [
        $row['sales_name'],
        number_format($row['signed'], 2, '.', ''),
        number_format($row['received'], 2, '.', ''),
        number_format($row['receivable'], 2, '.', ''),
        number_format($row['overdue'], 2, '.', ''),
    ]);
    $sumSigned += $row['signed'];
    $sumReceived += $row['received'];
    $sumReceivable += $row['receivable'];
    $sumOverdue += $row['overdue'];
}

// 合计行
fputcsv($out, [
    '合计',
    number_format($sumSigned, 2, '.', ''),
    number_format($sumReceived, 2, '.', ''),
    number_format($sumReceivable, 2, '.', ''),
    number_format($sumOverdue, 2, '.', ''),
]);

fclose($out);
