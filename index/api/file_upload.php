<?php
require_once __DIR__ . '/../core/api_init.php';
// 文件上传API

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

// 需要登录
$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$customerId = intval($_POST['customer_id'] ?? 0);
$fileType = $_POST['file_type'] ?? ''; // customer 或 company

if ($customerId === 0) {
    echo json_encode(['success' => false, 'message' => '客户ID无效']);
    exit;
}

if (!in_array($fileType, ['customer', 'company'])) {
    echo json_encode(['success' => false, 'message' => '文件类型无效']);
    exit;
}

// 权限检查
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在']);
    exit;
}

$hasPermission = false;
if ($user['role'] === 'system_admin') {
    $hasPermission = true;
} elseif ($user['role'] === 'dept_admin' && $customer['department_id'] == $user['department_id']) {
    $hasPermission = true;
} elseif ($customer['owner_user_id'] == $user['id']) {
    $hasPermission = true;
}

if (!$hasPermission) {
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

// 检查是否有文件上传
if (empty($_FILES['files'])) {
    echo json_encode(['success' => false, 'message' => '没有文件上传']);
    exit;
}

// 创建上传目录
$uploadDir = __DIR__ . '/../uploads/customer_' . $customerId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

try {
    $files = $_FILES['files'];
    $uploadedCount = 0;
    
    // 处理多文件上传
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    
    for ($i = 0; $i < $fileCount; $i++) {
        if (is_array($files['name'])) {
            $fileName = $files['name'][$i];
            $fileTmpName = $files['tmp_name'][$i];
            $fileSize = $files['size'][$i];
            $fileError = $files['error'][$i];
        } else {
            $fileName = $files['name'];
            $fileTmpName = $files['tmp_name'];
            $fileSize = $files['size'];
            $fileError = $files['error'];
        }
        
        if ($fileError !== UPLOAD_ERR_OK) {
            continue;
        }
        
        // 生成唯一文件名
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileBaseName = pathinfo($fileName, PATHINFO_FILENAME);
        $uniqueFileName = $fileBaseName . '_' . time() . '_' . rand(1000, 9999) . '.' . $fileExt;
        $filePath = $uploadDir . $uniqueFileName;
        
        // 移动文件
        if (move_uploaded_file($fileTmpName, $filePath)) {
            // 保存到数据库
            Db::execute('INSERT INTO files (customer_id, file_type, file_name, file_path, file_size, create_time, create_user_id) 
                         VALUES (:customer_id, :file_type, :file_name, :file_path, :file_size, :create_time, :create_user_id)', [
                'customer_id' => $customerId,
                'file_type' => $fileType,
                'file_name' => $fileName,
                'file_path' => $uniqueFileName,
                'file_size' => $fileSize,
                'create_time' => time(),
                'create_user_id' => $user['id']
            ]);
            
            $uploadedCount++;
        }
    }
    
    if ($uploadedCount > 0) {
        echo json_encode([
            'success' => true,
            'message' => "成功上传 {$uploadedCount} 个文件"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '文件上传失败'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '上传失败: ' . $e->getMessage()
    ]);
}
