<?php
/**
 * 客户门户 - 分享文件代理 API
 * 
 * 通过后端代理获取S3文件，避免CORS和HTTP/HTTPS混合内容问题
 * GET ?s=share_token&url=xxx - 代理获取文件
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$shareToken = trim($_GET['s'] ?? '');
$url = $_GET['url'] ?? '';
$download = isset($_GET['download']) ? $_GET['download'] : null;

if (!$shareToken) {
    http_response_code(401);
    die('缺少分享token');
}

if (!$url) {
    http_response_code(400);
    die('缺少url参数');
}

// 验证分享token
$pdo = Db::pdo();
$stmt = $pdo->prepare("
    SELECT ds.*, d.file_path 
    FROM deliverable_shares ds
    INNER JOIN deliverables d ON d.id = ds.deliverable_id
    WHERE ds.share_token = ?
    AND ds.is_active = 1
    AND d.deleted_at IS NULL
    AND d.approval_status = 'approved'
    LIMIT 1
");
$stmt->execute([$shareToken]);
$share = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$share) {
    http_response_code(401);
    die('无效或已过期的分享链接');
}

// 检查是否过期
if (!empty($share['expire_at'])) {
    $expireTime = strtotime($share['expire_at']);
    if ($expireTime !== false && $expireTime < time()) {
        http_response_code(410);
        die('分享链接已过期');
    }
}

// 检查下载次数限制
if (!empty($share['max_downloads']) && $share['download_count'] >= $share['max_downloads']) {
    http_response_code(410);
    die('下载次数已用完');
}

// 验证URL是否为合法的S3/MinIO URL
$parsedUrl = parse_url($url);
if (!$parsedUrl || !isset($parsedUrl['host'])) {
    http_response_code(400);
    die('无效的URL');
}

// 只允许访问配置的S3端点
$config = storage_config();
$s3Endpoint = $config['s3']['endpoint'] ?? '';
$s3PublicUrl = $config['s3']['public_url'] ?? '';
$s3Host = parse_url($s3Endpoint, PHP_URL_HOST);
$s3PublicHost = $s3PublicUrl ? parse_url($s3PublicUrl, PHP_URL_HOST) : null;

// 允许的主机列表
$allowedHosts = array_filter([$s3Host, $s3PublicHost, 'localhost', '127.0.0.1']);
if (!in_array($parsedUrl['host'], $allowedHosts)) {
    http_response_code(403);
    die('不允许访问该URL');
}

try {
    // 使用cURL获取文件
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // 获取响应头
    $headers = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) return $len;
        $headers[strtolower(trim($header[0]))] = trim($header[1]);
        return $len;
    });
    
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        http_response_code(500);
        die('获取文件失败: ' . $error);
    }
    
    if ($httpCode !== 200) {
        http_response_code($httpCode);
        die('获取文件失败: HTTP ' . $httpCode);
    }
    
    // 设置响应头
    if (isset($headers['content-type'])) {
        header('Content-Type: ' . $headers['content-type']);
    } else {
        // 根据URL后缀猜测类型
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    }
    
    if (isset($headers['content-length'])) {
        header('Content-Length: ' . $headers['content-length']);
    } else {
        header('Content-Length: ' . strlen($content));
    }
    
    // 支持 Range 请求（视频播放需要）
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    
    // 如果指定了下载文件名，添加 Content-Disposition 头
    if ($download) {
        $filename = basename($download);
        $encodedFilename = rawurlencode($filename);
        header("Content-Disposition: attachment; filename=\"{$filename}\"; filename*=UTF-8''{$encodedFilename}");
    }
    
    echo $content;
    
} catch (Exception $e) {
    error_log('[API] portal_share_proxy 错误: ' . $e->getMessage());
    http_response_code(500);
    die('服务器错误');
}
