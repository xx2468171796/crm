<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目评价 API
 * GET: 获取项目评价
 * POST: 提交评价（客户门户）
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        case 'POST':
            handlePost();
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[PROJECT_EVALUATION_API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet() {
    $projectId = intval($_GET['project_id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取评价
    $evaluation = Db::queryOne("
        SELECT pe.*, c.name as customer_name
        FROM project_evaluations pe
        JOIN customers c ON pe.customer_id = c.id
        WHERE pe.project_id = ?
    ", [$projectId]);
    
    // 获取项目状态和评价截止时间
    $project = Db::queryOne("
        SELECT current_status, evaluation_deadline, completed_at, completed_by
        FROM projects
        WHERE id = ?
    ", [$projectId]);
    
    $remainingDays = null;
    if ($project && $project['evaluation_deadline'] && !$project['completed_at']) {
        $deadline = new DateTime($project['evaluation_deadline']);
        $now = new DateTime();
        $diff = $now->diff($deadline);
        $remainingDays = $diff->invert ? -$diff->days : $diff->days;
    }
    
    // 检查是否有评价表单实例
    $evaluationForm = Db::queryOne("
        SELECT fi.id, fi.instance_name, fi.fill_token, fi.status, ft.name as template_name
        FROM form_instances fi
        JOIN form_templates ft ON fi.template_id = ft.id
        WHERE fi.project_id = ? AND fi.purpose = 'evaluation'
        ORDER BY fi.id DESC LIMIT 1
    ", [$projectId]);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'evaluation' => $evaluation,
            'evaluation_form' => $evaluationForm,
            'current_status' => $project['current_status'] ?? null,
            'evaluation_deadline' => $project['evaluation_deadline'] ?? null,
            'remaining_days' => $remainingDays,
            'completed_at' => $project['completed_at'] ?? null,
            'completed_by' => $project['completed_by'] ?? null,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $projectId = intval($input['project_id'] ?? 0);
    $token = trim($input['token'] ?? '');
    $rating = intval($input['rating'] ?? 5);
    $comment = trim($input['comment'] ?? '');
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少门户Token'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证评分范围
    if ($rating < 1 || $rating > 5) {
        $rating = 5;
    }
    
    // 验证 token 并获取客户ID
    $portalLink = Db::queryOne("
        SELECT customer_id FROM portal_links WHERE token = ? AND enabled = 1
    ", [$token]);
    
    if (!$portalLink) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '无效的门户Token'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $customerId = $portalLink['customer_id'];
    
    // 检查项目是否存在且属于该客户
    $project = Db::queryOne("
        SELECT id, current_status, completed_at FROM projects 
        WHERE id = ? AND customer_id = ?
    ", [$projectId, $customerId]);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在或无权访问'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查是否已评价
    $existingEvaluation = Db::queryOne("
        SELECT id FROM project_evaluations WHERE project_id = ?
    ", [$projectId]);
    
    if ($existingEvaluation) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '该项目已评价'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 插入评价
    Db::execute("
        INSERT INTO project_evaluations (project_id, customer_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ", [$projectId, $customerId, $rating, $comment]);
    
    // 自动完工项目
    Db::execute("
        UPDATE projects SET completed_at = NOW(), completed_by = 'customer'
        WHERE id = ?
    ", [$projectId]);
    
    echo json_encode([
        'success' => true,
        'message' => '评价提交成功，感谢您的反馈！'
    ], JSON_UNESCAPED_UNICODE);
}
