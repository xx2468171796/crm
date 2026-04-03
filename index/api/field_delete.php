<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 字段删除 API
 * DELETE /api/field_delete.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少字段 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Db::execute("DELETE FROM custom_fields WHERE id = ?", [$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
