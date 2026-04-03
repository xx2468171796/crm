<?php
/**
 * 获取个人工资条数据API
 * 支持员工查看自己的工资条，管理员查看任意员工的工资条
 * 提成数据直接从commission_calculate.php获取，确保与提成报表一致
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

$month = $_GET['month'] ?? date('Y-m');
$targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user['id'];
$displayCurrency = trim($_GET['display_currency'] ?? 'CNY');
$rateType = trim($_GET['rate_type'] ?? 'fixed');

// 权限检查：员工只能查看自己的，管理员可查看所有
$isAdmin = canOrAdmin(PermissionCode::FINANCE_VIEW);
if (!$isAdmin && $targetUserId != $user['id']) {
    echo json_encode(['success' => false, 'message' => '无权查看他人工资条'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 加载汇率
    $allCurrencies = Db::query("SELECT code, fixed_rate, floating_rate FROM currencies WHERE status = 1");
    $currencyRates = [];
    foreach ($allCurrencies as $cur) {
        $currencyRates[$cur['code']] = [
            'fixed' => (float)($cur['fixed_rate'] ?? 1),
            'floating' => (float)($cur['floating_rate'] ?? 1),
        ];
    }
    
    function convertCurrencySlip($amount, $fromCurrency, $toCurrency, $currencyRates, $rateType) {
        if ($fromCurrency === $toCurrency) return $amount;
        $fromRate = $currencyRates[$fromCurrency][$rateType] ?? 1;
        $toRate = $currencyRates[$toCurrency][$rateType] ?? 1;
        $amountInCNY = $amount / $fromRate;
        return $amountInCNY * $toRate;
    }
    
    // 获取用户基本信息
    $userInfo = Db::queryOne('SELECT id, realname, username, department_id FROM users WHERE id = ?', [$targetUserId]);
    if (!$userInfo) {
        echo json_encode(['success' => false, 'message' => '用户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取部门名称
    $dept = Db::queryOne('SELECT name FROM departments WHERE id = ?', [$userInfo['department_id'] ?? 0]);
    $deptName = $dept['name'] ?? '';
    
    // 获取月度工资数据
    $monthlyData = Db::queryOne(
        'SELECT * FROM salary_user_monthly WHERE user_id = ? AND month = ?',
        [$targetUserId, $month]
    );
    
    $baseSalary = floatval($monthlyData['base_salary'] ?? 0);
    $attendance = floatval($monthlyData['attendance'] ?? 0);
    $adjustment = floatval($monthlyData['adjustment'] ?? 0);
    $deduction = floatval($monthlyData['deduction'] ?? 0);
    $incentive = floatval($monthlyData['incentive'] ?? 0);
    
    // 获取活动的提成规则
    $ruleSet = Db::queryOne('SELECT id, currency FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
    $ruleId = $ruleSet['id'] ?? 0;
    $ruleCurrency = $ruleSet['currency'] ?? 'TWD';
    
    // 调用commission_calculate API获取提成数据
    $commissionData = null;
    if ($ruleId > 0) {
        // 内部调用commission_calculate逻辑
        $_REQUEST['month'] = $month;
        $_REQUEST['rule_id'] = $ruleId;
        $_REQUEST['user_id'] = $targetUserId;
        $_REQUEST['display_currency'] = $displayCurrency;
        $_REQUEST['rate_type'] = $rateType;
        
        ob_start();
        include __DIR__ . '/commission_calculate.php';
        $output = ob_get_clean();
        $commissionResult = json_decode($output, true);
        
        if ($commissionResult && $commissionResult['success'] && isset($commissionResult['data']['summary'])) {
            foreach ($commissionResult['data']['summary'] as $s) {
                if ((int)$s['user_id'] === $targetUserId) {
                    $commissionData = $s;
                    // 同时获取details
                    $commissionDetails = $commissionResult['data']['details'][strval($targetUserId)] ?? null;
                    break;
                }
            }
        }
    }
    
    // 从提成数据中提取信息（字段映射）
    $tierBase = $commissionData['tier_base'] ?? 0;
    $tierRate = $commissionData['tier_rate'] ?? 0;
    // commission_calculate返回的字段名是 new_order_commission 和 installment_commission
    $part1Commission = $commissionData['new_order_commission_display'] ?? ($commissionData['new_order_commission'] ?? 0);
    $part2Commission = $commissionData['installment_commission_display'] ?? ($commissionData['installment_commission'] ?? 0);
    $totalCommission = $commissionData['commission_display'] ?? ($commissionData['commission'] ?? 0);
    
    // 从details中获取明细数据
    $newOrdersData = [];
    $installmentsData = [];
    $tierContracts = [];
    
    if (isset($commissionDetails)) {
        // 档位基数明细
        $tierContracts = $commissionDetails['tier_contracts'] ?? [];
        
        // Part1明细（本月新单）
        foreach (($commissionDetails['new_orders'] ?? []) as $d) {
            $newOrdersData[] = [
                'contract_id' => $d['contract_id'] ?? 0,
                'contract_name' => $d['contract_name'] ?? '',
                'customer' => $d['customer_name'] ?? '',
                'amount' => $d['amount'] ?? 0,
                'amount_in_rule' => $d['amount_in_rule'] ?? 0,
                'rate' => $d['rate'] ?? 0,
                'commission' => $d['commission'] ?? 0,
                'collector' => $d['collector'] ?? '',
                'method' => $d['method'] ?? '',
                'currency' => $d['currency'] ?? $ruleCurrency,
            ];
        }
        
        // Part2明细（往期分期）
        foreach (($commissionDetails['installments'] ?? []) as $i) {
            $installmentsData[] = [
                'contract_id' => $i['contract_id'] ?? 0,
                'contract_name' => $i['contract_name'] ?? '',
                'customer' => $i['customer_name'] ?? '',
                'sign_month' => $i['sign_month'] ?? '',
                'amount' => $i['amount'] ?? 0,
                'amount_in_rule' => $i['amount_in_rule'] ?? 0,
                'rate' => $i['rate'] ?? 0,
                'commission' => $i['commission'] ?? 0,
                'collector' => $i['collector'] ?? '',
                'method' => $i['method'] ?? '',
                'currency' => $i['currency'] ?? $ruleCurrency,
            ];
        }
    }
    
    $total = $baseSalary + $attendance + $totalCommission + $incentive + $adjustment - $deduction;
    
    $totalInDisplay = convertCurrencySlip($total, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
    $commissionInDisplay = convertCurrencySlip($totalCommission, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'user_id' => $targetUserId,
            'user_name' => $userInfo['realname'] ?: $userInfo['username'],
            'department' => $deptName,
            'month' => $month,
            'rule_currency' => $ruleCurrency,
            'display_currency' => $displayCurrency,
            'rate_type' => $rateType,
            'basic' => [
                'base_salary' => $baseSalary,
                'attendance' => $attendance,
                'subtotal' => $baseSalary + $attendance,
            ],
            'commission' => [
                'tier_base' => round($tierBase, 2),
                'tier_rate' => $tierRate,
                'tier_contracts' => $tierContracts,
                'part1_commission' => round($part1Commission, 2),
                'part2_commission' => round($part2Commission, 2),
                'new_orders' => $newOrdersData,
                'installments' => $installmentsData,
                'subtotal' => round($totalCommission, 2),
                'subtotal_display' => round($commissionInDisplay, 2),
            ],
            'other' => [
                'incentive' => $incentive,
                'adjustment' => $adjustment,
                'deduction' => $deduction,
            ],
            'total' => round($total, 2),
            'total_display' => round($totalInDisplay, 2),
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '获取工资条失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
