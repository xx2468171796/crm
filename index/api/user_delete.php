<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 用户删除 API
 * DELETE /api/user_delete.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少用户 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($id == $user['id']) {
    echo json_encode(['success' => false, 'error' => '不能删除自己'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::execute("UPDATE users SET status = 0, deleted_at = ? WHERE id = ?", [time(), $id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
