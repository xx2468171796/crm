<?php
/**
 * 回收站自动清理脚本
 * 
 * 清理30天前删除的文件，同时删除S3云端文件
 * 
 * 使用方式：
 * - 手动执行: php scripts/cleanup_recycle_bin.php
 * - 定时任务 (cron): 0 3 * * * php /path/to/scripts/cleanup_recycle_bin.php
 * - Windows 计划任务: schtasks /create /tn "RecycleBinCleanup" /tr "php d:\aiDDDDDDM\WWW\crmchonggou\index\scripts\cleanup_recycle_bin.php" /sc daily /st 03:00
 */

// 防止 web 访问
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/S3Service.php';

echo "=== 回收站清理脚本 ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = Db::pdo();
    
    // 计算30天前的时间戳
    $retentionDays = 30;
    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
    $cutoffDate = date('Y-m-d H:i:s', $cutoffTime);
    
    echo "清理 {$retentionDays} 天前删除的文件 (删除时间 < {$cutoffDate})\n\n";
    
    // 获取需要清理的文件
    $files = Db::query(
        "SELECT id, deliverable_name, file_path, file_size, deleted_at, project_id 
         FROM deliverables 
         WHERE deleted_at IS NOT NULL AND deleted_at < ?
         ORDER BY deleted_at ASC",
        [$cutoffTime]
    );
    
    $totalCount = count($files);
    
    if ($totalCount === 0) {
        echo "没有需要清理的文件\n";
        exit(0);
    }
    
    echo "找到 {$totalCount} 个需要清理的文件\n\n";
    
    // 初始化S3服务
    $s3 = null;
    try {
        $s3 = new S3Service();
    } catch (Exception $e) {
        echo "警告: 无法初始化S3服务 - {$e->getMessage()}\n";
        echo "将只删除数据库记录，不删除S3文件\n\n";
    }
    
    $deletedCount = 0;
    $s3DeletedCount = 0;
    $errorCount = 0;
    $totalSize = 0;
    $errors = [];
    
    foreach ($files as $index => $file) {
        $num = $index + 1;
        $filename = $file['deliverable_name'];
        $filePath = $file['file_path'];
        $fileSize = (int)$file['file_size'];
        $deletedAt = date('Y-m-d H:i', $file['deleted_at']);
        
        echo "[{$num}/{$totalCount}] {$filename} (删除于 {$deletedAt})\n";
        
        // 删除S3文件
        $s3Deleted = false;
        if ($s3 && !empty($filePath)) {
            try {
                $s3->deleteObject($filePath);
                $s3Deleted = true;
                $s3DeletedCount++;
                echo "  ✓ S3文件已删除\n";
            } catch (Exception $e) {
                echo "  ✗ S3删除失败: {$e->getMessage()}\n";
                $errors[] = "S3: {$filename} - {$e->getMessage()}";
            }
        }
        
        // 删除数据库记录
        try {
            $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
            $stmt->execute([$file['id']]);
            $deletedCount++;
            $totalSize += $fileSize;
            echo "  ✓ 数据库记录已删除\n";
        } catch (Exception $e) {
            echo "  ✗ 数据库删除失败: {$e->getMessage()}\n";
            $errors[] = "DB: {$filename} - {$e->getMessage()}";
            $errorCount++;
        }
        
        echo "\n";
    }
    
    // 输出统计
    echo "=== 清理完成 ===\n";
    echo "总文件数: {$totalCount}\n";
    echo "已删除记录: {$deletedCount}\n";
    echo "已删除S3文件: {$s3DeletedCount}\n";
    echo "释放空间: " . formatBytes($totalSize) . "\n";
    echo "错误数: {$errorCount}\n";
    
    if (!empty($errors)) {
        echo "\n错误详情:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }
    
    echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
    
    // 记录到日志
    error_log("[cleanup_recycle_bin] 清理完成: 删除 {$deletedCount} 条记录, S3删除 {$s3DeletedCount} 个文件, 释放 " . formatBytes($totalSize));
    
} catch (Exception $e) {
    echo "错误: {$e->getMessage()}\n";
    error_log("[cleanup_recycle_bin] 错误: " . $e->getMessage());
    exit(1);
}

/**
 * 格式化字节数
 */
function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}
