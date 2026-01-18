<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';
/**
 * 资源中心 - 完成分片上传
 * 
 * POST /api/rc_upload_complete.php
 * 
 * 请求体(JSON):
 * {
 *   "upload_id": "xxx",
 *   "storage_key": "groups/xxx/xxx.psd",
 *   "parts": [{"PartNumber": 1, "ETag": "xxx"}, ...],
 *   "project_id": 123,
 *   "file_category": "artwork_file",
 *   "parent_folder_id": 0,
 *   "filename": "大文件.psd",
 *   "filesize": 1073741824
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
$parts = $input['parts'] ?? [];
$projectId = intval($input['project_id'] ?? 0);
$fileCategory = trim($input['file_category'] ?? 'artwork_file');
$parentFolderId = intval($input['parent_folder_id'] ?? 0);
$filename = trim($input['filename'] ?? '');
$filesize = intval($input['filesize'] ?? 0);

if (empty($uploadId) || empty($storageKey) || empty($parts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 完成分片上传
    $multipart = new MultipartUploadService();
    $result = $multipart->complete($storageKey, $uploadId, $parts);
    
    // 确定审批状态
    $approvalStatus = ($fileCategory === 'artwork_file') ? 'pending' : 'approved';
    
    // 确定交付物类型
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $deliverableType = getDeliverableType($extension);
    
    // 插入数据库记录
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO deliverables (
            project_id, deliverable_name, deliverable_type, file_category, file_path, file_size,
            visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $projectId,
        $filename,
        $deliverableType,
        $fileCategory,
        $storageKey,
        $filesize,
        'client',
        $approvalStatus,
        $user['id'],
        $now,
        $now,
        $now,
        $parentFolderId ?: null
    ]);
    
    $deliverableId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'deliverable_id' => $deliverableId,
            'storage_key' => $storageKey,
            'etag' => $result['etag'] ?? '',
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function getDeliverableType($extension) {
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
    $videoTypes = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v'];
    $audioTypes = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];
    $documentTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt'];
    $archiveTypes = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
    $modelTypes = ['obj', 'fbx', 'stl', 'gltf', 'glb', 'blend', 'max', '3ds', 'dae', 'c4d'];
    $designTypes = ['psd', 'ai', 'sketch', 'fig', 'xd', 'indd', 'eps', 'cdr'];
    
    if (in_array($extension, $imageTypes)) return 'image';
    if (in_array($extension, $videoTypes)) return 'video';
    if (in_array($extension, $audioTypes)) return 'audio';
    if (in_array($extension, $documentTypes)) return 'document';
    if (in_array($extension, $archiveTypes)) return 'archive';
    if (in_array($extension, $modelTypes)) return 'model';
    if (in_array($extension, $designTypes)) return 'design';
    
    return 'other';
}
