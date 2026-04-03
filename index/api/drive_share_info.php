<?php
/**
 * 获取网盘分享链接信息 API
 * GET /api/drive_share_info.php?token=xxx
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'reason' => 'missing_token', 'message' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    $stmt = $pdo->prepare("
        SELECT dsl.*, u.name as user_name, u.username
        FROM drive_share_links dsl
        JOIN personal_drives pd ON pd.id = dsl.drive_id
        JOIN users u ON u.id = pd.user_id
        WHERE dsl.token = ?
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        echo json_encode(['valid' => false, 'reason' => 'not_found', 'message' => '链接不存在']);
        exit;
    }
    
    // 检查状态
    if ($link['status'] !== 'active') {
        echo json_encode(['valid' => false, 'reason' => 'disabled', 'message' => '链接已禁用']);
        exit;
    }
    
    // 检查过期
    if (strtotime($link['expires_at']) < time()) {
        $updateStmt = $pdo->prepare("UPDATE drive_share_links SET status = 'expired' WHERE id = ?");
        $updateStmt->execute([$link['id']]);
        echo json_encode(['valid' => false, 'reason' => 'expired', 'message' => '链接已过期']);
        exit;
    }
    
    // 检查访问次数
    if ($link['max_visits'] !== null && $link['visit_count'] >= $link['max_visits']) {
        echo json_encode(['valid' => false, 'reason' => 'max_visits', 'message' => '链接已达到最大访问次数']);
        exit;
    }
    
    echo json_encode([
        'valid' => true,
        'owner_name' => $link['user_name'] ?? $link['username'],
        'folder_path' => $link['folder_path'],
        'requires_password' => !empty($link['password']),
        'expires_at' => $link['expires_at'],
        'max_visits' => $link['max_visits'],
        'visit_count' => $link['visit_count']
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'reason' => 'error', 'message' => '服务器错误']);
}
