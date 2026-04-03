<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 获取分片上传预签名 URL
 * POST /api/desktop_upload_part_url.php
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = $input['upload_id'] ?? '';
$storageKey = $input['storage_key'] ?? '';
$partNumber = (int)($input['part_number'] ?? 1);

if (!$uploadId || !$storageKey || $partNumber < 1) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $uploadService = new MultipartUploadService();
    $presignedUrl = $uploadService->getPartPresignedUrl($storageKey, $uploadId, $partNumber, 3600);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'presigned_url' => $presignedUrl,  // 保持兼容
            'upload_url' => $presignedUrl,     // 统一字段名
            'part_number' => $partNumber,
            'expires_in' => 3600
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_upload_part_url 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
