<?php
/**
 * 分片上传 API
 * 支持大文件分片上传，分片大小90MB
 */

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$token = trim($_POST['token'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证分享链接
    $stmt = $pdo->prepare("
        SELECT 
            fsl.*,
            p.project_name AS project_name,
            p.customer_id,
            c.name AS customer_name,
            c.group_code
        FROM file_share_links fsl
        JOIN projects p ON p.id = fsl.project_id
        LEFT JOIN customers c ON c.id = p.customer_id
        WHERE fsl.token = ? AND fsl.status = 'active'
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        http_response_code(404);
        echo json_encode(['error' => '链接无效或已失效']);
        exit;
    }
    
    // 检查是否过期
    if (strtotime($link['expires_at']) < time()) {
        http_response_code(410);
        echo json_encode(['error' => '链接已过期']);
        exit;
    }
    
    // 验证密码
    if (!empty($link['password'])) {
        if (empty($password) || !password_verify($password, $link['password'])) {
            http_response_code(403);
            echo json_encode(['error' => '密码错误']);
            exit;
        }
    }
    
    // 获取存储配置
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    if (empty($storageConfig)) {
        http_response_code(500);
        echo json_encode(['error' => '存储配置错误']);
        exit;
    }
    
    $s3 = new S3StorageProvider($storageConfig, []);
    
    switch ($action) {
        case 'init':
            // 初始化分片上传
            handleInit($pdo, $link, $s3, $storageConfig);
            break;
            
        case 'upload_chunk':
            // 上传分片
            handleUploadChunk($pdo, $s3);
            break;
            
        case 'complete':
            // 完成上传
            handleComplete($pdo, $link, $s3);
            break;
            
        case 'abort':
            // 取消上传
            handleAbort($pdo, $s3);
            break;
            
        case 'direct':
            // 小文件直接上传
            handleDirectUpload($pdo, $link, $s3, $storageConfig);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => '无效的操作']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
}

/**
 * 初始化分片上传
 */
function handleInit($pdo, $link, $s3, $storageConfig) {
    $fileName = trim($_POST['file_name'] ?? '');
    $fileSize = intval($_POST['file_size'] ?? 0);
    $fileType = trim($_POST['file_type'] ?? 'application/octet-stream');
    $totalChunks = intval($_POST['total_chunks'] ?? 0);
    
    if (empty($fileName) || $fileSize <= 0 || $totalChunks <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '参数不完整']);
        exit;
    }
    
    // 限制单个文件大小不超过2GB
    $maxSize = 2 * 1024 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        http_response_code(400);
        echo json_encode(['error' => '单个文件大小不能超过2GB']);
        exit;
    }
    
    // 生成存储路径
    $storedName = '分享+' . $fileName;
    $groupCode = $link['group_code'] ?? '';
    $customerName = $link['customer_name'] ?? '未知客户';
    $projectName = $link['project_name'] ?? '未知项目';
    
    $folderName = $groupCode ? "{$groupCode} {$customerName}" : $customerName;
    $basePath = "customers/{$folderName}/{$projectName}/客户文件";
    
    $ext = pathinfo($storedName, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $basePath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    // 生成上传ID
    $uploadId = uniqid('upload_', true);
    
    // 创建临时目录存储分片
    $tempDir = sys_get_temp_dir() . '/chunk_uploads/' . $uploadId;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // 记录上传任务
    $stmt = $pdo->prepare("
        INSERT INTO chunk_upload_tasks 
        (upload_id, share_link_id, project_id, file_name, stored_name, file_size, file_type, 
         storage_key, total_chunks, uploaded_chunks, temp_dir, status, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'uploading', ?, ?)
    ");
    
    $now = time();
    try {
        $stmt->execute([
            $uploadId,
            $link['id'],
            $link['project_id'],
            $fileName,
            $storedName,
            $fileSize,
            $fileType,
            $storageKey,
            $totalChunks,
            $tempDir,
            $now,
            $now
        ]);
    } catch (PDOException $e) {
        // 如果表不存在，创建它
        if (strpos($e->getMessage(), 'doesn\'t exist') !== false) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS chunk_upload_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    upload_id VARCHAR(64) NOT NULL UNIQUE,
                    share_link_id INT,
                    project_id INT,
                    file_name VARCHAR(255),
                    stored_name VARCHAR(255),
                    file_size BIGINT,
                    file_type VARCHAR(100),
                    storage_key VARCHAR(500),
                    total_chunks INT,
                    uploaded_chunks INT DEFAULT 0,
                    temp_dir VARCHAR(500),
                    status ENUM('uploading', 'completed', 'aborted') DEFAULT 'uploading',
                    create_time INT,
                    update_time INT,
                    INDEX idx_upload_id (upload_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $stmt->execute([
                $uploadId,
                $link['id'],
                $link['project_id'],
                $fileName,
                $storedName,
                $fileSize,
                $fileType,
                $storageKey,
                $totalChunks,
                $tempDir,
                $now,
                $now
            ]);
        } else {
            throw $e;
        }
    }
    
    echo json_encode([
        'success' => true,
        'upload_id' => $uploadId,
        'storage_key' => $storageKey,
        'message' => '分片上传已初始化'
    ]);
}

/**
 * 上传分片
 */
function handleUploadChunk($pdo, $s3) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    $chunkIndex = intval($_POST['chunk_index'] ?? -1);
    
    if (empty($uploadId) || $chunkIndex < 0) {
        http_response_code(400);
        echo json_encode(['error' => '参数不完整']);
        exit;
    }
    
    if (empty($_FILES['chunk'])) {
        http_response_code(400);
        echo json_encode(['error' => '未收到分片数据']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("SELECT * FROM chunk_upload_tasks WHERE upload_id = ? AND status = 'uploading'");
    $stmt->execute([$uploadId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => '上传任务不存在或已完成']);
        exit;
    }
    
    $chunk = $_FILES['chunk'];
    $tempDir = $task['temp_dir'];
    
    // 保存分片到临时目录
    $chunkPath = $tempDir . '/chunk_' . str_pad($chunkIndex, 5, '0', STR_PAD_LEFT);
    
    if (!move_uploaded_file($chunk['tmp_name'], $chunkPath)) {
        http_response_code(500);
        echo json_encode(['error' => '保存分片失败']);
        exit;
    }
    
    // 更新已上传分片数
    $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET uploaded_chunks = uploaded_chunks + 1, update_time = ? WHERE upload_id = ?");
    $stmt->execute([time(), $uploadId]);
    
    // 获取更新后的进度
    $stmt = $pdo->prepare("SELECT uploaded_chunks, total_chunks FROM chunk_upload_tasks WHERE upload_id = ?");
    $stmt->execute([$uploadId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'chunk_index' => $chunkIndex,
        'uploaded_chunks' => intval($progress['uploaded_chunks']),
        'total_chunks' => intval($progress['total_chunks']),
        'message' => "分片 {$chunkIndex} 上传成功"
    ]);
}

/**
 * 完成上传 - 合并分片
 */
function handleComplete($pdo, $link, $s3) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少upload_id']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("SELECT * FROM chunk_upload_tasks WHERE upload_id = ? AND status = 'uploading'");
    $stmt->execute([$uploadId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => '上传任务不存在']);
        exit;
    }
    
    // 检查所有分片是否已上传
    if ($task['uploaded_chunks'] < $task['total_chunks']) {
        http_response_code(400);
        echo json_encode(['error' => '分片尚未全部上传', 'uploaded' => $task['uploaded_chunks'], 'total' => $task['total_chunks']]);
        exit;
    }
    
    $tempDir = $task['temp_dir'];
    $storageKey = $task['storage_key'];
    
    // 合并分片到临时文件
    $mergedFile = $tempDir . '/merged_' . uniqid();
    $mergedHandle = fopen($mergedFile, 'wb');
    
    if (!$mergedHandle) {
        http_response_code(500);
        echo json_encode(['error' => '无法创建合并文件']);
        exit;
    }
    
    for ($i = 0; $i < $task['total_chunks']; $i++) {
        $chunkPath = $tempDir . '/chunk_' . str_pad($i, 5, '0', STR_PAD_LEFT);
        if (!file_exists($chunkPath)) {
            fclose($mergedHandle);
            http_response_code(500);
            echo json_encode(['error' => "分片 {$i} 不存在"]);
            exit;
        }
        
        $chunkData = file_get_contents($chunkPath);
        fwrite($mergedHandle, $chunkData);
        unlink($chunkPath);
    }
    
    fclose($mergedHandle);
    
    // 上传到S3
    $uploadResult = $s3->putObject($storageKey, $mergedFile, [
        'mime_type' => $task['file_type']
    ]);
    
    // 删除临时文件
    unlink($mergedFile);
    rmdir($tempDir);
    
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        http_response_code(500);
        echo json_encode(['error' => '上传到存储失败']);
        exit;
    }
    
    // 记录到deliverables表
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO deliverables 
        (project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, 
         visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $task['project_id'],
        $task['stored_name'],
        'share_upload',
        'customer_file',
        $storageKey,
        $task['file_size'],
        'client',
        'approved',
        $link['created_by'],
        $now,
        $now,
        $now
    ]);
    
    $deliverableId = $pdo->lastInsertId();
    
    // 记录到file_share_uploads表
    try {
        $stmt = $pdo->prepare("
            INSERT INTO file_share_uploads 
            (share_link_id, project_id, deliverable_id, original_filename, stored_filename, file_size, file_path, storage_key, uploader_ip, create_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $task['share_link_id'],
            $task['project_id'],
            $deliverableId,
            $task['file_name'],
            $task['stored_name'],
            $task['file_size'],
            $storageKey,
            $storageKey,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $now
        ]);
    } catch (PDOException $e) {
        error_log('file_share_uploads insert failed: ' . $e->getMessage());
    }
    
    // 更新访问次数
    $stmt = $pdo->prepare("UPDATE file_share_links SET visit_count = visit_count + 1 WHERE id = ?");
    $stmt->execute([$link['id']]);
    
    // 更新任务状态
    $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET status = 'completed', update_time = ? WHERE upload_id = ?");
    $stmt->execute([time(), $uploadId]);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'original_name' => $task['file_name'],
            'stored_name' => $task['stored_name'],
            'file_size' => $task['file_size'],
            'message' => '文件上传成功'
        ]
    ]);
}

/**
 * 取消上传
 */
function handleAbort($pdo, $s3) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少upload_id']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("SELECT * FROM chunk_upload_tasks WHERE upload_id = ?");
    $stmt->execute([$uploadId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($task) {
        // 删除临时文件
        $tempDir = $task['temp_dir'];
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
        
        // 更新状态
        $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET status = 'aborted', update_time = ? WHERE upload_id = ?");
        $stmt->execute([time(), $uploadId]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '上传已取消'
    ]);
}

/**
 * 小文件直接上传（不分片）
 */
function handleDirectUpload($pdo, $link, $s3, $storageConfig) {
    // 检查文件
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = '文件上传失败';
        if (isset($_FILES['file'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = '文件过大';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg = '没有选择文件';
                    break;
            }
        }
        http_response_code(400);
        echo json_encode(['error' => $errorMsg]);
        exit;
    }
    
    $file = $_FILES['file'];
    $originalName = $file['name'];
    $fileSize = $file['size'];
    $tmpPath = $file['tmp_name'];
    $fileType = $file['type'] ?: 'application/octet-stream';
    
    // 获取客户文件夹路径
    $groupCode = $link['group_code'] ?? '';
    $customerName = $link['customer_name'] ?? '未知客户';
    $projectName = $link['project_name'] ?? '未知项目';
    
    $folderName = $groupCode ? "{$groupCode} {$customerName}" : $customerName;
    $basePath = "customers/{$folderName}/{$projectName}/客户文件";
    
    // 文件重命名
    $storedName = '分享+' . $originalName;
    
    // 生成存储路径
    $ext = pathinfo($storedName, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $basePath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    // 上传到S3
    $uploadResult = $s3->putObject($storageKey, $tmpPath, [
        'mime_type' => $fileType
    ]);
    
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        http_response_code(500);
        echo json_encode(['error' => '文件上传到存储失败']);
        exit;
    }
    
    // 记录到deliverables表
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO deliverables 
        (project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, 
         visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $link['project_id'],
        $storedName,
        'share_upload',
        'customer_file',
        $storageKey,
        $fileSize,
        'client',
        'approved',
        $link['customer_id'] ?? 0,
        $now,
        $now,
        $now
    ]);
    
    // 记录到file_share_uploads表
    try {
        $stmt = $pdo->prepare("
            INSERT INTO file_share_uploads (link_id, original_name, stored_name, file_size, storage_key, upload_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $link['id'],
            $originalName,
            $storedName,
            $fileSize,
            $storageKey,
            $now
        ]);
    } catch (Exception $e) {
        // 忽略file_share_uploads表的错误
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_size' => $fileSize
        ],
        'message' => '文件上传成功'
    ]);
}
