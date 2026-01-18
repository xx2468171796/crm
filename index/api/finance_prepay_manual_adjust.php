<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = (int)($_POST['customer_id'] ?? 0);
$direction = trim((string)($_POST['direction'] ?? 'in'));
$amountStr = trim((string)($_POST['amount'] ?? ''));
$method = trim((string)($_POST['method'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));

// 使用统一收款方式字典
if ($method !== '') {
    $methodLabel = getPaymentMethodLabel($method);
    if ($methodLabel !== '') {
        $note = '[' . $methodLabel . '] ' . $note;
    }
}

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => '客户ID错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($direction, ['in', 'out'], true)) {
    echo json_encode(['success' => false, 'message' => '方向错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($amountStr === '' || !is_numeric($amountStr)) {
    echo json_encode(['success' => false, 'message' => '请输入有效金额'], JSON_UNESCAPED_UNICODE);
    exit;
}

$amount = (float)$amountStr;
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => '金额必须大于0'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($note) > 255) {
    echo json_encode(['success' => false, 'message' => '备注最多255字'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $cust = Db::queryOne('SELECT id, owner_user_id FROM customers WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1', ['id' => $customerId]);
    if (!$cust) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($user['role'] ?? '') === 'sales') {
        if ($direction !== 'in') {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '无权限：销售仅可新增预收'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int)($cust['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '无权限：只能操作自己名下客户'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    Db::query('SELECT id FROM finance_prepay_ledger WHERE customer_id = :cid FOR UPDATE', ['cid' => $customerId]);

    $balanceRow = Db::queryOne(
        'SELECT COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS balance
         FROM finance_prepay_ledger
         WHERE customer_id = :cid',
        ['cid' => $customerId]
    );
    $balanceBefore = (float)($balanceRow['balance'] ?? 0);

    if ($direction === 'out' && $balanceBefore + 0.00001 < $amount) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '预收余额不足，无法扣减'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    Db::execute(
        'INSERT INTO finance_prepay_ledger (customer_id, direction, amount, source_type, source_id, note, created_at, created_by)
         VALUES (:customer_id, :direction, :amount, "manual_adjust", NULL, :note, :created_at, :created_by)',
        [
            'customer_id' => $customerId,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'note' => $note !== '' ? $note : null,
            'created_at' => $now,
            'created_by' => (int)($user['id'] ?? 0),
        ]
    );

    $balanceAfter = $direction === 'in'
        ? ($balanceBefore + $amount)
        : max(0.0, $balanceBefore - $amount);

    Db::commit();

    echo json_encode([
        'success' => true,
        'message' => '已记账',
        'data' => [
            'customer_id' => $customerId,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'balance_before' => $balanceBefore,
            'balance_after' => round($balanceAfter, 2),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
