<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 任务评论 API
 * 
 * GET /api/desktop_task_comments.php?task_id=123 - 获取评论列表
 * POST /api/desktop_task_comments.php - 添加评论
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

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
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '不支持的方法']], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_task_comments 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $taskId = $_GET['task_id'] ?? null;
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少 task_id']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $comments = Db::query("
        SELECT 
            c.id,
            c.content,
            c.created_at,
            u.realname as user_name
        FROM task_comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.task_id = ?
        ORDER BY c.created_at ASC
    ", [$taskId]);
    
    // 格式化时间
    foreach ($comments as &$comment) {
        $comment['created_at'] = date('m-d H:i', strtotime($comment['created_at']));
    }
    
    echo json_encode(['success' => true, 'data' => ['comments' => $comments]], JSON_UNESCAPED_UNICODE);
}

function handlePost($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $taskId = $input['task_id'] ?? null;
    $content = trim($input['content'] ?? '');
    
    if (!$taskId || !$content) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少参数']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证任务存在
    $task = Db::queryOne("SELECT id, user_id FROM daily_tasks WHERE id = ?", [$taskId]);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => '任务不存在']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $id = Db::insert('task_comments', [
        'task_id' => $taskId,
        'user_id' => $user['id'],
        'content' => $content,
    ]);
    
    echo json_encode(['success' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
}
