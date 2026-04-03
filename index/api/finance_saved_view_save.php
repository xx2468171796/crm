<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

$pageKey = trim($_POST['page_key'] ?? '');
$name = trim($_POST['name'] ?? '');
$filtersJson = trim($_POST['filters_json'] ?? '');
$sortJson = trim($_POST['sort_json'] ?? '');
$isDefault = (int)($_POST['is_default'] ?? 0);

if ($pageKey === '') {
    echo json_encode(['success' => false, 'message' => 'page_key不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($name === '') {
    echo json_encode(['success' => false, 'message' => '视图名称不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($name) > 80) {
    echo json_encode(['success' => false, 'message' => '视图名称最多80字'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($filtersJson === '') {
    echo json_encode(['success' => false, 'message' => 'filters_json不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($filtersJson, true);
if (!is_array($decoded)) {
    echo json_encode(['success' => false, 'message' => 'filters_json格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($sortJson !== '') {
    $sortDecoded = json_decode($sortJson, true);
    if (!is_array($sortDecoded)) {
        echo json_encode(['success' => false, 'message' => 'sort_json格式错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $sortJson = null;
}

try {
    Db::beginTransaction();

    $now = time();

    if ($isDefault === 1) {
        Db::execute(
            'UPDATE finance_saved_views SET is_default = 0, update_time = :t WHERE user_id = :uid AND page_key = :page_key',
            [
                't' => $now,
                'uid' => (int)($user['id'] ?? 0),
                'page_key' => $pageKey,
            ]
        );
    }

    Db::execute(
        'INSERT INTO finance_saved_views (user_id, page_key, name, filters_json, sort_json, is_default, status, create_time, update_time)
         VALUES (:user_id, :page_key, :name, :filters_json, :sort_json, :is_default, 1, :create_time, :update_time)',
        [
            'user_id' => (int)($user['id'] ?? 0),
            'page_key' => $pageKey,
            'name' => $name,
            'filters_json' => $filtersJson,
            'sort_json' => $sortJson,
            'is_default' => $isDefault === 1 ? 1 : 0,
            'create_time' => $now,
            'update_time' => $now,
        ]
    );

    $id = (int)Db::lastInsertId();

    Db::commit();

    echo json_encode(['success' => true, 'message' => '已保存', 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try {
        Db::rollback();
    } catch (Exception $ignore) {
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
