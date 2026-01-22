<?php
/**
 * 创建文件分享链接 API
 * POST /api/file_share_create.php
 */

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 验证用户登录
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$projectId = intval($input['project_id'] ?? 0);
$regionId = !empty($input['region_id']) ? intval($input['region_id']) : null;
$password = trim($input['password'] ?? '');
$maxVisits = !empty($input['max_visits']) ? intval($input['max_visits']) : null;
$expiresInDays = intval($input['expires_in_days'] ?? 7);
$note = trim($input['note'] ?? '');

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '请提供有效的项目ID']);
    exit;
}

if ($expiresInDays < 1 || $expiresInDays > 365) {
    $expiresInDays = 7;
}

try {
    $pdo = Db::pdo();
    
    // 验证项目存在
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => '项目不存在']);
        exit;
    }
    
    // 生成唯一token
    $token = bin2hex(random_bytes(32));
    
    // 计算过期时间
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));
    
    // 密码加密存储
    $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    // 插入分享链接
    $stmt = $pdo->prepare("
        INSERT INTO file_share_links 
        (project_id, token, created_by, region_id, password, max_visits, expires_at, note, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $projectId,
        $token,
        $user['id'],
        $regionId,
        $hashedPassword,
        $maxVisits,
        $expiresAt,
        $note,
        time()
    ]);
    
    $linkId = $pdo->lastInsertId();
    
    // 获取分享节点信息
    $shareUrl = '';
    if ($regionId) {
        $stmt = $pdo->prepare("SELECT domain, port, protocol FROM share_regions WHERE id = ? AND status = 1");
        $stmt->execute([$regionId]);
        $region = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($region) {
            $protocol = $region['protocol'] ?? 'https';
            $domain = $region['domain'];
            $port = $region['port'] ? ":{$region['port']}" : '';
            $shareUrl = "{$protocol}://{$domain}{$port}/public/share_upload.php?token={$token}";
        }
    }
    
    // 如果没有节点，使用默认链接
    if (!$shareUrl) {
        // 获取默认节点
        $stmt = $pdo->prepare("SELECT domain, port, protocol FROM share_regions WHERE is_default = 1 AND status = 1 LIMIT 1");
        $stmt->execute();
        $defaultRegion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($defaultRegion) {
            $protocol = $defaultRegion['protocol'] ?? 'https';
            $domain = $defaultRegion['domain'];
            $port = $defaultRegion['port'] ? ":{$defaultRegion['port']}" : '';
            $shareUrl = "{$protocol}://{$domain}{$port}/public/share_upload.php?token={$token}";
        } else {
            // 使用当前域名
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $shareUrl = "{$protocol}://{$host}/public/share_upload.php?token={$token}";
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $linkId,
            'token' => $token,
            'share_url' => $shareUrl,
            'project_name' => $project['name'],
            'expires_at' => $expiresAt,
            'max_visits' => $maxVisits,
            'has_password' => !empty($password),
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
}
