<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目状态变更 API
 * POST /api/project_status.php
 * - 变更项目状态
 * - 写入状态日志和时间线审计
 * 
 * 使用统一的 ProjectService 确保数据一致性
 */

error_reporting(E_ERROR | E_PARSE);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/constants.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

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

$projectId = intval($input['project_id'] ?? 0);
$newStatus = trim($input['status'] ?? '');
$notes = trim($input['notes'] ?? '');

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($newStatus)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少目标状态'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 权限检查：需要project_status_edit权限
if (!canEditProjectStatus($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限变更项目状态'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 使用统一的 ProjectService 更新状态
    $projectService = ProjectService::getInstance();
    $result = $projectService->updateStatus(
        $projectId,
        $newStatus,
        $user['id'],
        $user['realname'] ?? $user['username'],
        $notes
    );
    
    if (!$result['success']) {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '状态变更失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
