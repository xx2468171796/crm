<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 使用 RBAC 检查权限：finance_status_edit 或 finance_edit 或管理员
if (!canOrAdmin(PermissionCode::FINANCE_STATUS_EDIT) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$installmentId = (int)($_POST['installment_id'] ?? 0);
$newStatus = trim((string)($_POST['new_status'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

$allowed = ['待收', '催款'];

if ($installmentId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：installment_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($newStatus, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => '状态错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($reason === '') {
    echo json_encode(['success' => false, 'message' => '请填写原因'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    $inst = Db::queryOne(
        'SELECT i.id, i.contract_id, i.customer_id, i.status, i.manual_status, i.amount_due, i.amount_paid, c.sales_user_id, cu.owner_user_id
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

    if (($user['role'] ?? '') === 'sales' && (int)$inst['sales_user_id'] !== (int)($user['id'] ?? 0)) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '无权限：只能操作自己客户的分期'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $old = $inst['manual_status'] !== null && $inst['manual_status'] !== '' ? (string)$inst['manual_status'] : (string)($inst['status'] ?? '');

    $due = (float)($inst['amount_due'] ?? 0);
    $paid = (float)($inst['amount_paid'] ?? 0);
    $unpaid = $due - $paid;
    $isFullyPaid = $due > 0 && $unpaid <= 0.00001;

    if ($newStatus === '待收') {
        $manualStatus = $isFullyPaid ? '待收' : null;
    } else {
        $manualStatus = $newStatus;
    }

    Db::execute(
        'UPDATE finance_installments SET manual_status = :ms, update_time = :t, update_user_id = :uid WHERE id = :id',
        [
            'ms' => $manualStatus,
            't' => time(),
            'uid' => (int)($user['id'] ?? 0),
            'id' => $installmentId,
        ]
    );

    Db::execute(
        'INSERT INTO finance_status_change_logs (
            entity_type, entity_id, customer_id, contract_id, installment_id,
            old_status, new_status, reason, actor_user_id, change_time
        ) VALUES (
            "installment", :eid, :customer_id, :contract_id, :installment_id,
            :old_status, :new_status, :reason, :actor_user_id, :change_time
        )',
        [
            'eid' => (int)$inst['id'],
            'customer_id' => (int)$inst['customer_id'],
            'contract_id' => (int)$inst['contract_id'],
            'installment_id' => (int)$inst['id'],
            'old_status' => $old !== '' ? $old : null,
            'new_status' => $newStatus,
            'reason' => $reason,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'change_time' => time(),
        ]
    );

    Db::commit();

    echo json_encode(['success' => true, 'message' => '已更新分期状态', 'data' => ['installment_id' => $installmentId, 'manual_status' => $manualStatus]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
