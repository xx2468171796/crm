<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目手动完工 API
 * POST: 技术主管/管理员手动完工项目
 * 
 * 使用统一的 ProjectService 确保数据一致性
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查权限：管理员或技术主管
if (!isAdmin($user) && $user['role'] !== 'tech_lead') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只支持POST请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $projectId = intval($input['project_id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 使用统一的 ProjectService 完成项目
    $projectService = ProjectService::getInstance();
    $result = $projectService->completeProject(
        $projectId,
        $user['id'],
        $user['realname'] ?? $user['username']
    );
    
    if (!$result['success']) {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[PROJECT_COMPLETE_API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
