<?php
/**
 * 客户门户分片上传 API
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
$projectId = intval($_POST['project_id'] ?? 0);

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证门户token (使用portal_links表)
    $portalStmt = $pdo->prepare("SELECT * FROM portal_links WHERE token = ? AND enabled = 1");
    $portalStmt->execute([$token]);
    $portal = $portalStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$portal) {
        http_response_code(401);
        echo json_encode(['error' => '无效的访问令牌']);
        exit;
    }
    
    // 获取客户信息
    $customerId = $portal['customer_id'];
    $stmt = $pdo->prepare("SELECT id, name, group_code FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(401);
        echo json_encode(['error' => '客户不存在']);
        exit;
    }
    
    // 根据action执行不同操作
    switch ($action) {
        case 'init':
            handleInit($pdo, $customer, $projectId);
            break;
        case 'upload_chunk':
            handleUploadChunk($pdo, $customer);
            break;
        case 'complete':
            handleComplete($pdo, $customer);
            break;
        case 'abort':
            handleAbort($pdo, $customer);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => '无效的操作']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
}

/**
 * 初始化分片上传
 */
function handleInit($pdo, $customer, $projectId) {
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少项目ID']);
        exit;
    }
    
    // 验证项目属于该客户
    $stmt = $pdo->prepare("SELECT id, project_name FROM projects WHERE id = ? AND customer_id = ?");
    $stmt->execute([$projectId, $customer['id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => '项目不存在']);
        exit;
    }
    
    $fileName = trim($_POST['file_name'] ?? '');
    $fileSize = intval($_POST['file_size'] ?? 0);
    $fileType = trim($_POST['file_type'] ?? 'application/octet-stream');
    $totalChunks = intval($_POST['total_chunks'] ?? 0);
    
    if (empty($fileName) || $fileSize <= 0 || $totalChunks <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少必要的文件信息']);
        exit;
    }
    
    // 检查文件大小限制 (2GB)
    $maxFileSize = 2 * 1024 * 1024 * 1024;
    if ($fileSize > $maxFileSize) {
        http_response_code(400);
        echo json_encode(['error' => '文件大小超过2GB限制']);
        exit;
    }
    
    // 生成唯一上传ID
    $uploadId = bin2hex(random_bytes(16));
    
    // 创建临时目录
    $tempDir = sys_get_temp_dir() . '/portal_chunks/' . $uploadId;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    // 确保chunk_upload_tasks表有customer_id列（兼容旧表结构）
    try {
        $pdo->exec("ALTER TABLE chunk_upload_tasks ADD COLUMN customer_id INT AFTER project_id");
    } catch (PDOException $e) {
        // 列可能已存在，忽略
    }
    
    // 记录上传任务
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO chunk_upload_tasks 
        (upload_id, project_id, customer_id, file_name, file_size, file_type, total_chunks, temp_dir, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $uploadId,
        $projectId,
        $customer['id'],
        $fileName,
        $fileSize,
        $fileType,
        $totalChunks,
        $tempDir,
        $now,
        $now
    ]);
    
    echo json_encode([
        'success' => true,
        'upload_id' => $uploadId,
        'total_chunks' => $totalChunks,
        'message' => '分片上传已初始化'
    ]);
}

/**
 * 上传单个分片
 */
function handleUploadChunk($pdo, $customer) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    $chunkIndex = intval($_POST['chunk_index'] ?? -1);
    
    if (empty($uploadId) || $chunkIndex < 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少必要参数']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("
        SELECT * FROM chunk_upload_tasks 
        WHERE upload_id = ? AND customer_id = ? AND status = 'uploading'
    ");
    $stmt->execute([$uploadId, $customer['id']]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => '上传任务不存在或已过期']);
        exit;
    }
    
    // 检查分片文件
    if (empty($_FILES['chunk'])) {
        http_response_code(400);
        echo json_encode(['error' => '缺少分片数据']);
        exit;
    }
    
    $chunk = $_FILES['chunk'];
    if ($chunk['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => '分片上传失败: ' . $chunk['error']]);
        exit;
    }
    
    // 保存分片到临时目录
    $tempDir = $task['temp_dir'];
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $chunkPath = $tempDir . '/chunk_' . str_pad($chunkIndex, 5, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($chunk['tmp_name'], $chunkPath)) {
        http_response_code(500);
        echo json_encode(['error' => '保存分片失败']);
        exit;
    }
    
    // 更新已上传分片数
    $stmt = $pdo->prepare("
        UPDATE chunk_upload_tasks 
        SET uploaded_chunks = uploaded_chunks + 1 
        WHERE upload_id = ?
    ");
    $stmt->execute([$uploadId]);
    
    echo json_encode([
        'success' => true,
        'chunk_index' => $chunkIndex,
        'message' => '分片上传成功'
    ]);
}

/**
 * 完成上传（合并分片）
 */
function handleComplete($pdo, $customer) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少upload_id']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("
        SELECT * FROM chunk_upload_tasks 
        WHERE upload_id = ? AND customer_id = ? AND status = 'uploading'
    ");
    $stmt->execute([$uploadId, $customer['id']]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        http_response_code(404);
        echo json_encode(['error' => '上传任务不存在']);
        exit;
    }
    
    // 验证项目
    $stmt = $pdo->prepare("SELECT id, project_name FROM projects WHERE id = ? AND customer_id = ?");
    $stmt->execute([$task['project_id'], $customer['id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => '项目不存在']);
        exit;
    }
    
    $tempDir = $task['temp_dir'];
    $totalChunks = $task['total_chunks'];
    
    // 检查所有分片是否都已上传
    $chunkFiles = glob($tempDir . '/chunk_*');
    if (count($chunkFiles) < $totalChunks) {
        http_response_code(400);
        echo json_encode([
            'error' => '分片不完整',
            'uploaded' => count($chunkFiles),
            'total' => $totalChunks
        ]);
        exit;
    }
    
    // 合并分片到临时文件
    $mergedFile = $tempDir . '/merged_' . uniqid();
    $mergedHandle = fopen($mergedFile, 'wb');
    
    if (!$mergedHandle) {
        http_response_code(500);
        echo json_encode(['error' => '无法创建合并文件']);
        exit;
    }
    
    sort($chunkFiles, SORT_NATURAL);
    foreach ($chunkFiles as $chunkFile) {
        $chunkData = file_get_contents($chunkFile);
        fwrite($mergedHandle, $chunkData);
        unset($chunkData);
    }
    fclose($mergedHandle);
    
    // 获取客户文件夹路径
    $groupCode = $customer['group_code'] ?? '';
    $customerName = $customer['name'] ?? '未知客户';
    $projectName = $project['project_name'] ?? '未知项目';
    
    $folderName = $groupCode ? "{$groupCode} {$customerName}" : $customerName;
    $basePath = "customers/{$folderName}/{$projectName}/客户文件";
    
    // 文件重命名
    $originalName = $task['file_name'];
    $storedName = '客户上传+' . $originalName;
    
    // 上传到S3
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    if (empty($storageConfig)) {
        http_response_code(500);
        echo json_encode(['error' => '存储配置错误']);
        exit;
    }
    
    $ext = pathinfo($storedName, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $basePath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    $s3 = new S3StorageProvider($storageConfig, []);
    $uploadResult = $s3->putObject($storageKey, $mergedFile, [
        'mime_type' => $task['file_type'] ?? 'application/octet-stream'
    ]);
    
    // 清理临时文件（putObject内部已删除源文件，这里清理分片）
    foreach ($chunkFiles as $chunkFile) {
        @unlink($chunkFile);
    }
    @rmdir($tempDir);
    
    // S3StorageProvider返回包含storage_key的数组
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET status = 'aborted' WHERE upload_id = ?");
        $stmt->execute([$uploadId]);
        
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
        $task['project_id'],
        $storedName,
        'portal_upload',
        'customer_file',
        $storageKey,
        $task['file_size'],
        'client',
        'approved',
        $customer['id'],
        $now,
        $now,
        $now
    ]);
    
    // 更新任务状态
    $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET status = 'completed' WHERE upload_id = ?");
    $stmt->execute([$uploadId]);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_size' => $task['file_size']
        ],
        'message' => '文件上传成功'
    ]);
}

/**
 * 中止上传
 */
function handleAbort($pdo, $customer) {
    $uploadId = trim($_POST['upload_id'] ?? '');
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少upload_id']);
        exit;
    }
    
    // 获取上传任务
    $stmt = $pdo->prepare("
        SELECT * FROM chunk_upload_tasks 
        WHERE upload_id = ? AND customer_id = ?
    ");
    $stmt->execute([$uploadId, $customer['id']]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($task) {
        // 清理临时文件
        $tempDir = $task['temp_dir'];
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
        
        // 更新任务状态
        $stmt = $pdo->prepare("UPDATE chunk_upload_tasks SET status = 'aborted' WHERE upload_id = ?");
        $stmt->execute([$uploadId]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '上传已中止'
    ]);
}
