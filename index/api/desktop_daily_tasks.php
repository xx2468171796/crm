<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 每日任务 API
 * 
 * GET /api/desktop_daily_tasks.php - 获取任务列表
 * POST /api/desktop_daily_tasks.php - 创建任务
 * PUT /api/desktop_daily_tasks.php?id=123 - 更新任务
 * DELETE /api/desktop_daily_tasks.php?id=123 - 删除任务
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/services/NotificationService.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($user);
            break;
        case 'POST':
            handlePost($user);
            break;
        case 'PUT':
            handlePut($user);
            break;
        case 'DELETE':
            handleDelete($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '不支持的方法']], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_daily_tasks 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $view = $_GET['view'] ?? '';  // today/yesterday/future/help/assigned
    $date = $_GET['date'] ?? date('Y-m-d');
    $userId = $_GET['user_id'] ?? $user['id'];
    
    // 权限检查：只能查看自己的任务，除非是主管
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    if ($userId != $user['id'] && !$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '无权查看他人任务']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // 构建查询条件
    $conditions = ["t.user_id = ?"];
    $params = [$userId];
    
    // 根据 view 参数筛选
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
        case 'assigned':
            $conditions[] = "t.assigned_by IS NOT NULL";
            break;
        default:
            // 默认按日期筛选
            $conditions[] = "t.task_date = ?";
            $params[] = $date;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $tasks = Db::query("
        SELECT 
            t.*,
            p.project_name,
            p.project_code,
            c.name as customer_name,
            u.realname as assigned_by_name
        FROM daily_tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.assigned_by = u.id
        WHERE {$whereClause}
        ORDER BY t.priority DESC, t.task_date ASC, t.created_at ASC
    ", $params);
    
    // 获取评论数
    foreach ($tasks as &$task) {
        $commentCount = Db::queryOne("SELECT COUNT(*) as count FROM task_comments WHERE task_id = ?", [$task['id']]);
        $task['comment_count'] = (int)$commentCount['count'];
    }
    
    echo json_encode(['success' => true, 'data' => ['items' => $tasks]], JSON_UNESCAPED_UNICODE);
}

function handlePost($user) {
    error_log("[desktop_daily_tasks] handlePost 开始, user_id={$user['id']}");
    
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("[desktop_daily_tasks] input: " . json_encode($input, JSON_UNESCAPED_UNICODE));
    
    $title = trim($input['title'] ?? '');
    if (!$title) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '标题不能为空']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 支持主管分配任务给其他人
    $targetUserId = $input['user_id'] ?? $user['id'];
    $assignedBy = null;
    
    // 如果是分配给其他人，记录分配人
    if ($targetUserId != $user['id']) {
        $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
        if (!$isManager) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '无权分配任务给他人']], JSON_UNESCAPED_UNICODE);
            return;
        }
        $assignedBy = $user['id'];
    }
    
    $data = [
        'user_id' => $targetUserId,
        'title' => $title,
        'description' => $input['description'] ?? null,
        'task_date' => $input['task_date'] ?? date('Y-m-d'),
        'project_id' => $input['project_id'] ?? null,
        'customer_id' => $input['customer_id'] ?? null,
        'priority' => $input['priority'] ?? 'medium',
        'estimated_hours' => $input['estimated_hours'] ?? null,
        'assigned_by' => $assignedBy,
        'need_help' => $input['need_help'] ?? 0,
    ];
    
    $id = Db::insert('daily_tasks', $data);
    
    // 发送通知给任务接收者
    try {
        $pdo = Db::getInstance();
        $notificationService = new NotificationService($pdo);
        
        // 获取创建者姓名
        $creatorName = $user['realname'] ?? $user['username'] ?? '系统';
        
        // 获取项目名称
        $projectName = '';
        if (!empty($input['project_id'])) {
            $project = Db::queryOne("SELECT project_name FROM projects WHERE id = ?", [$input['project_id']]);
            $projectName = $project ? $project['project_name'] : '';
        }
        
        // 构建通知内容
        $notifyTitle = "📝 新任务: {$title}";
        $notifyContent = "创建者: {$creatorName}";
        if ($projectName) {
            $notifyContent .= "\n关联项目: {$projectName}";
        }
        if (!empty($input['description'])) {
            $notifyContent .= "\n描述: " . mb_substr($input['description'], 0, 100);
        }
        $taskDate = $input['task_date'] ?? date('Y-m-d');
        $notifyContent .= "\n计划日期: {$taskDate}";
        
        // 发送通知
        $notificationService->create(
            $targetUserId,
            'task',
            $notifyTitle,
            $notifyContent,
            'task',
            $id
        );
        
        error_log("[Notification] 已为用户 {$targetUserId} 创建任务通知，任务ID: {$id}");
    } catch (Exception $e) {
        error_log("[Notification] 创建任务通知失败: " . $e->getMessage());
    }
    
    $task = Db::queryOne("SELECT * FROM daily_tasks WHERE id = ?", [$id]);
    echo json_encode(['success' => true, 'data' => $task], JSON_UNESCAPED_UNICODE);
}

function handlePut($user) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少任务ID']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查任务存在且属于当前用户
    $task = Db::queryOne("SELECT * FROM daily_tasks WHERE id = ?", [$id]);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => '任务不存在']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($task['user_id'] != $user['id'] && $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '无权修改此任务']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $allowedFields = ['title', 'description', 'status', 'progress', 'priority', 'estimated_hours', 'actual_hours', 'need_help', 'project_id', 'task_date'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[$field] = $input[$field];
        }
    }
    
    if (!empty($updates)) {
        $sets = [];
        $params = [];
        foreach ($updates as $field => $value) {
            $sets[] = "`$field` = ?";
            $params[] = $value;
        }
        $params[] = $id;
        
        Db::execute("UPDATE daily_tasks SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }
    
    $task = Db::queryOne("SELECT * FROM daily_tasks WHERE id = ?", [$id]);
    echo json_encode(['success' => true, 'data' => $task], JSON_UNESCAPED_UNICODE);
}

function handleDelete($user) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少任务ID']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $task = Db::queryOne("SELECT * FROM daily_tasks WHERE id = ?", [$id]);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => '任务不存在']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($task['user_id'] != $user['id'] && $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '无权删除此任务']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 删除评论
    Db::execute("DELETE FROM task_comments WHERE task_id = ?", [$id]);
    // 删除任务
    Db::execute("DELETE FROM daily_tasks WHERE id = ?", [$id]);
    
    echo json_encode(['success' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
}
