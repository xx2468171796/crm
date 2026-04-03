<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 需求状态变更 API
 * POST - 变更表单需求状态
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/services/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$instanceId = intval($input['instance_id'] ?? 0);
$newStatus = trim($input['status'] ?? '');
$portalToken = trim($input['portal_token'] ?? '');

if ($instanceId <= 0 || empty($newStatus)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证状态值
$allowedStatuses = ['pending', 'communicating', 'confirmed', 'modifying'];
if (!in_array($newStatus, $allowedStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的状态值'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单实例
    $stmt = $pdo->prepare("
        SELECT fi.*, p.project_name, p.id as project_id, p.customer_id
        FROM form_instances fi
        LEFT JOIN projects p ON fi.project_id = p.id
        WHERE fi.id = ?
    ");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '表单实例不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $currentStatus = $instance['requirement_status'] ?? 'pending';
    
    // 权限检查
    if (!empty($portalToken)) {
        // 门户客户操作：只能申请修改
        $portalStmt = $pdo->prepare("SELECT * FROM portal_links WHERE token = ? AND enabled = 1");
        $portalStmt->execute([$portalToken]);
        $portal = $portalStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$portal || $portal['customer_id'] != $instance['customer_id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '无权操作此表单'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 客户只能将状态变更为 modifying
        if ($newStatus !== 'modifying') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '客户只能申请修改需求'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 只有 confirmed 状态才能申请修改
        if ($currentStatus !== 'confirmed') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '只有已确认的需求才能申请修改'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
    } else {
        // 管理后台操作
        $user = current_user();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 管理后台用户可以自由更改状态
        $operatorId = $user['id'];
        $operatorName = $user['realname'] ?? $user['username'];
    }
    
    // 更新状态
    $now = time();
    $updateStmt = $pdo->prepare("UPDATE form_instances SET requirement_status = ?, update_time = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $now, $instanceId]);
    
    // 记录状态变更到timeline_events
    $eventData = json_encode([
        'from_status' => $currentStatus,
        'to_status' => $newStatus,
        'operator_name' => $operatorName ?? '客户'
    ], JSON_UNESCAPED_UNICODE);
    
    $logStmt = $pdo->prepare("
        INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
        VALUES ('form_instance', ?, 'requirement_status_change', ?, ?, ?)
    ");
    $logStmt->execute([$instanceId, $operatorId ?? 0, $eventData, $now]);
    
    // 发送通知
    $notificationService = new NotificationService($pdo);
    
    if ($newStatus === 'modifying' && $instance['project_id']) {
        // 客户申请修改，通知技术人员
        $notificationService->sendModifyRequestNotification(
            $instance['project_id'],
            $instanceId,
            $instance['instance_name'] ?? '需求表单',
            $instance['project_name'] ?? '未知项目'
        );
    }
    
    $statusLabels = [
        'communicating' => '需求沟通',
        'confirmed' => '需求确认',
        'modifying' => '需求修改'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => '状态已更新为: ' . ($statusLabels[$newStatus] ?? $newStatus),
        'data' => [
            'instance_id' => $instanceId,
            'status' => $newStatus
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
