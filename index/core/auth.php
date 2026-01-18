<?php
// 登录与权限相关函数
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(app_config()['app']['session_name'] ?? 'ankotti_crm');
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_require(): void
{
    $token = '';

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        $token = $header;
    } elseif (isset($_POST['_csrf']) && is_string($_POST['_csrf'])) {
        $token = $_POST['_csrf'];
    }

    $expected = csrf_token();
    if ($token === '' || !hash_equals($expected, $token)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 检查是否已登录
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user']) && !empty($_SESSION['user']);
}

/**
 * 获取当前登录用户
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * 页面重定向
 */
function redirect(string $url): void
{
    // 如果URL不是以http开头，添加基础路径
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/' . ltrim($url, '/');
    }
    header('Location: ' . $url);
    exit;
}

/**
 * 要求必须登录
 */
function auth_require(): void
{
    if (!current_user()) {
        // 检查是否是 API 请求（AJAX 或 JSON 请求）
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $isJsonRequest = isset($_SERVER['HTTP_ACCEPT']) && 
                         strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
        $isApiPath = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
        
        if ($isAjax || $isJsonRequest || $isApiPath) {
            // API 请求返回 JSON 错误
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => '请先登录',
                'code' => 'UNAUTHORIZED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 页面请求使用绝对路径重定向
        header('Location: /login.php');
        exit;
    }
}

/**
 * 设置登录状态
 */
function auth_login(array $user): void
{
    $_SESSION['user'] = [
        'id'            => $user['id'],
        'username'      => $user['username'],
        'name'          => $user['realname'] ?? $user['name'] ?? $user['username'],
        'role'          => $user['role'] ?? 'sales',
        'department_id' => $user['department_id'] ?? null,
    ];
}

/**
 * 退出登录
 */
function auth_logout(): void
{
    $_SESSION['user'] = null;
    session_destroy();
}

/**
 * 检查链接访问权限
 * 
 * @param array $link 链接信息
 * @param array|null $user 当前用户（null表示未登录）
 * @param string|null $password 输入的密码
 * @return string 权限级别: 'edit'=可编辑, 'view'=只读, 'none'=拒绝访问
 */
function checkLinkPermission(array $link, ?array $user, ?string $password): string
{
    // 缓存键（需要考虑密码不同导致的权限差异）
    $passwordKey = $password ? hash('sha256', $password) : 'no-password';
    $cacheKey = 'link_permission_' . $link['id'] . '_' . ($user['id'] ?? 'guest') . '_' . $passwordKey;
    
    // 检查缓存（5分钟有效期）
    if (isset($_SESSION[$cacheKey]) && $_SESSION[$cacheKey]['expire'] > time()) {
        return $_SESSION[$cacheKey]['permission'];
    }
    
    $permission = 'none';
    
    // 1. 检查指定用户权限（最高优先级）
    if ($user) {
        // 检查是否在可编辑列表中
        $allowedEditUsers = json_decode($link['allowed_edit_users'] ?? '[]', true) ?: [];
        if (in_array($user['id'], $allowedEditUsers)) {
            $permission = 'edit';
            goto cache_and_return;
        }
        
        // 检查是否在可查看列表中
        $allowedViewUsers = json_decode($link['allowed_view_users'] ?? '[]', true) ?: [];
        if (in_array($user['id'], $allowedViewUsers)) {
            $permission = 'view';
            goto cache_and_return;
        }
    }
    
    // 2. 检查组织内权限（仅当用户已登录时）
    if ($user) {
        $orgPermission = $link['org_permission'] ?? 'edit';
        if ($orgPermission === 'edit') {
            $permission = 'edit';
            goto cache_and_return;
        } elseif ($orgPermission === 'view') {
            $permission = 'view';
            goto cache_and_return;
        }
        // 如果组织内权限是 'none'，继续检查密码权限
        // 如果密码验证失败或没有密码，已登录用户应该返回 'none'
        if ($password && !empty($link['password'])) {
            if (verifyLinkPassword($password, $link['password'])) {
                $passwordPermission = $link['password_permission'] ?? 'editable';
                $permission = ($passwordPermission === 'editable') ? 'edit' : 'view';
                goto cache_and_return;
            }
        }
        // 已登录用户但组织内权限为 'none' 且密码验证失败，返回 'none'
        $permission = 'none';
        goto cache_and_return;
    }
    
    // 3. 检查密码权限（未登录用户）
    if ($password && !empty($link['password'])) {
        if (verifyLinkPassword($password, $link['password'])) {
            $passwordPermission = $link['password_permission'] ?? 'editable';
            $permission = ($passwordPermission === 'editable') ? 'edit' : 'view';
            goto cache_and_return;
        }
    }
    
    // 4. 默认权限（组织外访客只读）
    $permission = 'view';
    
    cache_and_return:
    // 缓存结果
    $_SESSION[$cacheKey] = [
        'permission' => $permission,
        'expire' => time() + 300 // 5分钟
    ];
    
    return $permission;
}

/**
 * 清除链接权限缓存
 * 
 * @param int $linkId 链接ID
 */
function clearLinkPermissionCache(int $linkId): void
{
    // 清除所有与该链接相关的缓存
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'link_permission_' . $linkId . '_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}

/**
 * 获取链接密码加密密钥
 * 使用应用配置中的密钥，如果没有则使用默认密钥
 * 
 * @return string 加密密钥
 */
function getLinkPasswordKey(): string
{
    $config = app_config();
    // 使用应用密钥，如果没有则使用默认密钥（生产环境应该设置）
    return $config['app']['encryption_key'] ?? 'ankotti_link_password_key_2024_default_change_in_production';
}

/**
 * 加密链接密码（可逆加密，用于在管理界面显示）
 * 
 * @param string $password 明文密码
 * @return string 加密后的密码
 */
function encryptLinkPassword(string $password): string
{
    if (empty($password)) {
        return '';
    }
    
    $key = hash('sha256', getLinkPasswordKey(), true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    
    // 将IV和加密数据组合，使用base64编码
    return base64_encode($iv . $encrypted);
}

/**
 * 解密链接密码
 * 
 * @param string $encryptedPassword 加密后的密码
 * @return string|null 解密后的明文密码，如果解密失败返回null
 */
function decryptLinkPassword(string $encryptedPassword): ?string
{
    if (empty($encryptedPassword)) {
        return null;
    }
    
    try {
        $data = base64_decode($encryptedPassword, true);
        if ($data === false || strlen($data) < 16) {
            return null;
        }
        
        $key = hash('sha256', getLinkPasswordKey(), true);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        return $decrypted !== false ? $decrypted : null;
    } catch (Exception $e) {
        error_log('解密链接密码失败: ' . $e->getMessage());
        return null;
    }
}

/**
 * 验证链接密码（兼容旧的hash密码和新的加密密码）
 * 
 * @param string $inputPassword 用户输入的密码
 * @param string $storedPassword 数据库中存储的密码（可能是hash或加密）
 * @return bool 验证是否通过
 */
function verifyLinkPassword(string $inputPassword, string $storedPassword): bool
{
    if (empty($storedPassword)) {
        return false;
    }
    
    // 先尝试作为hash验证（兼容旧数据）
    if (password_verify($inputPassword, $storedPassword)) {
        return true;
    }
    
    // 如果不是hash，尝试解密后比较（新数据）
    $decrypted = decryptLinkPassword($storedPassword);
    if ($decrypted !== null) {
        return hash_equals($decrypted, $inputPassword);
    }
    
    return false;
}

/**
 * 检查是否存在客户分享访问权限（customer_links）
 */
function hasCustomerShareAccess(int $customerId): bool
{
    if ($customerId <= 0) {
        return false;
    }

    if (!isset($_SESSION['share_verified_' . $customerId])) {
        return false;
    }

    return isset($_SESSION['share_editable_' . $customerId]) || isset($_SESSION['share_readonly_' . $customerId]);
}

/**
 * 检查是否存在文件管理分享访问权限（file_manager_links）
 */
function hasFileManagerShareAccess(int $customerId): bool
{
    if ($customerId <= 0) {
        return false;
    }

    if (!isset($_SESSION['file_manager_share_verified_' . $customerId])) {
        return false;
    }

    return isset($_SESSION['file_manager_share_editable_' . $customerId]) || isset($_SESSION['file_manager_share_readonly_' . $customerId]);
}

/**
 * 获取分享访问所使用的密码（客户分享或文件管理分享）
 */
function getShareSessionPassword(int $customerId): ?string
{
    if ($customerId <= 0) {
        return null;
    }

    if (isset($_SESSION['file_manager_share_password_' . $customerId])) {
        return $_SESSION['file_manager_share_password_' . $customerId];
    }

    if (isset($_SESSION['share_password_' . $customerId])) {
        return $_SESSION['share_password_' . $customerId];
    }

    return null;
}

/**
 * 根据分享上下文构建一个访客用户对象
 */
function resolveShareActor(int $customerId): ?array
{
    $hasCustomerShare = hasCustomerShareAccess($customerId);
    $hasFileManagerShare = hasFileManagerShareAccess($customerId);

    if (!$hasCustomerShare && !$hasFileManagerShare) {
        return null;
    }

    return [
        'id' => 0,
        'role' => 'share_guest',
        'share_context' => $hasFileManagerShare ? 'file_manager' : 'customer',
    ];
}
