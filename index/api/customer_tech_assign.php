<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户-技术分配 API
 * 
 * POST /api/customer_tech_assign.php
 * - action=assign: 分配技术
 * - action=unassign: 取消分配
 * - action=list: 查询已分配的技术列表
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 权限检查：需要客户编辑权限
if (!canOrAdmin(PermissionCode::CUSTOMER_EDIT)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$customerId = intval($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证客户存在
$customer = Db::queryOne('SELECT id, name, group_code FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'assign':
            $techUserId = intval($_POST['tech_user_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($techUserId <= 0) {
                echo json_encode(['success' => false, 'message' => '缺少技术人员ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 验证技术人员存在且角色为tech
            $techUser = Db::queryOne('SELECT id, realname, role FROM users WHERE id = ? AND status = 1', [$techUserId]);
            if (!$techUser) {
                echo json_encode(['success' => false, 'message' => '技术人员不存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($techUser['role'] !== 'tech') {
                echo json_encode(['success' => false, 'message' => '该用户不是技术角色'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 检查是否已分配
            $existing = Db::queryOne(
                'SELECT id FROM customer_tech_assignments WHERE customer_id = ? AND tech_user_id = ?',
                [$customerId, $techUserId]
            );
            
            if ($existing) {
                echo json_encode(['success' => false, 'message' => '该技术已分配给此客户'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 插入分配记录
            Db::execute(
                'INSERT INTO customer_tech_assignments (customer_id, tech_user_id, assigned_by, assigned_at, notes) VALUES (?, ?, ?, ?, ?)',
                [$customerId, $techUserId, $user['id'], time(), $notes]
            );
            
            echo json_encode([
                'success' => true,
                'message' => '分配成功',
                'data' => [
                    'customer_id' => $customerId,
                    'tech_user_id' => $techUserId,
                    'tech_name' => $techUser['realname']
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'unassign':
            $techUserId = intval($_POST['tech_user_id'] ?? 0);
            
            if ($techUserId <= 0) {
                echo json_encode(['success' => false, 'message' => '缺少技术人员ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $deleted = Db::execute(
                'DELETE FROM customer_tech_assignments WHERE customer_id = ? AND tech_user_id = ?',
                [$customerId, $techUserId]
            );
            
            echo json_encode([
                'success' => true,
                'message' => $deleted > 0 ? '取消分配成功' : '未找到分配记录'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'list':
        default:
            $assignments = Db::query(
                'SELECT cta.*, u.realname as tech_name, u.username as tech_username, 
                        au.realname as assigned_by_name
                 FROM customer_tech_assignments cta
                 LEFT JOIN users u ON cta.tech_user_id = u.id
                 LEFT JOIN users au ON cta.assigned_by = au.id
                 WHERE cta.customer_id = ?
                 ORDER BY cta.assigned_at DESC',
                [$customerId]
            );
            
            // 获取所有可分配的技术人员
            $allTechs = Db::query(
                "SELECT id, realname, username FROM users WHERE role = 'tech' AND status = 1 ORDER BY realname"
            );
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'customer' => $customer,
                    'assignments' => $assignments,
                    'available_techs' => $allTechs
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
