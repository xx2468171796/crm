<?php
/**
 * 上传文件到个人网盘 API
 * POST /api/personal_drive_upload.php
 */

require_once __DIR__ . '/../core/api_init.php';

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = desktop_auth_require();

$folderPath = trim($_POST['folder_path'] ?? '/');
if (empty($folderPath)) $folderPath = '/';

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

try {
    $pdo = Db::pdo();
    
    // 获取用户网盘
    $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$drive) {
        $stmt = $pdo->prepare("INSERT INTO personal_drives (user_id, create_time) VALUES (?, ?)");
        $stmt->execute([$user['id'], time()]);
        $driveId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE id = ?");
        $stmt->execute([$driveId]);
        $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 检查存储空间
    $newUsed = $drive['used_storage'] + $file['size'];
    if ($newUsed > $drive['storage_limit']) {
        http_response_code(400);
        echo json_encode(['error' => '存储空间不足']);
        exit;
    }
    
    // 获取用户部门信息
    $stmt = $pdo->prepare("SELECT d.name as dept_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ?");
    $stmt->execute([$user['id']]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $deptName = $userInfo['dept_name'] ?? '未分配部门';
    $userName = $user['name'] ?? $user['username'];
    
    // 生成存储路径: 部门/用户名/网盘文件/
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    $filename = $file['name'];
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    
    $basePath = "drives/{$deptName}/{$userName}";
    $fullPath = $basePath . ($folderPath === '/' ? '' : $folderPath);
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $fullPath . '/' . $uniqueName;
    $storageKey = ltrim($storageKey, '/');
    
    // 上传到S3
    $s3 = new S3StorageProvider($storageConfig, []);
    $uploadResult = $s3->putObject($storageKey, $file['tmp_name'], [
        'ContentType' => $file['type'] ?? 'application/octet-stream'
    ]);
    
    if (!$uploadResult || empty($uploadResult['success'])) {
        http_response_code(500);
        echo json_encode(['error' => '文件上传到存储失败']);
        exit;
    }
    
    // 记录到数据库
    $stmt = $pdo->prepare("
        INSERT INTO drive_files 
        (drive_id, user_id, filename, original_filename, folder_path, storage_key, file_size, file_type, upload_source, uploader_ip, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', ?, ?)
    ");
    $stmt->execute([
        $drive['id'],
        $user['id'],
        $filename,
        $filename,
        $folderPath,
        $storageKey,
        $file['size'],
        $file['type'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        time()
    ]);
    
    // 更新已用空间
    $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage + ?, update_time = ? WHERE id = ?");
    $stmt->execute([$file['size'], time(), $drive['id']]);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $pdo->lastInsertId(),
            'filename' => $filename,
            'file_size' => $file['size'],
            'message' => '上传成功'
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '上传错误: ' . $e->getMessage()]);
}
