<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件下载 API
 * 
 * GET /api/desktop_download.php?action=get_url&storage_key=xxx
 * POST /api/desktop_download.php { storage_key: xxx }
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];
$storageKey = '';

if ($method === 'GET') {
    $storageKey = $_GET['storage_key'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $storageKey = $input['storage_key'] ?? '';
}

if (!$storageKey) {
    echo json_encode(['success' => false, 'error' => '缺少 storage_key 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $uploadService = new MultipartUploadService();
    $presignedUrl = $uploadService->getDownloadPresignedUrl($storageKey, 3600);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'download_url' => $presignedUrl,
            'presigned_url' => $presignedUrl,
            'url' => $presignedUrl,
            'expires_in' => 3600
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_download 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
