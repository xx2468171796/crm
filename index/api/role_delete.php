<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 角色删除 API
 * DELETE /api/role_delete.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少角色 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 检查是否有用户使用此角色
    $count = Db::getValue("SELECT COUNT(*) FROM users WHERE role_id = ?", [$id]);
    if ($count > 0) {
        echo json_encode(['success' => false, 'error' => '该角色下还有用户，无法删除'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    Db::execute("DELETE FROM roles WHERE id = ?", [$id]);
    Db::execute("DELETE FROM role_permissions WHERE role_id = ?", [$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
