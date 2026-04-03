<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件下载/预览 API
 * 
 * GET ?file_id=123 - 下载/预览文件
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';

// 认证
$user = desktop_auth_require();

$fileId = (int)($_GET['file_id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    die('文件ID无效');
}

try {
    // 从 deliverables 表获取文件信息
    $file = Db::queryOne(
        "SELECT d.*, p.project_name 
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.id = ?",
        [$fileId]
    );
    
    if (!$file) {
        http_response_code(404);
        die('文件不存在');
    }
    
    $filePath = $file['file_path'] ?? '';
    $storageKey = $file['storage_key'] ?? $filePath;
    $fileName = $file['deliverable_name'] ?? basename($filePath);
    
    // 判断文件存储位置
    if (strpos($filePath, 'http://') === 0 || strpos($filePath, 'https://') === 0) {
        // 外部URL，重定向
        header('Location: ' . $filePath);
        exit;
    }
    
    // 优先使用storage_key从S3获取
    $s3Key = $storageKey ?: $filePath;
    if ($s3Key) {
        // 移除可能的s3://前缀
        $s3Key = str_replace('s3://', '', $s3Key);
        
        try {
            $s3 = new S3Service();
            $presignedUrl = $s3->getPresignedUrl($s3Key, 3600);
            
            if ($presignedUrl) {
                header('Location: ' . $presignedUrl);
                exit;
            }
        } catch (Exception $e) {
            error_log('[API] S3预签名URL获取失败: ' . $e->getMessage() . ' key=' . $s3Key);
        }
    }
    
    // 本地文件回退
    $localPath = __DIR__ . '/../' . ltrim($filePath, '/');
    if (!file_exists($localPath)) {
        http_response_code(404);
        die('文件不存在或无法访问');
    }
    
    // 获取MIME类型
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
    ];
    
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // 设置响应头
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($localPath));
    header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
    header('Cache-Control: public, max-age=86400');
    
    // 输出文件
    readfile($localPath);
    exit;
    
} catch (Exception $e) {
    error_log('[API] desktop_file_download 错误: ' . $e->getMessage());
    http_response_code(500);
    die('服务器错误');
}
