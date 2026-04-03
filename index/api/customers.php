<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户信息 API
 * GET ?id=xxx - 获取单个客户详情
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

try {
    $pdo = Db::pdo();
    $customerId = (int)($_GET['id'] ?? 0);
    
    if (!$customerId) {
        echo json_encode(['success' => false, 'message' => '缺少客户ID']);
        exit;
    }
    
    // 查询客户基本信息
    $sql = "SELECT c.*, u.realname as owner_name
            FROM customers c
            LEFT JOIN users u ON c.owner_user_id = u.id
            WHERE c.id = ? AND c.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => '客户不存在']);
        exit;
    }
    
    // 数据权限检查：非管理员只能查看自己负责的客户
    if (!isAdmin($user)) {
        if ($user['role'] === 'sales' && $customer['owner_user_id'] != $user['id']) {
            echo json_encode(['success' => false, 'message' => '无权查看此客户']);
            exit;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $customer], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
