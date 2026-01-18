<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目-技术分配 API
 * 
 * POST /api/project_tech_assign.php
 * - action=sync: 同步分配（批量设置）
 * - action=assign: 分配技术
 * - action=unassign: 取消分配
 * - action=get: 查询已分配的技术列表
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/NotificationService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 权限检查：需要项目分配权限
if (!canOrAdmin(PermissionCode::PROJECT_ASSIGN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? 'get';
$projectId = intval($input['project_id'] ?? $_GET['project_id'] ?? 0);

if ($projectId <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证项目存在
$project = Db::queryOne('SELECT id, project_name, customer_id FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);
if (!$project) {
    echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    switch ($action) {
        case 'sync':
            // 批量同步分配（用于前端多选场景）
            $techUserIds = $input['tech_user_ids'] ?? [];
            if (!is_array($techUserIds)) {
                echo json_encode(['success' => false, 'message' => '技术人员ID列表格式错误'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $pdo->beginTransaction();
            
            // 删除现有分配
            $pdo->prepare('DELETE FROM project_tech_assignments WHERE project_id = ?')->execute([$projectId]);
            
            // 插入新分配
            $now = time();
            $insertStmt = $pdo->prepare(
                'INSERT INTO project_tech_assignments (project_id, tech_user_id, assigned_by, assigned_at, notes) VALUES (?, ?, ?, ?, ?)'
            );
            
            foreach ($techUserIds as $techUserId) {
                $techUserId = intval($techUserId);
                if ($techUserId > 0) {
                    $insertStmt->execute([$projectId, $techUserId, $user['id'], $now, '批量分配']);
                }
            }
            
            // 写入时间线
            $timelineStmt = $pdo->prepare(
                'INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $timelineStmt->execute([
                'project',
                $projectId,
                '分配变更',
                $user['id'],
                json_encode(['tech_user_ids' => $techUserIds, 'action' => 'sync']),
                $now
            ]);
            
            $pdo->commit();
            
            // 发送实时通知给新分配的技术人员
            if (!empty($techUserIds)) {
                $notificationService = new NotificationService($pdo);
                $notificationService->sendProjectAssignNotification(
                    $projectId,
                    $project['project_name'],
                    $techUserIds,
                    $user['id']
                );
            }
            
            echo json_encode([
                'success' => true,
                'message' => '分配成功'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'assign':
            $techUserId = intval($input['tech_user_id'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            
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
                'SELECT id FROM project_tech_assignments WHERE project_id = ? AND tech_user_id = ?',
                [$projectId, $techUserId]
            );
            
            if ($existing) {
                echo json_encode(['success' => false, 'message' => '该技术已分配给此项目'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $now = time();
            
            // 插入分配记录
            Db::execute(
                'INSERT INTO project_tech_assignments (project_id, tech_user_id, assigned_by, assigned_at, notes) VALUES (?, ?, ?, ?, ?)',
                [$projectId, $techUserId, $user['id'], $now, $notes]
            );
            
            // 写入时间线
            Db::execute(
                'INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time) VALUES (?, ?, ?, ?, ?, ?)',
                ['project', $projectId, '分配技术', $user['id'], json_encode(['tech_user_id' => $techUserId, 'tech_name' => $techUser['realname']]), $now]
            );
            
            // 发送实时通知给新分配的技术人员
            $notificationService = new NotificationService($pdo);
            $notificationService->sendProjectAssignNotification(
                $projectId,
                $project['project_name'],
                [$techUserId],
                $user['id']
            );
            
            echo json_encode([
                'success' => true,
                'message' => '分配成功',
                'data' => [
                    'project_id' => $projectId,
                    'tech_user_id' => $techUserId,
                    'tech_name' => $techUser['realname']
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'unassign':
            $techUserId = intval($input['tech_user_id'] ?? 0);
            
            if ($techUserId <= 0) {
                echo json_encode(['success' => false, 'message' => '缺少技术人员ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $deleted = Db::execute(
                'DELETE FROM project_tech_assignments WHERE project_id = ? AND tech_user_id = ?',
                [$projectId, $techUserId]
            );
            
            // 写入时间线
            Db::execute(
                'INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time) VALUES (?, ?, ?, ?, ?, ?)',
                ['project', $projectId, '取消分配', $user['id'], json_encode(['tech_user_id' => $techUserId]), time()]
            );
            
            echo json_encode([
                'success' => true,
                'message' => $deleted > 0 ? '取消分配成功' : '未找到分配记录'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get':
        default:
            $assignments = Db::query(
                'SELECT pta.*, u.realname as tech_name, u.username as tech_username, 
                        au.realname as assigned_by_name
                 FROM project_tech_assignments pta
                 LEFT JOIN users u ON pta.tech_user_id = u.id
                 LEFT JOIN users au ON pta.assigned_by = au.id
                 WHERE pta.project_id = ?
                 ORDER BY pta.assigned_at DESC',
                [$projectId]
            );
            
            // 获取所有可分配的技术人员
            $allTechs = Db::query(
                "SELECT id, realname, username FROM users WHERE role = 'tech' AND status = 1 ORDER BY realname"
            );
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'project' => $project,
                    'assignments' => $assignments,
                    'available_techs' => $allTechs
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
