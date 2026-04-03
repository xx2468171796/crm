<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $row = Db::queryOne('SELECT id, user_id FROM finance_saved_views WHERE id = :id LIMIT 1', ['id' => $id]);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => '视图不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$row['user_id'] !== (int)($user['id'] ?? 0)) {
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    Db::execute('UPDATE finance_saved_views SET status = 0, is_default = 0, update_time = :t WHERE id = :id', ['t' => $now, 'id' => $id]);

    echo json_encode(['success' => true, 'message' => '已删除'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
