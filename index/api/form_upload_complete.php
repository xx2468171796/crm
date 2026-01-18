<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单附件 - 完成分片上传
 * 
 * POST /api/form_upload_complete.php
 * 
 * 请求体(JSON):
 * {
 *   "upload_id": "xxx",
 *   "storage_key": "customers/1/需求表单/xxx/file.psd",
 *   "deliverable_id": 123,
 *   "parts": [
 *     {"PartNumber": 1, "ETag": "xxx"},
 *     {"PartNumber": 2, "ETag": "yyy"}
 *   ]
 * }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$uploadId = trim($input['upload_id'] ?? '');
$storageKey = trim($input['storage_key'] ?? '');
$deliverableId = intval($input['deliverable_id'] ?? 0);
$parts = $input['parts'] ?? [];

if (empty($uploadId) || empty($storageKey) || $deliverableId <= 0 || empty($parts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 完成分片上传
    $uploadService = new MultipartUploadService();
    $result = $uploadService->complete($storageKey, $uploadId, $parts);
    
    // 更新数据库状态
    $now = time();
    $updateStmt = $pdo->prepare("
        UPDATE deliverables 
        SET approval_status = 'approved', update_time = ? 
        WHERE id = ?
    ");
    $updateStmt->execute([$now, $deliverableId]);
    
    // 获取文件信息
    $stmt = $pdo->prepare("SELECT * FROM deliverables WHERE id = ?");
    $stmt->execute([$deliverableId]);
    $deliverable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => '文件上传完成',
        'data' => [
            'deliverable_id' => $deliverableId,
            'filename' => $deliverable['deliverable_name'] ?? '',
            'storage_key' => $storageKey
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 上传失败，更新状态
    $pdo->prepare("UPDATE deliverables SET approval_status = 'rejected' WHERE id = ?")->execute([$deliverableId]);
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
