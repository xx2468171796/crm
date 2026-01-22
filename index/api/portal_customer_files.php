<?php
/**
 * 获取客户上传的文件列表 API
 * GET /api/portal_customer_files.php?token=xxx&project_id=xxx
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';

$token = trim($_GET['token'] ?? '');
$projectId = intval($_GET['project_id'] ?? 0);

if (empty($token) || $projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证token
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE portal_token = ?");
    $stmt->execute([$token]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(401);
        echo json_encode(['error' => '无效的访问令牌']);
        exit;
    }
    
    // 验证项目属于该客户
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND customer_id = ?");
    $stmt->execute([$projectId, $customer['id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => '项目不存在']);
        exit;
    }
    
    // 获取客户上传的文件（包括门户上传和分享链接上传）
    $stmt = $pdo->prepare("
        SELECT id, file_name, file_size, create_time, upload_source
        FROM deliverables 
        WHERE project_id = ? 
        AND category = '客户文件'
        AND (file_name LIKE '客户上传+%' OR file_name LIKE '分享+%')
        ORDER BY create_time DESC
    ");
    $stmt->execute([$projectId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $files
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误']);
}
