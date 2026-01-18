<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件代理 API
 * 
 * 通过后端代理获取S3文件，避免CORS问题
 * GET ?url=xxx - 代理获取文件
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$user = desktop_auth_require();

$url = $_GET['url'] ?? '';

error_log('[file_proxy] 请求URL: ' . substr($url, 0, 200));

if (!$url) {
    error_log('[file_proxy] 错误: 缺少url参数');
    http_response_code(400);
    die('缺少url参数');
}

// 验证URL是否为合法的S3/MinIO URL
$parsedUrl = parse_url($url);
if (!$parsedUrl || !isset($parsedUrl['host'])) {
    error_log('[file_proxy] 错误: 无效的URL格式');
    http_response_code(400);
    die('无效的URL');
}

error_log('[file_proxy] 解析URL host: ' . $parsedUrl['host']);

// 只允许访问配置的S3端点
$config = storage_config();
$s3Endpoint = $config['s3']['endpoint'] ?? '';
$s3Host = parse_url($s3Endpoint, PHP_URL_HOST);

error_log('[file_proxy] S3 endpoint: ' . $s3Endpoint . ', host: ' . $s3Host);

// 允许的主机列表
$allowedHosts = [$s3Host, 'localhost', '127.0.0.1', '192.168.110.246'];
if (!in_array($parsedUrl['host'], $allowedHosts)) {
    error_log('[file_proxy] 错误: 不允许的host - ' . $parsedUrl['host']);
    http_response_code(403);
    die('不允许访问该URL: ' . $parsedUrl['host']);
}

try {
    error_log('[file_proxy] 开始cURL请求...');
    
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
    $contentLength = strlen($content);
    curl_close($ch);
    
    error_log('[file_proxy] cURL响应: HTTP ' . $httpCode . ', 内容长度: ' . $contentLength . ', 错误: ' . ($error ?: '无'));
    
    if ($error) {
        error_log('[file_proxy] cURL错误: ' . $error);
        http_response_code(500);
        die('获取文件失败: ' . $error);
    }
    
    if ($httpCode !== 200) {
        error_log('[file_proxy] HTTP错误: ' . $httpCode . ', 响应: ' . substr($content, 0, 500));
        http_response_code($httpCode);
        die('获取文件失败: HTTP ' . $httpCode);
    }
    
    error_log('[file_proxy] 成功获取文件, 大小: ' . $contentLength);
    
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
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
    }
    
    if (isset($headers['content-length'])) {
        header('Content-Length: ' . $headers['content-length']);
    } else {
        header('Content-Length: ' . strlen($content));
    }
    
    header('Cache-Control: public, max-age=3600');
    
    echo $content;
    
} catch (Exception $e) {
    error_log('[API] desktop_file_proxy 错误: ' . $e->getMessage());
    http_response_code(500);
    die('服务器错误');
}
