<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单提交 API
 * POST - 提交表单数据
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/services/NotificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$fillToken = trim($input['fill_token'] ?? $_GET['token'] ?? '');
$submissionData = $input['data'] ?? [];
$submitterName = trim($input['submitter_name'] ?? '');

if (empty($fillToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少表单token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单实例（包含项目信息和用途）
    $stmt = $pdo->prepare("
        SELECT fi.*, fi.purpose, ft.name as template_name, p.project_name, p.id as project_id
        FROM form_instances fi
        JOIN form_templates ft ON fi.template_id = ft.id
        LEFT JOIN projects p ON fi.project_id = p.id
        WHERE fi.fill_token = ?
    ");
    $stmt->execute([$fillToken]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '表单不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $now = time();
    
    // 创建提交记录
    $submitStmt = $pdo->prepare("
        INSERT INTO form_submissions (instance_id, submission_data_json, submitted_by_type, submitted_by_name, submitted_at, ip_address, user_agent)
        VALUES (?, ?, 'guest', ?, ?, ?, ?)
    ");
    $submitStmt->execute([
        $instance['id'],
        json_encode($submissionData, JSON_UNESCAPED_UNICODE),
        $submitterName ?: '匿名',
        $now,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    $submissionId = $pdo->lastInsertId();
    
    // 更新实例状态和需求状态
    // 评价表单提交后直接设为"已确认"，其他表单设为"沟通中"
    $newReqStatus = ($instance['purpose'] === 'evaluation') ? 'confirmed' : 'communicating';
    $updateStmt = $pdo->prepare("UPDATE form_instances SET status = 'submitted', requirement_status = ?, update_time = ? WHERE id = ?");
    $updateStmt->execute([$newReqStatus, $now, $instance['id']]);
    
    // 如果是评价表单，触发项目完工
    if ($instance['purpose'] === 'evaluation' && $instance['project_id']) {
        $completeStmt = $pdo->prepare("
            UPDATE projects SET 
                current_status = '完工',
                completed_at = NOW(),
                completed_by = 'customer',
                update_time = ?
            WHERE id = ? AND current_status = '设计评价'
        ");
        $completeStmt->execute([$now, $instance['project_id']]);
        
        // 记录到时间线
        $timelineStmt = $pdo->prepare("
            INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
            VALUES ('project', ?, '客户评价完工', 0, ?, ?)
        ");
        $timelineStmt->execute([
            $instance['project_id'],
            json_encode(['submission_id' => $submissionId, 'form_name' => $instance['instance_name']]),
            $now
        ]);
    }
    
    // 发送通知给项目技术人员
    if ($instance['project_id']) {
        try {
            $notificationService = new NotificationService($pdo);
            $notificationService->sendFormSubmitNotification(
                $instance['project_id'],
                $instance['id'],
                $instance['instance_name'] ?? $instance['template_name'],
                $instance['project_name'] ?? '未知项目'
            );
        } catch (Exception $notifyErr) {
            // 通知失败不影响提交
            error_log('CUNZHI_DEBUG: 发送通知失败: ' . $notifyErr->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => '提交成功',
        'data' => ['submission_id' => $submissionId]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '提交失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
