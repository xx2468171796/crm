<?php
/**
 * 个人网盘分片上传 API
 * 支持大文件分片上传和小文件直接上传
 * 
 * Actions:
 * - init: 初始化上传，返回upload_id
 * - upload_chunk: 上传分片
 * - complete: 完成上传，合并分片
 * - abort: 取消上传
 * - direct: 小文件直接上传
 */

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$user = desktop_auth_require();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'init':
        handleInit($user);
        break;
    case 'upload_chunk':
        handleUploadChunk($user);
        break;
    case 'complete':
        handleComplete($user);
        break;
    case 'abort':
        handleAbort($user);
        break;
    case 'direct':
        handleDirect($user);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效的操作']);
}

/**
 * 初始化分片上传
 */
function handleInit($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $filename = $input['filename'] ?? '';
    $fileSize = intval($input['file_size'] ?? 0);
    $folderPath = trim($input['folder_path'] ?? '/');
    $totalChunks = intval($input['total_chunks'] ?? 1);
    $relativePath = $input['relative_path'] ?? ''; // 文件夹上传时的相对路径
    
    if (empty($filename) || $fileSize <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少文件名或文件大小']);
        return;
    }
    
    try {
        $pdo = Db::pdo();
        
        // 获取或创建用户网盘
        $drive = getOrCreateDrive($pdo, $user['id']);
        
        // 检查存储空间
        $newUsed = $drive['used_storage'] + $fileSize;
        if ($newUsed > $drive['storage_limit']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '存储空间不足']);
            return;
        }
        
        // 生成上传ID
        $uploadId = uniqid('upload_', true) . '_' . time();
        
        // 创建临时目录
        $tempDir = sys_get_temp_dir() . '/drive_chunks/' . $uploadId;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // 处理相对路径（文件夹上传）
        $targetPath = $folderPath;
        if (!empty($relativePath)) {
            $relDir = dirname($relativePath);
            if ($relDir !== '.' && $relDir !== '') {
                $targetPath = rtrim($folderPath, '/') . '/' . $relDir;
            }
        }
        
        // 保存上传信息到临时文件
        $uploadInfo = [
            'upload_id' => $uploadId,
            'user_id' => $user['id'],
            'drive_id' => $drive['id'],
            'filename' => $filename,
            'file_size' => $fileSize,
            'folder_path' => $targetPath,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => [],
            'create_time' => time(),
        ];
        file_put_contents($tempDir . '/info.json', json_encode($uploadInfo));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'upload_id' => $uploadId,
                'total_chunks' => $totalChunks,
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * 上传分片
 */
function handleUploadChunk($user) {
    $uploadId = $_POST['upload_id'] ?? '';
    $chunkIndex = intval($_POST['chunk_index'] ?? -1);
    
    if (empty($uploadId) || $chunkIndex < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少upload_id或chunk_index']);
        return;
    }
    
    if (empty($_FILES['chunk'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少分片数据']);
        return;
    }
    
    $tempDir = sys_get_temp_dir() . '/drive_chunks/' . $uploadId;
    $infoFile = $tempDir . '/info.json';
    
    if (!file_exists($infoFile)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '上传会话不存在或已过期']);
        return;
    }
    
    $uploadInfo = json_decode(file_get_contents($infoFile), true);
    
    // 验证用户
    if ($uploadInfo['user_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限']);
        return;
    }
    
    // 保存分片
    $chunkFile = $tempDir . '/chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '保存分片失败']);
        return;
    }
    
    // 更新上传信息
    $uploadInfo['uploaded_chunks'][] = $chunkIndex;
    $uploadInfo['uploaded_chunks'] = array_unique($uploadInfo['uploaded_chunks']);
    sort($uploadInfo['uploaded_chunks']);
    file_put_contents($infoFile, json_encode($uploadInfo));
    
    $uploadedCount = count($uploadInfo['uploaded_chunks']);
    $progress = round($uploadedCount / $uploadInfo['total_chunks'] * 100, 1);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'chunk_index' => $chunkIndex,
            'uploaded_count' => $uploadedCount,
            'total_chunks' => $uploadInfo['total_chunks'],
            'progress' => $progress,
        ]
    ]);
}

/**
 * 完成上传，合并分片
 */
function handleComplete($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $uploadId = $input['upload_id'] ?? '';
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少upload_id']);
        return;
    }
    
    $tempDir = sys_get_temp_dir() . '/drive_chunks/' . $uploadId;
    $infoFile = $tempDir . '/info.json';
    
    if (!file_exists($infoFile)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '上传会话不存在或已过期']);
        return;
    }
    
    $uploadInfo = json_decode(file_get_contents($infoFile), true);
    
    // 验证用户
    if ($uploadInfo['user_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限']);
        return;
    }
    
    // 检查所有分片是否已上传
    if (count($uploadInfo['uploaded_chunks']) < $uploadInfo['total_chunks']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '分片未完全上传']);
        return;
    }
    
    try {
        $pdo = Db::pdo();
        
        // 合并分片
        $mergedFile = $tempDir . '/merged_file';
        $fp = fopen($mergedFile, 'wb');
        
        for ($i = 0; $i < $uploadInfo['total_chunks']; $i++) {
            $chunkFile = $tempDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
            if (!file_exists($chunkFile)) {
                fclose($fp);
                throw new Exception("分片 {$i} 不存在");
            }
            fwrite($fp, file_get_contents($chunkFile));
        }
        fclose($fp);
        
        // 上传到S3
        $result = uploadToS3($pdo, $user, $uploadInfo, $mergedFile);
        
        // 清理临时文件
        cleanupTempDir($tempDir);
        
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        
    } catch (Exception $e) {
        // 清理临时文件
        cleanupTempDir($tempDir);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * 取消上传
 */
function handleAbort($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $uploadId = $input['upload_id'] ?? '';
    
    if (empty($uploadId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少upload_id']);
        return;
    }
    
    $tempDir = sys_get_temp_dir() . '/drive_chunks/' . $uploadId;
    cleanupTempDir($tempDir);
    
    echo json_encode(['success' => true, 'message' => '上传已取消']);
}

/**
 * 小文件直接上传
 */
function handleDirect($user) {
    $folderPath = trim($_POST['folder_path'] ?? '/');
    $relativePath = $_POST['relative_path'] ?? ''; // 文件夹上传时的相对路径
    
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '请选择要上传的文件']);
        return;
    }
    
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '文件上传失败: ' . $file['error']]);
        return;
    }
    
    try {
        $pdo = Db::pdo();
        
        // 获取或创建用户网盘
        $drive = getOrCreateDrive($pdo, $user['id']);
        
        // 检查存储空间
        $newUsed = $drive['used_storage'] + $file['size'];
        if ($newUsed > $drive['storage_limit']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '存储空间不足']);
            return;
        }
        
        // 处理相对路径（文件夹上传）
        $targetPath = $folderPath;
        if (!empty($relativePath)) {
            $relDir = dirname($relativePath);
            if ($relDir !== '.' && $relDir !== '') {
                $targetPath = rtrim($folderPath, '/') . '/' . $relDir;
            }
        }
        
        $uploadInfo = [
            'user_id' => $user['id'],
            'drive_id' => $drive['id'],
            'filename' => $file['name'],
            'file_size' => $file['size'],
            'folder_path' => $targetPath,
        ];
        
        $result = uploadToS3($pdo, $user, $uploadInfo, $file['tmp_name']);
        
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * 获取或创建用户网盘
 */
function getOrCreateDrive($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE user_id = ?");
    $stmt->execute([$userId]);
    $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$drive) {
        $stmt = $pdo->prepare("INSERT INTO personal_drives (user_id, create_time) VALUES (?, ?)");
        $stmt->execute([$userId, time()]);
        $driveId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE id = ?");
        $stmt->execute([$driveId]);
        $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $drive;
}

/**
 * 上传文件到S3并记录数据库
 */
function uploadToS3($pdo, $user, $uploadInfo, $filePath) {
    // 获取用户部门信息
    $stmt = $pdo->prepare("SELECT d.name as dept_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ?");
    $stmt->execute([$user['id']]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $deptName = $userInfo['dept_name'] ?? '未分配部门';
    $userName = $user['name'] ?? $user['username'];
    
    // 生成存储路径
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    $filename = $uploadInfo['filename'];
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    
    $basePath = "drives/{$deptName}/{$userName}";
    $folderPath = $uploadInfo['folder_path'] ?? '/';
    $fullPath = $basePath . ($folderPath === '/' ? '' : $folderPath);
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $fullPath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    // 上传到S3
    $s3 = new S3StorageProvider($storageConfig, []);
    $uploadResult = $s3->putObject($storageKey, $filePath, [
        'mime_type' => mime_content_type($filePath) ?: 'application/octet-stream'
    ]);
    
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        throw new Exception('文件上传到存储失败');
    }
    
    // 确保文件夹路径存在于数据库
    ensureFolderExists($pdo, $uploadInfo['drive_id'], $folderPath);
    
    // 记录到数据库
    $stmt = $pdo->prepare("
        INSERT INTO drive_files 
        (drive_id, user_id, filename, original_filename, folder_path, storage_key, file_size, file_type, upload_source, uploader_ip, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', ?, ?)
    ");
    $stmt->execute([
        $uploadInfo['drive_id'],
        $user['id'],
        $filename,
        $filename,
        $folderPath,
        $storageKey,
        $uploadInfo['file_size'],
        mime_content_type($filePath) ?: '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        time()
    ]);
    
    $fileId = $pdo->lastInsertId();
    
    // 更新已用空间
    $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage + ?, update_time = ? WHERE id = ?");
    $stmt->execute([$uploadInfo['file_size'], time(), $uploadInfo['drive_id']]);
    
    return [
        'id' => $fileId,
        'filename' => $filename,
        'file_size' => $uploadInfo['file_size'],
        'folder_path' => $folderPath,
        'message' => '上传成功'
    ];
}

/**
 * 确保文件夹路径存在
 */
function ensureFolderExists($pdo, $driveId, $folderPath) {
    if ($folderPath === '/' || empty($folderPath)) return;
    
    $parts = array_filter(explode('/', $folderPath));
    $currentPath = '';
    
    foreach ($parts as $part) {
        $parentPath = $currentPath ?: '/';
        $currentPath = $currentPath . '/' . $part;
        
        // 检查文件夹是否存在（通过查询是否有该路径的文件）
        // 这里我们不需要创建实际的文件夹记录，因为文件夹是虚拟的
    }
}

/**
 * 清理临时目录
 */
function cleanupTempDir($dir) {
    if (!is_dir($dir)) return;
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            cleanupTempDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
