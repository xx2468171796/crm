<?php
require_once __DIR__ . '/../core/api_init.php';
// 文件分享链接管理 API

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permission.php';
require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../services/FileLinkService.php';
require_once __DIR__ . '/../services/CustomerFilePolicy.php';
require_once __DIR__ . '/../services/ShareRegionService.php';

header('Content-Type: application/json; charset=utf-8');

// 获取用户列表（GET请求，不需要file_id）
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
$fileId = intval($_POST['file_id'] ?? 0);

if ($fileId === 0) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

// 获取文件信息
$file = Db::queryOne('SELECT * FROM customer_files WHERE id = :id AND deleted_at IS NULL', ['id' => $fileId]);
if (!$file) {
    echo json_encode(['success' => false, 'message' => '文件不存在']);
    exit;
}

// 获取客户信息
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $file['customer_id']]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在']);
    exit;
}

// 检查用户是否有文件访问权限（用于生成分享链接）
$hasFilePermission = CustomerFilePolicy::canView($user, $customer);

try {
    switch ($action) {
        case 'create':
            // 创建文件分享链接
            if (!$hasFilePermission) {
                echo json_encode(['success' => false, 'message' => '无权限操作此文件']);
                exit;
            }
            
            // 检查是否已存在链接
            $existing = FileLinkService::getByFileId($fileId);
            if ($existing) {
                echo json_encode(['success' => false, 'message' => '链接已存在，请使用更新功能']);
                exit;
            }
            
            $enabled = intval($_POST['enabled'] ?? 1);
            $password = trim($_POST['password'] ?? '');
            $orgPermission = trim($_POST['org_permission'] ?? 'edit');
            $passwordPermission = trim($_POST['password_permission'] ?? 'editable');
            $allowedViewUsers = $_POST['allowed_view_users'] ?? '[]';
            $allowedEditUsers = $_POST['allowed_edit_users'] ?? '[]';
            
            // 解析JSON数组
            $allowedViewUsersArray = json_decode($allowedViewUsers, true) ?: [];
            $allowedEditUsersArray = json_decode($allowedEditUsers, true) ?: [];
            
            $link = FileLinkService::create($fileId, [
                'enabled' => $enabled,
                'password' => $password,
                'org_permission' => $orgPermission,
                'password_permission' => $passwordPermission,
                'allowed_view_users' => $allowedViewUsersArray,
                'allowed_edit_users' => $allowedEditUsersArray
            ]);
            
            // 生成分享链接URL
            $shareUrl = Url::base() . '/file_share.php?token=' . $link['token'];
            
            // 解密密码用于显示（仅在有权限时）
            $linkData = $link;
            $linkData['has_password'] = !empty($link['password']);
            if (!empty($link['password'])) {
                $linkData['password'] = decryptLinkPassword($link['password']) ?? '';
            } else {
                $linkData['password'] = '';
            }
            
            // 生成多区域链接
            $regionUrls = ShareRegionService::generateRegionUrls($link['token']);
            
            echo json_encode([
                'success' => true,
                'message' => '链接生成成功',
                'data' => $linkData,
                'share_url' => $shareUrl,
                'region_urls' => $regionUrls
            ]);
            break;
            
        case 'update':
            // 更新链接设置
            $link = FileLinkService::getByFileId($fileId);
            if (!$link) {
                // 如果链接不存在，自动创建一个默认链接
                $enabled = intval($_POST['enabled'] ?? 1);
                $password = trim($_POST['password'] ?? '');
                $orgPermission = trim($_POST['org_permission'] ?? 'edit');
                $passwordPermission = trim($_POST['password_permission'] ?? 'editable');
                $allowedViewUsers = $_POST['allowed_view_users'] ?? '[]';
                $allowedEditUsers = $_POST['allowed_edit_users'] ?? '[]';
                
                // 解析JSON数组
                $allowedViewUsersArray = json_decode($allowedViewUsers, true) ?: [];
                $allowedEditUsersArray = json_decode($allowedEditUsers, true) ?: [];
                
                $link = FileLinkService::create($fileId, [
                    'enabled' => $enabled,
                    'password' => $password,
                    'org_permission' => $orgPermission,
                    'password_permission' => $passwordPermission,
                    'allowed_view_users' => $allowedViewUsersArray,
                    'allowed_edit_users' => $allowedEditUsersArray
                ]);
            }
            
            // 检查权限：只有有文件访问权限的用户才能更新
            if (!$hasFilePermission) {
                echo json_encode(['success' => false, 'message' => '无权限操作此文件']);
                exit;
            }
            
            $enabled = intval($_POST['enabled'] ?? $link['enabled']);
            $password = trim($_POST['password'] ?? '');
            $orgPermission = trim($_POST['org_permission'] ?? $link['org_permission']);
            $passwordPermission = trim($_POST['password_permission'] ?? $link['password_permission']);
            $allowedViewUsers = $_POST['allowed_view_users'] ?? '[]';
            $allowedEditUsers = $_POST['allowed_edit_users'] ?? '[]';
            
            // 解析JSON数组
            $allowedViewUsersArray = json_decode($allowedViewUsers, true) ?: [];
            $allowedEditUsersArray = json_decode($allowedEditUsers, true) ?: [];
            
            $updateOptions = [
                'enabled' => $enabled,
                'org_permission' => $orgPermission,
                'password_permission' => $passwordPermission,
                'allowed_view_users' => $allowedViewUsersArray,
                'allowed_edit_users' => $allowedEditUsersArray
            ];
            
            // 如果提供了密码，更新密码
            if (isset($_POST['password'])) {
                $updateOptions['password'] = $password;
            }
            
            $link = FileLinkService::update($link['id'], $updateOptions);
            
            // 生成分享链接URL
            $shareUrl = Url::base() . '/file_share.php?token=' . $link['token'];
            
            // 解密密码用于显示（仅在有权限时）
            $linkData = $link;
            $linkData['has_password'] = !empty($link['password']);
            if (!empty($link['password'])) {
                $linkData['password'] = decryptLinkPassword($link['password']) ?? '';
            } else {
                $linkData['password'] = '';
            }
            
            // 生成多区域链接
            $regionUrls = ShareRegionService::generateRegionUrls($link['token']);
            
            echo json_encode([
                'success' => true,
                'message' => '设置已更新',
                'data' => $linkData,
                'share_url' => $shareUrl,
                'region_urls' => $regionUrls
            ]);
            break;
            
        case 'toggle':
            // 启用/停用链接
            $link = FileLinkService::getByFileId($fileId);
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在']);
                exit;
            }
            
            if (!$hasFilePermission) {
                echo json_encode(['success' => false, 'message' => '无权限操作此文件']);
                exit;
            }
            
            $newStatus = $link['enabled'] ? 0 : 1;
            $link = FileLinkService::update($link['id'], ['enabled' => $newStatus]);
            
            echo json_encode([
                'success' => true,
                'message' => $newStatus ? '链接已启用' : '链接已停用',
                'enabled' => $newStatus
            ]);
            break;
            
        case 'delete':
            // 删除链接
            $link = FileLinkService::getByFileId($fileId);
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在']);
                exit;
            }
            
            if (!$hasFilePermission) {
                echo json_encode(['success' => false, 'message' => '无权限操作此文件']);
                exit;
            }
            
            FileLinkService::delete($link['id']);
            
            echo json_encode([
                'success' => true,
                'message' => '链接已删除'
            ]);
            break;
            
        case 'get':
            // 获取链接信息
            $link = FileLinkService::getByFileId($fileId);
            
            if (!$link) {
                echo json_encode(['success' => false, 'message' => '链接不存在', 'data' => null]);
                exit;
            }
            
            if (!$hasFilePermission) {
                echo json_encode(['success' => false, 'message' => '无权限操作此文件']);
                exit;
            }
            
            // 生成分享链接URL
            $shareUrl = Url::base() . '/file_share.php?token=' . $link['token'];
            
            // 解密密码用于显示（仅在有权限时）
            $linkData = $link;
            $linkData['has_password'] = !empty($link['password']);
            if (!empty($link['password'])) {
                $linkData['password'] = decryptLinkPassword($link['password']) ?? '';
            } else {
                $linkData['password'] = '';
            }
            
            // 生成多区域链接
            $regionUrls = ShareRegionService::generateRegionUrls($link['token']);
            
            echo json_encode([
                'success' => true,
                'data' => $linkData,
                'share_url' => $shareUrl,
                'region_urls' => $regionUrls
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
}

