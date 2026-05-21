<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$installmentId = (int)($_POST['installment_id'] ?? 0);
$dueDate = trim((string)($_POST['due_date'] ?? ''));
$amountDue = (float)($_POST['amount_due'] ?? 0);

// 可选字段：只有 POST 里出现才视为修改
$currencyInput   = array_key_exists('currency', $_POST)          ? trim((string)$_POST['currency'])          : null;
$collectorInput  = array_key_exists('collector_user_id', $_POST) ? (int)$_POST['collector_user_id']         : null;
$methodInput     = array_key_exists('payment_method', $_POST)    ? trim((string)$_POST['payment_method'])    : null;
// 已收金额：管理员可直接调整（含修正录入错误）。不与应收金额做关联校验。
$amountPaidInput = array_key_exists('amount_paid', $_POST)       ? (float)$_POST['amount_paid']             : null;

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

if ($amountPaidInput !== null && $amountPaidInput < 0) {
    echo json_encode(['success' => false, 'message' => '已收金额不可为负数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 校验可选字段（提交了才校验）
if ($currencyInput !== null && $currencyInput !== '') {
    $cur = Db::queryOne('SELECT code FROM currencies WHERE code = :c AND status = 1 LIMIT 1', ['c' => $currencyInput]);
    if (!$cur) {
        echo json_encode(['success' => false, 'message' => '货币无效：' . $currencyInput], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if ($collectorInput !== null && $collectorInput > 0) {
    $u = Db::queryOne('SELECT id FROM users WHERE id = :id AND status = 1 LIMIT 1', ['id' => $collectorInput]);
    if (!$u) {
        echo json_encode(['success' => false, 'message' => '收款人无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if ($methodInput !== null && $methodInput !== '') {
    $m = Db::queryOne(
        "SELECT dict_code FROM system_dict WHERE dict_type = 'payment_method' AND dict_code = :c AND is_enabled = 1 LIMIT 1",
        ['c' => $methodInput]
    );
    if (!$m) {
        echo json_encode(['success' => false, 'message' => '收款方式无效：' . $methodInput], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    Db::beginTransaction();

    $row = Db::queryOne(
        'SELECT i.id, i.contract_id, i.customer_id, i.due_date, i.amount_due, i.amount_paid,
                i.currency, i.collector_user_id, i.payment_method,
                c.net_amount, c.sales_user_id, cu.owner_user_id
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

    $oldDue  = (string)($row['due_date'] ?? '');
    $oldAmt  = round((float)($row['amount_due'] ?? 0), 2);
    $oldPaid = round((float)($row['amount_paid'] ?? 0), 2);
    $newAmt  = round((float)$amountDue, 2);

    // 注意：已撤销「新应收 ≥ 已收」护栏，管理员可自由调整（含修正录入错误）

    // 动态拼装 UPDATE：必填两项 + 可选四项（提交且确实变化才更新）
    $sets   = ['due_date = :due_date', 'amount_due = :amount_due', 'update_time = :t', 'update_user_id = :uid'];
    $params = [
        'due_date'   => $dueDate,
        'amount_due' => $newAmt,
        't'          => time(),
        'uid'        => (int)($user['id'] ?? 0),
        'id'         => $installmentId,
    ];
    $diffNotes = [];
    if ($oldDue !== $dueDate) {
        $diffNotes[] = '到期日 ' . ($oldDue !== '' ? $oldDue : '-') . ' → ' . $dueDate;
    }
    if (abs($oldAmt - $newAmt) > 0.005) {
        $diffNotes[] = '应收 ' . number_format($oldAmt, 2) . ' → ' . number_format($newAmt, 2);
    }

    if ($amountPaidInput !== null) {
        $newPaid = round((float)$amountPaidInput, 2);
        if (abs($oldPaid - $newPaid) > 0.005) {
            $sets[] = 'amount_paid = :amount_paid';
            $params['amount_paid'] = $newPaid;
            $diffNotes[] = '已收 ' . number_format($oldPaid, 2) . ' → ' . number_format($newPaid, 2);
        }
    }

    $oldCurrency = (string)($row['currency'] ?? '');
    if ($currencyInput !== null) {
        $newCurrency = $currencyInput === '' ? null : $currencyInput;
        if ($oldCurrency !== (string)$newCurrency) {
            $sets[] = 'currency = :currency';
            $params['currency'] = $newCurrency;
            $diffNotes[] = '货币 ' . ($oldCurrency !== '' ? $oldCurrency : '-') . ' → ' . ($newCurrency ?? '-');
        }
    }

    $oldCollector = (int)($row['collector_user_id'] ?? 0);
    if ($collectorInput !== null) {
        $newCollectorId = $collectorInput > 0 ? $collectorInput : null;
        if ($oldCollector !== (int)($newCollectorId ?? 0)) {
            $sets[] = 'collector_user_id = :collector_user_id';
            $params['collector_user_id'] = $newCollectorId;
            $oldName = $oldCollector > 0
                ? (Db::queryOne('SELECT realname FROM users WHERE id = :id LIMIT 1', ['id' => $oldCollector])['realname'] ?? '')
                : '';
            $newName = $newCollectorId
                ? (Db::queryOne('SELECT realname FROM users WHERE id = :id LIMIT 1', ['id' => $newCollectorId])['realname'] ?? '')
                : '';
            $diffNotes[] = '收款人 ' . ($oldName ?: '-') . ' → ' . ($newName ?: '-');
        }
    }

    $oldMethod = (string)($row['payment_method'] ?? '');
    if ($methodInput !== null) {
        $newMethod = $methodInput === '' ? null : $methodInput;
        if ($oldMethod !== (string)$newMethod) {
            $sets[] = 'payment_method = :payment_method';
            $params['payment_method'] = $newMethod;
            $diffNotes[] = '收款方式 ' . ($oldMethod !== '' ? getPaymentMethodLabel($oldMethod) : '-')
                         . ' → ' . ($newMethod !== null ? getPaymentMethodLabel($newMethod) : '-');
        }
    }

    Db::execute(
        'UPDATE finance_installments SET ' . implode(', ', $sets) . ' WHERE id = :id',
        $params
    );

    $contractId = (int)$row['contract_id'];
    $customerId = (int)$row['customer_id'];

    // 注意：已撤销「分期合计 = 合同折后金额」硬护栏。改后若不平，仅记录差额到变更日志，不阻塞。
    $sumRow = Db::queryOne(
        'SELECT COALESCE(SUM(amount_due),0) AS s FROM finance_installments WHERE contract_id = :cid AND deleted_at IS NULL',
        ['cid' => $contractId]
    );
    $sum = round((float)($sumRow['s'] ?? 0), 2);
    $net = round((float)($row['net_amount'] ?? 0), 2);
    $imbalance = $sum - $net;
    if (abs($imbalance) > 0.01) {
        $diffNotes[] = '⚠ 分期合计 ' . number_format($sum, 2) . ' 与合同折后金额 ' . number_format($net, 2)
                     . ' 不平 (' . ($imbalance > 0 ? '+' : '') . number_format($imbalance, 2) . ')';
    }

    $note = '编辑分期' . (count($diffNotes) > 0 ? '：' . implode('；', $diffNotes) : '（无字段变更）');

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
            'contract_id'    => $contractId,
            'customer_id'    => $customerId,
            'actor_user_id'  => (int)($user['id'] ?? 0),
            'change_time'    => time(),
            'old_due_date'   => $oldDue !== '' ? $oldDue : null,
            'new_due_date'   => $dueDate,
            'old_amount_due' => $oldAmt,
            'new_amount_due' => $newAmt,
            'note'           => mb_substr($note, 0, 250, 'UTF-8'),
        ]
    );

    Db::commit();

    echo json_encode(['success' => true, 'data' => ['installment_id' => $installmentId]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
