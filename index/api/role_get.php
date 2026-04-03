<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 角色获取 API
 * GET /api/role_get.php?id=xxx
 */

require_once __DIR__ . '/../core/auth.php';

$user = auth_require();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => '缺少角色 ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $role = Db::queryOne("SELECT * FROM roles WHERE id = ?", [$id]);
    if (!$role) {
        echo json_encode(['success' => false, 'error' => '角色不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取角色权限
    $permissions = Db::query("SELECT permission_code FROM role_permissions WHERE role_id = ?", [$id]);
    $role['permissions'] = array_column($permissions, 'permission_code');
    
    echo json_encode(['success' => true, 'data' => $role], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
