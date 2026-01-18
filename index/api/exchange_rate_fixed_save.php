<?php
/**
 * 固定汇率保存API - 管理员设置固定汇率
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_MANAGE)) {
    echo json_encode(['success' => false, 'message' => '无权限修改汇率']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';
$fixedRate = $input['fixed_rate'] ?? null;

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => '货币代码不能为空']);
    exit;
}

if ($fixedRate === null || $fixedRate <= 0) {
    echo json_encode(['success' => false, 'message' => '固定汇率必须大于0']);
    exit;
}

try {
    $currency = Db::queryOne("SELECT code, is_base FROM currencies WHERE code = ?", [$code]);
    
    if (!$currency) {
        echo json_encode(['success' => false, 'message' => '货币不存在']);
        exit;
    }
    
    if ($currency['is_base']) {
        echo json_encode(['success' => false, 'message' => '基准货币汇率不可修改']);
        exit;
    }
    
    $now = time();
    $user = current_user();
    
    Db::execute(
        "UPDATE currencies SET fixed_rate = ?, updated_at = ? WHERE code = ?",
        [$fixedRate, $now, $code]
    );
    
    Db::execute(
        "INSERT INTO exchange_rate_history (currency_code, rate_type, rate, created_at, created_by) VALUES (?, 'fixed', ?, ?, ?)",
        [$code, $fixedRate, $now, $user['id']]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '固定汇率已更新'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
