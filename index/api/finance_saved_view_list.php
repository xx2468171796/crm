<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

$pageKey = trim($_GET['page_key'] ?? '');
if ($pageKey === '') {
    echo json_encode(['success' => false, 'message' => 'page_key不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = Db::query(
    'SELECT id, page_key, name, filters_json, sort_json, is_default, status, create_time, update_time
     FROM finance_saved_views
     WHERE user_id = :uid AND page_key = :page_key AND status = 1
     ORDER BY is_default DESC, id DESC',
    [
        'uid' => (int)($user['id'] ?? 0),
        'page_key' => $pageKey,
    ]
);

$data = [];
foreach ($rows as $r) {
    $data[] = [
        'id' => (int)($r['id'] ?? 0),
        'page_key' => $r['page_key'],
        'name' => $r['name'],
        'filters_json' => $r['filters_json'],
        'sort_json' => $r['sort_json'],
        'is_default' => (int)($r['is_default'] ?? 0),
        'create_time' => (int)($r['create_time'] ?? 0),
        'update_time' => (int)($r['update_time'] ?? 0),
    ];
}

echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
