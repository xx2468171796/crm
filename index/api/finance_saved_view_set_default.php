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
    Db::beginTransaction();

    $view = Db::queryOne('SELECT id, user_id, page_key FROM finance_saved_views WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $id]);
    if (!$view) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '视图不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$view['user_id'] !== (int)($user['id'] ?? 0)) {
        Db::rollback();
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();

    Db::execute(
        'UPDATE finance_saved_views SET is_default = 0, update_time = :t WHERE user_id = :uid AND page_key = :page_key',
        [
            't' => $now,
            'uid' => (int)($user['id'] ?? 0),
            'page_key' => (string)$view['page_key'],
        ]
    );

    Db::execute(
        'UPDATE finance_saved_views SET is_default = 1, status = 1, update_time = :t WHERE id = :id',
        [
            't' => $now,
            'id' => $id,
        ]
    );

    Db::commit();

    echo json_encode(['success' => true, 'message' => '已设为默认'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
