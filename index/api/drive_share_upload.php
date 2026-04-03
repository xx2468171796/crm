<?php
/**
 * 网盘分享上传 API
 * POST /api/drive_share_upload.php
 * 无需登录，通过token验证
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = trim($_POST['token'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 获取分享链接信息
    $stmt = $pdo->prepare("
        SELECT dsl.*, pd.user_id, pd.storage_limit, pd.used_storage,
               u.name as user_name, u.username, d.name as dept_name
        FROM drive_share_links dsl
        JOIN personal_drives pd ON pd.id = dsl.drive_id
        JOIN users u ON u.id = pd.user_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE dsl.token = ? AND dsl.status = 'active'
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
        $updateStmt = $pdo->prepare("UPDATE drive_share_links SET status = 'expired' WHERE id = ?");
        $updateStmt->execute([$link['id']]);
        http_response_code(410);
        echo json_encode(['error' => '链接已过期']);
        exit;
    }
    
    // 检查访问次数
    if ($link['max_visits'] !== null && $link['visit_count'] >= $link['max_visits']) {
        http_response_code(410);
        echo json_encode(['error' => '链接已达到最大访问次数']);
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
    
    // 检查文件
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => '请选择要上传的文件']);
        exit;
    }
    
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => '文件上传失败: ' . $file['error']]);
        exit;
    }
    
    // 检查存储空间
    $newUsed = $link['used_storage'] + $file['size'];
    if ($newUsed > $link['storage_limit']) {
        http_response_code(400);
        echo json_encode(['error' => '网盘存储空间不足']);
        exit;
    }
    
    // 文件重命名：分享+原文件名+时间戳
    $originalName = $file['name'];
    $timestamp = date('YmdHis');
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
    $storedName = "分享+{$nameWithoutExt}+{$timestamp}" . ($ext ? ".{$ext}" : '');
    
    // 生成存储路径
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    $deptName = $link['dept_name'] ?? '未分配部门';
    $userName = $link['user_name'] ?? $link['username'];
    $folderPath = $link['folder_path'] ?? '/';
    
    $basePath = "drives/{$deptName}/{$userName}";
    $fullPath = $basePath . ($folderPath === '/' ? '' : $folderPath);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $fullPath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    // 异步上传优化
    $fileSize = $file['size'];
    $mimeType = $file['type'] ?? 'application/octet-stream';
    $tmpPath = $file['tmp_name'];
    $useAsyncUpload = $fileSize <= 2 * 1024 * 1024 * 1024;
    $asyncUploadFile = null;
    
    if ($useAsyncUpload) {
        $cacheDir = __DIR__ . '/../../storage/upload_cache';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        
        $cacheFile = $cacheDir . '/' . uniqid('dshare_') . '_' . $uniqueName;
        if (copy($tmpPath, $cacheFile)) {
            file_put_contents($cacheFile . '.json', json_encode([
                'storage_key' => $storageKey, 'mime_type' => $mimeType,
                'file_size' => $fileSize, 'create_time' => time()
            ]));
            $asyncUploadFile = $cacheFile;
        } else {
            $useAsyncUpload = false;
        }
    }
    
    if (!$useAsyncUpload) {
        $s3 = new S3StorageProvider($storageConfig, []);
        $uploadResult = $s3->putObject($storageKey, $tmpPath, ['mime_type' => $mimeType]);
        
        if (!$uploadResult || empty($uploadResult['storage_key'])) {
            http_response_code(500);
            echo json_encode(['error' => '文件上传到存储失败']);
            exit;
        }
    }
    
    // 记录到数据库
    $stmt = $pdo->prepare("
        INSERT INTO drive_files 
        (drive_id, user_id, filename, original_filename, folder_path, storage_key, file_size, file_type, upload_source, uploader_ip, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'share', ?, ?)
    ");
    $stmt->execute([
        $link['drive_id'],
        $link['user_id'],
        $storedName,
        $originalName,
        $folderPath,
        $storageKey,
        $file['size'],
        $file['type'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        time()
    ]);
    
    // 更新已用空间
    $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage + ?, update_time = ? WHERE id = ?");
    $stmt->execute([$file['size'], time(), $link['drive_id']]);
    
    // 更新访问次数
    $stmt = $pdo->prepare("UPDATE drive_share_links SET visit_count = visit_count + 1 WHERE id = ?");
    $stmt->execute([$link['id']]);
    
    // 异步上传：先返回响应再执行S3上传
    if ($asyncUploadFile && file_exists($asyncUploadFile)) {
        $response = json_encode([
            'success' => true,
            'data' => ['original_name' => $originalName, 'stored_name' => $storedName, 'file_size' => $fileSize, 'message' => '文件上传成功', 'async' => true]
        ]);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($response));
        echo $response;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { ob_end_flush(); flush(); }
        
        try {
            $s3 = new S3StorageProvider($storageConfig, []);
            $meta = json_decode(file_get_contents($asyncUploadFile . '.json'), true);
            $s3->putObject($meta['storage_key'], $asyncUploadFile, ['mime_type' => $meta['mime_type']]);
            @unlink($asyncUploadFile . '.json');
        } catch (Exception $e) {
            error_log("[DSHARE_ASYNC] S3 upload failed: " . $e->getMessage());
        }
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_size' => $file['size'],
            'message' => '文件上传成功'
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '上传错误: ' . $e->getMessage()]);
}
