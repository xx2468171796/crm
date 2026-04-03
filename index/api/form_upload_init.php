<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单附件 - 分片上传初始化 API
 * 
 * POST /api/form_upload_init.php
 * 
 * 请求体(JSON):
 * {
 *   "form_instance_id": 123,
 *   "filename": "参考图.psd",
 *   "filesize": 1073741824,
 *   "mime_type": "application/octet-stream"
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

$formInstanceId = intval($input['form_instance_id'] ?? 0);
$filename = trim($input['filename'] ?? '');
$filesize = intval($input['filesize'] ?? 0);
$mimeType = trim($input['mime_type'] ?? 'application/octet-stream');

error_log('[form_upload_init] 收到参数: form_instance_id=' . $formInstanceId . ', filename=' . $filename . ', filesize=' . $filesize);

if ($formInstanceId <= 0 || empty($filename) || $filesize <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数: form_instance_id=' . $formInstanceId . ', filename=' . $filename . ', filesize=' . $filesize], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单实例和关联的项目、客户信息
    $stmt = $pdo->prepare("
        SELECT fi.*, p.customer_id, p.project_name, p.project_code, c.name as customer_name, c.group_code
        FROM form_instances fi
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE fi.id = ?
    ");
    $stmt->execute([$formInstanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '表单实例不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取项目信息
    $groupCode = $instance['group_code'] ?? '';
    $projectCode = $instance['project_code'] ?? 'unknown';
    $formInstanceId = $instance['id'];
    $formName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $instance['instance_name'] ?? '表单');
    
    // 检查filename是否包含路径（文件夹上传场景）
    $hasPath = strpos($filename, '/') !== false;
    
    if ($hasPath) {
        // 文件夹上传：保留层级结构
        // filename格式：表单名_文件夹名/子目录/文件.txt
        $pathParts = explode('/', $filename);
        $actualFilename = array_pop($pathParts); // 提取文件名
        $folderPath = implode('/', $pathParts);  // 提取文件夹路径
        
        // 清理路径中的非法字符
        $safeFolderPath = preg_replace('/[\\\\:*?"<>|]/', '_', $folderPath);
        $safeActualFilename = preg_replace('/[\\\\:*?"<>|]/', '_', $actualFilename);
        
        $displayName = $filename;
        
        // 生成唯一文件名
        $ext = pathinfo($actualFilename, PATHINFO_EXTENSION);
        $uniqueId = uniqid('form_');
        $storageFilename = $uniqueId . ($ext ? '.' . $ext : '');
        
        // 存储路径保留层级: groups/{groupCode}/{projectCode}/customer_files/{folderPath}/{uniqueId}.{ext}
        $storageKey = "groups/{$groupCode}/{$projectCode}/customer_files/{$safeFolderPath}/{$storageFilename}";
    } else {
        // 单文件上传：原有逻辑
        $safeFilename = preg_replace('/[\/\\\\:*?"<>|]/', '_', $filename);
        $displayName = $formName . '_' . $safeFilename;
        
        // 生成ASCII安全的存储路径（避免中文编码问题）
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uniqueId = uniqid('form_');
        $storageFilename = $uniqueId . ($ext ? '.' . $ext : '');
        
        // 存储路径: groups/{groupCode}/{projectCode}/customer_files/{uniqueId}.{ext}
        $storageKey = "groups/{$groupCode}/{$projectCode}/customer_files/{$storageFilename}";
    }
    
    // 初始化分片上传
    $uploadService = new MultipartUploadService();
    $result = $uploadService->initiate($storageKey, $mimeType);
    
    if (empty($result['upload_id'])) {
        throw new Exception('初始化上传失败');
    }
    
    // 计算分片信息
    $partSize = 50 * 1024 * 1024; // 50MB per part
    $totalParts = ceil($filesize / $partSize);
    
    // 记录上传任务到数据库
    $now = time();
    $insertStmt = $pdo->prepare("
        INSERT INTO deliverables (
            project_id, form_instance_id, deliverable_name, file_path, 
            file_size, file_category, deliverable_type, approval_status, visibility_level,
            submitted_by, submitted_at, create_time, update_time
        ) VALUES (?, ?, ?, ?, ?, 'customer_file', 'form_attachment', 'approved', 'client', 0, ?, ?, ?)
    ");
    $insertStmt->execute([
        $instance['project_id'],
        $instance['id'],  // form_instance_id
        $displayName,  // 使用带表单前缀的显示名称
        $storageKey,
        $filesize,
        $now,
        $now,
        $now
    ]);
    $deliverableId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'upload_id' => $result['upload_id'],
            'storage_key' => $storageKey,
            'deliverable_id' => $deliverableId,
            'part_size' => $partSize,
            'total_parts' => $totalParts
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
