<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户信息更新 API
 * POST - 更新客户别名等信息
 */

error_reporting(E_ERROR | E_PARSE);

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 尝试桌面端认证
$user = null;
$token = desktop_get_token();
if ($token) {
    $user = desktop_verify_token($token);
}

// 如果桌面端认证失败，尝试后台认证
if (!$user) {
    $user = current_user();
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$customerId = (int)($input['customer_id'] ?? 0);
$alias = $input['alias'] ?? null;

if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查客户是否存在
$customer = Db::queryOne("SELECT id FROM customers WHERE id = ?", [$customerId]);
if (!$customer) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 更新别名
    Db::execute("UPDATE customers SET alias = ? WHERE id = ?", [$alias ?: null, $customerId]);
    
    echo json_encode([
        'success' => true,
        'message' => '客户信息已更新',
        'data' => [
            'alias' => $alias ?: null,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '更新失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
