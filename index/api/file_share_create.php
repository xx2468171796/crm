<?php
/**
 * 创建文件分享链接 API
 * POST /api/file_share_create.php
 */

// CORS处理
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 支持两种认证方式：桌面端token认证 或 web session认证
$user = null;

// 检查是否有Authorization header (桌面端)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    require_once __DIR__ . '/../core/desktop_auth.php';
    $user = desktop_verify_token($matches[1]);
}

// 如果没有token认证，尝试session认证
if (!$user) {
    require_once __DIR__ . '/../core/auth.php';
    $user = current_user();
}

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
    $stmt = $pdo->prepare("SELECT id, project_name FROM projects WHERE id = ?");
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
            'project_name' => $project['project_name'],
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
