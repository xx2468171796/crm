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

$id = (int)($_POST['id'] ?? 0);
$isActive = (int)($_POST['is_active'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $row = Db::queryOne('SELECT id FROM commission_rule_sets WHERE id = :id LIMIT 1', ['id' => $id]);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => '规则不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    Db::execute(
        'UPDATE commission_rule_sets SET is_active = :a, updated_at = :t, updated_by = :uid WHERE id = :id',
        [
            'a' => $isActive ? 1 : 0,
            't' => $now,
            'uid' => (int)($user['id'] ?? 0),
            'id' => $id,
        ]
    );

    echo json_encode(['success' => true, 'message' => '已更新'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
