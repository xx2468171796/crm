<?php
/**
 * 门户上传后台Worker
 * 异步将文件从SSD队列目录上传到S3
 * 
 * 用法: php portal_upload_worker.php /tmp/portal_upload_queue/filename.ext
 */

// 只允许CLI运行
if (php_sapi_name() !== 'cli') {
    exit('This script can only be run from command line');
}

if ($argc < 2) {
    exit('Usage: php portal_upload_worker.php <queue_file>');
}

$queueFile = $argv[1];
$metaFile = $queueFile . '.json';

// 检查文件是否存在
if (!file_exists($queueFile) || !file_exists($metaFile)) {
    error_log("[PORTAL_WORKER] Queue file not found: {$queueFile}");
    exit(1);
}

// 读取元数据
$taskData = json_decode(file_get_contents($metaFile), true);
if (!$taskData) {
    error_log("[PORTAL_WORKER] Invalid meta file: {$metaFile}");
    @unlink($queueFile);
    @unlink($metaFile);
    exit(1);
}

require_once __DIR__ . '/../core/storage/storage_provider.php';

try {
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    if (empty($storageConfig)) {
        throw new Exception('Storage config not found');
    }
    
    $s3 = new S3StorageProvider($storageConfig, []);
    
    $startTime = microtime(true);
    $uploadResult = $s3->putObject($taskData['storage_key'], $queueFile, [
        'mime_type' => $taskData['mime_type'] ?? 'application/octet-stream'
    ]);
    $uploadTime = round((microtime(true) - $startTime) * 1000);
    
    if (!$uploadResult || empty($uploadResult['storage_key'])) {
        throw new Exception('S3 upload failed');
    }
    
    // 上传成功，删除队列文件
    @unlink($metaFile);
    // 注意：$queueFile 已被 S3StorageProvider::putObject 删除
    
    error_log("[PORTAL_WORKER] Success: {$taskData['stored_name']}, size={$taskData['file_size']}, time={$uploadTime}ms");
    
} catch (Exception $e) {
    error_log("[PORTAL_WORKER] Error: " . $e->getMessage() . ", file={$taskData['stored_name']}");
    
    // 保留文件，稍后重试
    // 可以添加重试计数器，超过一定次数后删除
    $retryCount = ($taskData['retry_count'] ?? 0) + 1;
    if ($retryCount >= 3) {
        error_log("[PORTAL_WORKER] Max retries reached, giving up: {$taskData['stored_name']}");
        @unlink($queueFile);
        @unlink($metaFile);
    } else {
        $taskData['retry_count'] = $retryCount;
        $taskData['last_error'] = $e->getMessage();
        file_put_contents($metaFile, json_encode($taskData));
    }
    
    exit(1);
}
