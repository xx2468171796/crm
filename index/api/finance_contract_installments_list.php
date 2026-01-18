<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contractId = (int)($_GET['contract_id'] ?? 0);
if ($contractId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = Db::query('SELECT
            i.id AS installment_id,
            i.installment_no,
            i.due_date,
            i.amount_due,
            i.amount_paid,
            i.status AS installment_status,
            i.manual_status,
            i.contract_id,
            i.customer_id,
            i.remark,
            i.create_time,
            i.update_time,
            i.collector_user_id,
            i.currency,
            i.payment_method,
            ragg.last_received_date,
            ragg.last_receipt_time,
            coll.realname AS collector_name,
            c.currency AS contract_currency
        FROM finance_installments i
        LEFT JOIN finance_contracts c ON c.id = i.contract_id
        LEFT JOIN users coll ON coll.id = i.collector_user_id
        LEFT JOIN (
            SELECT r1.installment_id, r1.received_date AS last_received_date, r1.create_time AS last_receipt_time
            FROM finance_receipts r1
            INNER JOIN (
                SELECT installment_id, MAX(id) AS max_id
                FROM finance_receipts
                WHERE amount_applied > 0
                GROUP BY installment_id
            ) r2 ON r1.id = r2.max_id
        ) ragg ON ragg.installment_id = i.id
        WHERE i.contract_id = :contract_id
          AND i.deleted_at IS NULL
        ORDER BY i.installment_no ASC', ['contract_id' => $contractId]);

    $data = [];
    foreach ($rows as $row) {
        $amountDue = (float)$row['amount_due'];
        $amountPaid = (float)$row['amount_paid'];
        $row['amount_unpaid'] = max(0.0, $amountDue - $amountPaid);
        $row['id'] = $row['installment_id'];
        // 计算逾期天数
        $dueDate = $row['due_date'] ?? '';
        $overdueDays = 0;
        if ($dueDate && $amountDue > $amountPaid) {
            $dueDateTs = strtotime($dueDate);
            $today = strtotime(date('Y-m-d'));
            if ($dueDateTs && $today > $dueDateTs) {
                $overdueDays = (int)(($today - $dueDateTs) / 86400);
            }
        }
        $row['overdue_days'] = $overdueDays;
        $data[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
