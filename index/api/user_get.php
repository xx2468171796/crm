<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 用户获取 API
 * GET /api/user_get.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少用户 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $targetUser = Db::queryOne("SELECT id, username, realname, role, role_id, department_id, status, created_at FROM users WHERE id = ?", [$id]);
    if (!$targetUser) {
        echo json_encode(['success' => false, 'error' => '用户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $targetUser], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
