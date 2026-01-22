<?php
/**
 * 生成网盘分享链接 API
 * POST /api/personal_drive_share.php
 */

require_once __DIR__ . '/../core/api_init.php';

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$folderPath = trim($input['folder_path'] ?? '/');
$fileId = !empty($input['file_id']) ? intval($input['file_id']) : null;  // 支持单文件分享
$regionId = !empty($input['region_id']) ? intval($input['region_id']) : null;  // 分享节点
$password = trim($input['password'] ?? '');
$maxVisits = !empty($input['max_visits']) ? intval($input['max_visits']) : null;
$expiresInDays = intval($input['expires_in_days'] ?? 7);

if ($expiresInDays < 1 || $expiresInDays > 365) {
    $expiresInDays = 7;
}

try {
    $pdo = Db::pdo();
    
    // 获取用户网盘
    $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$drive) {
        http_response_code(404);
        echo json_encode(['error' => '网盘不存在']);
        exit;
    }
    
    // 生成唯一token
    $token = bin2hex(random_bytes(32));
    
    // 计算过期时间
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));
    
    // 密码加密存储
    $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    // 如果是文件分享，验证文件存在
    if ($fileId) {
        $stmt = $pdo->prepare("SELECT id, filename FROM drive_files WHERE id = ? AND drive_id = ?");
        $stmt->execute([$fileId, $drive['id']]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fileInfo) {
            http_response_code(404);
            echo json_encode(['error' => '文件不存在']);
            exit;
        }
    }
    
    // 插入分享链接
    $stmt = $pdo->prepare("
        INSERT INTO drive_share_links 
        (drive_id, user_id, token, folder_path, file_id, password, max_visits, expires_at, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $drive['id'],
        $user['id'],
        $token,
        $folderPath,
        $fileId,
        $hashedPassword,
        $maxVisits,
        $expiresAt,
        time()
    ]);
    
    $linkId = $pdo->lastInsertId();
    
    // 获取分享节点
    $shareUrl = '';
    if ($regionId) {
        $stmt = $pdo->prepare("SELECT domain, port, protocol FROM share_regions WHERE id = ? AND status = 1");
        $stmt->execute([$regionId]);
        $region = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($region) {
            $portPart = $region['port'] ? ':' . $region['port'] : '';
            $shareUrl = "{$region['protocol']}://{$region['domain']}{$portPart}/drive_share.php?token={$token}";
        }
    }
    
    // 如果没有指定节点，使用默认节点
    if (!$shareUrl) {
        $stmt = $pdo->prepare("SELECT domain, port, protocol FROM share_regions WHERE is_default = 1 AND status = 1 LIMIT 1");
        $stmt->execute();
        $defaultRegion = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($defaultRegion) {
            $portPart = $defaultRegion['port'] ? ':' . $defaultRegion['port'] : '';
            $shareUrl = "{$defaultRegion['protocol']}://{$defaultRegion['domain']}{$portPart}/drive_share.php?token={$token}";
        } else {
            // 没有配置节点，使用当前域名
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $shareUrl = "{$protocol}://{$host}/drive_share.php?token={$token}";
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $linkId,
            'token' => $token,
            'share_url' => $shareUrl,
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
