<?php

require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端登录 API
 * 
 * POST /api/desktop_login.php
 * 
 * 请求参数：
 * - username: 用户名
 * - password: 密码
 * 
 * 成功响应：
 * {
 *   "success": true,
 *   "data": {
 *     "token": "...",
 *     "user": { "id", "username", "name", "role" }
 *   }
 * }
 * 
 * 失败响应：
 * {
 *   "success": false,
 *   "error": { "code": "...", "message": "..." }
 * }
 */

header('Content-Type: application/json; charset=utf-8');

// CORS 配置 - 支持 Tauri 桌面端等跨域请求
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 只允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '仅支持 POST 请求']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析请求体（支持 JSON 和表单）
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

// 参数校验
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'INVALID_PARAMS', 'message' => '用户名和密码不能为空']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 查询用户
    $user = Db::queryOne(
        'SELECT id, username, password, realname, role, department_id, status FROM users WHERE username = ? LIMIT 1',
        [$username]
    );
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'USER_NOT_FOUND', 'message' => '用户不存在']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($user['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'USER_DISABLED', 'message' => '用户已被禁用']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'WRONG_PASSWORD', 'message' => '密码错误']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 生成简单 token（生产环境建议用 JWT）
    $token = bin2hex(random_bytes(32));
    $expireAt = time() + 86400 * 7; // 7天有效期
    
    // 存储 token（使用简单的数据库存储，生产环境可用 Redis）
    // 先检查 desktop_tokens 表是否存在，不存在则创建
    try {
        Db::execute("
            CREATE TABLE IF NOT EXISTS desktop_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL,
                expire_at INT NOT NULL,
                created_at INT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_token (token),
                KEY idx_user_id (user_id),
                KEY idx_expire_at (expire_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='桌面端登录 Token'
        ");
    } catch (Exception $e) {
        // 表已存在，忽略
    }
    
    // 插入 token
    Db::execute(
        'INSERT INTO desktop_tokens (user_id, token, expire_at, created_at) VALUES (?, ?, ?, ?)',
        [$user['id'], $token, $expireAt, time()]
    );
    
    // 返回成功
    echo json_encode([
        'success' => true,
        'data' => [
            'token' => $token,
            'expire_at' => $expireAt,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'name' => $user['realname'] ?? $user['username'],
                'role' => $user['role'],
                'department_id' => $user['department_id'] ? (int)$user['department_id'] : null,
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[SYNC_DEBUG] 桌面端登录失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器内部错误']
    ], JSON_UNESCAPED_UNICODE);
}
