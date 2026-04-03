<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 交付物审批 API
 * POST /api/deliverable_approval.php
 * - action=approve: 审批通过
 * - action=reject: 审批驳回（需填写驳回原因）
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

// 权限检查：仅管理员可审批
if (!isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限审批'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$deliverableId = intval($input['deliverable_id'] ?? 0);

if ($deliverableId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少交付物ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询交付物
    $stmt = $pdo->prepare("
        SELECT d.*, p.project_name, p.customer_id, c.name as customer_name
        FROM deliverables d
        JOIN projects p ON d.project_id = p.id
        JOIN customers c ON p.customer_id = c.id
        WHERE d.id = ?
    ");
    $stmt->execute([$deliverableId]);
    $deliverable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deliverable) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '交付物不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $now = time();
    
    switch ($action) {
        case 'approve':
            // 审批通过
            $updateStmt = $pdo->prepare("
                UPDATE deliverables 
                SET approval_status = 'approved', approved_by = ?, approved_at = ?, update_time = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$user['id'], $now, $now, $deliverableId]);
            
            // 写入时间线
            $timelineStmt = $pdo->prepare("
                INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $timelineStmt->execute([
                'deliverable',
                $deliverableId,
                '审批通过',
                $user['id'],
                json_encode(['deliverable_name' => $deliverable['deliverable_name']]),
                $now
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => '审批通过'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'reject':
            $rejectReason = trim($input['reject_reason'] ?? '');
            
            if (empty($rejectReason)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '驳回必须填写原因'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 审批驳回
            $updateStmt = $pdo->prepare("
                UPDATE deliverables 
                SET approval_status = 'rejected', approved_by = ?, approved_at = ?, 
                    reject_reason = ?, update_time = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$user['id'], $now, $rejectReason, $now, $deliverableId]);
            
            // 写入时间线
            $timelineStmt = $pdo->prepare("
                INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $timelineStmt->execute([
                'deliverable',
                $deliverableId,
                '审批驳回',
                $user['id'],
                json_encode([
                    'deliverable_name' => $deliverable['deliverable_name'],
                    'reject_reason' => $rejectReason
                ]),
                $now
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => '已驳回'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
