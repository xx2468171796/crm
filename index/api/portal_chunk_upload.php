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
        case 'direct':
            handleDirectUpload($pdo, $customer, $projectId);
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

/**
 * 小文件直接上传（不分片）
 */
function handleDirectUpload($pdo, $customer, $projectId) {
    $startTime = microtime(true);
    $timings = [];
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少项目ID']);
        exit;
    }
    
    // 验证项目属于该客户
    $stmt = $pdo->prepare("
        SELECT p.id, p.project_name, c.name as customer_name, c.group_code
        FROM projects p
        JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ? AND p.customer_id = ?
    ");
    $stmt->execute([$projectId, $customer['id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(403);
        echo json_encode(['error' => '无权访问此项目']);
        exit;
    }
    
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
    
    // 小文件优化：先保存到SSD队列目录，后台异步上传到S3
    // 这样用户不用等待HDD的fsync
    // 2GB以下使用异步上传（缓存配额2GB）
    $useAsyncUpload = $fileSize <= 2 * 1024 * 1024 * 1024; // 2GB以下使用异步上传
    $asyncDebug = ['enabled' => $useAsyncUpload, 'size' => $fileSize];
    
    // 获取客户文件夹路径
    $groupCode = $project['group_code'] ?? '';
    $customerName = $project['customer_name'] ?? '未知客户';
    $projectName = $project['project_name'] ?? '未知项目';
    
    $folderName = $groupCode ? "{$groupCode} {$customerName}" : $customerName;
    $basePath = "customers/{$folderName}/{$projectName}/客户文件";
    
    // 文件重命名
    $storedName = '客户上传+' . $originalName;
    
    $timings['validate'] = round((microtime(true) - $startTime) * 1000);
    
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
    
    // 异步上传优化：先保存到SSD缓存目录，立即返回成功，后台异步上传到S3
    // 使用专用的SSD缓存目录（2GB配额）
    if ($useAsyncUpload) {
        $queueDir = __DIR__ . '/../../storage/upload_cache';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0777, true);
        }
        
        // 检查缓存目录大小，超过2GB时回退到同步上传
        $cacheSize = 0;
        foreach (glob($queueDir . '/*') as $f) {
            if (is_file($f) && !str_ends_with($f, '.json')) {
                $cacheSize += filesize($f);
            }
        }
        if ($cacheSize > 2 * 1024 * 1024 * 1024) { // 2GB缓存配额
            error_log("[PORTAL_ASYNC] Cache full, fallback to sync upload");
            $useAsyncUpload = false;
        }
    }
    
    // 再次检查useAsyncUpload（可能被上面的检查禁用）
    if ($useAsyncUpload) {
        // 保存文件到SSD队列目录
        $queueFile = $queueDir . '/' . $uniqueName;
        
        // 使用copy而不是move_uploaded_file，因为move可能跨文件系统失败
        $asyncDebug['queue_file'] = $queueFile;
        $asyncDebug['tmp_path'] = $tmpPath;
        $asyncDebug['tmp_exists'] = file_exists($tmpPath);
        $asyncDebug['dir_writable'] = is_writable($queueDir);
        
        if (!copy($tmpPath, $queueFile)) {
            $asyncDebug['copy_failed'] = true;
            $asyncDebug['copy_error'] = error_get_last();
            $useAsyncUpload = false;
        } else {
            @unlink($tmpPath); // 删除原临时文件
            $asyncDebug['copy_success'] = true;
            // 保存上传任务元数据
            $taskData = [
                'queue_file' => $queueFile,
                'storage_key' => $storageKey,
                'mime_type' => $fileType,
                'project_id' => $projectId,
                'customer_id' => $customer['id'],
                'stored_name' => $storedName,
                'file_size' => $fileSize,
                'create_time' => time()
            ];
            file_put_contents($queueFile . '.json', json_encode($taskData));
            
            // 先记录到数据库（状态为pending）
            $now = time();
            $stmt = $pdo->prepare("
                INSERT INTO deliverables 
                (project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, 
                 visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $projectId,
                $storedName,
                'portal_upload',
                'customer_file',
                $storageKey,  // 预先设置storage_key
                $fileSize,
                'client',
                'approved',
                $customer['id'],
                $now,
                $now,
                $now
            ]);
            
            // 触发后台上传
            // 方案1: 使用fastcgi_finish_request()立即返回响应，然后继续执行上传
            // 方案2: 使用exec后台执行（可能在Docker中不可用）
            $workerScript = __DIR__ . '/portal_upload_worker.php';
            
            // 先返回响应给用户
            $timings['async'] = true;
            $timings['total'] = round((microtime(true) - $startTime) * 1000);
            
            $response = json_encode([
                'success' => true,
                'data' => [
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'file_size' => $fileSize,
                    'timings_ms' => $timings,
                    'async' => true
                ],
                'message' => '文件上传成功'
            ]);
            
            // 设置响应头并输出
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen($response));
            echo $response;
            
            // 立即刷新输出缓冲区并结束请求
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_end_flush();
                flush();
            }
            
            // 请求已结束，用户不用等待，现在执行S3上传
            try {
                $config = require __DIR__ . '/../config/storage.php';
                $storageConfig = $config['s3'] ?? [];
                $s3 = new S3StorageProvider($storageConfig, []);
                $uploadResult = $s3->putObject($storageKey, $queueFile, [
                    'mime_type' => $fileType
                ]);
                // 上传成功，删除元数据文件
                @unlink($queueFile . '.json');
                error_log("[PORTAL_ASYNC] S3 upload success: {$storedName}");
            } catch (Exception $e) {
                error_log("[PORTAL_ASYNC] S3 upload failed: " . $e->getMessage());
            }
            
            exit; // 确保不再执行后续代码
        }
    }
    
    // 同步上传（大文件或异步失败时）
    $s3StartTime = microtime(true);
    $s3 = new S3StorageProvider($storageConfig, []);
    $uploadResult = $s3->putObject($storageKey, $tmpPath, [
        'mime_type' => $fileType
    ]);
    $timings['s3_upload'] = round((microtime(true) - $s3StartTime) * 1000);
    
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        http_response_code(500);
        echo json_encode(['error' => '文件上传到存储失败']);
        exit;
    }
    
    $dbStartTime = microtime(true);
    // 记录到deliverables表
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO deliverables 
        (project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, 
         visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $projectId,
        $storedName,
        'portal_upload',
        'customer_file',
        $storageKey,
        $fileSize,
        'client',
        'approved',
        $customer['id'],
        $now,
        $now,
        $now
    ]);
    
    $timings['db_insert'] = round((microtime(true) - $dbStartTime) * 1000);
    $timings['total'] = round((microtime(true) - $startTime) * 1000);
    
    // 记录慢上传日志（超过3秒）
    if ($timings['total'] > 3000) {
        error_log("[PORTAL_UPLOAD_SLOW] file={$originalName}, size={$fileSize}, timings=" . json_encode($timings));
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_size' => $fileSize,
            'timings_ms' => $timings,
            'async_debug' => $asyncDebug ?? null
        ],
        'message' => '文件上传成功'
    ]);
}
