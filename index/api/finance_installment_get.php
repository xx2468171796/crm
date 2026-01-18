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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $row = Db::queryOne('SELECT
            i.id AS installment_id,
            i.installment_no,
            i.due_date,
            i.amount_due,
            i.amount_paid,
            i.status AS installment_status,
            i.contract_id,
            i.customer_id,
            i.currency AS installment_currency,
            c.contract_no,
            c.title AS contract_title,
            c.sales_user_id,
            c.currency AS contract_currency,
            u.realname AS sales_name,
            cu.name AS customer_name,
            cu.mobile AS customer_mobile,
            cu.customer_code,
            cu.activity_tag
        FROM finance_installments i
        INNER JOIN finance_contracts c ON c.id = i.contract_id
        INNER JOIN customers cu ON cu.id = i.customer_id
        LEFT JOIN users u ON u.id = c.sales_user_id
        WHERE i.id = :id
          AND i.deleted_at IS NULL
        LIMIT 1', ['id' => $id]);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $amountDue = (float)$row['amount_due'];
    $amountPaid = (float)$row['amount_paid'];
    $amountUnpaid = max(0.0, $amountDue - $amountPaid);

    $row['amount_unpaid'] = $amountUnpaid;

    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
