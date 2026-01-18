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

$installmentId = (int)($_POST['installment_id'] ?? 0);
$receivedDate = trim($_POST['received_date'] ?? '');
$amountReceivedStr = trim($_POST['amount_received'] ?? '');
$prepayAmountStr = trim($_POST['prepay_amount'] ?? '');
$method = trim($_POST['method'] ?? '');
$note = trim($_POST['note'] ?? '');
$collectorUserId = (int)($_POST['collector_user_id'] ?? 0);
$currency = trim($_POST['currency'] ?? 'TWD');

// 验证货币代码
$validCurrencies = ['CNY', 'TWD', 'USD', 'GBP', 'SGD', 'HKD'];
if (!in_array($currency, $validCurrencies)) {
    $currency = 'TWD';
}

// 收款人默认为当前登录用户
if ($collectorUserId <= 0) {
    $collectorUserId = (int)($user['id'] ?? 0);
}

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择分期'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($receivedDate === '') {
    echo json_encode(['success' => false, 'message' => '请选择收款日期'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
    echo json_encode(['success' => false, 'message' => '收款日期格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$amountReceived = 0.0;
if ($amountReceivedStr !== '' && is_numeric($amountReceivedStr)) {
    $amountReceived = (float)$amountReceivedStr;
}

$prepayAmount = 0.0;
if ($prepayAmountStr !== '' && is_numeric($prepayAmountStr)) {
    $prepayAmount = (float)$prepayAmountStr;
}

if ($amountReceived < 0) {
    echo json_encode(['success' => false, 'message' => '收款金额不能为负'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($prepayAmount < 0) {
    echo json_encode(['success' => false, 'message' => '预收核销金额不能为负'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalAmount = $amountReceived + $prepayAmount;
if ($totalAmount <= 0) {
    echo json_encode(['success' => false, 'message' => '收款金额或预收核销金额必须大于0'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $inst = Db::queryOne(
        'SELECT i.*, c.sales_user_id, cu.owner_user_id
         FROM finance_installments i
         INNER JOIN finance_contracts c ON c.id = i.contract_id
         INNER JOIN customers cu ON cu.id = i.customer_id
         WHERE i.id = :id AND i.deleted_at IS NULL
         LIMIT 1 FOR UPDATE',
        ['id' => $installmentId]
    );
    if (!$inst) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '分期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($user['role'] ?? '') === 'sales') {
        $uid = (int)($user['id'] ?? 0);
        $salesId = (int)($inst['sales_user_id'] ?? 0);
        $ownerId = (int)($inst['owner_user_id'] ?? 0);
        if ($uid <= 0 || ($uid !== $salesId && $uid !== $ownerId)) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '无权限：只能给自己负责/自己销售的分期登记收款'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $customerId = (int)$inst['customer_id'];
    $amountDue = (float)$inst['amount_due'];
    $amountPaid = (float)$inst['amount_paid'];
    $amountUnpaid = max(0.0, $amountDue - $amountPaid);

    // 检查预收余额是否足够
    $prepayBalance = 0.0;
    if ($prepayAmount > 0) {
        Db::query('SELECT id FROM finance_prepay_ledger WHERE customer_id = :cid FOR UPDATE', ['cid' => $customerId]);
        $balanceRow = Db::queryOne(
            'SELECT COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS balance
             FROM finance_prepay_ledger WHERE customer_id = :cid',
            ['cid' => $customerId]
        );
        $prepayBalance = (float)($balanceRow['balance'] ?? 0);
        if ($prepayBalance + 0.00001 < $prepayAmount) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '预收余额不足（当前余额: ' . number_format($prepayBalance, 2) . '）'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 计算总收款和分配
    $cashApplied = min($amountReceived, $amountUnpaid);
    $cashOverflow = max(0.0, $amountReceived - $cashApplied);
    $remainUnpaid = max(0.0, $amountUnpaid - $cashApplied);
    $prepayApplied = min($prepayAmount, $remainUnpaid);

    $amountApplied = $cashApplied + $prepayApplied;
    $amountOverflow = $cashOverflow;

    $newPaid = $amountPaid + $amountApplied;

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

    // 获取当前汇率
    $currencyInfo = Db::queryOne('SELECT floating_rate, fixed_rate FROM currencies WHERE code = :code', ['code' => $currency]);
    $exchangeRateFloating = $currencyInfo ? (float)$currencyInfo['floating_rate'] : ($currency === 'TWD' ? 4.5 : 1);
    $exchangeRateFixed = $currencyInfo ? (float)$currencyInfo['fixed_rate'] : ($currency === 'TWD' ? 4.5 : 1);
    
    // 计算折算人民币金额（使用固定汇率）
    $amountCny = $exchangeRateFixed > 0 ? round($amountReceived / $exchangeRateFixed, 2) : $amountReceived;

    $contract = Db::queryOne('SELECT id, sales_user_id FROM finance_contracts WHERE id = :id LIMIT 1', ['id' => (int)$inst['contract_id']]);
    $salesSnapshot = $contract ? (int)($contract['sales_user_id'] ?? 0) : 0;
    if ($salesSnapshot <= 0) {
        $salesSnapshot = null;
    }

    Db::execute(
        'UPDATE finance_installments SET amount_paid = :paid, status = :status, collector_user_id = :collector, payment_method = :method, update_time = :t, update_user_id = :uid WHERE id = :id',
        [
            'paid' => $newPaid,
            'status' => $newStatus,
            'collector' => $collectorUserId,
            'method' => $method !== '' ? $method : null,
            't' => $now,
            'uid' => (int)($user['id'] ?? 0),
            'id' => $installmentId
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
        'INSERT INTO finance_receipts (customer_id, contract_id, installment_id, sales_user_id_snapshot, source_type, source_id, received_date, amount_received, amount_applied, amount_overflow, method, note, create_time, create_user_id, collector_user_id, currency, exchange_rate_floating, exchange_rate_fixed, amount_cny)
         VALUES (:customer_id, :contract_id, :installment_id, :sales_user_id_snapshot, :source_type, :source_id, :received_date, :amount_received, :amount_applied, :amount_overflow, :method, :note, :create_time, :create_user_id, :collector_user_id, :currency, :exchange_rate_floating, :exchange_rate_fixed, :amount_cny)',
        [
            'customer_id' => (int)$inst['customer_id'],
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => $installmentId,
            'sales_user_id_snapshot' => $salesSnapshot,
            'source_type' => 'cash_receipt',
            'source_id' => null,
            'received_date' => $receivedDate,
            'amount_received' => $amountReceived,
            'amount_applied' => $amountApplied,
            'amount_overflow' => $amountOverflow,
            'method' => $method !== '' ? $method : null,
            'note' => $note !== '' ? $note : null,
            'create_time' => $now,
            'create_user_id' => (int)($user['id'] ?? 0),
            'collector_user_id' => $collectorUserId,
            'currency' => $currency,
            'exchange_rate_floating' => $exchangeRateFloating,
            'exchange_rate_fixed' => $exchangeRateFixed,
            'amount_cny' => $amountCny,
        ]
    );

    $receiptId = (int)Db::lastInsertId();

    if ($amountOverflow > 0) {
        Db::execute(
            'INSERT INTO finance_prepay_ledger (customer_id, direction, amount, source_type, source_id, note, created_at, created_by)
             VALUES (:customer_id, "in", :amount, "receipt_overflow", :source_id, :note, :created_at, :created_by)',
            [
                'customer_id' => $customerId,
                'amount' => $amountOverflow,
                'source_id' => $receiptId,
                'note' => $note !== '' ? $note : '收款超收转预收',
                'created_at' => $now,
                'created_by' => (int)($user['id'] ?? 0),
            ]
        );
    }

    // 预收核销：扣减预收余额
    if ($prepayApplied > 0) {
        Db::execute(
            'INSERT INTO finance_prepay_ledger (customer_id, direction, amount, source_type, source_id, note, created_at, created_by)
             VALUES (:customer_id, "out", :amount, "apply_to_installment", :source_id, :note, :created_at, :created_by)',
            [
                'customer_id' => $customerId,
                'amount' => $prepayApplied,
                'source_id' => $installmentId,
                'note' => $note !== '' ? $note : '混合收款-预收核销',
                'created_at' => $now,
                'created_by' => (int)($user['id'] ?? 0),
            ]
        );
    }

    Db::execute(
        'INSERT INTO finance_collection_logs (customer_id, contract_id, installment_id, actor_user_id, action_time, method, result, note)
         VALUES (:customer_id, :contract_id, :installment_id, :actor_user_id, :action_time, :method, :result, :note)',
        [
            'customer_id' => $customerId,
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => $installmentId,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'action_time' => $now,
            'method' => $method !== '' ? $method : null,
            'result' => 'received',
            'note' => $note !== '' ? $note : null,
        ]
    );

    Db::commit();

    $msg = '收款登记成功';
    if ($amountReceived > 0 && $prepayApplied > 0) {
        $msg = '混合收款登记成功（现金 ' . number_format($amountReceived, 2) . ' + 预收 ' . number_format($prepayApplied, 2) . '）';
    } elseif ($prepayApplied > 0) {
        $msg = '预收核销成功';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'data' => [
            'receipt_id' => $receiptId,
            'amount_received' => $amountReceived,
            'prepay_applied' => $prepayApplied,
            'amount_applied' => $amountApplied,
            'amount_overflow' => $amountOverflow,
            'installment_status' => $newStatus,
            'installment_amount_paid' => $newPaid,
            'currency' => $currency,
            'amount_cny' => $amountCny,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
