<?php
/**
 * 清理已删除客户和文件的定时任务脚本
 * 
 * 功能：自动清理超过15天保留期的已删除客户和文件
 * 执行方式：通过 Cron 每天执行一次
 * 示例：0 2 * * * /usr/bin/php /path/to/scripts/cleanup_deleted_customer_files.php
 * 
 * 保留期：15天（86400秒/天 * 15天 = 1296000秒）
 * 
 * 清理逻辑：
 * 1. 清理超过15天的已删除文件（从S3和数据库删除）
 * 2. 清理超过15天的已删除客户（从数据库物理删除）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

// 设置脚本执行时间限制（10分钟）
set_time_limit(600);

// 保留期（15天，单位：秒）
const RETENTION_DAYS = 15;
const RETENTION_SECONDS = RETENTION_DAYS * 86400;

// 日志文件路径（可选，如果未设置则使用 error_log）
$logFile = __DIR__ . '/../logs/cleanup_deleted_files.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

/**
 * 记录日志
 */
function writeLog($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
    
    // 输出到控制台（如果通过命令行运行）
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
    
    // 写入日志文件
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // 同时写入系统错误日志
    error_log($logMessage);
}

/**
 * 格式化文件大小
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

try {
    writeLog("=== 开始清理已删除客户文件任务 ===");
    
    // 获取存储提供者
    $storage = storage_provider();
    
    // 计算截止时间戳（当前时间 - 15天）
    $cutoffTime = time() - RETENTION_SECONDS;
    writeLog("保留期截止时间: " . date('Y-m-d H:i:s', $cutoffTime) . " (15天前)");
    
    // 查询需要清理的文件（deleted_at < 截止时间）
    $filesToClean = Db::query(
        'SELECT id, customer_id, filename, storage_key, filesize, deleted_at, deleted_by
         FROM customer_files
         WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff_time
         ORDER BY deleted_at ASC
         LIMIT 1000',
        ['cutoff_time' => $cutoffTime]
    );
    
    $totalFiles = count($filesToClean);
    writeLog("找到 {$totalFiles} 个文件需要清理");
    
    if ($totalFiles === 0) {
        writeLog("没有需要清理的文件，任务完成");
        exit(0);
    }
    
    $successCount = 0;
    $failedCount = 0;
    $failedFiles = [];
    $totalSize = 0;
    
    foreach ($filesToClean as $file) {
        $fileId = $file['id'];
        $customerId = $file['customer_id'];
        $filename = $file['filename'];
        $storageKey = $file['storage_key'];
        $filesize = $file['filesize'];
        $deletedAt = $file['deleted_at'];
        
        try {
            // 1. 删除物理文件
            try {
                $storage->deleteObject($storageKey);
                writeLog("已删除物理文件: {$filename} (storage_key: {$storageKey})", 'DEBUG');
            } catch (Exception $e) {
                // 如果文件不存在，记录警告但继续处理
                writeLog("物理文件删除失败（可能已不存在）: {$filename} - {$e->getMessage()}", 'WARNING');
            }
            
            // 2. 删除数据库记录
            Db::execute('DELETE FROM customer_files WHERE id = :id', ['id' => $fileId]);
            writeLog("已删除数据库记录: file_id={$fileId}, customer_id={$customerId}, filename={$filename}", 'DEBUG');
            
            $successCount++;
            $totalSize += $filesize;
            
        } catch (Exception $e) {
            $failedCount++;
            $failedFiles[] = [
                'id' => $fileId,
                'customer_id' => $customerId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ];
            writeLog("清理失败: file_id={$fileId}, filename={$filename}, error={$e->getMessage()}", 'ERROR');
        }
    }
    
    // 生成清理报告
    writeLog("=== 清理任务完成 ===");
    writeLog("成功清理: {$successCount} 个文件");
    writeLog("失败: {$failedCount} 个文件");
    writeLog("释放存储空间: " . formatBytes($totalSize));
    
    if ($failedCount > 0) {
        writeLog("失败文件列表:", 'ERROR');
        foreach ($failedFiles as $failed) {
            writeLog("  - file_id={$failed['id']}, customer_id={$failed['customer_id']}, filename={$failed['filename']}, error={$failed['error']}", 'ERROR');
        }
    }
    
    // 记录操作日志到数据库
    try {
        Db::execute(
            'INSERT INTO operation_logs 
                (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
             VALUES 
                (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
            [
                'user_id' => 0, // 系统任务
                'module' => 'customer_files',
                'action' => 'cleanup_deleted',
                'target_type' => 'cleanup_task',
                'target_id' => null,
                'customer_id' => null,
                'file_id' => null,
                'description' => "自动清理已删除客户文件: 成功 {$successCount} 个，失败 {$failedCount} 个，释放空间 " . formatBytes($totalSize),
                'extra' => json_encode([
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'total_size' => $totalSize,
                    'failed_files' => $failedFiles,
                    'cutoff_time' => $cutoffTime,
                ]),
                'ip' => '127.0.0.1',
                'user_agent' => 'cleanup_script',
                'created_at' => time()
            ]
        );
    } catch (Exception $e) {
        writeLog("操作日志记录失败: {$e->getMessage()}", 'WARNING');
    }
    
    // ========== 第二部分：清理超过15天的已删除客户 ==========
    writeLog("=== 开始清理已删除客户任务 ===");
    
    // 查询需要清理的客户（deleted_at < 截止时间）
    $customersToClean = Db::query(
        'SELECT id, name, customer_code, deleted_at, deleted_by
         FROM customers
         WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff_time
         ORDER BY deleted_at ASC
         LIMIT 500',
        ['cutoff_time' => $cutoffTime]
    );
    
    $totalCustomers = count($customersToClean);
    writeLog("找到 {$totalCustomers} 个客户需要清理");
    
    $customerSuccessCount = 0;
    $customerFailedCount = 0;
    $failedCustomers = [];
    
    foreach ($customersToClean as $customer) {
        $customerId = $customer['id'];
        $customerName = $customer['name'];
        $deletedAt = $customer['deleted_at'];
        
        try {
            // 检查该客户是否还有未清理的文件
            $remainingFiles = Db::queryOne(
                'SELECT COUNT(*) as cnt FROM customer_files 
                 WHERE customer_id = :customer_id AND deleted_at IS NOT NULL',
                ['customer_id' => $customerId]
            );
            
            if ($remainingFiles['cnt'] > 0) {
                writeLog("客户 {$customerId} ({$customerName}) 还有 {$remainingFiles['cnt']} 个文件未清理，跳过客户删除", 'WARNING');
                continue;
            }
            
            // 物理删除客户记录
            Db::execute('DELETE FROM customers WHERE id = :id', ['id' => $customerId]);
            writeLog("已删除客户: customer_id={$customerId}, name={$customerName}", 'DEBUG');
            
            $customerSuccessCount++;
            
        } catch (Exception $e) {
            $customerFailedCount++;
            $failedCustomers[] = [
                'id' => $customerId,
                'name' => $customerName,
                'error' => $e->getMessage()
            ];
            writeLog("客户清理失败: customer_id={$customerId}, name={$customerName}, error={$e->getMessage()}", 'ERROR');
        }
    }
    
    // 生成客户清理报告
    writeLog("=== 客户清理任务完成 ===");
    writeLog("成功清理: {$customerSuccessCount} 个客户");
    writeLog("失败: {$customerFailedCount} 个客户");
    
    if ($customerFailedCount > 0) {
        writeLog("失败客户列表:", 'ERROR');
        foreach ($failedCustomers as $failed) {
            writeLog("  - customer_id={$failed['id']}, name={$failed['name']}, error={$failed['error']}", 'ERROR');
        }
    }
    
    // 记录客户清理操作日志到数据库
    try {
        Db::execute(
            'INSERT INTO operation_logs 
                (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
             VALUES 
                (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
            [
                'user_id' => 0, // 系统任务
                'module' => 'customers',
                'action' => 'cleanup_deleted',
                'target_type' => 'cleanup_task',
                'target_id' => null,
                'customer_id' => null,
                'file_id' => null,
                'description' => "自动清理已删除客户: 成功 {$customerSuccessCount} 个，失败 {$customerFailedCount} 个",
                'extra' => json_encode([
                    'success_count' => $customerSuccessCount,
                    'failed_count' => $customerFailedCount,
                    'failed_customers' => $failedCustomers,
                    'cutoff_time' => $cutoffTime,
                ]),
                'ip' => '127.0.0.1',
                'user_agent' => 'cleanup_script',
                'created_at' => time()
            ]
        );
    } catch (Exception $e) {
        writeLog("客户清理操作日志记录失败: {$e->getMessage()}", 'WARNING');
    }
    
    writeLog("=== 任务结束 ===");
    
    // 如果有失败的文件或客户，返回非零退出码
    exit(($failedCount > 0 || $customerFailedCount > 0) ? 1 : 0);
    
} catch (Exception $e) {
    writeLog("清理任务执行失败: {$e->getMessage()}", 'ERROR');
    writeLog("堆栈跟踪: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}

