<?php
/**
 * 获取分享链接信息 API
 * GET /api/file_share_info.php?token=xxx
 * 用于分享页面获取项目信息和验证链接有效性
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::getConnection();
    
    // 获取分享链接信息
    $stmt = $pdo->prepare("
        SELECT 
            fsl.id,
            fsl.project_id,
            fsl.token,
            fsl.password,
            fsl.max_visits,
            fsl.visit_count,
            fsl.expires_at,
            fsl.status,
            fsl.note,
            p.name AS project_name,
            c.name AS customer_name,
            c.group_code
        FROM file_share_links fsl
        JOIN projects p ON p.id = fsl.project_id
        LEFT JOIN customers c ON c.id = p.customer_id
        WHERE fsl.token = ?
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        http_response_code(404);
        echo json_encode([
            'error' => '链接不存在',
            'valid' => false,
            'reason' => 'not_found'
        ]);
        exit;
    }
    
    // 检查链接状态
    if ($link['status'] === 'disabled') {
        echo json_encode([
            'valid' => false,
            'reason' => 'disabled',
            'message' => '此链接已被禁用'
        ]);
        exit;
    }
    
    // 检查是否过期
    if (strtotime($link['expires_at']) < time()) {
        // 更新状态为已过期
        $updateStmt = $pdo->prepare("UPDATE file_share_links SET status = 'expired' WHERE id = ?");
        $updateStmt->execute([$link['id']]);
        
        echo json_encode([
            'valid' => false,
            'reason' => 'expired',
            'message' => '此链接已过期'
        ]);
        exit;
    }
    
    // 检查访问次数
    if ($link['max_visits'] !== null && $link['visit_count'] >= $link['max_visits']) {
        echo json_encode([
            'valid' => false,
            'reason' => 'max_visits_reached',
            'message' => '此链接已达到最大访问次数'
        ]);
        exit;
    }
    
    // 链接有效
    echo json_encode([
        'valid' => true,
        'requires_password' => !empty($link['password']),
        'project_name' => $link['project_name'],
        'customer_name' => $link['customer_name'],
        'note' => $link['note'],
        'expires_at' => $link['expires_at']
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误']);
}
