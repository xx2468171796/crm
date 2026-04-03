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

echo json_encode(['success' => false, 'message' => '合同创建后不允许手工编辑分期，请使用“重生成分期”调整'], JSON_UNESCAPED_UNICODE);
exit;

$installmentId = (int)($_POST['installment_id'] ?? 0);
$dueDate = trim((string)($_POST['due_date'] ?? ''));
$amountDue = (float)($_POST['amount_due'] ?? 0);

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：installment_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    echo json_encode(['success' => false, 'message' => '到期日格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($amountDue <= 0) {
    echo json_encode(['success' => false, 'message' => '分期金额必须大于 0'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $row = Db::queryOne(
        'SELECT i.id, i.contract_id, i.customer_id, i.due_date, i.amount_due, i.amount_paid, c.net_amount, c.sales_user_id, cu.owner_user_id
         FROM finance_installments i
         INNER JOIN finance_contracts c ON c.id = i.contract_id
         INNER JOIN customers cu ON cu.id = i.customer_id
         WHERE i.id = :id AND i.deleted_at IS NULL
         LIMIT 1 FOR UPDATE',
        ['id' => $installmentId]
    );

    if (!$row) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($user['role'] ?? '') === 'sales') {
        if ((int)($row['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '无权限：只能操作自己名下客户的分期'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $oldDue = (string)($row['due_date'] ?? '');
    $oldAmt = round((float)($row['amount_due'] ?? 0), 2);
    $paid = round((float)($row['amount_paid'] ?? 0), 2);
    $newAmt = round((float)$amountDue, 2);

    if ($newAmt + 0.00001 < $paid) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期应收金额不得小于已收金额(' . number_format($paid, 2) . ')'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Db::execute(
        'UPDATE finance_installments SET due_date = :due_date, amount_due = :amount_due, update_time = :t, update_user_id = :uid WHERE id = :id',
        [
            'due_date' => $dueDate,
            'amount_due' => $newAmt,
            't' => time(),
            'uid' => (int)($user['id'] ?? 0),
            'id' => $installmentId,
        ]
    );

    $contractId = (int)$row['contract_id'];
    $customerId = (int)$row['customer_id'];

    $sumRow = Db::queryOne('SELECT COALESCE(SUM(amount_due),0) AS s FROM finance_installments WHERE contract_id = :cid AND deleted_at IS NULL', ['cid' => $contractId]);
    $sum = round((float)($sumRow['s'] ?? 0), 2);
    $net = round((float)($row['net_amount'] ?? 0), 2);

    if (abs($sum - $net) > 0.01) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期合计(' . number_format($sum,2) . ') 必须等于 折后金额(' . number_format($net,2) . ')'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Db::execute(
        'INSERT INTO finance_installment_change_logs (
            installment_id, contract_id, customer_id, actor_user_id, change_time,
            old_due_date, new_due_date, old_amount_due, new_amount_due, note
        ) VALUES (
            :installment_id, :contract_id, :customer_id, :actor_user_id, :change_time,
            :old_due_date, :new_due_date, :old_amount_due, :new_amount_due, :note
        )',
        [
            'installment_id' => $installmentId,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'change_time' => time(),
            'old_due_date' => $oldDue !== '' ? $oldDue : null,
            'new_due_date' => $dueDate,
            'old_amount_due' => $oldAmt,
            'new_amount_due' => $newAmt,
            'note' => '编辑分期',
        ]
    );

    Db::commit();

    echo json_encode(['success' => true, 'data' => ['installment_id' => $installmentId]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
