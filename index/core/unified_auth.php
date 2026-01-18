<?php
/**
 * 统一认证模块（三端共用）
 * 
 * 支持：
 * - 桌面端: Authorization: Bearer <token>
 * - Web端: Session cookie
 * - 客户门户: X-Portal-Token
 */

require_once __DIR__ . '/db.php';

/**
 * 统一认证检查
 * 
 * @return array ['success' => bool, 'user' => array|null, 'type' => string, 'error' => string|null]
 */
function unified_auth(): array
{
    $user = null;
    $authType = 'unknown';
    
    // 1. 尝试桌面端认证（Bearer Token）
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        try {
            $tokenData = Db::queryOne(
                "SELECT user_id, expires_at FROM desktop_tokens WHERE token = ?",
                [$token]
            );
            if ($tokenData && $tokenData['expires_at'] > time()) {
                $userData = Db::queryOne(
                    "SELECT id, username, realname, role, department_id FROM users WHERE id = ? AND status = 1",
                    [$tokenData['user_id']]
                );
                if ($userData) {
                    $user = $userData;
                    $user['type'] = 'user';
                    $authType = 'desktop';
                }
            }
        } catch (Exception $e) {
            error_log('[unified_auth] Desktop auth error: ' . $e->getMessage());
        }
    }
    
    // 2. 尝试Web端Session认证
    if (!$user) {
        if (session_status() === PHP_SESSION_NONE) {
            // 使用与auth.php相同的session名称
            require_once __DIR__ . '/../config.php';
            session_name(app_config()['app']['session_name'] ?? 'ankotti_crm');
            session_start();
        }
        if (!empty($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
            // auth.php 存储格式: $_SESSION['user'] = ['id' => ..., 'username' => ..., ...]
            $user = [
                'id' => $_SESSION['user']['id'],
                'username' => $_SESSION['user']['username'] ?? '',
                'realname' => $_SESSION['user']['name'] ?? '',
                'role' => $_SESSION['user']['role'] ?? 'sales',
                'department_id' => $_SESSION['user']['department_id'] ?? null,
                'type' => 'user',
            ];
            $authType = 'web';
        } elseif (!empty($_SESSION['user_id'])) {
            // 兼容旧的session格式
            try {
                $userData = Db::queryOne(
                    "SELECT id, username, realname, role, department_id FROM users WHERE id = ? AND status = 1",
                    [$_SESSION['user_id']]
                );
                if ($userData) {
                    $user = $userData;
                    $user['type'] = 'user';
                    $authType = 'web';
                }
            } catch (Exception $e) {
                error_log('[unified_auth] Web auth error: ' . $e->getMessage());
            }
        }
    }
    
    // 3. 尝试客户门户Token认证
    if (!$user) {
        $portalToken = $_SERVER['HTTP_X_PORTAL_TOKEN'] ?? '';
        if ($portalToken) {
            try {
                $tokenData = Db::queryOne(
                    "SELECT customer_id, expires_at FROM customer_portal_tokens WHERE token = ? AND expires_at > ?",
                    [$portalToken, time()]
                );
                if ($tokenData) {
                    $customer = Db::queryOne(
                        "SELECT id, name, group_code FROM customers WHERE id = ?",
                        [$tokenData['customer_id']]
                    );
                    if ($customer) {
                        $user = [
                            'id' => $customer['id'],
                            'type' => 'customer',
                            'name' => $customer['name'],
                            'group_code' => $customer['group_code'],
                        ];
                        $authType = 'portal';
                    }
                }
            } catch (Exception $e) {
                error_log('[unified_auth] Portal auth error: ' . $e->getMessage());
            }
        }
    }
    
    if (!$user) {
        return [
            'success' => false,
            'user' => null,
            'type' => 'unknown',
            'error' => '未授权访问',
        ];
    }
    
    return [
        'success' => true,
        'user' => $user,
        'type' => $authType,
        'error' => null,
    ];
}

/**
 * 要求认证（失败时直接退出）
 */
function unified_auth_require(): array
{
    $result = unified_auth();
    if (!$result['success']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $result;
}
