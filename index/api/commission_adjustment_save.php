<?php
/**
 * 保存提成手动调整
 */
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

csrf_require();

$userId = (int)($_POST['user_id'] ?? 0);
$month = trim($_POST['month'] ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择销售人员'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => '月份格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($amount == 0) {
    echo json_encode(['success' => false, 'message' => '调整金额不能为0'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => '请填写调整原因'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    error_log("[SALARY_AUDIT] Saving adjustment: user_id=$userId, month=$month, amount=$amount, reason=$reason, created_by=" . $user['id']);
    
    Db::exec(
        "INSERT INTO commission_adjustments (user_id, month, amount, reason, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $month, $amount, $reason, $user['id'], time()]
    );
    
    error_log("[SALARY_AUDIT] Adjustment saved successfully");
    echo json_encode(['success' => true, 'message' => '保存成功'], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("[SALARY_AUDIT] Adjustment save error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
