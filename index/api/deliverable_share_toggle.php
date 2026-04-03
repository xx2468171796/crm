<?php
/**
 * 切换交付物分享开关
 * POST /api/deliverable_share_toggle.php
 * 参数: id (交付物ID), enabled (0或1)
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$id = intval($input['id'] ?? 0);
$enabled = intval($input['enabled'] ?? -1);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少交付物ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($enabled !== 0 && $enabled !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'enabled参数必须为0或1'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 检查交付物是否存在
    $stmt = $pdo->prepare("SELECT id, deliverable_name, share_enabled FROM deliverables WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $deliverable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deliverable) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '交付物不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 更新分享开关
    $stmt = $pdo->prepare("UPDATE deliverables SET share_enabled = ?, update_time = ? WHERE id = ?");
    $stmt->execute([$enabled, time(), $id]);
    
    echo json_encode([
        'success' => true,
        'message' => $enabled ? '已开启分享' : '已关闭分享',
        'data' => [
            'id' => $id,
            'share_enabled' => $enabled
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
