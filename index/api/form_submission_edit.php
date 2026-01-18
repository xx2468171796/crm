<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单提交编辑 API
 * POST - 更新表单提交数据
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证登录
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$instanceId = intval($input['instance_id'] ?? 0);
$newData = $input['data'] ?? [];

if ($instanceId <= 0 || empty($newData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单实例
    $stmt = $pdo->prepare("SELECT * FROM form_instances WHERE id = ?");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '表单实例不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取最新提交记录
    $subStmt = $pdo->prepare("
        SELECT * FROM form_submissions 
        WHERE instance_id = ? 
        ORDER BY submitted_at DESC 
        LIMIT 1
    ");
    $subStmt->execute([$instanceId]);
    $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '没有可编辑的提交记录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 合并原数据和新数据
    $oldData = json_decode($submission['submission_data_json'] ?? '{}', true);
    $mergedData = array_merge($oldData, $newData);
    $newDataJson = json_encode($mergedData, JSON_UNESCAPED_UNICODE);
    
    $now = time();
    
    // 更新提交记录
    $updateStmt = $pdo->prepare("
        UPDATE form_submissions 
        SET submission_data_json = ?, 
            submitted_by_name = CONCAT(submitted_by_name, ' (已由', ?, '编辑)'),
            submitted_at = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$newDataJson, $user['realname'] ?? $user['username'], $now, $submission['id']]);
    
    // 更新表单实例时间
    $pdo->prepare("UPDATE form_instances SET update_time = ? WHERE id = ?")->execute([$now, $instanceId]);
    
    // 记录到timeline
    $eventData = json_encode([
        'editor' => $user['realname'] ?? $user['username'],
        'changes' => array_keys($newData)
    ], JSON_UNESCAPED_UNICODE);
    
    $logStmt = $pdo->prepare("
        INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
        VALUES ('form_instance', ?, 'submission_edited', ?, ?, ?)
    ");
    $logStmt->execute([$instanceId, $user['id'], $eventData, $now]);
    
    echo json_encode([
        'success' => true,
        'message' => '需求已更新'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
