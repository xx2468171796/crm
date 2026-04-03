<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();
$role = $user['role'] ?? '';
$userId = (int)($user['id'] ?? 0);

$contractId = (int)($_POST['contract_id'] ?? 0);

if ($contractId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：contract_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contract = Db::queryOne('SELECT * FROM finance_contracts WHERE id = ? LIMIT 1', [$contractId]);
if (!$contract) {
    echo json_encode(['success' => false, 'message' => '合同不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = in_array($role, ['admin', 'system_admin', 'super_admin'], true);
$isSalesSelf = ($role === 'sales' && $userId > 0 && (int)$contract['sales_user_id'] === $userId);

if ($isAdmin) {
    // 管理员可直接删除
} elseif ($isSalesSelf) {
    // 销售只能在10分钟内删除自己创建的合同
    $createTime = (int)($contract['create_time'] ?? 0);
    $now = time();
    if ($now - $createTime > 600) {
        echo json_encode(['success' => false, 'message' => '已超过10分钟，无法删除（仅管理员可删除）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => '无权限删除该合同'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::beginTransaction();

    // 删除合同关联的收款记录
    Db::execute('DELETE FROM finance_receipts WHERE installment_id IN (SELECT id FROM finance_installments WHERE contract_id = ?)', [$contractId]);

    // 删除合同关联的分期记录
    Db::execute('DELETE FROM finance_installments WHERE contract_id = ?', [$contractId]);

    // 删除合同附件关联（不删除文件本身，只解绑）
    Db::execute('DELETE FROM finance_contract_files WHERE contract_id = ?', [$contractId]);

    // 删除合同
    Db::execute('DELETE FROM finance_contracts WHERE id = ?', [$contractId]);

    Db::commit();

    echo json_encode([
        'success' => true,
        'message' => '合同已删除',
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    Db::rollback();
    echo json_encode([
        'success' => false,
        'message' => '删除失败：' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
