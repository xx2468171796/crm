<?php
/**
 * 保存员工月度工资数据
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_require();

$userId = (int)($_POST['user_id'] ?? 0);
$month = trim($_POST['month'] ?? '');
$field = trim($_POST['field'] ?? '');
$value = (float)($_POST['value'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => '用户ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => '月份格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fieldMap = [
    'base_salary' => 'base_salary',
    'attendance' => 'attendance',
    'adjustment' => 'adjustment',
    'deduction' => 'deduction',
];
if (!isset($fieldMap[$field])) {
    echo json_encode(['success' => false, 'message' => '字段不允许修改'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $now = time();
    
    // 检查是否已存在记录
    $existing = Db::queryOne(
        "SELECT id FROM salary_user_monthly WHERE user_id = ? AND month = ?",
        [$userId, $month]
    );
    
    if ($existing) {
        // 更新指定字段
        Db::exec(
            "UPDATE salary_user_monthly SET {$fieldMap[$field]} = ?, updated_at = ? WHERE id = ?",
            [$value, $now, $existing['id']]
        );
    } else {
        // 插入新记录
        $data = [
            'user_id' => $userId,
            'month' => $month,
            'base_salary' => 0,
            'attendance' => 0,
            'commission' => 0,
            'incentive' => 0,
            'adjustment' => 0,
            'deduction' => 0,
            'total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $data[$field] = $value;
        
        Db::exec(
            "INSERT INTO salary_user_monthly (user_id, month, base_salary, attendance, commission, incentive, adjustment, deduction, total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$data['user_id'], $data['month'], $data['base_salary'], $data['attendance'], $data['commission'], $data['incentive'], $data['adjustment'], $data['deduction'], $data['total'], $data['created_at'], $data['updated_at']]
        );
    }
    
    // 重新计算（对齐 salary_calculate.php：commission 通过实收金额*档位比例计算；incentive 继续使用落库值）
    $monthStart = strtotime($month . '-01 00:00:00');
    $monthEnd = strtotime($month . '-01 +1 month -1 second');
    $tierRate = 0.03;

    $commission = 0;
    $receipts = Db::query(
        "SELECT r.amount_received, c.is_first_contract
         FROM finance_receipts r
         LEFT JOIN finance_contracts c ON r.contract_id = c.id
         WHERE c.sales_user_id = ? AND r.received_date >= ? AND r.received_date <= ?",
        [$userId, date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd)]
    );

    foreach ($receipts as $r) {
        $isFirstContract = (int)($r['is_first_contract'] ?? 0) === 1;
        if ($isFirstContract) {
            $commission += (float)($r['amount_received'] ?? 0) * $tierRate;
        }
    }
    $commission = round($commission, 2);

    // 重新读取行数据并计算总工资
    $row = Db::queryOne(
        "SELECT base_salary, attendance, commission, incentive, adjustment, deduction FROM salary_user_monthly WHERE user_id = ? AND month = ?",
        [$userId, $month]
    );
    
    if ($row) {
        $incentive = (float)($row['incentive'] ?? 0);
        $total = (float)$row['base_salary'] + (float)$row['attendance'] + (float)$commission + (float)$incentive + (float)$row['adjustment'] - (float)$row['deduction'];
        Db::exec(
            "UPDATE salary_user_monthly SET commission = ?, total = ?, updated_at = ? WHERE user_id = ? AND month = ?",
            [$commission, $total, $now, $userId, $month]
        );
    }
    
    echo json_encode(['success' => true, 'message' => '保存成功'], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
