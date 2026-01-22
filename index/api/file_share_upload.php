<?php
/**
 * 分享链接文件上传 API
 * POST /api/file_share_upload.php
 * 无需登录认证，通过token验证
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
        $updateStmt = $pdo->prepare("UPDATE file_share_links SET status = 'expired' WHERE id = ?");
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
    
    // 检查文件上传
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => '请选择要上传的文件']);
        exit;
    }
    
    $file = $_FILES['file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止'
        ];
        $errorMsg = $errorMessages[$file['error']] ?? ('上传错误代码: ' . $file['error']);
        http_response_code(400);
        echo json_encode(['error' => $errorMsg]);
        exit;
    }
    
    // 限制单个文件大小不超过2GB
    $maxSize = 2 * 1024 * 1024 * 1024; // 2GB
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['error' => '单个文件大小不能超过2GB']);
        exit;
    }
    
    // 文件重命名：分享+原文件名
    $originalName = $file['name'];
    $storedName = '分享+' . $originalName;
    
    // 获取客户文件夹路径
    $groupCode = $link['group_code'] ?? '';
    $customerName = $link['customer_name'] ?? '未知客户';
    $projectName = $link['project_name'] ?? '未知项目';
    
    $folderName = $groupCode ? "{$groupCode} {$customerName}" : $customerName;
    $basePath = "customers/{$folderName}/{$projectName}/客户文件";
    
    // 使用S3存储
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    if (empty($storageConfig)) {
        http_response_code(500);
        echo json_encode(['error' => '存储配置错误']);
        exit;
    }
    
    // 生成存储key
    $ext = pathinfo($storedName, PATHINFO_EXTENSION);
    $uniqueName = date('Ymd_His') . '_' . uniqid() . ($ext ? ".{$ext}" : '');
    $storageKey = trim($storageConfig['prefix'] ?? '', '/') . '/' . $basePath . '/' . $uniqueName;
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
    
    // 记录到deliverables表
    $stmt = $pdo->prepare("
        INSERT INTO deliverables 
        (project_id, file_path, file_name, file_size, category, storage_key, upload_source, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $link['project_id'],
        $basePath . '/' . $uniqueName,
        $storedName,
        $file['size'],
        '客户文件',
        $storageKey,
        'share_link',
        time()
    ]);
    
    $deliverableId = $pdo->lastInsertId();
    
    // 记录到file_share_uploads表
    $stmt = $pdo->prepare("
        INSERT INTO file_share_uploads 
        (share_link_id, project_id, deliverable_id, original_filename, stored_filename, file_size, file_path, storage_key, uploader_ip, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $link['id'],
        $link['project_id'],
        $deliverableId,
        $originalName,
        $storedName,
        $file['size'],
        $basePath . '/' . $uniqueName,
        $storageKey,
        $_SERVER['REMOTE_ADDR'] ?? '',
        time()
    ]);
    
    // 更新访问次数
    $stmt = $pdo->prepare("UPDATE file_share_links SET visit_count = visit_count + 1 WHERE id = ?");
    $stmt->execute([$link['id']]);
    
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
