<?php
/**
 * 客户门户 - 记录分享链接下载次数
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '仅支持POST请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$shareToken = trim($_GET['s'] ?? '');

if (empty($shareToken)) {
    echo json_encode(['success' => false, 'message' => '缺少分享token'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../core/db.php';
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("
        UPDATE deliverable_shares 
        SET download_count = download_count + 1 
        WHERE share_token = ? AND is_active = 1
    ");
    $stmt->execute([$shareToken]);
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
