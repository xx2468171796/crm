<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 管理员手动删除已删除客户文件接口
 * 权限：仅系统管理员可访问
 * 功能：立即物理删除已删除客户的文件
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CUSTOMER_DELETE)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '无权限访问'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件ID（支持单个或批量）
$fileIds = [];
if (!empty($_POST['file_id'])) {
    $fileIds = [intval($_POST['file_id'])];
} elseif (!empty($_POST['file_ids'])) {
    if (is_array($_POST['file_ids'])) {
        $fileIds = array_map('intval', $_POST['file_ids']);
    } elseif (is_string($_POST['file_ids'])) {
        // 支持 JSON 字符串
        $decoded = json_decode($_POST['file_ids'], true);
        if (is_array($decoded)) {
            $fileIds = array_map('intval', $decoded);
        } else {
            $fileIds = array_map('intval', explode(',', $_POST['file_ids']));
        }
    }
}

if (empty($fileIds)) {
    echo json_encode([
        'success' => false,
        'message' => '请指定要删除的文件ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 过滤无效ID
$fileIds = array_filter($fileIds, fn($id) => $id > 0);
if (empty($fileIds)) {
    echo json_encode([
        'success' => false,
        'message' => '文件ID无效'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取存储提供者
    $storage = storage_provider();
    
    // 查询文件信息（必须是已删除的文件）
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $files = Db::query(
        "SELECT id, customer_id, filename, storage_key, filesize, deleted_at, deleted_by
         FROM customer_files
         WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL",
        $fileIds
    );
    
    if (empty($files)) {
        echo json_encode([
            'success' => false,
            'message' => '未找到已删除的文件'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $successCount = 0;
    $failedCount = 0;
    $failedFiles = [];
    $totalSize = 0;
    
    foreach ($files as $file) {
        try {
            // 1. 删除物理文件
            try {
                $storage->deleteObject($file['storage_key']);
            } catch (Exception $e) {
                // 如果文件不存在，记录警告但继续处理
                error_log("物理文件删除失败（可能已不存在）: file_id={$file['id']}, storage_key={$file['storage_key']} - {$e->getMessage()}");
            }
            
            // 2. 删除数据库记录
            Db::execute('DELETE FROM customer_files WHERE id = :id', ['id' => $file['id']]);
            
            // 3. 记录操作日志
            try {
                Db::execute(
                    'INSERT INTO operation_logs 
                        (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
                     VALUES 
                        (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
                    [
                        'user_id' => $user['id'],
                        'module' => 'customer_files',
                        'action' => 'admin_delete_deleted',
                        'target_type' => 'customer_file',
                        'target_id' => $file['id'],
                        'customer_id' => $file['customer_id'],
                        'file_id' => $file['id'],
                        'description' => "管理员手动删除已删除客户文件: {$file['filename']}",
                        'extra' => json_encode([
                            'filename' => $file['filename'],
                            'storage_key' => $file['storage_key'],
                            'filesize' => $file['filesize'],
                            'deleted_at' => $file['deleted_at'],
                        ]),
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'created_at' => time()
                    ]
                );
            } catch (Exception $logError) {
                error_log('操作日志记录失败: ' . $logError->getMessage());
            }
            
            $successCount++;
            $totalSize += $file['filesize'];
            
        } catch (Exception $e) {
            $failedCount++;
            $failedFiles[] = [
                'id' => $file['id'],
                'filename' => $file['filename'],
                'error' => $e->getMessage()
            ];
            error_log("删除文件失败: file_id={$file['id']}, filename={$file['filename']}, error={$e->getMessage()}");
        }
    }
    
    $message = "成功删除 {$successCount} 个文件";
    if ($failedCount > 0) {
        $message .= "，失败 {$failedCount} 个";
    }
    
    echo json_encode([
        'success' => $failedCount === 0,
        'message' => $message,
        'data' => [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'failed_files' => $failedFiles,
            'total_size' => $totalSize
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Admin delete deleted customer file error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '删除失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

