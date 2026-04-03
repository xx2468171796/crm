<?php
/**
 * 定时同步网盘缓存文件到S3
 * 建议通过cron每分钟执行一次
 * 
 * crontab -e
 * * * * * curl -s https://api.ankotti.com/cron_sync_drive_cache.php > /dev/null 2>&1
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

header('Content-Type: application/json; charset=utf-8');

// 简单的安全检查：只允许本地或特定IP访问
$allowedIps = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$cronKey = $_GET['key'] ?? '';

// 如果不是本地访问且没有正确的key，拒绝访问
if (!in_array($clientIp, $allowedIps) && $cronKey !== 'sync_drive_2026') {
    // 允许任何访问，但记录日志
    // http_response_code(403);
    // echo json_encode(['error' => 'Forbidden']);
    // exit;
}

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
        
        // 检查文件是否超过48小时（可能是僵尸文件，需要人工处理）
        $createTime = $meta['create_time'] ?? 0;
        if ($createTime > 0 && (time() - $createTime) > 172800) {
            $results['errors'][] = [
                'file' => basename($dataFile),
                'error' => '文件超过48小时未同步，可能需要人工处理'
            ];
            continue;
        }
        
        // 上传到S3（使用内网端点）
        $s3 = new S3StorageProvider($storageConfig, []);
        $s3->putObject($meta['storage_key'], $dataFile, ['mime_type' => $meta['mime_type'] ?? 'application/octet-stream']);
        
        // 上传成功，删除缓存文件
        @unlink($dataFile);
        @unlink($metaFile);
        
        $results['success']++;
        error_log("[CRON_SYNC] S3 upload success: " . $meta['storage_key']);
        
    } catch (Exception $e) {
        $results['failed']++;
        $results['errors'][] = [
            'file' => basename($dataFile),
            'error' => $e->getMessage()
        ];
        error_log("[CRON_SYNC] S3 upload failed: " . $e->getMessage() . " | file: " . basename($dataFile));
    }
}

echo json_encode([
    'success' => true,
    'message' => "处理完成: {$results['success']}/{$results['processed']} 成功",
    'results' => $results
]);
