<?php
/**
 * 同步待上传的网盘文件到S3
 * 用于处理异步上传失败的文件
 * 
 * 可以通过cron定时执行，或手动调用
 * GET /api/personal_drive_sync_pending.php
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

header('Content-Type: application/json; charset=utf-8');

// 可选：需要管理员权限
// require_once __DIR__ . '/../core/desktop_auth.php';
// $user = desktop_auth_require();
// if (!in_array($user['role'] ?? '', ['admin', 'super_admin', 'system_admin'])) {
//     http_response_code(403);
//     echo json_encode(['error' => '权限不足']);
//     exit;
// }

$cacheDir = __DIR__ . '/../../storage/upload_cache';
$config = require __DIR__ . '/../config/storage.php';
$storageConfig = $config['s3'] ?? [];

$results = [
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'errors' => []
];

if (!is_dir($cacheDir)) {
    echo json_encode(['success' => true, 'message' => '缓存目录不存在', 'results' => $results]);
    exit;
}

// 扫描缓存目录中的.json元数据文件
$files = glob($cacheDir . '/*.json');

foreach ($files as $metaFile) {
    $dataFile = str_replace('.json', '', $metaFile);
    
    if (!file_exists($dataFile)) {
        // 数据文件不存在，删除元数据
        @unlink($metaFile);
        continue;
    }
    
    $results['processed']++;
    
    try {
        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta || empty($meta['storage_key'])) {
            throw new Exception('元数据无效');
        }
        
        // 检查文件是否超过24小时（可能是僵尸文件）
        $createTime = $meta['create_time'] ?? 0;
        if ($createTime > 0 && (time() - $createTime) > 86400) {
            error_log("[DRIVE_SYNC] Skipping old file (>24h): " . $meta['storage_key']);
            // 可选：删除超过24小时的僵尸文件
            // @unlink($dataFile);
            // @unlink($metaFile);
            // continue;
        }
        
        // 上传到S3
        $s3 = new S3StorageProvider($storageConfig, []);
        $s3->putObject($meta['storage_key'], $dataFile, ['mime_type' => $meta['mime_type'] ?? 'application/octet-stream']);
        
        // 上传成功，删除缓存文件
        @unlink($dataFile);
        @unlink($metaFile);
        
        $results['success']++;
        error_log("[DRIVE_SYNC] S3 upload success: " . $meta['storage_key']);
        
    } catch (Exception $e) {
        $results['failed']++;
        $results['errors'][] = [
            'file' => basename($dataFile),
            'error' => $e->getMessage()
        ];
        error_log("[DRIVE_SYNC] S3 upload failed: " . $e->getMessage() . " | file: " . basename($dataFile));
    }
}

echo json_encode([
    'success' => true,
    'message' => "处理完成: {$results['success']}/{$results['processed']} 成功",
    'results' => $results
]);
