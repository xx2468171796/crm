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

echo json_encode(['success' => false, 'message' => '合同创建后不允许手工新增分期，请使用“重生成分期”调整'], JSON_UNESCAPED_UNICODE);
exit;

$contractId = (int)($_POST['contract_id'] ?? 0);
$dueDate = trim((string)($_POST['due_date'] ?? ''));
$amountDue = (float)($_POST['amount_due'] ?? 0);

if ($contractId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：contract_id'], JSON_UNESCAPED_UNICODE);
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

    $c = Db::queryOne(
        'SELECT c.id, c.customer_id, c.sales_user_id, c.net_amount, cu.owner_user_id
         FROM finance_contracts c
         INNER JOIN customers cu ON cu.id = c.customer_id
         WHERE c.id = :id
         LIMIT 1 FOR UPDATE',
        ['id' => $contractId]
    );

    if (!$c) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '合同不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($user['role'] ?? '') === 'sales') {
        if ((int)($c['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '无权限：只能操作自己名下客户的合同'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $customerId = (int)$c['customer_id'];
    $uid = (int)($user['id'] ?? 0);
    $now = time();

    $maxNo = Db::queryOne('SELECT COALESCE(MAX(installment_no),0) AS m FROM finance_installments WHERE contract_id = :cid AND deleted_at IS NULL', ['cid' => $contractId]);
    $nextNo = ((int)($maxNo['m'] ?? 0)) + 1;

    Db::execute(
        'INSERT INTO finance_installments (
            contract_id, customer_id, installment_no, due_date, amount_due, amount_paid,
            status, create_time, update_time, create_user_id, update_user_id
         ) VALUES (
            :contract_id, :customer_id, :installment_no, :due_date, :amount_due, 0.00,
            "pending", :t, :t, :uid, :uid
         )',
        [
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'installment_no' => $nextNo,
            'due_date' => $dueDate,
            'amount_due' => round($amountDue, 2),
            't' => $now,
            'uid' => $uid,
        ]
    );

    $newId = (int)Db::lastInsertId();

    $sumRow = Db::queryOne('SELECT COALESCE(SUM(amount_due),0) AS s FROM finance_installments WHERE contract_id = :cid AND deleted_at IS NULL', ['cid' => $contractId]);
    $sum = round((float)($sumRow['s'] ?? 0), 2);
    $net = round((float)($c['net_amount'] ?? 0), 2);

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
            NULL, :new_due_date, NULL, :new_amount_due, :note
        )',
        [
            'installment_id' => $newId,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'actor_user_id' => $uid,
            'change_time' => $now,
            'new_due_date' => $dueDate,
            'new_amount_due' => round($amountDue, 2),
            'note' => '新增分期',
        ]
    );

    Db::commit();

    echo json_encode(['success' => true, 'data' => ['installment_id' => $newId]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
