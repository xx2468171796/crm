<?php
require_once __DIR__ . '/../core/api_init.php';
// 客户链接管理 API

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permission.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * 移除链接数据中的密码字段，添加 has_password 标记
 * 
 * @param array $link 链接数据
 * @return array 处理后的链接数据
 */
function sanitizeLinkData(array $link): array
{
    $linkData = $link;
    $hasPassword = !empty($link['password']);
    unset($linkData['password']);
    $linkData['has_password'] = $hasPassword;
    return $linkData;
}

// 获取多区域分享链接（GET请求）
if (isset($_GET['action']) && $_GET['action'] === 'get_region_urls') {
    $user = current_user();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    $customerCode = trim($_GET['customer_code'] ?? '');
    if (empty($customerCode)) {
        echo json_encode(['success' => false, 'message' => '缺少客户编码']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/../services/ShareRegionService.php';
        $regions = ShareRegionService::generateRegionUrls($customerCode, '/share.php?code=');
        echo json_encode(['success' => true, 'regions' => $regions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '获取区域链接失败: ' . $e->getMessage()]);
    }
    exit;
}

// 获取用户列表（GET请求，不需要customer_id）
if (isset($_GET['action']) && $_GET['action'] === 'get_users') {
    $user = current_user();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    try {
        $users = Db::query('SELECT id, username, realname, department_id FROM users WHERE status = 1 ORDER BY realname');
        
        // 尝试查询部门表，如果不存在则返回空数组
        try {
            $departments = Db::query('SELECT id, name FROM departments ORDER BY name');
        } catch (Exception $e) {
            $departments = [];
        }
        
        echo json_encode(['success' => true, 'users' => $users, 'departments' => $departments]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询失败: ' . $e->getMessage()]);
    }
    exit;
}

// 需要登录
$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$customerId = intval($_POST['customer_id'] ?? 0);

if ($customerId === 0) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

// 检查权限：是否有权限管理此客户的链接
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在']);
    exit;
}

try {
    switch ($action) {
        case 'update':
            // 更新链接设置（启用状态 + 密码 + 权限）
            $enabled = intval($_POST['enabled'] ?? 1);
            $password = trim($_POST['password'] ?? '');
            $orgPermission = trim($_POST['org_permission'] ?? 'edit');
            $passwordPermission = trim($_POST['password_permission'] ?? 'editable');
            $allowedViewUsers = $_POST['allowed_view_users'] ?? '[]';
            $allowedEditUsers = $_POST['allowed_edit_users'] ?? '[]';
            
            // 验证权限值
            if (!in_array($orgPermission, ['none', 'view', 'edit'])) {
                $orgPermission = 'edit';
            }
            if (!in_array($passwordPermission, ['readonly', 'editable'])) {
                $passwordPermission = 'editable';  // 默认改为可编辑
            }
            
            // 检查链接是否存在
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if (!$link) {
                // 如果链接不存在，自动创建一个默认链接
                error_log("链接不存在，自动创建: customer_id=$customerId");
                $token = bin2hex(random_bytes(32)); // 64位随机token
                $now = time();
                $result = Db::execute('INSERT INTO customer_links 
                    (customer_id, token, enabled, org_permission, password_permission, created_at, updated_at) 
                    VALUES 
                    (:cid, :token, :enabled, :org_permission, :password_permission, :created_at, :updated_at)', [
                    'cid' => $customerId,
                    'token' => $token,
                    'enabled' => $enabled,
                    'org_permission' => $orgPermission,
                    'password_permission' => $passwordPermission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                
                if ($result === false) {
                    echo json_encode(['success' => false, 'message' => '创建链接失败']);
                    exit;
                }
                
                // 重新查询创建的链接
                $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
                if (!$link) {
                    echo json_encode(['success' => false, 'message' => '创建链接后查询失败']);
                    exit;
                }
            }
            
            // 权限检查：使用更宽松的链接权限检查（支持授权用户）
            if (!has_customer_link_permission($user, $customer, $link)) {
                error_log("权限检查失败: user_id={$user['id']}, customer_id=$customerId, user_role={$user['role']}, customer_create_user_id={$customer['create_user_id']}");
                echo json_encode([
                    'success' => false, 
                    'message' => '无权限操作此客户',
                    'debug' => [
                        'user_id' => $user['id'],
                        'user_role' => $user['role'],
                        'customer_create_user_id' => $customer['create_user_id'],
                        'customer_owner_user_id' => $customer['owner_user_id'] ?? null
                    ]
                ]);
                exit;
            }
            
            // 处理密码（如果为空则设为NULL，否则加密）
            $encryptedPassword = null;
            if (!empty($password)) {
                $encryptedPassword = encryptLinkPassword($password);
            }
            
            // 更新设置
            Db::execute(
                'UPDATE customer_links SET 
                    enabled = :enabled, 
                    password = :password,
                    org_permission = :org_permission,
                    password_permission = :password_permission,
                    allowed_view_users = :allowed_view_users,
                    allowed_edit_users = :allowed_edit_users,
                    updated_at = :now 
                WHERE customer_id = :cid',
                [
                    'enabled' => $enabled,
                    'password' => $encryptedPassword,
                    'org_permission' => $orgPermission,
                    'password_permission' => $passwordPermission,
                    'allowed_view_users' => $allowedViewUsers,
                    'allowed_edit_users' => $allowedEditUsers,
                    'now' => time(),
                    'cid' => $customerId
                ]
            );
            
            // 清除权限缓存
            clearLinkPermissionCache($link['id']);
            
            // 返回版本号用于前端缓存清除
            $version = time();
            echo json_encode([
                'success' => true, 
                'message' => '设置已更新',
                'version' => $version,
                'cache_key' => 'link_permission_' . $link['id']
            ]);
            break;
            
        case 'generate':
            // 生成链接 - 需要严格权限（只有创建者/归属者/管理员）
            if (!has_customer_permission($user, $customer)) {
                echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
                exit;
            }
            
            $existing = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            $now = time();
            
            if ($existing) {
                // 链接已存在，自动启用它
                Db::execute('UPDATE customer_links SET enabled = 1, updated_at = :now WHERE customer_id = :cid', [
                    'now' => $now,
                    'cid' => $customerId,
                ]);
                $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
                $message = $existing['enabled'] ? '链接已存在并启用' : '链接已启用';
            } else {
                // 创建新链接
                $token = bin2hex(random_bytes(32)); // 64位随机token
                Db::execute('INSERT INTO customer_links (customer_id, token, enabled, created_at, updated_at) VALUES (:cid, :token, 1, :now, :now)', [
                    'cid' => $customerId,
                    'token' => $token,
                    'now' => $now,
                ]);
                $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
                $message = '链接生成成功';
            }
            
            // 返回版本号用于前端缓存清除
            $version = time();
            echo json_encode([
                'success' => true,
                'message' => $message,
                'data' => sanitizeLinkData($link),
                'version' => $version,
                'cache_key' => 'link_permission_' . $link['id']
            ]);
            break;
            
        case 'toggle':
            // 启用/停用链接
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在']);
                exit;
            }
            
            // 权限检查：支持授权用户
            if (!has_customer_link_permission($user, $customer, $link)) {
                echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
                exit;
            }
            
            $newStatus = $link['enabled'] ? 0 : 1;
            Db::execute('UPDATE customer_links SET enabled = :status, updated_at = :now WHERE customer_id = :cid', [
                'status' => $newStatus,
                'now' => time(),
                'cid' => $customerId,
            ]);
            
            // 清除权限缓存
            clearLinkPermissionCache($link['id']);
            
            // 返回版本号用于前端缓存清除
            $version = time();
            echo json_encode([
                'success' => true,
                'message' => $newStatus ? '链接已启用' : '链接已停用',
                'enabled' => $newStatus,
                'version' => $version,
                'cache_key' => 'link_permission_' . $link['id']
            ]);
            break;
            
        case 'set_password':
            // 设置/修改密码
            $password = trim($_POST['password'] ?? '');
            
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在']);
                exit;
            }
            
            // 权限检查：支持授权用户
            if (!has_customer_link_permission($user, $customer, $link)) {
                echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
                exit;
            }
            
            Db::execute('UPDATE customer_links SET password = :pwd, updated_at = :now WHERE customer_id = :cid', [
                'pwd' => $password === '' ? null : $password,
                'now' => time(),
                'cid' => $customerId,
            ]);
            
            // 清除权限缓存
            clearLinkPermissionCache($link['id']);
            
            // 返回版本号用于前端缓存清除
            $version = time();
            echo json_encode([
                'success' => true,
                'message' => $password === '' ? '密码已清除' : '密码已设置',
                'version' => $version,
                'cache_key' => 'link_permission_' . $link['id']
            ]);
            break;
            
        case 'reset':
            // 重置链接（刷新token）
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在']);
                exit;
            }
            
            // 权限检查：严格权限（只有创建者/归属者/管理员）
            if (!has_customer_permission($user, $customer)) {
                echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
                exit;
            }
            
            $newToken = bin2hex(random_bytes(32));
            Db::execute('UPDATE customer_links SET token = :token, updated_at = :now WHERE customer_id = :cid', [
                'token' => $newToken,
                'now' => time(),
                'cid' => $customerId,
            ]);
            
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            // 清除权限缓存
            clearLinkPermissionCache($link['id']);
            
            // 返回版本号用于前端缓存清除
            $version = time();
            echo json_encode([
                'success' => true,
                'message' => '链接已重置',
                'data' => sanitizeLinkData($link),
                'version' => $version,
                'cache_key' => 'link_permission_' . $link['id']
            ]);
            break;
            
        case 'get':
            // 获取链接信息
            $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在', 'data' => null]);
                exit;
            }
            
            // 权限检查：支持授权用户
            if (!has_customer_link_permission($user, $customer, $link)) {
                echo json_encode(['success' => false, 'message' => '无权限操作此客户']);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'data' => sanitizeLinkData($link)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
}
