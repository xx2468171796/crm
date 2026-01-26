<?php
/**
 * 门户上传缓存清理脚本
 * 清理超过1小时的缓存文件，并重试失败的上传任务
 * 
 * 建议通过cron每10分钟运行一次:
 * */10 * * * * php /opt/1panel/www/sites/188.209.141.2198086/index/api/portal_upload_cleanup.php
 */

// 只允许CLI运行
if (php_sapi_name() !== 'cli') {
    exit('This script can only be run from command line');
}

$queueDir = '/opt/portal_upload_cache';
if (!is_dir($queueDir)) {
    exit(0);
}

$now = time();
$cleanedCount = 0;
$retriedCount = 0;

foreach (glob($queueDir . '/*.json') as $metaFile) {
    $queueFile = str_replace('.json', '', $metaFile);
    
    // 读取元数据
    $taskData = @json_decode(file_get_contents($metaFile), true);
    if (!$taskData) {
        // 无效的元数据文件，删除
        @unlink($metaFile);
        @unlink($queueFile);
        $cleanedCount++;
        continue;
    }
    
    $fileAge = $now - ($taskData['create_time'] ?? 0);
    
    // 超过1小时的文件，删除
    if ($fileAge > 3600) {
        @unlink($queueFile);
        @unlink($metaFile);
        $cleanedCount++;
        error_log("[PORTAL_CLEANUP] Removed old cache file: " . basename($queueFile) . ", age={$fileAge}s");
        continue;
    }
    
    // 检查是否有失败的任务需要重试
    if (isset($taskData['retry_count']) && $taskData['retry_count'] > 0 && file_exists($queueFile)) {
        // 重试上传
        $workerScript = __DIR__ . '/portal_upload_worker.php';
        if (file_exists($workerScript)) {
            exec("nohup php {$workerScript} " . escapeshellarg($queueFile) . " > /dev/null 2>&1 &");
            $retriedCount++;
        }
    }
}

// 清理孤立的文件（没有对应的.json元数据）
foreach (glob($queueDir . '/*') as $file) {
    if (str_ends_with($file, '.json')) continue;
    
    $metaFile = $file . '.json';
    if (!file_exists($metaFile)) {
        // 孤立文件，检查是否超过10分钟
        $fileAge = $now - filemtime($file);
        if ($fileAge > 600) {
            @unlink($file);
            $cleanedCount++;
            error_log("[PORTAL_CLEANUP] Removed orphan file: " . basename($file));
        }
    }
}

if ($cleanedCount > 0 || $retriedCount > 0) {
    echo "Cleanup complete: cleaned={$cleanedCount}, retried={$retriedCount}\n";
}
