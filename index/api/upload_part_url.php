<?php
/**
 * 统一获取分片上传URL API（三端共用）
 * POST /api/upload_part_url.php
 * 
 * 参数:
 * - upload_id: 上传ID（必填）
 * - storage_key: 存储键（必填）
 * - part_number: 分片编号（必填）
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/unified_auth.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

// 统一认证
$authResult = unified_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = $input['upload_id'] ?? '';
$storageKey = $input['storage_key'] ?? '';
$partNumber = (int)($input['part_number'] ?? 0);

if (!$uploadId || !$storageKey || $partNumber < 1) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new FolderUploadService();
    $url = $service->getPartUploadUrl($storageKey, $uploadId, $partNumber);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'url' => $url,
            'part_number' => $partNumber,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] upload_part_url 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
