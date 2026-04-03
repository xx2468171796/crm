<?php
/**
 * 资源中心 - 文件哈希检查 API（去重/秒传）
 * 
 * POST /api/rc_upload_check_hash.php
 * 
 * 请求体(JSON):
 * {
 *   "file_hash": "sha256哈希值",
 *   "project_id": 123,
 *   "file_category": "artwork_file",
 *   "parent_folder_id": 0,
 *   "filename": "文件名.psd",
 *   "filesize": 12345678
 * }
 * 
 * 返回:
 * - exists: true/false
 * - 如果存在，返回 storage_key 用于复用
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

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

$fileHash = trim($input['file_hash'] ?? '');
$projectId = intval($input['project_id'] ?? 0);
$fileCategory = trim($input['file_category'] ?? 'artwork_file');
$parentFolderId = intval($input['parent_folder_id'] ?? 0);
$filename = trim($input['filename'] ?? '');
$filesize = intval($input['filesize'] ?? 0);

if (empty($fileHash) || strlen($fileHash) !== 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的文件哈希'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查找具有相同哈希的文件
    $stmt = $pdo->prepare("
        SELECT id, file_path, file_size, deliverable_name 
        FROM deliverables 
        WHERE file_hash = ? AND is_folder = 0
        LIMIT 1
    ");
    $stmt->execute([$fileHash]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // 文件已存在，可以秒传
        // 直接创建新的数据库记录，复用已有的 storage_key
        
        // 确定审批状态
        $approvalStatus = ($fileCategory === 'artwork_file') ? 'pending' : 'approved';
        
        // 确定交付物类型
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $deliverableType = getDeliverableType($extension);
        
        $now = time();
        $insertStmt = $pdo->prepare("
            INSERT INTO deliverables (
                project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, file_hash,
                visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $projectId,
            $filename,
            $deliverableType,
            $fileCategory,
            $existing['file_path'],  // 复用已有的存储路径
            $filesize,
            $fileHash,
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
                'exists' => true,
                'instant' => true,  // 秒传
                'deliverable_id' => $deliverableId,
                'storage_key' => $existing['file_path'],
                'message' => '文件已存在，秒传成功'
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 文件不存在，需要正常上传
        echo json_encode([
            'success' => true,
            'data' => [
                'exists' => false,
                'instant' => false
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
    
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
    $designTypes = ['psd', 'ai', 'sketch', 'fig', 'xd', 'indd', 'eps', 'cdr', 'skp'];
    
    if (in_array($extension, $imageTypes)) return 'image';
    if (in_array($extension, $videoTypes)) return 'video';
    if (in_array($extension, $audioTypes)) return 'audio';
    if (in_array($extension, $documentTypes)) return 'document';
    if (in_array($extension, $archiveTypes)) return 'archive';
    if (in_array($extension, $modelTypes)) return 'model';
    if (in_array($extension, $designTypes)) return 'design';
    
    return 'other';
}
