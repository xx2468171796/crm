<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 任务管理 API
 * 
 * GET ?action=my_tasks - 获取我的任务
 * POST action=create - 创建任务
 * POST action=update_status - 更新任务状态
 * POST action=assign - 分配任务（技术主管）
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 任务状态常量
const TASK_STATUS_PENDING = 'pending';
const TASK_STATUS_IN_PROGRESS = 'in_progress';
const TASK_STATUS_COMPLETED = 'completed';
const TASK_VALID_STATUSES = [TASK_STATUS_PENDING, TASK_STATUS_IN_PROGRESS, TASK_STATUS_COMPLETED];

// 任务优先级常量
const TASK_PRIORITY_HIGH = 'high';
const TASK_PRIORITY_MEDIUM = 'medium';
const TASK_PRIORITY_LOW = 'low';
const TASK_VALID_PRIORITIES = [TASK_PRIORITY_HIGH, TASK_PRIORITY_MEDIUM, TASK_PRIORITY_LOW];

// 认证
$user = desktop_auth_require();

$action = $_GET['action'] ?? $_POST['action'] ?? 'my_tasks';
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    switch ($action) {
        case 'my_tasks':
            handleMyTasks($user, $isManager);
            break;
        case 'create':
            handleCreate($user);
            break;
        case 'update_status':
            handleUpdateStatus($user, $isManager);
            break;
        case 'assign':
            handleAssign($user, $isManager);
            break;
        case 'delete':
            handleDelete($user, $isManager);
            break;
        case 'update':
            handleUpdate($user, $isManager);
            break;
        case 'projects':
            handleProjects($user, $isManager);
            break;
        case 'users':
            handleUsers($user, $isManager);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_tasks_manage 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取排序子句
 */
function getSortClause($sortBy, $sortOrder) {
    $validSorts = [
        'priority' => "FIELD(t.priority, 'high', 'medium', 'low'), t.deadline ASC, t.create_time DESC",
        'deadline' => "t.deadline {$sortOrder}, t.create_time DESC",
        'create_time' => "t.create_time {$sortOrder}",
        'status' => "FIELD(t.status, 'in_progress', 'pending', 'completed'), t.create_time DESC",
        'update_time' => "t.update_time {$sortOrder}",
    ];
    return $validSorts[$sortBy] ?? $validSorts['priority'];
}

/**
 * 获取我的任务
 */
function handleMyTasks($user, $isManager) {
    $filterStatus = $_GET['status'] ?? '';
    $filterUserId = $_GET['user_id'] ?? '';
    $dateFilter = $_GET['date_filter'] ?? 'all'; // all, today, week, month, last_month, custom
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $sortBy = $_GET['sort'] ?? 'priority'; // priority, deadline, create_time, status
    $sortOrder = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    
    $conditions = ["t.deleted_at IS NULL"];
    $params = [];
    
    // 基础用户条件（用于全局统计）
    $userCondition = "";
    $userParams = [];
    
    // 非管理员可以看到：分配给自己的任务 OR 自己创建的任务
    if (!$isManager) {
        $userCondition = "(t.assignee_id = ? OR t.created_by = ?)";
        $userParams = [$user['id'], $user['id']];
        $conditions[] = $userCondition;
        $params = array_merge($params, $userParams);
    } elseif ($filterUserId) {
        $conditions[] = "(t.assignee_id = ? OR t.created_by = ?)";
        $params[] = (int)$filterUserId;
        $params[] = (int)$filterUserId;
    }
    
    if ($filterStatus) {
        $statuses = explode(',', $filterStatus);
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $conditions[] = "t.status IN ({$placeholders})";
        $params = array_merge($params, $statuses);
    }
    
    // 日期筛选 - 基于 create_time
    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));
    
    switch ($dateFilter) {
        case 'today':
            // 今日任务
            $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) = ?";
            $params[] = $today;
            break;
        case 'week':
            // 本周任务
            $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $params[] = $weekStart;
            $params[] = $weekEnd;
            break;
        case 'month':
            // 本月任务
            $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $params[] = $monthStart;
            $params[] = $monthEnd;
            break;
        case 'last_month':
            // 上月任务
            $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $params[] = $lastMonthStart;
            $params[] = $lastMonthEnd;
            break;
        case 'custom':
            // 自定义时间范围
            if ($startDate) {
                $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) >= ?";
                $params[] = $startDate;
            }
            if ($endDate) {
                $conditions[] = "DATE(FROM_UNIXTIME(t.create_time)) <= ?";
                $params[] = $endDate;
            }
            break;
        // 'all' 不添加日期条件
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $tasks = Db::query("
        SELECT 
            t.id, t.title, t.description, t.status, t.priority,
            t.deadline, t.project_id, t.assignee_id, t.created_by,
            t.create_time, t.update_time,
            p.project_name, p.project_code,
            u.realname as assignee_name,
            c.realname as creator_name
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN users c ON t.created_by = c.id
        WHERE {$whereClause}
        ORDER BY 
            " . getSortClause($sortBy, $sortOrder) . "
        LIMIT 100
    ", $params);
    
    $result = [];
    foreach ($tasks as $task) {
        $result[] = [
            'id' => (int)$task['id'],
            'title' => $task['title'],
            'description' => $task['description'],
            'status' => $task['status'] ?: 'pending',
            'priority' => $task['priority'] ?: 'medium',
            'deadline' => $task['deadline'] ?: null,
            'project_id' => $task['project_id'] ? (int)$task['project_id'] : null,
            'project_name' => $task['project_name'],
            'project_code' => $task['project_code'],
            'assignee_id' => $task['assignee_id'] ? (int)$task['assignee_id'] : null,
            'assignee_name' => $task['assignee_name'],
            'creator_name' => $task['creator_name'],
            'create_time' => $task['create_time'] ? date('Y-m-d H:i', $task['create_time']) : null,
            'update_time' => $task['update_time'] ? date('Y-m-d H:i', $task['update_time']) : null,
        ];
    }
    
    // 统计 - 使用不含状态筛选的基础条件，确保各状态数量始终正确显示
    $statsConditions = ["t.deleted_at IS NULL"];
    $statsParams = [];
    
    // 用户权限条件
    if (!$isManager) {
        $statsConditions[] = "(t.assignee_id = ? OR t.created_by = ?)";
        $statsParams[] = $user['id'];
        $statsParams[] = $user['id'];
    } elseif ($filterUserId) {
        $statsConditions[] = "(t.assignee_id = ? OR t.created_by = ?)";
        $statsParams[] = (int)$filterUserId;
        $statsParams[] = (int)$filterUserId;
    }
    
    // 日期筛选（保留日期筛选）- 复用主查询中已定义的日期变量
    switch ($dateFilter) {
        case 'today':
            $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) = ?";
            $statsParams[] = date('Y-m-d');
            break;
        case 'week':
            $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $statsParams[] = date('Y-m-d', strtotime('monday this week'));
            $statsParams[] = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $statsParams[] = date('Y-m-01');
            $statsParams[] = date('Y-m-t');
            break;
        case 'last_month':
            $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) BETWEEN ? AND ?";
            $statsParams[] = date('Y-m-01', strtotime('first day of last month'));
            $statsParams[] = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'custom':
            if ($startDate) {
                $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) >= ?";
                $statsParams[] = $startDate;
            }
            if ($endDate) {
                $statsConditions[] = "DATE(FROM_UNIXTIME(t.create_time)) <= ?";
                $statsParams[] = $endDate;
            }
            break;
    }
    
    $statsWhereClause = implode(' AND ', $statsConditions);
    $statsRow = Db::queryOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM tasks t
        WHERE {$statsWhereClause}
    ", $statsParams);
    
    $stats = [
        'total' => (int)($statsRow['total'] ?? 0),
        'pending' => (int)($statsRow['pending'] ?? 0),
        'in_progress' => (int)($statsRow['in_progress'] ?? 0),
        'completed' => (int)($statsRow['completed'] ?? 0),
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'tasks' => $result,
            'stats' => $stats,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 创建任务
 */
function handleCreate($user) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $priority = $input['priority'] ?? TASK_PRIORITY_MEDIUM;
    $deadline = $input['deadline'] ?? null;
    $projectId = $input['project_id'] ?? null;
    $assigneeId = $input['assignee_id'] ?? $user['id'];
    
    // 输入验证
    if (empty($title)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '任务标题不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (mb_strlen($title) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '任务标题不能超过200个字符'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!in_array($priority, TASK_VALID_PRIORITIES)) {
        $priority = TASK_PRIORITY_MEDIUM;
    }
    
    $now = time();
    
    Db::execute("
        INSERT INTO tasks (title, description, status, priority, deadline, project_id, assignee_id, created_by, create_time, update_time)
        VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
    ", [$title, $description, $priority, $deadline, $projectId, $assigneeId, $user['id'], $now, $now]);
    
    $taskId = Db::lastInsertId();
    
    // 如果分配给其他人，发送通知
    if ($assigneeId && $assigneeId != $user['id']) {
        createTaskNotification($assigneeId, $taskId, $title, $user['realname'] ?? $user['username'], $projectId);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '任务创建成功',
        'data' => ['id' => (int)$taskId]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 创建任务通知
 */
function createTaskNotification($userId, $taskId, $taskTitle, $creatorName, $projectId = null) {
    $now = time();
    $content = "{$creatorName} 给你分配了新任务: {$taskTitle}";
    
    // 获取项目信息
    $projectCode = null;
    if ($projectId) {
        $project = Db::queryOne("SELECT project_code FROM projects WHERE id = ?", [$projectId]);
        $projectCode = $project['project_code'] ?? null;
    }
    
    Db::execute("
        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
        VALUES (?, 'task', '新任务分配', ?, 'task', ?, 0, ?)
    ", [
        $userId,
        $content,
        $taskId,
        $now
    ]);
}

/**
 * 更新任务状态
 */
function handleUpdateStatus($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = (int)($input['task_id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    
    if ($taskId <= 0 || !in_array($newStatus, TASK_VALID_STATUSES)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效的任务状态'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取任务信息
    $task = Db::queryOne("SELECT id, title, assignee_id, created_by, status as old_status FROM tasks WHERE id = ?", [$taskId]);
    
    // 验证权限
    if (!$isManager) {
        if (!$task || $task['assignee_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '无权限修改此任务'], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    
    $now = time();
    Db::execute("UPDATE tasks SET status = ?, update_time = ? WHERE id = ?", [$newStatus, $now, $taskId]);
    
    // 发送任务状态变更通知给创建者（如果不是自己）
    if ($task && $task['created_by'] && $task['created_by'] != $user['id']) {
        $statusMap = ['pending' => '待处理', 'in_progress' => '进行中', 'completed' => '已完成'];
        $oldStatusName = $statusMap[$task['old_status']] ?? $task['old_status'];
        $newStatusName = $statusMap[$newStatus] ?? $newStatus;
        $operatorName = $user['realname'] ?? $user['username'];
        
        Db::execute("
            INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
            VALUES (?, 'task', '任务状态变更', ?, 'task', ?, 0, ?)
        ", [
            $task['created_by'],
            "{$operatorName} 将任务 [{$task['title']}] 状态改为: {$newStatusName}",
            $taskId,
            $now
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '状态已更新'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 分配任务（技术主管）
 */
function handleAssign($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = (int)($input['task_id'] ?? 0);
    $assigneeId = (int)($input['assignee_id'] ?? 0);
    
    if ($taskId <= 0 || $assigneeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取任务信息
    $task = Db::queryOne("SELECT id, title, project_id FROM tasks WHERE id = ?", [$taskId]);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("UPDATE tasks SET assignee_id = ?, update_time = ? WHERE id = ?", [$assigneeId, $now, $taskId]);
    
    // 发送通知给被分配者
    if ($assigneeId != $user['id']) {
        createTaskNotification($assigneeId, $taskId, $task['title'], $user['realname'] ?? $user['username'], $task['project_id']);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '任务已分配'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 删除任务（软删除）
 */
function handleDelete($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = (int)($input['task_id'] ?? 0);
    
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取任务信息
    $task = Db::queryOne("SELECT id, title, assignee_id, created_by FROM tasks WHERE id = ? AND deleted_at IS NULL", [$taskId]);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证权限：管理员、创建者、或被分配者
    if (!$isManager && $task['created_by'] != $user['id'] && $task['assignee_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限删除此任务'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("UPDATE tasks SET deleted_at = ?, deleted_by = ? WHERE id = ?", [$now, $user['id'], $taskId]);
    
    echo json_encode([
        'success' => true,
        'message' => '任务已删除'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 编辑任务
 */
function handleUpdate($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $taskId = (int)($input['task_id'] ?? 0);
    
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取任务信息
    $task = Db::queryOne("SELECT id, title, assignee_id, created_by FROM tasks WHERE id = ? AND deleted_at IS NULL", [$taskId]);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证权限：管理员、创建者、或被分配者
    if (!$isManager && $task['created_by'] != $user['id'] && $task['assignee_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限编辑此任务'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 构建更新字段
    $updates = [];
    $params = [];
    
    if (isset($input['title']) && trim($input['title'])) {
        $updates[] = "title = ?";
        $params[] = trim($input['title']);
    }
    
    if (isset($input['description'])) {
        $updates[] = "description = ?";
        $params[] = trim($input['description']);
    }
    
    if (isset($input['priority']) && in_array($input['priority'], ['high', 'medium', 'low'])) {
        $updates[] = "priority = ?";
        $params[] = $input['priority'];
    }
    
    if (isset($input['deadline'])) {
        if ($input['deadline']) {
            $updates[] = "deadline = ?";
            $params[] = $input['deadline']; // DATE类型，直接使用日期字符串
        } else {
            $updates[] = "deadline = NULL";
        }
    }
    
    if (isset($input['project_id'])) {
        $updates[] = "project_id = ?";
        $params[] = $input['project_id'] ? (int)$input['project_id'] : null;
    }
    
    if (empty($updates)) {
        echo json_encode(['success' => false, 'error' => '没有要更新的字段'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $updates[] = "update_time = ?";
    $params[] = $now;
    $params[] = $taskId;
    
    $updateClause = implode(', ', $updates);
    Db::execute("UPDATE tasks SET {$updateClause} WHERE id = ?", $params);
    
    echo json_encode([
        'success' => true,
        'message' => '任务已更新'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取项目列表（用于创建任务时选择）
 */
function handleProjects($user, $isManager) {
    $conditions = ["p.deleted_at IS NULL"];
    $params = [];
    $search = trim($_GET['search'] ?? '');
    
    // 搜索条件
    if ($search) {
        $conditions[] = "(p.project_code LIKE ? OR p.project_name LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $projects = Db::query("
        SELECT p.id, p.project_code, p.project_name
        FROM projects p
        WHERE {$whereClause}
        ORDER BY p.update_time DESC
        LIMIT 100
    ", $params);
    
    $result = [];
    foreach ($projects as $p) {
        $result[] = [
            'id' => (int)$p['id'],
            'project_code' => $p['project_code'],
            'project_name' => $p['project_name'],
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取用户列表（用于分配任务时选择）
 */
function handleUsers($user, $isManager) {
    if (!$isManager) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $users = Db::query("
        SELECT id, username, realname, role
        FROM users
        WHERE role IN ('tech', 'tech_manager', 'manager', 'admin', 'super_admin', 'dept_leader') AND status = 1
        ORDER BY FIELD(role, 'super_admin', 'admin', 'manager', 'dept_leader', 'tech_manager', 'tech'), realname
    ");
    
    $result = [];
    foreach ($users as $u) {
        $result[] = [
            'id' => (int)$u['id'],
            'name' => $u['realname'] ?: $u['username'],
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
}
