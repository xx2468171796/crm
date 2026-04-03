<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 时间线查询 API
 * GET /api/timeline.php?entity_type=project&entity_id=123
 * 返回指定实体的时间线事件列表
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entityType = trim($_GET['entity_type'] ?? '');
$entityId = intval($_GET['entity_id'] ?? 0);

if (empty($entityType) || $entityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少实体类型或ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询时间线事件
    $stmt = $pdo->prepare("
        SELECT 
            te.*,
            u.realname as operator_name,
            u.username as operator_username
        FROM timeline_events te
        LEFT JOIN users u ON te.operator_user_id = u.id
        WHERE te.entity_type = ? AND te.entity_id = ?
        ORDER BY te.create_time DESC
    ");
    $stmt->execute([$entityType, $entityId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化事件数据
    $formattedEvents = array_map(function($event) {
        $eventData = json_decode($event['event_data_json'], true) ?: [];
        
        return [
            'id' => $event['id'],
            'event_type' => $event['event_type'],
            'operator_name' => $event['operator_name'] ?: '系统',
            'operator_username' => $event['operator_username'],
            'event_data' => $eventData,
            'create_time' => $event['create_time'],
            'formatted_time' => date('Y-m-d H:i:s', $event['create_time']),
            'description' => formatEventDescription($event['event_type'], $eventData)
        ];
    }, $events);
    
    echo json_encode([
        'success' => true,
        'data' => $formattedEvents
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '查询失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// 格式化事件描述
function formatEventDescription($eventType, $eventData) {
    switch ($eventType) {
        case '创建项目':
            return "创建了项目 {$eventData['project_name']}";
        case '状态变更':
            return "将状态从「{$eventData['from_status']}」变更为「{$eventData['to_status']}」" . 
                   ($eventData['notes'] ? "，备注：{$eventData['notes']}" : '');
        case '分配技术':
            return "分配技术人员：{$eventData['tech_name']}";
        case '取消分配':
            return "取消分配技术人员";
        case '分配变更':
            return "批量变更技术分配";
        default:
            return $eventType;
    }
}
