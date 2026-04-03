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

$customerId = (int)($_POST['customer_id'] ?? 0);
$installmentId = (int)($_POST['installment_id'] ?? 0);
$amountStr = trim($_POST['amount'] ?? '');
$note = trim($_POST['note'] ?? '');

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => '客户ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择分期'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($amountStr === '' || !is_numeric($amountStr)) {
    echo json_encode(['success' => false, 'message' => '请输入有效的核销金额'], JSON_UNESCAPED_UNICODE);
    exit;
}
$amount = (float)$amountStr;
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => '核销金额必须大于0'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($note) > 255) {
    echo json_encode(['success' => false, 'message' => '备注最多255字'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $cust = Db::queryOne('SELECT id FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
    if (!$cust) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inst = Db::queryOne('SELECT * FROM finance_installments WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE', ['id' => $installmentId]);
    if (!$inst) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$inst['customer_id'] !== $customerId) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期不属于该客户'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $amountDue = (float)$inst['amount_due'];
    $amountPaid = (float)$inst['amount_paid'];
    $unpaid = max(0.0, $amountDue - $amountPaid);
    if ($unpaid <= 0.00001) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '该分期已无未收金额'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lock ledger rows for this customer to reduce race conditions
    Db::query('SELECT id FROM finance_prepay_ledger WHERE customer_id = :cid FOR UPDATE', ['cid' => $customerId]);

    $balanceRow = Db::queryOne('SELECT
            COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS balance
        FROM finance_prepay_ledger
        WHERE customer_id = :cid', ['cid' => $customerId]);
    $balance = (float)($balanceRow['balance'] ?? 0);

    if ($balance <= 0.00001) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '预收余额不足'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $maxAllow = min($unpaid, $balance);
    if ($amount > $maxAllow + 0.00001) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '核销金额超出可用范围（最大可核销 ' . number_format($maxAllow, 2) . '）'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newPaid = $amountPaid + $amount;

    $newStatus = $inst['status'] ?? 'pending';
    if ($newPaid >= $amountDue - 0.00001) {
        $newStatus = 'paid';
    } elseif ($newPaid > 0) {
        $newStatus = 'partial';
    } else {
        $newStatus = 'pending';
    }

    if ($newStatus !== 'paid') {
        $dueDate = (string)$inst['due_date'];
        if ($dueDate !== '' && $dueDate < date('Y-m-d') && ($amountDue - $newPaid) > 0.00001) {
            $newStatus = 'overdue';
        }
    }

    $now = time();

    Db::execute(
        'UPDATE finance_installments SET amount_paid = :paid, status = :status, update_time = :t, update_user_id = :uid WHERE id = :id',
        [
            'paid' => $newPaid,
            'status' => $newStatus,
            't' => $now,
            'uid' => (int)($user['id'] ?? 0),
            'id' => $installmentId,
        ]
    );

    // Auto update contract status: closed if all installments paid, otherwise active (void stays void)
    $contractId = (int)($inst['contract_id'] ?? 0);
    if ($contractId > 0) {
        $cRow = Db::queryOne('SELECT id, status FROM finance_contracts WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $contractId]);
        if ($cRow) {
            $cStatus = (string)($cRow['status'] ?? '');
            if ($cStatus !== 'void') {
                $unpaidRow = Db::queryOne(
                    'SELECT COUNT(*) AS c
                     FROM finance_installments
                     WHERE contract_id = :cid AND deleted_at IS NULL AND (amount_due - amount_paid) > 0.00001',
                    ['cid' => $contractId]
                );
                $unpaidCount = (int)($unpaidRow['c'] ?? 0);
                $newContractStatus = ($unpaidCount <= 0) ? 'closed' : 'active';
                if ($newContractStatus !== $cStatus) {
                    Db::execute(
                        'UPDATE finance_contracts SET status = :s, update_time = :t, update_user_id = :uid WHERE id = :id',
                        [
                            's' => $newContractStatus,
                            't' => $now,
                            'uid' => (int)($user['id'] ?? 0),
                            'id' => $contractId,
                        ]
                    );
                }
            }
        }
    }

    Db::execute(
        'INSERT INTO finance_prepay_ledger (customer_id, direction, amount, source_type, source_id, note, created_at, created_by)
         VALUES (:customer_id, "out", :amount, "apply_to_installment", :source_id, :note, :created_at, :created_by)',
        [
            'customer_id' => $customerId,
            'amount' => $amount,
            'source_id' => $installmentId,
            'note' => $note !== '' ? $note : '预收核销到分期',
            'created_at' => $now,
            'created_by' => (int)($user['id'] ?? 0),
        ]
    );

    $ledgerId = (int)Db::lastInsertId();

    $contract = Db::queryOne('SELECT id, sales_user_id FROM finance_contracts WHERE id = :id LIMIT 1', ['id' => (int)$inst['contract_id']]);
    $salesSnapshot = $contract ? (int)($contract['sales_user_id'] ?? 0) : 0;
    if ($salesSnapshot <= 0) {
        $salesSnapshot = null;
    }

    Db::execute(
        'INSERT INTO finance_receipts (customer_id, contract_id, installment_id, sales_user_id_snapshot, source_type, source_id, received_date, amount_received, amount_applied, amount_overflow, method, note, create_time, create_user_id)
         VALUES (:customer_id, :contract_id, :installment_id, :sales_user_id_snapshot, :source_type, :source_id, :received_date, :amount_received, :amount_applied, :amount_overflow, :method, :note, :create_time, :create_user_id)',
        [
            'customer_id' => $customerId,
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => $installmentId,
            'sales_user_id_snapshot' => $salesSnapshot,
            'source_type' => 'prepay_apply',
            'source_id' => $ledgerId > 0 ? $ledgerId : null,
            'received_date' => date('Y-m-d'),
            'amount_received' => 0,
            'amount_applied' => round((float)$amount, 2),
            'amount_overflow' => 0,
            'method' => 'prepay',
            'note' => $note !== '' ? $note : '预收核销',
            'create_time' => $now,
            'create_user_id' => (int)($user['id'] ?? 0),
        ]
    );

    Db::execute(
        'INSERT INTO finance_collection_logs (customer_id, contract_id, installment_id, actor_user_id, action_time, method, result, note)
         VALUES (:customer_id, :contract_id, :installment_id, :actor_user_id, :action_time, :method, :result, :note)',
        [
            'customer_id' => $customerId,
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => $installmentId,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'action_time' => $now,
            'method' => 'prepay',
            'result' => 'apply',
            'note' => $note !== '' ? $note : null,
        ]
    );

    Db::commit();

    echo json_encode([
        'success' => true,
        'message' => '核销成功',
        'data' => [
            'customer_id' => $customerId,
            'installment_id' => $installmentId,
            'amount' => $amount,
            'balance_before' => $balance,
            'balance_after' => max(0.0, $balance - $amount),
            'installment_amount_paid' => $newPaid,
            'installment_status' => $newStatus,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
