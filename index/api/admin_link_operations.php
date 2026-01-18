<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 管理员链接操作API
 * 处理启用/禁用、设置密码、重新生成token、删除等操作
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$currentUser = current_user();

if (!canOrAdmin(PermissionCode::CUSTOMER_EDIT)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限执行此操作']);
    exit;
}

// 获取操作类型
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'toggle_enabled':
            // 启用/禁用链接
            $linkId = intval($_POST['link_id'] ?? 0);
            $enabled = intval($_POST['enabled'] ?? 0);
            
            if ($linkId <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            Db::execute(
                'UPDATE customer_links SET enabled = :enabled, updated_at = :time WHERE id = :id',
                ['enabled' => $enabled, 'time' => time(), 'id' => $linkId]
            );
            
            // 记录操作日志
            logOperation($currentUser['id'], 'toggle_link', $linkId, $enabled ? '启用链接' : '禁用链接');
            
            echo json_encode([
                'success' => true,
                'message' => $enabled ? '链接已启用' : '链接已禁用'
            ]);
            break;
            
        case 'set_password':
            // 设置/修改密码
            $linkId = intval($_POST['link_id'] ?? 0);
            $password = trim($_POST['password'] ?? '');
            
            if ($linkId <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            if (empty($password)) {
                throw new Exception('密码不能为空');
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            Db::execute(
                'UPDATE customer_links SET password = :password, updated_at = :time WHERE id = :id',
                ['password' => $hashedPassword, 'time' => time(), 'id' => $linkId]
            );
            
            logOperation($currentUser['id'], 'set_password', $linkId, '设置链接密码');
            
            echo json_encode([
                'success' => true,
                'message' => '密码设置成功'
            ]);
            break;
            
        case 'remove_password':
            // 删除密码
            $linkId = intval($_POST['link_id'] ?? 0);
            
            if ($linkId <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            Db::execute(
                'UPDATE customer_links SET password = NULL, updated_at = :time WHERE id = :id',
                ['time' => time(), 'id' => $linkId]
            );
            
            logOperation($currentUser['id'], 'remove_password', $linkId, '删除链接密码');
            
            echo json_encode([
                'success' => true,
                'message' => '密码已删除'
            ]);
            break;
            
        case 'regenerate_token':
            // 重新生成token
            $linkId = intval($_POST['link_id'] ?? 0);
            
            if ($linkId <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 生成新token
            $newToken = bin2hex(random_bytes(32));
            
            Db::execute(
                'UPDATE customer_links SET token = :token, updated_at = :time WHERE id = :id',
                ['token' => $newToken, 'time' => time(), 'id' => $linkId]
            );
            
            logOperation($currentUser['id'], 'regenerate_token', $linkId, '重新生成token');
            
            // 返回新的链接URL
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                     . '://' . $_SERVER['HTTP_HOST'];
            $newUrl = $baseUrl . '/share.php?token=' . $newToken;
            
            echo json_encode([
                'success' => true,
                'message' => 'Token已重新生成',
                'new_token' => $newToken,
                'new_url' => $newUrl
            ]);
            break;
            
        case 'delete_link':
            // 删除链接
            $linkId = intval($_POST['link_id'] ?? 0);
            
            if ($linkId <= 0) {
                throw new Exception('无效的链接ID');
            }
            
            // 获取链接信息用于日志
            $link = Db::queryOne('SELECT customer_id, token FROM customer_links WHERE id = :id', ['id' => $linkId]);
            
            if (!$link) {
                throw new Exception('链接不存在');
            }
            
            Db::execute('DELETE FROM customer_links WHERE id = :id', ['id' => $linkId]);
            
            logOperation($currentUser['id'], 'delete_link', $linkId, '删除链接 (token: ' . $link['token'] . ')');
            
            echo json_encode([
                'success' => true,
                'message' => '链接已删除'
            ]);
            break;
            
        case 'batch_toggle':
            // 批量启用/禁用
            $linkIds = $_POST['link_ids'] ?? [];
            $enabled = intval($_POST['enabled'] ?? 0);
            
            if (empty($linkIds) || !is_array($linkIds)) {
                throw new Exception('请选择要操作的链接');
            }
            
            $linkIds = array_map('intval', $linkIds);
            $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
            
            $params = array_merge($linkIds, [time()]);
            Db::execute(
                "UPDATE customer_links SET enabled = $enabled, updated_at = ? WHERE id IN ($placeholders)",
                $params
            );
            
            $count = count($linkIds);
            logOperation($currentUser['id'], 'batch_toggle', 0, ($enabled ? '批量启用' : '批量禁用') . " {$count} 个链接");
            
            echo json_encode([
                'success' => true,
                'message' => ($enabled ? '已启用' : '已禁用') . " {$count} 个链接"
            ]);
            break;
            
        case 'batch_delete':
            // 批量删除
            $linkIds = $_POST['link_ids'] ?? [];
            
            if (empty($linkIds) || !is_array($linkIds)) {
                throw new Exception('请选择要删除的链接');
            }
            
            $linkIds = array_map('intval', $linkIds);
            $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
            
            Db::execute("DELETE FROM customer_links WHERE id IN ($placeholders)", $linkIds);
            
            $count = count($linkIds);
            logOperation($currentUser['id'], 'batch_delete', 0, "批量删除 {$count} 个链接");
            
            echo json_encode([
                'success' => true,
                'message' => "已删除 {$count} 个链接"
            ]);
            break;
            
        default:
            throw new Exception('无效的操作类型');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 记录操作日志
 */
function logOperation($userId, $action, $linkId, $description, $customerId = null) {
    try {
        Db::execute(
            'INSERT INTO operation_logs 
                (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
             VALUES 
                (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
            [
                'user_id' => $userId,
                'module' => 'customer_links',
                'action' => $action,
                'target_type' => 'customer_link',
                'target_id' => $linkId,
                'customer_id' => $customerId,
                'file_id' => null,
                'description' => $description,
                'extra' => null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => time()
            ]
        );
    } catch (Exception $e) {
        // 日志记录失败不影响主操作
        error_log('操作日志记录失败: ' . $e->getMessage());
    }
}
