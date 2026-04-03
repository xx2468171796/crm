<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户删除API
 * 权限：管理员可以删除任何客户，普通用户只能删除自己创建的客户
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

// 检查登录
auth_require();
$user = current_user();

// 支持单个删除和批量删除
$customerId = intval($_POST['customer_id'] ?? 0);
$customerIdsStr = trim($_POST['customer_ids'] ?? '');

// 确定是单个还是批量删除
$customerIds = [];
if ($customerId > 0) {
    // 单个删除
    $customerIds = [$customerId];
} elseif ($customerIdsStr !== '') {
    // 批量删除：解析逗号分隔的ID列表
    $ids = explode(',', $customerIdsStr);
    foreach ($ids as $id) {
        $id = intval(trim($id));
        if ($id > 0) {
            $customerIds[] = $id;
        }
    }
}

if (empty($customerIds)) {
    echo json_encode([
        'success' => false, 
        'message' => '参数错误：请提供客户ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $now = time();
    $deletedCount = 0;
    $failedCount = 0;
    $errors = [];
    $totalFileCount = 0;
    $deletedCustomerNames = [];
    
    // 开始事务
    Db::beginTransaction();
    
    try {
        foreach ($customerIds as $customerId) {
            try {
                // 获取客户信息
                $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
                
                if (!$customer) {
                    $failedCount++;
                    $errors[] = "客户ID {$customerId} 不存在";
                    continue;
                }
                
                // 权限检查：有删除权限或管理员可以删除任何客户，普通用户只能删除自己创建的客户
                if (!canOrAdmin(PermissionCode::CUSTOMER_DELETE) && $customer['create_user_id'] != $user['id']) {
                    $failedCount++;
                    $errors[] = "客户 \"{$customer['name']}\" (ID: {$customerId}) 无权限删除";
                    continue;
                }
                
                // 检查客户是否已被删除
                if (!empty($customer['deleted_at'])) {
                    $failedCount++;
                    $errors[] = "客户 \"{$customer['name']}\" (ID: {$customerId}) 已被删除";
                    continue;
                }
                
                // 1. 标记客户为已删除（软删除）
                Db::execute(
                    'UPDATE customers SET deleted_at = :deleted_at, deleted_by = :deleted_by WHERE id = :id',
                    [
                        'deleted_at' => $now,
                        'deleted_by' => $user['id'],
                        'id' => $customerId
                    ]
                );
                
                // 2. 将该客户的所有文件标记为软删除（进入15天保留期）
                $fileCount = Db::execute(
                    'UPDATE customer_files SET deleted_at = :deleted_at, deleted_by = :deleted_by 
                     WHERE customer_id = :customer_id AND deleted_at IS NULL',
                    [
                        'deleted_at' => $now,
                        'deleted_by' => $user['id'],
                        'customer_id' => $customerId
                    ]
                );
                $totalFileCount += $fileCount;
                
                // 3. 软删除关联的项目
                $projectCount = Db::execute(
                    'UPDATE projects SET deleted_at = :deleted_at WHERE customer_id = :customer_id AND deleted_at IS NULL',
                    [
                        'deleted_at' => $now,
                        'customer_id' => $customerId
                    ]
                );
                
                // 4. 删除其他相关数据（保持原逻辑，物理删除）
                Db::execute('DELETE FROM customer_links WHERE customer_id = :id', ['id' => $customerId]);
                Db::execute('DELETE FROM files WHERE customer_id = :id', ['id' => $customerId]); // 旧表
                Db::execute('DELETE FROM deal_record WHERE customer_id = :id', ['id' => $customerId]);
                Db::execute('DELETE FROM objection WHERE customer_id = :id', ['id' => $customerId]);
                Db::execute('DELETE FROM first_contact WHERE customer_id = :id', ['id' => $customerId]);
                
                // 记录操作日志
                try {
                    Db::execute(
                        'INSERT INTO operation_logs 
                            (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
                         VALUES 
                            (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
                        [
                            'user_id' => $user['id'],
                            'module' => 'customers',
                            'action' => 'delete',
                            'target_type' => 'customer',
                            'target_id' => $customerId,
                            'customer_id' => $customerId,
                            'file_id' => null,
                            'description' => "删除客户: {$customer['name']} (ID: {$customerId}), 标记 {$fileCount} 个文件进入15天保留期",
                            'extra' => json_encode([
                                'customer_name' => $customer['name'],
                                'customer_code' => $customer['customer_code'] ?? null,
                                'files_marked' => $fileCount,
                                'deleted_at' => $now,
                                'batch_delete' => count($customerIds) > 1
                            ]),
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'created_at' => $now
                        ]
                    );
                } catch (Exception $logError) {
                    // 日志记录失败不影响主操作
                    error_log('客户删除操作日志记录失败: ' . $logError->getMessage());
                }
                
                $deletedCount++;
                $deletedCustomerNames[] = $customer['name'];
                error_log("客户删除成功（软删除）: customer_id={$customerId}, user_id={$user['id']}, customer_name={$customer['name']}, files_marked={$fileCount}");
                
            } catch (Exception $e) {
                $failedCount++;
                $errors[] = "删除客户ID {$customerId} 失败: " . $e->getMessage();
                error_log("客户删除失败: customer_id={$customerId}, error={$e->getMessage()}");
                // 继续处理下一个客户
            }
        }
        
        // 提交事务
        Db::commit();
        
        // 构建返回消息
        $message = '';
        if ($deletedCount > 0) {
            if (count($customerIds) === 1) {
                $message = '客户删除成功';
            } else {
                $message = "成功删除 {$deletedCount} 个客户";
                if ($totalFileCount > 0) {
                    $message .= "，共标记 {$totalFileCount} 个文件进入15天保留期";
                }
            }
        }
        
        if ($failedCount > 0) {
            if ($deletedCount > 0) {
                $message .= "，{$failedCount} 个客户删除失败：" . implode('; ', $errors);
            } else {
                $message = "删除失败：" . implode('; ', $errors);
            }
        }
        
        if ($deletedCount === 0 && $failedCount === 0) {
            $message = '没有可删除的客户';
        }
        
        echo json_encode([
            'success' => $deletedCount > 0,
            'message' => $message,
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        Db::rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Customer delete error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => '删除失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
