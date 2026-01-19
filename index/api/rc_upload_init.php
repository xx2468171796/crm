<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';
/**
 * 资源中心 - 分片上传初始化 API
 * 
 * POST /api/rc_upload_init.php
 * 
 * 请求体(JSON):
 * {
 *   "project_id": 123,
 *   "filename": "大文件.psd",
 *   "filesize": 1073741824,
 *   "file_category": "artwork_file",
 *   "parent_folder_id": 0,
 *   "mime_type": "application/octet-stream"
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

$projectId = intval($input['project_id'] ?? 0);
$filename = trim($input['filename'] ?? '');
$filesize = intval($input['filesize'] ?? 0);
$fileCategory = trim($input['file_category'] ?? 'artwork_file');
$parentFolderId = intval($input['parent_folder_id'] ?? 0);
$mimeType = trim($input['mime_type'] ?? 'application/octet-stream');

if ($projectId <= 0 || empty($filename) || $filesize <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询项目和客户信息
    $stmt = $pdo->prepare("
        SELECT p.project_name, p.project_code, c.group_code, c.group_name, c.name as customer_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 构建存储路径 - 优先使用 group_code，其次用 group_name，最后用客户名称
    $groupCode = $project['group_code'] ?: $project['group_name'] ?: $project['customer_name'] ?: ('P' . $projectId);
    $groupCode = preg_replace('/[\/\\\\:*?"<>|]/', '_', $groupCode);
    $projectName = $project['project_name'] ?: $project['project_code'] ?: ('项目' . $projectId);
    $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
    
    switch ($fileCategory) {
        case 'customer_file':
            $categoryDir = '客户文件';
            break;
        case 'model_file':
            $categoryDir = '模型文件';
            break;
        default:
            $categoryDir = '作品文件';
            break;
    }
    
    // 获取文件夹路径
    $folderPath = '';
    if ($parentFolderId > 0) {
        $folderPath = getFolderPath($pdo, $parentFolderId);
    }
    
    // 安全的文件名
    $safeFilename = preg_replace('/[\/\\\\:*?"<>|]/', '_', $filename);
    
    // 存储路径
    $storageKey = "groups/{$groupCode}/{$projectName}/{$categoryDir}";
    if (!empty($folderPath)) {
        $storageKey .= "/{$folderPath}";
    }
    $storageKey .= "/{$safeFilename}";
    
    // 初始化分片上传
    $multipart = new MultipartUploadService();
    $result = $multipart->initiate($storageKey, $mimeType);
    
    // 计算分片信息
    $partSize = 90 * 1024 * 1024; // 90MB per part
    $totalParts = ceil($filesize / $partSize);
    
    // 检测上传模式：网站HTTPS + S3 HTTPS = 直连，否则代理
    $storageConfig = require __DIR__ . '/../config/storage.php';
    $s3UseHttps = $storageConfig['s3']['use_https'] ?? false;
    $siteIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    // 强制模式或自动检测
    $uploadMode = $storageConfig['upload']['mode'] ?? 'auto';
    if ($uploadMode === 'direct') {
        $useDirect = true;
    } elseif ($uploadMode === 'proxy') {
        $useDirect = false;
    } else {
        // auto: HTTPS站点 + HTTPS S3 = 直连
        $useDirect = $s3UseHttps || !$siteIsHttps;
    }
    
    // 如果直连模式，生成所有分片的预签名URL
    $presignedUrls = [];
    if ($useDirect) {
        for ($i = 1; $i <= $totalParts; $i++) {
            $presignedUrls[$i] = $multipart->getPartPresignedUrl($storageKey, $result['upload_id'], $i, 3600);
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'upload_id' => $result['upload_id'],
            'storage_key' => $storageKey,
            'part_size' => $partSize,
            'total_parts' => $totalParts,
            'project_id' => $projectId,
            'file_category' => $fileCategory,
            'parent_folder_id' => $parentFolderId,
            'mode' => $useDirect ? 'direct' : 'proxy',
            'presigned_urls' => $presignedUrls,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// 获取文件夹路径
function getFolderPath($pdo, $folderId) {
    $path = [];
    $currentId = $folderId;
    $maxDepth = 10;
    
    while ($currentId && $maxDepth-- > 0) {
        $stmt = $pdo->prepare("SELECT id, deliverable_name, parent_folder_id FROM deliverables WHERE id = ? AND is_folder = 1");
        $stmt->execute([$currentId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$folder) break;
        
        array_unshift($path, $folder['deliverable_name']);
        $currentId = $folder['parent_folder_id'];
    }
    
    return implode('/', $path);
}
