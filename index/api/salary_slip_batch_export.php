<?php
/**
 * 批量工资条Excel导出
 * 导出指定月份所有员工的工资条汇总表
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '无权访问']);
    exit;
}

$month = $_GET['month'] ?? date('Y-m');

// 获取所有有工资数据的员工
$users = Db::query(
    "SELECT DISTINCT u.id, u.realname, u.username, u.department_id, d.name as dept_name
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     LEFT JOIN salary_user_monthly s ON u.id = s.user_id AND s.month = ?
     WHERE u.status = 'active' AND (s.id IS NOT NULL OR EXISTS (
         SELECT 1 FROM finance_contracts c WHERE c.sales_user_id = u.id AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?
     ))
     ORDER BY d.name, u.realname",
    [$month, $month]
);

// 获取提成规则
$ruleSet = Db::queryOne('SELECT id FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
$ruleId = $ruleSet['id'] ?? 0;
$tiers = $ruleId ? Db::query('SELECT tier_from, tier_to, rate FROM commission_rule_tiers WHERE rule_set_id = ? ORDER BY tier_from ASC', [$ruleId]) : [];

// 获取单个员工的工资条数据
function getSlipData($userId, $month, $tiers) {
    $userInfo = Db::queryOne('SELECT id, realname, username, department_id FROM users WHERE id = ?', [$userId]);
    if (!$userInfo) return null;
    
    $dept = Db::queryOne('SELECT name FROM departments WHERE id = ?', [$userInfo['department_id'] ?? 0]);
    $deptName = $dept['name'] ?? '';
    
    $monthlyData = Db::queryOne('SELECT * FROM salary_user_monthly WHERE user_id = ? AND month = ?', [$userId, $month]);
    
    $baseSalary = floatval($monthlyData['base_salary'] ?? 0);
    $attendance = floatval($monthlyData['attendance'] ?? 0);
    $adjustment = floatval($monthlyData['adjustment'] ?? 0);
    $deduction = floatval($monthlyData['deduction'] ?? 0);
    $incentive = floatval($monthlyData['incentive'] ?? 0);
    
    // 获取本月新单
    $newOrders = Db::query(
        "SELECT r.amount_received as amount
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') = ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    // 获取往期分期
    $installments = Db::query(
        "SELECT r.amount_received as amount
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') < ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    // 计算档位基数
    $tierContracts = Db::query(
        "SELECT c.net_amount as amount FROM finance_contracts c WHERE c.sales_user_id = ? AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?",
        [$userId, $month]
    );
    $tierBase = 0;
    foreach ($tierContracts as $tc) { $tierBase += floatval($tc['amount']); }
    
    // 确定档位比例
    $tierRate = 0;
    foreach ($tiers as $tier) {
        if ($tierBase >= $tier['tier_from'] && ($tier['tier_to'] === null || $tierBase < $tier['tier_to'])) { $tierRate = floatval($tier['rate']); break; }
    }
    if ($tierRate == 0 && count($tiers) > 0) { $tierRate = floatval($tiers[0]['rate']); }
    
    // 计算提成
    $part1Amount = 0;
    foreach ($newOrders as $o) { $part1Amount += floatval($o['amount']); }
    $part1Commission = $part1Amount * $tierRate;
    
    $part2Amount = 0;
    foreach ($installments as $i) { $part2Amount += floatval($i['amount']); }
    $part2Commission = $part2Amount * $tierRate;
    
    $totalCommission = $part1Commission + $part2Commission;
    $total = $baseSalary + $attendance + $totalCommission + $incentive + $adjustment - $deduction;
    
    return [
        'user_name' => $userInfo['realname'] ?: $userInfo['username'],
        'department' => $deptName,
        'base_salary' => $baseSalary,
        'attendance' => $attendance,
        'tier_base' => $tierBase,
        'tier_rate' => $tierRate,
        'part1_amount' => $part1Amount,
        'part1_commission' => $part1Commission,
        'part2_amount' => $part2Amount,
        'part2_commission' => $part2Commission,
        'total_commission' => $totalCommission,
        'incentive' => $incentive,
        'adjustment' => $adjustment,
        'deduction' => $deduction,
        'total' => $total,
    ];
}

// 收集所有员工数据
$allSlips = [];
foreach ($users as $u) {
    $slip = getSlipData($u['id'], $month, $tiers);
    if ($slip) {
        $allSlips[] = $slip;
    }
}

// 生成Excel (CSV格式，兼容性好)
$filename = $month . '_全员工资条.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 添加BOM以支持Excel正确识别UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// 表头
fputcsv($output, [
    '员工姓名',
    '部门',
    '底薪',
    '全勤奖',
    '基本工资小计',
    '档位基数',
    '档位比例',
    '新单收款',
    '新单提成',
    '分期收款',
    '分期提成',
    '提成小计',
    '激励奖金',
    '手动调整',
    '扣款',
    '应发工资'
]);

// 数据行
foreach ($allSlips as $slip) {
    fputcsv($output, [
        $slip['user_name'],
        $slip['department'],
        number_format($slip['base_salary'], 2),
        number_format($slip['attendance'], 2),
        number_format($slip['base_salary'] + $slip['attendance'], 2),
        number_format($slip['tier_base'], 2),
        number_format($slip['tier_rate'] * 100, 1) . '%',
        number_format($slip['part1_amount'], 2),
        number_format($slip['part1_commission'], 2),
        number_format($slip['part2_amount'], 2),
        number_format($slip['part2_commission'], 2),
        number_format($slip['total_commission'], 2),
        number_format($slip['incentive'], 2),
        number_format($slip['adjustment'], 2),
        number_format($slip['deduction'], 2),
        number_format($slip['total'], 2),
    ]);
}

// 汇总行
fputcsv($output, []);
fputcsv($output, [
    '合计',
    count($allSlips) . '人',
    number_format(array_sum(array_column($allSlips, 'base_salary')), 2),
    number_format(array_sum(array_column($allSlips, 'attendance')), 2),
    number_format(array_sum(array_map(fn($s) => $s['base_salary'] + $s['attendance'], $allSlips)), 2),
    '',
    '',
    number_format(array_sum(array_column($allSlips, 'part1_amount')), 2),
    number_format(array_sum(array_column($allSlips, 'part1_commission')), 2),
    number_format(array_sum(array_column($allSlips, 'part2_amount')), 2),
    number_format(array_sum(array_column($allSlips, 'part2_commission')), 2),
    number_format(array_sum(array_column($allSlips, 'total_commission')), 2),
    number_format(array_sum(array_column($allSlips, 'incentive')), 2),
    number_format(array_sum(array_column($allSlips, 'adjustment')), 2),
    number_format(array_sum(array_column($allSlips, 'deduction')), 2),
    number_format(array_sum(array_column($allSlips, 'total')), 2),
]);

fclose($output);
