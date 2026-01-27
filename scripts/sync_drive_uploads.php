#!/usr/bin/env php
<?php
/**
 * 同步待上传的网盘文件到S3
 * 命令行执行: php scripts/sync_drive_uploads.php
 */

chdir(__DIR__ . '/..');

require_once 'index/core/db.php';
require_once 'index/core/storage/storage_provider.php';

$cacheDir = __DIR__ . '/../storage/upload_cache';
$config = require __DIR__ . '/../index/config/storage.php';
$storageConfig = $config['s3'] ?? [];

echo "=== 网盘文件同步脚本 ===\n";
echo "缓存目录: {$cacheDir}\n\n";

$results = [
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
];

if (!is_dir($cacheDir)) {
    echo "缓存目录不存在\n";
    exit(0);
}

// 扫描缓存目录中的.json元数据文件
$files = glob($cacheDir . '/*.json');
echo "找到 " . count($files) . " 个待处理文件\n\n";

foreach ($files as $metaFile) {
    $dataFile = str_replace('.json', '', $metaFile);
    
    if (!file_exists($dataFile)) {
        echo "跳过: " . basename($metaFile) . " (数据文件不存在)\n";
        @unlink($metaFile);
        continue;
    }
    
    $results['processed']++;
    
    try {
        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta || empty($meta['storage_key'])) {
            throw new Exception('元数据无效');
        }
        
        $fileSize = filesize($dataFile);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);
        
        echo "处理: " . basename($dataFile) . " ({$fileSizeMB} MB)\n";
        echo "  Storage Key: " . $meta['storage_key'] . "\n";
        
        // 上传到S3
        $s3 = new S3StorageProvider($storageConfig, []);
        $s3->putObject($meta['storage_key'], $dataFile, ['mime_type' => $meta['mime_type'] ?? 'application/octet-stream']);
        
        // 上传成功，删除缓存文件
        @unlink($dataFile);
        @unlink($metaFile);
        
        $results['success']++;
        echo "  ✓ 上传成功\n\n";
        
    } catch (Exception $e) {
        $results['failed']++;
        echo "  ✗ 上传失败: " . $e->getMessage() . "\n\n";
    }
}

echo "=== 完成 ===\n";
echo "处理: {$results['processed']}, 成功: {$results['success']}, 失败: {$results['failed']}\n";
