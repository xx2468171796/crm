<?php
/**
 * 工资计算API（整合提成计算）
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$canSync = canOrAdmin(PermissionCode::FINANCE_EDIT);

$month = trim($_GET['month'] ?? date('Y-m'));
$ruleId = (int)($_GET['rule_id'] ?? 0);
$departmentIds = isset($_GET['department_ids']) ? array_filter(array_map('intval', (array)$_GET['department_ids'])) : [];
$userIds = isset($_GET['user_ids']) ? array_filter(array_map('intval', (array)$_GET['user_ids'])) : [];

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => '月份格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取工资配置
    $salaryConfig = null;
    if ($ruleId > 0) {
        $rule = Db::queryOne("SELECT salary_config FROM commission_rule_sets WHERE id = ?", [$ruleId]);
        if ($rule && $rule['salary_config']) {
            $salaryConfig = json_decode($rule['salary_config'], true);
        }
    }
    
    // 默认配置
    if (!$salaryConfig || empty($salaryConfig['components'])) {
        $salaryConfig = [
            'components' => [
                ['code' => 'base_salary', 'name' => '底薪', 'type' => 'fixed', 'default' => 5000, 'op' => '+'],
                ['code' => 'attendance', 'name' => '全勤奖', 'type' => 'manual', 'default' => 500, 'op' => '+'],
                ['code' => 'commission', 'name' => '销售提成', 'type' => 'calculated', 'default' => 0, 'op' => '+'],
                ['code' => 'incentive', 'name' => '激励奖金', 'type' => 'calculated', 'default' => 0, 'op' => '+'],
                ['code' => 'adjustment', 'name' => '手动调整', 'type' => 'manual', 'default' => 0, 'op' => '+'],
                ['code' => 'deduction', 'name' => '扣款', 'type' => 'manual', 'default' => 0, 'op' => '-'],
            ]
        ];
    }
    
    // 构建用户查询条件（支持多选）
    $userFilter = '';
    $params = [];
    if (!empty($departmentIds)) {
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $userFilter .= " AND u.department_id IN ({$placeholders})";
        $params = array_merge($params, $departmentIds);
    }
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userFilter .= " AND u.id IN ({$placeholders})";
        $params = array_merge($params, $userIds);
    }
    
    // 获取用户列表
    $users = Db::query(
        "SELECT u.id, u.realname, u.department_id, d.name as dept_name
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE u.status = 1 {$userFilter}
         ORDER BY u.realname",
        $params
    );
    
    // 获取月度工资数据
    $salaryData = [];
    $salaryRows = Db::query(
        "SELECT * FROM salary_user_monthly WHERE month = ?",
        [$month]
    );
    foreach ($salaryRows as $row) {
        $salaryData[$row['user_id']] = $row;
    }
    
    // 获取档位配置
    $tiers = [];
    if ($ruleId > 0) {
        $tiers = Db::query(
            "SELECT tier_from, tier_to, rate FROM commission_rule_tiers WHERE rule_set_id = ? ORDER BY sort_order",
            [$ruleId]
        );
    }
    
    // 根据基数查找档位比例（从档位表匹配，无硬编码）
    function findTierRate($base, $tiers) {
        foreach ($tiers as $t) {
            $from = (float)$t['tier_from'];
            $to = $t['tier_to'] !== null ? (float)$t['tier_to'] : PHP_FLOAT_MAX;
            if ($base >= $from && $base < $to) {
                return (float)$t['rate'];
            }
        }
        // 无匹配档位时使用第一档比例，如果没有档位配置则返回0
        return !empty($tiers) ? (float)$tiers[0]['rate'] : 0;
    }
    
    // 提成计算
    $commissionData = [];
    $detailsData = [];
    if ($ruleId > 0) {
        $monthStart = date('Y-m-d', strtotime($month . '-01'));
        $monthEnd = date('Y-m-d', strtotime($month . '-01 +1 month -1 day'));
        
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            
            // 1. 计算档位基数 = 本月签约的所有合同总额（首签+复购）
            $tierContracts = Db::query(
                "SELECT c.id, c.title as name, cu.name as customer,
                        CASE WHEN c.is_first_contract = 1 THEN '首签' ELSE '复购' END as type,
                        c.net_amount as amount, c.is_first_contract
                 FROM finance_contracts c
                 LEFT JOIN customers cu ON c.customer_id = cu.id
                 WHERE c.sales_user_id = ? AND c.sign_date >= ? AND c.sign_date <= ?",
                [$uid, $monthStart, $monthEnd]
            );
            
            $tierBase = 0;
            foreach ($tierContracts as $tc) {
                $tierBase += (float)$tc['amount'];
            }
            
            // 2. 查找本月档位比例
            $currentMonthRate = findTierRate($tierBase, $tiers);
            
            // 3. Part1: 本月首签实收 × 本月档位比例
            $newOrderReceipts = Db::query(
                "SELECT r.*, c.title as contract_name, c.sign_date,
                        c.is_first_contract, cu.name as customer_name,
                        collector.realname as collector_name, r.method as payment_method
                 FROM finance_receipts r
                 LEFT JOIN finance_contracts c ON r.contract_id = c.id
                 LEFT JOIN customers cu ON c.customer_id = cu.id
                 LEFT JOIN users collector ON r.collector_user_id = collector.id
                 WHERE c.sales_user_id = ? 
                   AND c.is_first_contract = 1
                   AND c.sign_date >= ? AND c.sign_date <= ?
                   AND r.received_date >= ? AND r.received_date <= ?",
                [$uid, $monthStart, $monthEnd, $monthStart, $monthEnd]
            );
            
            $part1Commission = 0;
            $newOrders = [];
            foreach ($newOrderReceipts as $r) {
                $amt = (float)($r['amount_received'] ?? 0);
                $comm = $amt * $currentMonthRate;
                $part1Commission += $comm;
                $newOrders[] = [
                    'contract_id' => $r['contract_id'] ?? 0,
                    'contract_name' => $r['contract_name'] ?? '',
                    'customer' => $r['customer_name'] ?? '',
                    'amount' => $amt,
                    'rate' => $currentMonthRate,
                    'commission' => round($comm, 2),
                    'collector' => $r['collector_name'] ?? '',
                    'method' => $r['payment_method'] ?? '',
                ];
            }
            
            // 4. Part2: 往期首签本月实收 × 历史锁定档位
            $installmentReceipts = Db::query(
                "SELECT r.*, c.title as contract_name, c.sign_date,
                        c.locked_commission_rate, cu.name as customer_name,
                        collector.realname as collector_name, r.method as payment_method
                 FROM finance_receipts r
                 LEFT JOIN finance_contracts c ON r.contract_id = c.id
                 LEFT JOIN customers cu ON c.customer_id = cu.id
                 LEFT JOIN users collector ON r.collector_user_id = collector.id
                 WHERE c.sales_user_id = ? 
                   AND c.is_first_contract = 1
                   AND c.sign_date < ?
                   AND r.received_date >= ? AND r.received_date <= ?",
                [$uid, $monthStart, $monthStart, $monthEnd]
            );
            
            $part2Commission = 0;
            $installments = [];
            $defaultHistoryRate = !empty($tiers) ? (float)$tiers[0]['rate'] : 0;
            foreach ($installmentReceipts as $r) {
                $amt = (float)($r['amount_received'] ?? 0);
                // 优先使用合同锁定的历史档位，否则使用档位表第一档作为兜底
                $historyRate = !empty($r['locked_commission_rate']) ? (float)$r['locked_commission_rate'] : $defaultHistoryRate;
                $comm = $amt * $historyRate;
                $part2Commission += $comm;
                $installments[] = [
                    'contract_id' => $r['contract_id'] ?? 0,
                    'contract_name' => $r['contract_name'] ?? '',
                    'sign_month' => substr($r['sign_date'] ?? '', 0, 7),
                    'customer' => $r['customer_name'] ?? '',
                    'amount' => $amt,
                    'rate' => $historyRate,
                    'commission' => round($comm, 2),
                    'collector' => $r['collector_name'] ?? '',
                    'method' => $r['payment_method'] ?? '',
                ];
            }
            
            $totalCommission = round($part1Commission + $part2Commission, 2);
            
            // 获取手动调整
            $adjustments = Db::query(
                "SELECT * FROM commission_adjustments WHERE user_id = ? AND month = ? ORDER BY created_at DESC",
                [$uid, $month]
            );
            
            $detailsData[$uid] = [
                'tier_base' => $tierBase,
                'tier_rate' => $currentMonthRate,
                'tier_contracts' => $tierContracts,
                'new_orders' => $newOrders,
                'part1_commission' => round($part1Commission, 2),
                'installments' => $installments,
                'part2_commission' => round($part2Commission, 2),
                'adjustments' => $adjustments,
            ];
            
            $commissionData[$uid] = $totalCommission;
        }
    }
    
    // 组装结果
    $summary = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $salary = $salaryData[$uid] ?? null;
        $commission = $commissionData[$uid] ?? 0;
        
        $baseSalary = $salary ? (float)$salary['base_salary'] : (float)($salaryConfig['components'][0]['default'] ?? 5000);
        $attendance = $salary ? (float)$salary['attendance'] : (float)($salaryConfig['components'][1]['default'] ?? 500);
        $adjustment = $salary ? (float)$salary['adjustment'] : 0;
        $deduction = $salary ? (float)$salary['deduction'] : 0;
        $incentive = $salary ? (float)$salary['incentive'] : 0;
        
        $total = $baseSalary + $attendance + $commission + $incentive + $adjustment - $deduction;
        
        $summary[] = [
            'user_id' => $uid,
            'user_name' => $u['realname'] ?? '',
            'department' => $u['dept_name'] ?? '',
            'base_salary' => $baseSalary,
            'attendance' => $attendance,
            'commission' => $commission,
            'incentive' => $incentive,
            'adjustment' => $adjustment,
            'deduction' => $deduction,
            'total' => round($total, 2),
        ];

        if ($canSync) {
            $now = time();
            $existing = Db::queryOne(
                "SELECT id FROM salary_user_monthly WHERE user_id = ? AND month = ?",
                [$uid, $month]
            );

            if ($existing) {
                Db::exec(
                    "UPDATE salary_user_monthly SET commission = ?, incentive = ?, total = ?, updated_at = ? WHERE id = ?",
                    [$commission, $incentive, round($total, 2), $now, $existing['id']]
                );
            } else {
                Db::exec(
                    "INSERT INTO salary_user_monthly (user_id, month, base_salary, attendance, commission, incentive, adjustment, deduction, total, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$uid, $month, $baseSalary, $attendance, $commission, $incentive, $adjustment, $deduction, round($total, 2), $now, $now]
                );
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'month' => $month,
            'config' => $salaryConfig,
            'summary' => $summary,
            'details' => $detailsData,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
