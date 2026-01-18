<?php
/**
 * 提成计算接口
 * 根据配置的规则计算销售人员的月度提成
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$month = trim($_REQUEST['month'] ?? date('Y-m'));
$ruleId = (int)($_REQUEST['rule_id'] ?? 0);
$userId = (int)($_REQUEST['user_id'] ?? 0);
$deptId = (int)($_REQUEST['department_id'] ?? 0);
$displayCurrency = trim($_REQUEST['display_currency'] ?? 'CNY');
$rateType = trim($_REQUEST['rate_type'] ?? 'fixed');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => '月份格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ruleId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择提成规则'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $monthStart = strtotime($month . '-01 00:00:00');
    $monthEnd = strtotime(date('Y-m-t 23:59:59', $monthStart));
    
    $userFilter = '';
    $params = [];
    if ($userId > 0) {
        $userFilter = ' AND u.id = ?';
        $params[] = $userId;
    }
    if ($deptId > 0) {
        $userFilter .= ' AND u.department_id = ?';
        $params[] = $deptId;
    }
    
    $salesUsers = Db::query(
        "SELECT u.id, u.realname, u.department_id, d.name as dept_name
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE u.status = 1 {$userFilter}
         ORDER BY u.realname",
        $params
    );
    
    $selectedRule = Db::queryOne(
        "SELECT * FROM commission_rule_sets WHERE id = ? AND is_active = 1",
        [$ruleId]
    );
    
    if (!$selectedRule) {
        echo json_encode(['success' => false, 'message' => '选定的规则不存在或已禁用'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $ruleTiers = [];
    $tiers = Db::query("SELECT * FROM commission_rule_tiers WHERE rule_set_id = ? ORDER BY sort_order", [$ruleId]);
    foreach ($tiers as $t) {
        $ruleTiers[] = $t;
    }
    
    $adjustments = Db::query(
        "SELECT * FROM commission_adjustments WHERE month = ?",
        [$month]
    );
    $adjMap = [];
    foreach ($adjustments as $a) {
        $uid = (int)$a['user_id'];
        if (!isset($adjMap[$uid])) $adjMap[$uid] = [];
        $adjMap[$uid][] = $a;
    }
    
    $summary = [];
    $details = [];
    
    $ruleType = $selectedRule['rule_type'] ?? 'fixed';
    $fixedRate = (float)($selectedRule['fixed_rate'] ?? 0);
    $ruleCurrency = $selectedRule['currency'] ?? 'CNY';
    
    // 解析工资组成配置，获取各项的货币、类型和默认值
    $salaryConfig = $selectedRule['salary_config'] ? json_decode($selectedRule['salary_config'], true) : null;
    $componentCurrencies = [];
    $componentDefaults = [];
    if ($salaryConfig && !empty($salaryConfig['components'])) {
        foreach ($salaryConfig['components'] as $comp) {
            $componentCurrencies[$comp['code']] = $comp['currency'] ?? 'CNY';
            // 对于fixed类型，记录默认值
            if (($comp['type'] ?? '') === 'fixed') {
                $componentDefaults[$comp['code']] = (float)($comp['default'] ?? 0);
            }
        }
    }
    // 默认货币配置
    if (empty($componentCurrencies)) {
        $componentCurrencies = [
            'base_salary' => 'CNY',
            'attendance' => 'CNY',
            'commission' => 'CNY',
            'incentive' => 'CNY',
            'adjustment' => 'CNY',
            'deduction' => 'CNY',
        ];
    }
    
    $allCurrencies = Db::query("SELECT code, fixed_rate, floating_rate FROM currencies WHERE status = 1");
    $currencyRates = [];
    foreach ($allCurrencies as $cur) {
        $currencyRates[$cur['code']] = [
            'fixed' => (float)($cur['fixed_rate'] ?? 1),
            'floating' => (float)($cur['floating_rate'] ?? 1),
        ];
    }
    
    function convertCurrency($amount, $fromCurrency, $toCurrency, $currencyRates, $rateType) {
        if ($fromCurrency === $toCurrency) return $amount;
        // rate表示 1 CNY = rate单位该货币 (如 USD rate=0.14 表示 1 CNY = 0.14 USD)
        // CNY是基准货币，rate=1
        $fromRate = $currencyRates[$fromCurrency][$rateType] ?? 1;
        $toRate = $currencyRates[$toCurrency][$rateType] ?? 1;
        // 先转成CNY：amount / fromRate
        // 再转成目标货币：amountInCNY * toRate
        $amountInCNY = $amount / $fromRate;
        return $amountInCNY * $toRate;
    }
    
    // 计算指定用户在指定月份的档位率和档位基数
    function getTierRateForMonth($userId, $signMonth, $ruleTiers, $ruleType, $fixedRate, $ruleCurrency, $currencyRates, $rateType, $returnBase = false) {
        if ($ruleType === 'fixed') {
            return $returnBase ? ['rate' => $fixedRate, 'base' => 0, 'contracts' => []] : $fixedRate;
        }
        
        // 计算签约月份的业绩总额
        $signMonthStart = strtotime($signMonth . '-01');
        $signMonthEnd = strtotime(date('Y-m-t', $signMonthStart));
        
        // 按客户归属人查询合同（不是合同签约人）
        $monthContracts = Db::query(
            "SELECT c.id, c.title, c.net_amount, c.currency, c.customer_id, cu.owner_user_id
             FROM finance_contracts c
             LEFT JOIN customers cu ON c.customer_id = cu.id
             WHERE cu.owner_user_id = ? AND c.sign_date >= ? AND c.sign_date <= ?",
            [$userId, date('Y-m-d', $signMonthStart), date('Y-m-d', $signMonthEnd)]
        );
        
        $monthTierBase = 0;
        $contractDetails = [];
        foreach ($monthContracts as $mc) {
            $mcAmount = (float)($mc['net_amount'] ?? 0);
            $mcCurrency = $mc['currency'] ?? 'TWD';
            $amountInRule = convertCurrency($mcAmount, $mcCurrency, $ruleCurrency, $currencyRates, $rateType);
            $monthTierBase += $amountInRule;
            $contractDetails[] = [
                'id' => (int)$mc['id'],
                'name' => $mc['title'] ?? '',
                'amount' => $mcAmount,
                'currency' => $mcCurrency,
                'amount_in_rule' => round($amountInRule, 2),
            ];
        }
        
        // 根据业绩总额确定档位
        $monthTierRate = 0;
        foreach ($ruleTiers as $t) {
            $from = (float)($t['tier_from'] ?? 0);
            $to = $t['tier_to'] !== null ? (float)$t['tier_to'] : PHP_FLOAT_MAX;
            if ($monthTierBase >= $from && $monthTierBase < $to) {
                $monthTierRate = (float)($t['rate'] ?? 0);
                break;
            }
        }
        
        return $returnBase ? ['rate' => $monthTierRate, 'base' => $monthTierBase, 'contracts' => $contractDetails] : $monthTierRate;
    }
    
    $salaryDataMap = [];
    $salaryDataRows = Db::query("SELECT user_id, base_salary, attendance, incentive, adjustment, deduction FROM salary_user_monthly WHERE month = ?", [$month]);
    foreach ($salaryDataRows as $row) {
        $salaryDataMap[(int)$row['user_id']] = $row;
    }
    
    foreach ($salesUsers as $su) {
        $uid = (int)$su['id'];
        $userDeptId = (int)($su['department_id'] ?? 0);
        $userSalary = $salaryDataMap[$uid] ?? [];
        
        // 如果没有该月的工资记录，对于fixed类型字段使用默认值
        if (empty($userSalary)) {
            $userSalary = [];
            foreach ($componentDefaults as $code => $defaultVal) {
                $userSalary[$code] = $defaultVal;
            }
        }
        
        // 档位基数按客户归属人计算（不是合同签约人）
        $contracts = Db::query(
            "SELECT c.*, cu.name as customer_name, cu.owner_user_id
             FROM finance_contracts c
             LEFT JOIN customers cu ON c.customer_id = cu.id
             WHERE cu.owner_user_id = ? AND c.sign_date >= ? AND c.sign_date <= ?",
            [$uid, date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd)]
        );
        
        $tierBase = 0;
        $tierContracts = [];
        foreach ($contracts as $c) {
            $originalAmount = (float)($c['net_amount'] ?? 0);
            $contractCurrency = $c['currency'] ?? 'TWD';
            $amountInRuleCurrency = convertCurrency($originalAmount, $contractCurrency, $ruleCurrency, $currencyRates, $rateType);
            $tierBase += $amountInRuleCurrency;
            $tierContracts[] = [
                'id' => (int)$c['id'],
                'name' => $c['title'] ?? '',
                'customer' => $c['customer_name'] ?? '',
                'amount' => $originalAmount,
                'currency' => $contractCurrency,
                'amount_in_rule' => round($amountInRuleCurrency, 2),
                'type' => (int)($c['is_first_contract'] ?? 0) === 1 ? '首单' : '复购',
            ];
        }
        
        $tierRate = 0;
        if ($ruleType === 'fixed') {
            $tierRate = $fixedRate;
        } else {
            foreach ($ruleTiers as $t) {
                $from = (float)($t['tier_from'] ?? 0);
                $to = $t['tier_to'] !== null ? (float)$t['tier_to'] : PHP_FLOAT_MAX;
                if ($tierBase >= $from && $tierBase < $to) {
                    $tierRate = (float)($t['rate'] ?? 0);
                    break;
                }
            }
        }
        
        // 首单提成按客户归属人计算，复购不计提成
        // 查询该用户名下客户的首单回款（按 owner_user_id 匹配）
        $receipts = Db::query(
            "SELECT r.*, c.title as contract_name, c.sign_date as contract_sign_date,
                    c.is_first_contract, c.locked_commission_rate, c.sales_user_id,
                    cu.name as customer_name, cu.owner_user_id, u.realname as collector_name
             FROM finance_receipts r
             LEFT JOIN finance_contracts c ON r.contract_id = c.id
             LEFT JOIN customers cu ON c.customer_id = cu.id
             LEFT JOIN users u ON r.collector_user_id = u.id
             WHERE cu.owner_user_id = ? AND r.received_date >= ? AND r.received_date <= ?
             ORDER BY r.received_date",
            [$uid, date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd)]
        );
        
        $newOrderCommission = 0;
        $newOrders = [];
        $installmentCommission = 0;
        $installments = [];
        
        foreach ($receipts as $r) {
            $receiptAmount = (float)($r['amount_received'] ?? 0);
            $receiptCurrency = $r['currency'] ?? 'TWD';
            $amountInRuleCurrency = convertCurrency($receiptAmount, $receiptCurrency, $ruleCurrency, $currencyRates, $rateType);
            
            $contractSignDate = $r['contract_sign_date'] ?? '';
            $contractSignTs = $contractSignDate ? strtotime($contractSignDate) : 0;
            $isCurrentMonth = ($contractSignTs >= $monthStart && $contractSignTs <= $monthEnd);
            $isFirstContract = (int)($r['is_first_contract'] ?? 0) === 1;
            
            // 只有首单才算提成，复购不算
            if (!$isFirstContract) {
                continue;
            }
            
            if ($isCurrentMonth) {
                $comm = $amountInRuleCurrency * $tierRate;
                $commDisplay = convertCurrency($comm, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
                $amountDisplay = convertCurrency($amountInRuleCurrency, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
                $newOrderCommission += $comm;
                $newOrders[] = [
                    'id' => (int)$r['id'],
                    'contract_id' => (int)($r['contract_id'] ?? 0),
                    'contract_name' => $r['contract_name'] ?? '',
                    'customer' => $r['customer_name'] ?? '',
                    'amount' => $receiptAmount,
                    'currency' => $receiptCurrency,
                    'amount_in_rule' => round($amountInRuleCurrency, 2),
                    'amount_display' => round($amountDisplay, 2),
                    'rate' => $tierRate,
                    'commission' => round($comm, 2),
                    'commission_display' => round($commDisplay, 2),
                    'collector' => $r['collector_name'] ?? '',
                ];
            } else {
                // 往期分期：使用签约月份的业绩来确定档位
                $signMonth = $contractSignDate ? date('Y-m', strtotime($contractSignDate)) : '';
                $historyRate = (float)($r['locked_commission_rate'] ?? 0);
                $historyTierBase = 0;
                $historyTierContracts = [];
                // 无论是否有锁定提成率，都计算签约月份的档位基数和合同明细
                if ($signMonth !== '') {
                    $tierInfo = getTierRateForMonth($uid, $signMonth, $ruleTiers, $ruleType, $fixedRate, $ruleCurrency, $currencyRates, $rateType, true);
                    $historyTierBase = $tierInfo['base'];
                    $historyTierContracts = $tierInfo['contracts'] ?? [];
                    if ($historyRate <= 0) {
                        $historyRate = $tierInfo['rate'];
                    }
                }
                if ($historyRate <= 0) {
                    $historyRate = $tierRate; // 最后的fallback
                }
                $comm = $amountInRuleCurrency * $historyRate;
                $commDisplay = convertCurrency($comm, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
                $amountDisplay = convertCurrency($amountInRuleCurrency, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
                $historyTierBaseDisplay = convertCurrency($historyTierBase, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
                $installmentCommission += $comm;
                $installments[] = [
                    'id' => (int)$r['id'],
                    'contract_id' => (int)($r['contract_id'] ?? 0),
                    'contract_name' => $r['contract_name'] ?? '',
                    'customer' => $r['customer_name'] ?? '',
                    'amount' => $receiptAmount,
                    'currency' => $receiptCurrency,
                    'amount_in_rule' => round($amountInRuleCurrency, 2),
                    'amount_display' => round($amountDisplay, 2),
                    'rate' => $historyRate,
                    'commission' => round($comm, 2),
                    'commission_display' => round($commDisplay, 2),
                    'collector' => $r['collector_name'] ?? '',
                    'sign_month' => $signMonth,
                    'history_tier_rate' => $historyRate,
                    'history_tier_base' => round($historyTierBase, 2),
                    'history_tier_base_display' => round($historyTierBaseDisplay, 2),
                    'history_tier_contracts' => $historyTierContracts,
                ];
            }
        }
        
        $manualAdj = 0;
        $adjList = $adjMap[$uid] ?? [];
        foreach ($adjList as $a) {
            $manualAdj += (float)($a['amount'] ?? 0);
        }
        
        $total = $newOrderCommission + $installmentCommission + $manualAdj;
        
        $commission = $newOrderCommission + $installmentCommission;
        
        $newOrderInDisplay = convertCurrency($newOrderCommission, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
        $installmentInDisplay = convertCurrency($installmentCommission, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
        $commissionInDisplay = convertCurrency($commission, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
        $adjustmentInDisplay = convertCurrency($manualAdj, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
        $totalInDisplay = convertCurrency($total, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
        
        $displayRate = $currencyRates[$displayCurrency][$rateType] ?? 1;
        $ruleRate = $currencyRates[$ruleCurrency][$rateType] ?? 1;
        
        // 获取各工资组成项的原始货币和值
        $baseSalary = (float)($userSalary['base_salary'] ?? 0);
        $attendance = (float)($userSalary['attendance'] ?? 0);
        $incentive = (float)($userSalary['incentive'] ?? 0);
        $adjustment = (float)($userSalary['adjustment'] ?? 0);
        $deduction = (float)($userSalary['deduction'] ?? 0);
        
        // 获取各项的原始货币
        $baseSalaryCurrency = $componentCurrencies['base_salary'] ?? 'CNY';
        $attendanceCurrency = $componentCurrencies['attendance'] ?? 'CNY';
        $incentiveCurrency = $componentCurrencies['incentive'] ?? 'CNY';
        $adjustmentCurrency = $componentCurrencies['adjustment'] ?? 'CNY';
        $deductionCurrency = $componentCurrencies['deduction'] ?? 'CNY';
        
        // 分别换算各工资项到显示货币
        $baseSalaryDisplay = convertCurrency($baseSalary, $baseSalaryCurrency, $displayCurrency, $currencyRates, $rateType);
        $attendanceDisplay = convertCurrency($attendance, $attendanceCurrency, $displayCurrency, $currencyRates, $rateType);
        $incentiveDisplay = convertCurrency($incentive, $incentiveCurrency, $displayCurrency, $currencyRates, $rateType);
        $adjustmentSalaryDisplay = convertCurrency($adjustment, $adjustmentCurrency, $displayCurrency, $currencyRates, $rateType);
        $deductionDisplay = convertCurrency($deduction, $deductionCurrency, $displayCurrency, $currencyRates, $rateType);
        
        // 把工资组成项换算到规则货币，计算规则货币下的总工资
        $baseSalaryInRule = convertCurrency($baseSalary, $baseSalaryCurrency, $ruleCurrency, $currencyRates, $rateType);
        $attendanceInRule = convertCurrency($attendance, $attendanceCurrency, $ruleCurrency, $currencyRates, $rateType);
        $incentiveInRule = convertCurrency($incentive, $incentiveCurrency, $ruleCurrency, $currencyRates, $rateType);
        $adjustmentInRule = convertCurrency($adjustment, $adjustmentCurrency, $ruleCurrency, $currencyRates, $rateType);
        $deductionInRule = convertCurrency($deduction, $deductionCurrency, $ruleCurrency, $currencyRates, $rateType);
        
        // 规则货币下的总工资（提成已经是规则货币）
        $totalInRule = $commission + $manualAdj + $baseSalaryInRule + $attendanceInRule + $incentiveInRule + $adjustmentInRule - $deductionInRule;
        
        // 计算显示货币下的总工资（各项分别换算后汇总）
        // 注意：adjustmentSalaryDisplay是salary_user_monthly.adjustment的换算，adjustmentInDisplay是commission_adjustments的换算
        // 两者是不同来源，都需要加上
        $totalDisplayCalc = $commissionInDisplay + $baseSalaryDisplay + $attendanceDisplay + $incentiveDisplay + $adjustmentSalaryDisplay + $adjustmentInDisplay - $deductionDisplay;
        
        $summary[] = [
            'user_id' => $uid,
            'user_name' => $su['realname'] ?? '',
            'department' => $su['dept_name'] ?? '',
            'tier_base' => round($tierBase, 2),
            'tier_base_display' => round(convertCurrency($tierBase, $ruleCurrency, $displayCurrency, $currencyRates, $rateType), 2),
            'tier_rate' => $tierRate,
            'new_order_commission' => round($newOrderCommission, 2),
            'new_order_commission_display' => round($newOrderInDisplay, 2),
            'installment_commission' => round($installmentCommission, 2),
            'installment_commission_display' => round($installmentInDisplay, 2),
            'commission' => round($commission, 2),
            'commission_display' => round($commissionInDisplay, 2),
            'incentive_bonus' => $incentive,
            'incentive' => $incentive,
            'incentive_display' => round($incentiveDisplay, 2),
            'incentive_currency' => $incentiveCurrency,
            'manual_adjustment' => round($manualAdj, 2),
            'adjustment' => $adjustment + round($manualAdj, 2),
            'adjustment_display' => round($adjustmentSalaryDisplay + $adjustmentInDisplay, 2),
            'adjustment_currency' => $adjustmentCurrency,
            'deduction' => $deduction,
            'deduction_display' => round($deductionDisplay, 2),
            'deduction_currency' => $deductionCurrency,
            'base_salary' => $baseSalary,
            'base_salary_display' => round($baseSalaryDisplay, 2),
            'base_salary_currency' => $baseSalaryCurrency,
            'attendance' => $attendance,
            'attendance_display' => round($attendanceDisplay, 2),
            'attendance_currency' => $attendanceCurrency,
            'total' => round($totalInRule, 2),
            'total_display' => round($totalDisplayCalc, 2),
            'rule_currency' => $ruleCurrency,
            'display_currency' => $displayCurrency,
            'rate_type' => $rateType,
            'rule_rate' => $ruleRate,
            'display_rate' => $displayRate,
        ];
        
        $details[strval($uid)] = [
            'tier_contracts' => $tierContracts,
            'new_orders' => $newOrders,
            'installments' => $installments,
            'incentives' => [],
            'adjustments' => $adjList,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'month' => $month,
            'rule_currency' => $ruleCurrency,
            'display_currency' => $displayCurrency,
            'rate_type' => $rateType,
            'currency_rates' => $currencyRates,
            'summary' => $summary,
            'details' => $details,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function matchRuleForUser(int $userId, int $userDeptId, array $allRules, array $ruleUserMap, array $ruleDeptMap): ?array {
    foreach ($allRules as $rule) {
        $rid = (int)($rule['id'] ?? 0);
        if (isset($ruleUserMap[$rid]) && in_array($userId, $ruleUserMap[$rid], true)) {
            return $rule;
        }
    }
    
    if ($userDeptId > 0) {
        foreach ($allRules as $rule) {
            $rid = (int)($rule['id'] ?? 0);
            if (isset($ruleDeptMap[$rid]) && in_array($userDeptId, $ruleDeptMap[$rid], true)) {
                return $rule;
            }
        }
    }
    
    foreach ($allRules as $rule) {
        $rid = (int)($rule['id'] ?? 0);
        if (!isset($ruleUserMap[$rid]) && !isset($ruleDeptMap[$rid])) {
            return $rule;
        }
    }
    
    return null;
}
