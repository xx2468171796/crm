<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单附件 - 获取分片预签名URL
 * 
 * POST /api/form_upload_part_url.php
 * 
 * 请求体(JSON):
 * {
 *   "upload_id": "xxx",
 *   "storage_key": "customers/1/需求表单/xxx/file.psd",
 *   "part_number": 1
 * }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../services/MultipartUploadService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
// 确保UTF-8解码
$input = json_decode($rawInput, true, 512, JSON_UNESCAPED_UNICODE);
if (json_last_error() !== JSON_ERROR_NONE) {
    $input = json_decode(mb_convert_encoding($rawInput, 'UTF-8', 'auto'), true);
}

$uploadId = trim($input['upload_id'] ?? '');
$storageKey = trim($input['storage_key'] ?? '');
$partNumber = intval($input['part_number'] ?? 0);

// 调试日志
error_log('[form_upload_part_url] storage_key received: ' . $storageKey);

if (empty($uploadId) || empty($storageKey) || $partNumber <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $uploadService = new MultipartUploadService();
    $url = $uploadService->getPartPresignedUrl($storageKey, $uploadId, $partNumber);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'upload_url' => $url,
            'part_number' => $partNumber
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
