<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 团队任务看板 API（仅主管）
 * 
 * GET /api/desktop_team_tasks.php - 获取团队所有成员的任务
 * 参数: date (可选), view (today/yesterday/future/help/all)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = desktop_auth_require();

// 权限检查：仅主管可访问
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
if (!$isManager) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '无权访问团队任务'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $action = $_GET['action'] ?? 'list';
    if ($action === 'members') {
        handleMembers($user);
    } else {
        handleGet($user);
    }
} catch (Exception $e) {
    error_log('[API] desktop_team_tasks 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    error_log('[API] desktop_team_tasks Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $view = $_GET['view'] ?? 'today';
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // 获取团队成员（同部门的用户）
    $members = Db::query("
        SELECT id, realname, role 
        FROM users 
        WHERE status = 1
        ORDER BY role DESC, realname ASC
    ");
    
    $result = [];
    
    foreach ($members as $member) {
        // 构建查询条件
        $conditions = ["t.user_id = ?"];
        $params = [$member['id']];
        
        switch ($view) {
            case 'today':
                $conditions[] = "t.task_date = ?";
                $params[] = $today;
                break;
            case 'yesterday':
                $conditions[] = "t.task_date = ? AND t.status != 'completed'";
                $params[] = $yesterday;
                break;
            case 'future':
                $conditions[] = "t.task_date > ?";
                $params[] = $today;
                break;
            case 'help':
                $conditions[] = "t.need_help = 1";
                break;
            default:
                // all - 不加日期限制，但限制最近30天
                $conditions[] = "t.task_date >= DATE_SUB(?, INTERVAL 30 DAY)";
                $params[] = $today;
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $tasks = Db::query("
            SELECT 
                t.id,
                t.title,
                t.task_date,
                t.status,
                t.project_id,
                t.need_help,
                t.assigned_by,
                p.project_name,
                p.project_code
            FROM daily_tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE {$whereClause}
            ORDER BY t.task_date ASC, t.id DESC
        ", $params);
        
        $result[] = [
            'user_id' => (int)$member['id'],
            'user_name' => $member['realname'],
            'role' => $member['role'],
            'task_count' => count($tasks),
            'completed_count' => count(array_filter($tasks, fn($t) => $t['status'] === 'completed')),
            'tasks' => $tasks,
        ];
    }
    
    // 过滤掉没有任务的成员（可选）
    // $result = array_filter($result, fn($m) => $m['task_count'] > 0);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'members' => array_values($result),
            'view' => $view,
            'date' => $date,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handleMembers($user) {
    // 获取团队成员列表（用于分配任务）
    $members = Db::query("
        SELECT id, realname as name, role 
        FROM users 
        WHERE status = 1 AND role IN ('tech', 'tech_manager', 'designer')
        ORDER BY realname ASC
    ");
    
    echo json_encode([
        'success' => true,
        'data' => [
            'members' => $members ?: []
        ]
    ], JSON_UNESCAPED_UNICODE);
}
