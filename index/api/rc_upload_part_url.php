<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';
/**
 * 资源中心 - 获取分片上传预签名URL
 * 
 * POST /api/rc_upload_part_url.php
 * 
 * 请求体(JSON):
 * {
 *   "upload_id": "xxx",
 *   "storage_key": "groups/xxx/xxx.psd",
 *   "part_number": 1
 * }
 */

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

$input = json_decode(file_get_contents('php://input'), true);

$uploadId = trim($input['upload_id'] ?? '');
$storageKey = trim($input['storage_key'] ?? '');
$partNumber = intval($input['part_number'] ?? 0);

if (empty($uploadId) || empty($storageKey) || $partNumber <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $multipart = new MultipartUploadService();
    $presignedUrl = $multipart->getPresignedUrl($storageKey, $uploadId, $partNumber, 3600);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'presigned_url' => $presignedUrl,
            'part_number' => $partNumber,
            'expires_in' => 3600,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
