<?php
/**
 * 统一分片上传代理 API（解决CORS问题）
 * POST /api/upload_part.php
 * 
 * 参数:
 * - upload_id: 上传ID（必填）
 * - storage_key: 存储键（必填）
 * - part_number: 分片编号（必填）
 * - 请求体: 二进制分片数据
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/unified_auth.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

// 统一认证
$authResult = unified_auth_require();

// 从查询参数获取（因为body是二进制数据）
$uploadId = $_GET['upload_id'] ?? '';
$storageKey = $_GET['storage_key'] ?? '';
$partNumber = (int)($_GET['part_number'] ?? 0);

if (!$uploadId || !$storageKey || $partNumber < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new FolderUploadService();
    
    // 获取预签名URL
    $presignedUrl = $service->getPartUploadUrl($storageKey, $uploadId, $partNumber);
    
    // 读取请求体（二进制分片数据）
    $partData = file_get_contents('php://input');
    if (empty($partData)) {
        throw new Exception('分片数据为空');
    }
    
    // 使用cURL转发到S3
    $ch = curl_init($presignedUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $partData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($partData),
        ],
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL错误: ' . $error);
    }
    
    if ($httpCode < 200 || $httpCode >= 300) {
        $body = substr($response, $headerSize);
        error_log('[API] upload_part S3返回错误: ' . $httpCode . ' - ' . $body);
        throw new Exception('S3上传失败: HTTP ' . $httpCode);
    }
    
    // 从响应头提取ETag
    $headers = substr($response, 0, $headerSize);
    $etag = '';
    if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $headers, $matches)) {
        $etag = $matches[1];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'part_number' => $partNumber,
            'etag' => $etag,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] upload_part 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
