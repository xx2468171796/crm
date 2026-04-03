<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 分片上传到本地缓存 API
 * 
 * POST action=init: 初始化分片上传
 * POST action=upload_part: 上传分片
 * POST action=complete: 完成上传（异步上传到S3）
 * 
 * 流程：
 * 1. 客户端调用 init 获取 upload_id
 * 2. 客户端分片上传到本地缓存目录
 * 3. 客户端调用 complete，服务端异步上传到S3
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../services/S3Service.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 缓存目录
$cacheDir = __DIR__ . '/../../storage/upload_cache/chunks';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

// 判断是分片上传还是JSON请求
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'multipart/form-data') !== false) {
    // 分片上传
    handleUploadPart($cacheDir, $user);
} else {
    // JSON请求
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'init':
            handleInit($input, $cacheDir, $user);
            break;
        case 'complete':
            handleComplete($input, $cacheDir, $user);
            break;
        default:
            echo json_encode(['success' => false, 'error' => '无效的操作'], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 初始化分片上传
 */
function handleInit($input, $cacheDir, $user) {
    $groupCode = $input['group_code'] ?? '';
    $projectId = (int)($input['project_id'] ?? 0);
    $assetType = $input['asset_type'] ?? 'works';
    $filename = $input['filename'] ?? '';
    $filesize = (int)($input['filesize'] ?? 0);
    $mimeType = $input['mime_type'] ?? 'application/octet-stream';
    
    if (!$groupCode || !$filename || !$filesize) {
        echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 生成唯一的上传ID
    $uploadId = uniqid('desktop_', true) . '_' . bin2hex(random_bytes(8));
    
    // 获取项目名称
    $projectName = '';
    if ($projectId > 0) {
        $project = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code as customer_group_code
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );
        if ($project) {
            $projectName = $project['project_name'] ?: $project['project_code'];
            $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
            if (preg_match('/^P\d+$/', $groupCode) && $project['customer_group_code']) {
                $groupCode = $project['customer_group_code'];
            }
        }
    }
    
    // 构建存储键（使用 FolderUploadService 统一逻辑，支持子目录结构）
    $relPath = $input['rel_path'] ?? '';
    // 入口处清理 rel_path，防止路径遍历
    $relPath = str_replace('\\', '/', $relPath);
    $relPath = preg_replace('#(?:^|/)\.\.(?:/|$)#', '/', $relPath);
    $relPath = preg_replace('#^(\.\./)+#', '', $relPath);
    $relPath = trim($relPath, '/');
    $folderService = new FolderUploadService();
    // 如果 rel_path 包含子目录则使用，否则只用文件名
    $relPathForKey = !empty($relPath) && strpos(str_replace('\\', '/', $relPath), '/') !== false
        ? $relPath
        : basename($filename);
    $keyResult = $folderService->buildStorageKey($groupCode, $assetType, $relPathForKey, $projectId);
    $storageKey = $keyResult['storage_key'];
    
    // 分片大小 90MB
    $partSize = 90 * 1024 * 1024;
    $totalParts = (int)ceil($filesize / $partSize);
    
    // 创建上传目录
    $uploadDir = $cacheDir . '/' . $uploadId;
    if (!mkdir($uploadDir, 0750, true)) {
        echo json_encode(['success' => false, 'error' => '创建上传目录失败'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 保存元数据
    $meta = [
        'upload_id' => $uploadId,
        'storage_key' => $storageKey,
        'filename' => $filename,
        'filesize' => $filesize,
        'mime_type' => $mimeType,
        'part_size' => $partSize,
        'total_parts' => $totalParts,
        'project_id' => $projectId,
        'asset_type' => $assetType,
        'rel_path' => $relPath,
        'user_id' => $user['id'],
        'create_time' => time(),
        'parts_uploaded' => [],
    ];
    file_put_contents($uploadDir . '/meta.json', json_encode($meta));
    
    echo json_encode([
        'success' => true,
        'data' => [
            'upload_id' => $uploadId,
            'storage_key' => $storageKey,
            'part_size' => $partSize,
            'total_parts' => $totalParts,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 上传分片
 */
function handleUploadPart($cacheDir, $user) {
    $uploadId = $_POST['upload_id'] ?? '';
    $partNumber = (int)($_POST['part_number'] ?? 0);

    if (!$uploadId || $partNumber <= 0) {
        echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 防止路径遍历：upload_id 只允许安全字符
    if (!preg_match('/^desktop_[a-f0-9_.]+$/i', $uploadId)) {
        echo json_encode(['success' => false, 'error' => '无效的upload_id'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $uploadDir = $cacheDir . '/' . $uploadId;
    $metaFile = $uploadDir . '/meta.json';
    
    if (!file_exists($metaFile)) {
        echo json_encode(['success' => false, 'error' => '上传会话不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => '分片上传失败'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 保存分片
    $partFile = $uploadDir . '/part_' . str_pad($partNumber, 5, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $partFile)) {
        echo json_encode(['success' => false, 'error' => '保存分片失败'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 更新元数据
    $meta = json_decode(file_get_contents($metaFile), true);
    $meta['parts_uploaded'][$partNumber] = [
        'size' => filesize($partFile),
        'time' => time(),
    ];
    file_put_contents($metaFile, json_encode($meta));
    
    echo json_encode([
        'success' => true,
        'data' => [
            'part_number' => $partNumber,
            'uploaded' => count($meta['parts_uploaded']),
            'total' => $meta['total_parts'],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 完成上传（合并分片并异步上传到S3）
 */
function handleComplete($input, $cacheDir, $user) {
    $uploadId = $input['upload_id'] ?? '';

    if (!$uploadId) {
        echo json_encode(['success' => false, 'error' => '缺少upload_id'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 防止路径遍历：upload_id 只允许安全字符
    if (!preg_match('/^desktop_[a-f0-9_.]+$/i', $uploadId)) {
        echo json_encode(['success' => false, 'error' => '无效的upload_id'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $uploadDir = $cacheDir . '/' . $uploadId;
    $metaFile = $uploadDir . '/meta.json';
    
    if (!file_exists($metaFile)) {
        echo json_encode(['success' => false, 'error' => '上传会话不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $meta = json_decode(file_get_contents($metaFile), true);
    
    // 检查所有分片是否已上传
    if (count($meta['parts_uploaded']) < $meta['total_parts']) {
        echo json_encode([
            'success' => false, 
            'error' => '分片未完全上传',
            'uploaded' => count($meta['parts_uploaded']),
            'total' => $meta['total_parts'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 合并分片
    $mergedFile = $uploadDir . '/merged';
    $fp = fopen($mergedFile, 'wb');
    if (!$fp) {
        echo json_encode(['success' => false, 'error' => '创建合并文件失败'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    for ($i = 1; $i <= $meta['total_parts']; $i++) {
        $partFile = $uploadDir . '/part_' . str_pad($i, 5, '0', STR_PAD_LEFT);
        if (!file_exists($partFile)) {
            fclose($fp);
            echo json_encode(['success' => false, 'error' => "分片 $i 不存在"], JSON_UNESCAPED_UNICODE);
            return;
        }
        $partFp = fopen($partFile, 'rb');
        if ($partFp) {
            stream_copy_to_stream($partFp, $fp);
            fclose($partFp);
        }
    }
    fclose($fp);
    
    // 落库到 deliverables
    $deliverableId = 0;
    $projectId = $meta['project_id'] ?? 0;
    $assetType = $meta['asset_type'] ?? 'works';
    $storageKey = $meta['storage_key'];
    $filename = $meta['filename'];
    $filesize = filesize($mergedFile);
    
    if ($projectId > 0) {
        try {
            $relPath = $meta['rel_path'] ?? '';
            $folderService = new FolderUploadService();
            $deliverableId = $folderService->recordDeliverable(
                $projectId, $storageKey, $filename, $filesize, $assetType, $user['id'], $relPath
            );
        } catch (Exception $e) {
            error_log('[DESKTOP_CHUNK] 落库失败: ' . $e->getMessage());
        }
    }
    
    // 保存异步上传元数据
    $asyncMeta = [
        'storage_key' => $storageKey,
        'mime_type' => $meta['mime_type'],
        'file_size' => $filesize,
        'deliverable_id' => $deliverableId,
        'create_time' => time(),
    ];
    file_put_contents($mergedFile . '.json', json_encode($asyncMeta));
    
    // 先返回响应
    $response = json_encode([
        'success' => true,
        'data' => [
            'storage_key' => $storageKey,
            'deliverable_id' => $deliverableId,
            'async' => true,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
    // 清除输出缓冲
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    header('X-Accel-Buffering: no');
    
    echo $response;
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    
    // 异步上传到S3
    try {
        $storage = storage_provider();
        $storage->putObject($storageKey, $mergedFile, ['mime_type' => $meta['mime_type']]);
        error_log("[DESKTOP_CHUNK_ASYNC] S3 upload success: $storageKey, size=$filesize");
        
        // 清理上传目录
        @unlink($mergedFile . '.json');
        @unlink($mergedFile);
        for ($i = 1; $i <= $meta['total_parts']; $i++) {
            @unlink($uploadDir . '/part_' . str_pad($i, 5, '0', STR_PAD_LEFT));
        }
        @unlink($metaFile);
        @rmdir($uploadDir);
    } catch (Exception $e) {
        error_log("[DESKTOP_CHUNK_ASYNC] S3 upload failed: " . $e->getMessage());
    }
}
