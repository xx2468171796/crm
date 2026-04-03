<?php
/**
 * 手续费报表API
 * 
 * GET ?action=summary - 按收款方式汇总手续费
 * GET ?action=detail&method=wechat - 获取指定收款方式的明细
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'summary';
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

// 默认本月
if ($startDate === '') {
    $startDate = date('Y-m-01');
}
if ($endDate === '') {
    $endDate = date('Y-m-t');
}

switch ($action) {
    case 'summary':
        handleSummary($startDate, $endDate);
        break;
        
    case 'detail':
        $method = trim($_GET['method'] ?? '');
        handleDetail($method, $startDate, $endDate);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
}

function handleSummary($startDate, $endDate) {
    $sql = "
        SELECT 
            r.method,
            COUNT(*) as receipt_count,
            COALESCE(SUM(r.original_amount), 0) as original_total,
            COALESCE(SUM(r.fee_amount), 0) as fee_total,
            COALESCE(SUM(r.amount_received), 0) as received_total
        FROM finance_receipts r
        WHERE r.received_date BETWEEN ? AND ?
        AND r.method IS NOT NULL
        AND r.method != ''
        GROUP BY r.method
        ORDER BY fee_total DESC
    ";
    
    $rows = Db::query($sql, [$startDate, $endDate]);
    
    // 获取支付方式标签
    $methods = getPaymentMethodsWithFee();
    $methodLabels = [];
    foreach ($methods as $m) {
        $methodLabels[$m['code']] = $m['label'];
    }
    
    // 添加标签
    foreach ($rows as &$row) {
        $row['method_label'] = $methodLabels[$row['method']] ?? $row['method'];
        $row['original_total'] = (float)$row['original_total'];
        $row['fee_total'] = (float)$row['fee_total'];
        $row['received_total'] = (float)$row['received_total'];
        $row['receipt_count'] = (int)$row['receipt_count'];
    }
    
    // 计算总计
    $totals = [
        'receipt_count' => 0,
        'original_total' => 0,
        'fee_total' => 0,
        'received_total' => 0,
    ];
    foreach ($rows as $row) {
        $totals['receipt_count'] += $row['receipt_count'];
        $totals['original_total'] += $row['original_total'];
        $totals['fee_total'] += $row['fee_total'];
        $totals['received_total'] += $row['received_total'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'rows' => $rows,
            'totals' => $totals,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handleDetail($method, $startDate, $endDate) {
    if ($method === '') {
        echo json_encode(['success' => false, 'message' => '请指定收款方式'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $sql = "
        SELECT 
            r.id,
            r.received_date,
            r.original_amount,
            r.fee_type,
            r.fee_value,
            r.fee_amount,
            r.amount_received,
            r.method,
            r.currency,
            r.note,
            c.name as customer_name,
            c.customer_code,
            fc.contract_no,
            fc.title as contract_title
        FROM finance_receipts r
        LEFT JOIN customers c ON r.customer_id = c.id
        LEFT JOIN finance_contracts fc ON r.contract_id = fc.id
        WHERE r.received_date BETWEEN ? AND ?
        AND r.method = ?
        ORDER BY r.received_date DESC, r.id DESC
        LIMIT 500
    ";
    
    $rows = Db::query($sql, [$startDate, $endDate, $method]);
    
    // 格式化数据
    foreach ($rows as &$row) {
        $row['original_amount'] = (float)($row['original_amount'] ?? 0);
        $row['fee_amount'] = (float)($row['fee_amount'] ?? 0);
        $row['amount_received'] = (float)($row['amount_received'] ?? 0);
        $row['fee_value'] = $row['fee_value'] !== null ? (float)$row['fee_value'] : null;
    }
    
    // 获取支付方式标签
    $methodLabel = getPaymentMethodLabel($method);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'method' => $method,
            'method_label' => $methodLabel,
            'rows' => $rows,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]
    ], JSON_UNESCAPED_UNICODE);
}
