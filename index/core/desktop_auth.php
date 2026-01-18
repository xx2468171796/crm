<?php

/**
 * 桌面端 Token 认证
 * 
 * 用法：
 * require_once __DIR__ . '/../core/desktop_auth.php';
 * $user = desktop_auth_require(); // 返回用户信息或终止请求
 */

require_once __DIR__ . '/db.php';

/**
 * 从请求头获取 token
 */
function desktop_get_token(): ?string
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    
    // 也支持从查询参数获取（用于调试）
    return $_GET['token'] ?? null;
}

/**
 * 验证 token 并返回用户信息
 * 
 * @param string $token
 * @return array|null 用户信息或 null
 */
function desktop_verify_token(string $token): ?array
{
    if (empty($token)) {
        return null;
    }
    
    try {
        // 查询 token
        $tokenRow = Db::queryOne(
            'SELECT user_id, expire_at FROM desktop_tokens WHERE token = ? LIMIT 1',
            [$token]
        );
        
        if (!$tokenRow) {
            return null;
        }
        
        // 检查是否过期
        if ($tokenRow['expire_at'] < time()) {
            // 删除过期 token
            Db::execute('DELETE FROM desktop_tokens WHERE token = ?', [$token]);
            return null;
        }
        
        // 查询用户
        $user = Db::queryOne(
            'SELECT id, username, realname, role, department_id, status FROM users WHERE id = ? LIMIT 1',
            [$tokenRow['user_id']]
        );
        
        if (!$user || $user['status'] != 1) {
            return null;
        }
        
        return [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'name' => $user['realname'] ?? $user['username'],
            'role' => $user['role'],
            'department_id' => $user['department_id'] ? (int)$user['department_id'] : null,
        ];
        
    } catch (Exception $e) {
        error_log('[SYNC_DEBUG] Token 验证失败: ' . $e->getMessage());
        return null;
    }
}

/**
 * 要求桌面端认证
 * 如果未认证，返回 401 并终止请求
 * 
 * @return array 用户信息
 */
function desktop_auth_require(): array
{
    header('Content-Type: application/json; charset=utf-8');
    
    $token = desktop_get_token();
    
    if (!$token) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'NO_TOKEN', 'message' => '缺少认证 Token']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $user = desktop_verify_token($token);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Token 无效或已过期']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $user;
}

/**
 * 检查用户是否有指定角色
 * 
 * @param array $user 用户信息
 * @param array $allowedRoles 允许的角色列表
 * @return bool
 */
function desktop_has_role(array $user, array $allowedRoles): bool
{
    return in_array($user['role'], $allowedRoles, true);
}

/**
 * 要求指定角色
 * 
 * @param array $user 用户信息
 * @param array $allowedRoles 允许的角色列表
 */
function desktop_require_role(array $user, array $allowedRoles): void
{
    if (!desktop_has_role($user, $allowedRoles)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'FORBIDDEN', 'message' => '权限不足']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
