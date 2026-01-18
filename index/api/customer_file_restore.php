<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 文件恢复API
 * 权限：仅管理员可以恢复已删除的文件
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../services/FileLinkService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CUSTOMER_DELETE)) {
    echo json_encode([
        'success' => false, 
        'message' => '无权限恢复文件'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileIds = $_POST['file_ids'] ?? [];

if (empty($fileIds)) {
    echo json_encode([
        'success' => false, 
        'message' => '请选择要恢复的文件'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 处理参数：可能是数组、JSON字符串或单个值
if (is_string($fileIds)) {
    // 尝试解析JSON字符串
    $decoded = json_decode($fileIds, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $fileIds = $decoded;
    } else {
        // 如果不是JSON，尝试作为单个值处理
        $fileIds = [$fileIds];
    }
}

// 确保是数组
if (!is_array($fileIds)) {
    $fileIds = [$fileIds];
}

// 转换为整数并过滤无效值
$fileIds = array_filter(array_map('intval', $fileIds), function($id) {
    return $id > 0;
});

if (empty($fileIds)) {
    echo json_encode([
        'success' => false, 
        'message' => '参数错误'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $now = time();
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    
    // 查询要恢复的文件
    $files = Db::query(
        "SELECT id, customer_id, filename, deleted_at 
         FROM customer_files 
         WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL",
        $fileIds
    );
    
    if (empty($files)) {
        echo json_encode([
            'success' => false, 
            'message' => '没有找到需要恢复的文件（文件可能未被删除或已被永久删除）'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $fileIdsToRestore = array_column($files, 'id');
    $placeholdersRestore = implode(',', array_fill(0, count($fileIdsToRestore), '?'));
    
    // 开始事务
    if (!Db::beginTransaction()) {
        throw new Exception('事务启动失败');
    }
    
    try {
        // 恢复文件（清除软删除标记）
        $updatedCount = Db::execute(
            "UPDATE customer_files SET deleted_at = NULL, deleted_by = NULL 
             WHERE id IN ({$placeholdersRestore}) AND deleted_at IS NOT NULL",
            $fileIdsToRestore
        );
        
        // 检查更新是否成功
        if ($updatedCount === false) {
            throw new Exception('恢复文件失败：数据库更新失败');
        }
        
        if ($updatedCount === 0) {
            throw new Exception('恢复失败：没有文件被更新（文件可能已被恢复或已被永久删除）');
        }
        
        if ($updatedCount !== count($fileIdsToRestore)) {
            // 部分文件恢复成功，记录警告
            error_log("警告：部分文件恢复失败，期望恢复 " . count($fileIdsToRestore) . " 个，实际恢复 {$updatedCount} 个");
        }
        
        // 恢复文件的分享链接状态（如果链接存在但被禁用，则启用它）
        foreach ($fileIdsToRestore as $fileId) {
            $link = FileLinkService::getByFileId($fileId);
            if ($link && !$link['enabled']) {
                // 如果链接存在但被禁用，恢复启用状态
                FileLinkService::update($link['id'], ['enabled' => 1]);
                error_log("文件恢复：已自动启用文件ID {$fileId} 的分享链接");
            }
        }
        
        // 记录操作日志
        try {
            $fileNames = array_column($files, 'filename');
            $customerIds = array_unique(array_column($files, 'customer_id'));
            
            foreach ($customerIds as $customerId) {
                $customerFiles = array_filter($files, function($f) use ($customerId) {
                    return $f['customer_id'] == $customerId;
                });
                
                Db::execute(
                    'INSERT INTO operation_logs 
                        (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
                     VALUES 
                        (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
                    [
                        'user_id' => $user['id'],
                        'module' => 'customer_files',
                        'action' => 'restore',
                        'target_type' => 'file',
                        'target_id' => null,
                        'customer_id' => $customerId,
                        'file_id' => null,
                        'description' => "恢复文件: " . count($customerFiles) . " 个文件",
                        'extra' => json_encode([
                            'file_ids' => array_column($customerFiles, 'id'),
                            'file_names' => array_column($customerFiles, 'filename'),
                            'restored_at' => $now
                        ]),
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'created_at' => $now
                    ]
                );
            }
        } catch (Exception $logError) {
            // 日志记录失败不影响主操作
            error_log('文件恢复操作日志记录失败: ' . $logError->getMessage());
        }
        
        // 提交事务
        if (!Db::commit()) {
            throw new Exception('事务提交失败');
        }
        
        error_log("文件恢复成功: file_ids=" . implode(',', $fileIdsToRestore) . ", user_id={$user['id']}, count={$updatedCount}");
        
        echo json_encode([
            'success' => true, 
            'message' => "成功恢复 {$updatedCount} 个文件",
            'data' => [
                'restored_count' => $updatedCount,
                'requested_count' => count($fileIdsToRestore)
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        Db::rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('File restore error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => '恢复失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

