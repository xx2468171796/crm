<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 门户密码设置 API
 * GET - 获取门户链接信息
 * POST - 设置/更新门户密码
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
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $customerId = intval($_GET['customer_id'] ?? 0);
    
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, customer_id, token, password_hash, password_plain, enabled, expires_at, last_access_at, access_count, create_time
        FROM portal_links 
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($link) {
        // 检查是否有实际密码（空密码hash验证）
        $hasPassword = !empty($link['password_hash']) && !password_verify('', $link['password_hash']);
        $link['has_password'] = $hasPassword ? 1 : 0;
        // 返回明文密码供显示
        $link['current_password'] = $link['password_plain'] ?? '';
        unset($link['password_hash']);
        unset($link['password_plain']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $link
    ], JSON_UNESCAPED_UNICODE);
    
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $customerId = intval($input['customer_id'] ?? 0);
    $password = $input['password'] ?? '';
    $enabled = isset($input['enabled']) ? intval($input['enabled']) : 1;
    $expiresAt = !empty($input['expires_at']) ? strtotime($input['expires_at']) : null;
    
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $now = time();
        
        // 检查是否已有记录
        $stmt = $pdo->prepare("SELECT id FROM portal_links WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // 更新现有记录
            $passwordHash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : password_hash('', PASSWORD_DEFAULT);
            $passwordPlain = $password; // 存储明文密码供管理员查看
            
            $updateStmt = $pdo->prepare("
                UPDATE portal_links 
                SET password_hash = ?, password_plain = ?, enabled = ?, expires_at = ?, update_time = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$passwordHash, $passwordPlain, $enabled, $expiresAt, $now, $existing['id']]);
            
            echo json_encode([
                'success' => true,
                'message' => '门户设置已更新'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // 创建新记录
            $token = bin2hex(random_bytes(32));
            $passwordHash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : password_hash('', PASSWORD_DEFAULT);
            $passwordPlain = $password; // 存储明文密码供管理员查看
            
            $insertStmt = $pdo->prepare("
                INSERT INTO portal_links (customer_id, token, password_hash, password_plain, enabled, expires_at, created_by, create_time, update_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$customerId, $token, $passwordHash, $passwordPlain, $enabled, $expiresAt, $user['id'], $now, $now]);
            
            echo json_encode([
                'success' => true,
                'message' => '门户已创建',
                'data' => [
                    'token' => $token
                ]
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
}
