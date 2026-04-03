<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 字段获取 API
 * GET /api/field_get.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少字段 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $field = Db::queryOne("SELECT * FROM custom_fields WHERE id = ?", [$id]);
    if (!$field) {
        echo json_encode(['success' => false, 'error' => '字段不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $field], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
