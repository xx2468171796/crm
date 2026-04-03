<?php
/**
 * 资源中心 - 分片上传代理
 * 
 * 解决 HTTP S3 和 HTTPS 网站之间的 Mixed Content 问题
 * 前端将分片数据发送到此代理，代理再转发到 S3
 * 
 * POST /api/rc_upload_part_proxy.php
 * 
 * 请求参数:
 * - upload_id: 上传会话ID
 * - storage_key: 存储键
 * - part_number: 分片编号
 * - 请求体: 分片二进制数据
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadId = trim($_GET['upload_id'] ?? $_POST['upload_id'] ?? '');
$storageKey = trim($_GET['storage_key'] ?? $_POST['storage_key'] ?? '');
$partNumber = intval($_GET['part_number'] ?? $_POST['part_number'] ?? 0);

if (empty($uploadId) || empty($storageKey) || $partNumber <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取预签名 URL
    $multipart = new MultipartUploadService();
    $presignedUrl = $multipart->getPartPresignedUrl($storageKey, $uploadId, $partNumber, 3600);
    
    // 读取请求体（分片数据）
    $chunkData = file_get_contents('php://input');
    if (empty($chunkData)) {
        throw new Exception('分片数据为空');
    }
    
    // 使用 cURL 上传到 S3
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $presignedUrl,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $chunkData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($chunkData),
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('上传分片失败: ' . $error);
    }
    
    if ($httpCode < 200 || $httpCode >= 300) {
        $body = substr($response, $headerSize);
        throw new Exception('S3 返回错误: HTTP ' . $httpCode . ' - ' . $body);
    }
    
    // 从响应头中提取 ETag
    $headers = substr($response, 0, $headerSize);
    $etag = '';
    if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $headers, $matches)) {
        $etag = trim($matches[1], '"');
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'part_number' => $partNumber,
            'etag' => $etag,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[RC_DEBUG] 分片上传代理错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
