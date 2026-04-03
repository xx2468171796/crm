<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户门户链接管理 API
 * - action=generate: 生成门户链接
 * - action=update: 更新链接（密码/禁用/有效期）
 * - action=get: 查询链接信息
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? 'get';

// 获取多区域门户链接（通过token查询）- 不需要登录验证，只需要有效的token
if ($action === 'get_region_urls') {
    $token = trim($_GET['token'] ?? '');
    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => '缺少token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        require_once __DIR__ . '/../services/ShareRegionService.php';
        $regions = ShareRegionService::generateRegionUrls($token, '/portal.php?token=');
        error_log("[PORTAL_LINK_DEBUG] token=$token, regions_count=" . count($regions));
        echo json_encode(['success' => true, 'regions' => $regions, 'debug_count' => count($regions)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("[PORTAL_LINK_DEBUG] Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '获取区域链接失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    switch ($action) {
        case 'generate':
            $password = trim($input['password'] ?? '');
            $expiresAt = intval($input['expires_at'] ?? 0);
            
            if (empty($password)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '密码不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 检查是否已有链接
            $existing = $pdo->prepare("SELECT id, token FROM portal_links WHERE customer_id = ?");
            $existing->execute([$customerId]);
            $link = $existing->fetch(PDO::FETCH_ASSOC);
            
            if ($link) {
                echo json_encode([
                    'success' => true,
                    'message' => '该客户已有门户链接',
                    'data' => ['token' => $link['token']]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // 生成token
            $token = bin2hex(random_bytes(32));
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $now = time();
            
            $stmt = $pdo->prepare("
                INSERT INTO portal_links (customer_id, token, password_hash, enabled, expires_at, created_by, create_time, update_time)
                VALUES (?, ?, ?, 1, ?, ?, ?, ?)
            ");
            $stmt->execute([$customerId, $token, $passwordHash, $expiresAt > 0 ? $expiresAt : null, $user['id'], $now, $now]);
            
            echo json_encode([
                'success' => true,
                'message' => '门户链接生成成功',
                'data' => ['token' => $token]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'update':
            $password = trim($input['password'] ?? '');
            $enabled = isset($input['enabled']) ? intval($input['enabled']) : null;
            $expiresAt = isset($input['expires_at']) ? intval($input['expires_at']) : null;
            
            $updateFields = [];
            $params = [];
            
            if (!empty($password)) {
                $updateFields[] = "password_hash = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($enabled !== null) {
                $updateFields[] = "enabled = ?";
                $params[] = $enabled;
            }
            if ($expiresAt !== null) {
                $updateFields[] = "expires_at = ?";
                $params[] = $expiresAt > 0 ? $expiresAt : null;
            }
            
            if (empty($updateFields)) {
                echo json_encode(['success' => true, 'message' => '无需更新'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $updateFields[] = "update_time = ?";
            $params[] = time();
            $params[] = $customerId;
            
            $sql = "UPDATE portal_links SET " . implode(', ', $updateFields) . " WHERE customer_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'message' => '更新成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get':
        default:
            $stmt = $pdo->prepare("SELECT * FROM portal_links WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($link) {
                unset($link['password_hash']); // 不返回密码哈希
            }
            
            echo json_encode([
                'success' => true,
                'data' => $link
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
