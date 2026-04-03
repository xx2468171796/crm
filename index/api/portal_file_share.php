<?php
/**
 * 客户门户 - 文件分享 API
 * 生成可分享的文件下载链接
 */

require_once __DIR__ . '/../core/db.php';

$fileId = intval($_GET['id'] ?? 0);
$token = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? 'download'); // download 或 info

if ($fileId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 如果有token，验证token权限
    $customerId = null;
    if (!empty($token)) {
        $stmt = $pdo->prepare("SELECT customer_id FROM portal_links WHERE token = ? AND enabled = 1 LIMIT 1");
        $stmt->execute([$token]);
        $portal = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($portal) {
            $customerId = $portal['customer_id'];
        }
    }
    
    // 查询文件信息
    $stmt = $pdo->prepare("
        SELECT d.*, p.customer_id, p.project_name 
        FROM deliverables d 
        INNER JOIN projects p ON d.project_id = p.id 
        WHERE d.id = ? 
        AND d.deleted_at IS NULL 
        AND d.approval_status = 'approved'
        AND d.visibility_level = 'client'
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '文件不存在或无权访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查分享是否启用
    if (empty($file['share_enabled'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '此文件的分享功能已关闭'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 如果有token，验证是否属于该客户
    if ($customerId !== null && $file['customer_id'] != $customerId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '无权访问此文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 从配置读取S3公开访问地址
    require_once __DIR__ . '/../core/storage/storage_provider.php';
    $storageConfig = storage_config();
    $s3Config = $storageConfig['s3'] ?? [];
    $s3Endpoint = $s3Config['public_url'] 
        ?? rtrim($s3Config['endpoint'] ?? '', '/') . '/' . ($s3Config['bucket'] ?? '') . '/';
    $fileUrl = $s3Endpoint . $file['file_path'];
    
    if ($action === 'info') {
        // 返回文件信息
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $file['id'],
                'name' => $file['deliverable_name'],
                'size' => $file['file_size'],
                'category' => $file['file_category'],
                'project_name' => $file['project_name'],
                'download_url' => $fileUrl
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 直接重定向到下载链接
        header('Location: ' . $fileUrl);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
