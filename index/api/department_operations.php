<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 部门管理操作API
 * 提供部门的创建、更新、删除、启用/禁用、排序等功能
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || (!canOrAdmin(PermissionCode::DEPT_MANAGE))) {
    echo json_encode(['success' => false, 'message' => '需要管理员权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 支持GET请求获取部门信息
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim($_GET['action'] ?? '');
    
    if ($action === 'get') {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的部门ID'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $dept = Db::queryOne('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
        if (!$dept) {
            echo json_encode(['success' => false, 'message' => '部门不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        echo json_encode(['success' => true, 'department' => $dept], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            // 创建部门
            $name = trim($_POST['name'] ?? '');
            $sort = intval($_POST['sort'] ?? 0);
            $status = intval($_POST['status'] ?? 1);
            $remark = trim($_POST['remark'] ?? '');
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '部门名称不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 检查名称是否重复
            $existing = Db::queryOne('SELECT id FROM departments WHERE name = :name', ['name' => $name]);
            if ($existing) {
                echo json_encode(['success' => false, 'message' => '部门名称已存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $now = time();
            Db::execute(
                'INSERT INTO departments (name, sort, status, remark, create_time, update_time) 
                 VALUES (:name, :sort, :status, :remark, :create_time, :update_time)',
                [
                    'name' => $name,
                    'sort' => $sort,
                    'status' => $status,
                    'remark' => $remark,
                    'create_time' => $now,
                    'update_time' => $now
                ]
            );
            
            echo json_encode(['success' => true, 'message' => '部门创建成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'update':
            // 更新部门
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $sort = intval($_POST['sort'] ?? 0);
            $status = intval($_POST['status'] ?? 1);
            $remark = trim($_POST['remark'] ?? '');
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的部门ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '部门名称不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 检查名称是否与其他部门重复
            $existing = Db::queryOne('SELECT id FROM departments WHERE name = :name AND id != :id', [
                'name' => $name,
                'id' => $id
            ]);
            if ($existing) {
                echo json_encode(['success' => false, 'message' => '部门名称已存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            Db::execute(
                'UPDATE departments SET name = :name, sort = :sort, status = :status, remark = :remark, update_time = :update_time 
                 WHERE id = :id',
                [
                    'name' => $name,
                    'sort' => $sort,
                    'status' => $status,
                    'remark' => $remark,
                    'update_time' => time(),
                    'id' => $id
                ]
            );
            
            echo json_encode(['success' => true, 'message' => '部门更新成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete':
            // 删除部门
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的部门ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 检查是否有关联用户
            $userCount = Db::queryOne('SELECT COUNT(*) as count FROM users WHERE department_id = :id', ['id' => $id]);
            if ($userCount['count'] > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => '该部门下有 ' . $userCount['count'] . ' 个用户，请先转移用户后再删除',
                    'user_count' => $userCount['count']
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            Db::execute('DELETE FROM departments WHERE id = :id', ['id' => $id]);
            
            echo json_encode(['success' => true, 'message' => '部门删除成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'toggle_status':
            // 启用/禁用部门
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的部门ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $dept = Db::queryOne('SELECT status FROM departments WHERE id = :id', ['id' => $id]);
            if (!$dept) {
                echo json_encode(['success' => false, 'message' => '部门不存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $newStatus = $dept['status'] ? 0 : 1;
            Db::execute('UPDATE departments SET status = :status, update_time = :update_time WHERE id = :id', [
                'status' => $newStatus,
                'update_time' => time(),
                'id' => $id
            ]);
            
            $message = $newStatus ? '部门已启用' : '部门已禁用';
            echo json_encode(['success' => true, 'message' => $message, 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'move':
            // 调整排序
            $id = intval($_POST['id'] ?? 0);
            $direction = trim($_POST['direction'] ?? '');
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的部门ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if (!in_array($direction, ['up', 'down'])) {
                echo json_encode(['success' => false, 'message' => '无效的移动方向'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $dept = Db::queryOne('SELECT id, sort FROM departments WHERE id = :id', ['id' => $id]);
            if (!$dept) {
                echo json_encode(['success' => false, 'message' => '部门不存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 找到要交换的部门
            if ($direction === 'up') {
                $targetDept = Db::queryOne(
                    'SELECT id, sort FROM departments WHERE sort < :sort ORDER BY sort DESC LIMIT 1',
                    ['sort' => $dept['sort']]
                );
            } else {
                $targetDept = Db::queryOne(
                    'SELECT id, sort FROM departments WHERE sort > :sort ORDER BY sort ASC LIMIT 1',
                    ['sort' => $dept['sort']]
                );
            }
            
            if (!$targetDept) {
                echo json_encode(['success' => false, 'message' => '已经是' . ($direction === 'up' ? '第一个' : '最后一个') . '了'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 交换排序值
            Db::execute('UPDATE departments SET sort = :sort, update_time = :update_time WHERE id = :id', [
                'sort' => $targetDept['sort'],
                'update_time' => time(),
                'id' => $dept['id']
            ]);
            
            Db::execute('UPDATE departments SET sort = :sort, update_time = :update_time WHERE id = :id', [
                'sort' => $dept['sort'],
                'update_time' => time(),
                'id' => $targetDept['id']
            ]);
            
            echo json_encode(['success' => true, 'message' => '排序调整成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
