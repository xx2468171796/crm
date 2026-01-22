<?php
/**
 * 客户门户文件上传 API
 * POST /api/portal_file_upload.php
 * 通过portal token验证
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
$projectId = intval($_POST['project_id'] ?? 0);

if (empty($token) || $projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证token
    $stmt = $pdo->prepare("SELECT id, name, group_code FROM customers WHERE portal_token = ?");
    $stmt->execute([$token]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(401);
        echo json_encode(['error' => '无效的访问令牌']);
        exit;
    }
    
    // 验证项目属于该客户
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ? AND customer_id = ?");
    $stmt->execute([$projectId, $customer['id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => '项目不存在']);
        exit;
    }
    
    // 检查文件上传
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
    
    // 文件重命名：客户上传+原文件名
    $originalName = $file['name'];
    $storedName = '客户上传+' . $originalName;
    
    // 获取客户文件夹路径
    $groupCode = $customer['group_code'] ?? '';
    $customerName = $customer['name'] ?? '未知客户';
    $projectName = $project['name'] ?? '未知项目';
    
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
    $uploadResult = $s3->uploadFile($file['tmp_name'], $storageKey, $file['type'] ?? 'application/octet-stream');
    
    if (!$uploadResult) {
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
        $projectId,
        $basePath . '/' . $uniqueName,
        $storedName,
        $file['size'],
        '客户文件',
        $storageKey,
        'portal',
        time()
    ]);
    
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
