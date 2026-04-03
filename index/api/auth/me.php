<?php
require_once __DIR__ . '/../../core/api_init.php';
/**
 * 获取当前用户信息 API
 * 
 * GET /api/auth/me
 * 
 * 用途：
 * - 连接测试（返回 401 表示连接成功但未登录）
 * - 获取当前登录用户信息
 */

// CORS 配置 - 支持 Tauri 桌面端等跨域请求
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/db.php';

// 获取 Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';

if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

// 无 token，返回 401（连接测试用）
if (!$token) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'UNAUTHORIZED', 'message' => '未登录']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 验证 token
    $tokenRecord = Db::queryOne(
        'SELECT user_id, expire_at FROM desktop_tokens WHERE token = ? LIMIT 1',
        [$token]
    );
    
    if (!$tokenRecord) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Token 无效']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($tokenRecord['expire_at'] < time()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'TOKEN_EXPIRED', 'message' => 'Token 已过期']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取用户信息
    $user = Db::queryOne(
        'SELECT id, username, realname, role, department_id, status FROM users WHERE id = ? LIMIT 1',
        [$tokenRecord['user_id']]
    );
    
    if (!$user || $user['status'] != 1) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'USER_INVALID', 'message' => '用户不存在或已禁用']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 返回用户信息
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'name' => $user['realname'] ?? $user['username'],
            'role' => $user['role'],
            'department_id' => $user['department_id'] ? (int)$user['department_id'] : null,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] auth/me 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器内部错误']
    ], JSON_UNESCAPED_UNICODE);
}
