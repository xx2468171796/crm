<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户门户项目状态变更API
 * 允许客户通过门户token更新项目状态
 * 
 * 使用统一的 ProjectService 确保数据一致性
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

// 只接受POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$token = trim($data['token'] ?? '');
$projectId = intval($data['project_id'] ?? 0);
$newStatus = trim($data['status'] ?? '');

// 验证参数
if (empty($token) || $projectId <= 0 || empty($newStatus)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '参数不完整']);
    exit;
}

try {
    // 验证token有效性并获取客户ID
    $link = Db::queryOne("SELECT customer_id FROM portal_links WHERE token = ? AND enabled = 1 LIMIT 1", [$token]);
    
    if (!$link) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无效的访问令牌']);
        exit;
    }
    
    $customerId = $link['customer_id'];
    
    // 验证项目属于该客户
    $project = Db::queryOne("SELECT id FROM projects WHERE id = ? AND customer_id = ? AND deleted_at IS NULL", [$projectId, $customerId]);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在或无权访问']);
        exit;
    }
    
    // 使用统一的 ProjectService 更新状态
    $projectService = ProjectService::getInstance();
    $result = $projectService->updateStatus(
        $projectId,
        $newStatus,
        0, // 客户操作，operatorId 为 0
        '客户门户'
    );
    
    if (!$result['success']) {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
}
