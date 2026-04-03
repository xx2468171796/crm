<?php
/**
 * 工资数据导出API
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$month = trim($_GET['month'] ?? date('Y-m'));
$ruleId = (int)($_GET['rule_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '月份格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

function csv_safe($v): string
{
    $s = (string)$v;
    if ($s !== '' && preg_match('/^[=\+\-@]/', $s)) {
        return "'" . $s;
    }
    return $s;
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
    
    // 获取用户列表和工资数据
    $users = Db::query(
        "SELECT u.id, u.realname, d.name as dept_name
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE u.status = 1
         ORDER BY u.realname"
    );
    
    $salaryData = [];
    $salaryRows = Db::query("SELECT * FROM salary_user_monthly WHERE month = ?", [$month]);
    foreach ($salaryRows as $row) {
        $salaryData[$row['user_id']] = $row;
    }
    
    // 生成CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salary_' . $month . '.csv"');
    
    // 输出BOM以支持Excel正确显示中文
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // 表头
    fputcsv($output, ['员工', '部门', '底薪', '全勤', '提成', '激励', '调整', '扣款', '总工资']);
    
    // 数据行
    $monthStart = strtotime($month . '-01 00:00:00');
    $monthEnd = strtotime($month . '-01 +1 month -1 second');
    $tierRate = 0.03;

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $salary = $salaryData[$uid] ?? null;

        // 计算提成（对齐 salary_calculate 口径）
        $commission = 0;
        $receipts = Db::query(
            "SELECT r.amount_received, c.is_first_contract
             FROM finance_receipts r
             LEFT JOIN finance_contracts c ON r.contract_id = c.id
             WHERE c.sales_user_id = ? AND r.received_date >= ? AND r.received_date <= ?",
            [$uid, date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd)]
        );
        foreach ($receipts as $r) {
            $isFirstContract = (int)($r['is_first_contract'] ?? 0) === 1;
            if ($isFirstContract) {
                $commission += (float)($r['amount_received'] ?? 0) * $tierRate;
            }
        }
        $commission = round($commission, 2);
        
        $baseSalary = $salary ? (float)$salary['base_salary'] : 5000;
        $attendance = $salary ? (float)$salary['attendance'] : 500;
        $incentive = $salary ? (float)$salary['incentive'] : 0;
        $adjustment = $salary ? (float)$salary['adjustment'] : 0;
        $deduction = $salary ? (float)$salary['deduction'] : 0;
        $total = $baseSalary + $attendance + $commission + $incentive + $adjustment - $deduction;
        
        fputcsv($output, [
            csv_safe($u['realname'] ?? ''),
            csv_safe($u['dept_name'] ?? ''),
            number_format($baseSalary, 2, '.', ''),
            number_format($attendance, 2, '.', ''),
            number_format($commission, 2, '.', ''),
            number_format($incentive, 2, '.', ''),
            number_format($adjustment, 2, '.', ''),
            number_format($deduction, 2, '.', ''),
            number_format($total, 2, '.', ''),
        ]);
    }
    
    fclose($output);
    
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
