<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户恢复API
 * 权限：仅管理员可以恢复已删除的客户
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
        'message' => '无权限恢复客户'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = intval($_POST['customer_id'] ?? 0);

if ($customerId <= 0) {
    echo json_encode([
        'success' => false, 
        'message' => '参数错误'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取客户信息
    $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
    
    if (!$customer) {
        echo json_encode([
            'success' => false, 
            'message' => '客户不存在'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查客户是否已被删除
    if (empty($customer['deleted_at'])) {
        echo json_encode([
            'success' => false, 
            'message' => '客户未被删除，无需恢复'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $now = time();
    
    // 开始事务
    if (!Db::beginTransaction()) {
        throw new Exception('事务启动失败');
    }
    
    try {
        // 1. 恢复客户（清除软删除标记）
        $customerUpdated = Db::execute(
            'UPDATE customers SET deleted_at = NULL, deleted_by = NULL WHERE id = :id',
            ['id' => $customerId]
        );
        
        if ($customerUpdated === false) {
            throw new Exception('恢复客户记录失败');
        }
        
        if ($customerUpdated === 0) {
            throw new Exception('客户记录未更新，可能已被恢复或不存在');
        }
        
        // 2. 恢复该客户的所有文件（清除软删除标记）
        $fileCount = Db::execute(
            'UPDATE customer_files SET deleted_at = NULL, deleted_by = NULL 
             WHERE customer_id = :customer_id AND deleted_at IS NOT NULL',
            ['customer_id' => $customerId]
        );
        
        if ($fileCount === false) {
            throw new Exception('恢复客户文件失败');
        }
        
        // 3. 恢复客户分享链接（customer_links）
        // 注意：删除客户时会物理删除 customer_links，所以恢复时需要重新创建
        $existingCustomerLink = Db::queryOne('SELECT id FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
        if (!$existingCustomerLink) {
            // 如果客户分享链接不存在，重新创建
            $token = bin2hex(random_bytes(32)); // 64位随机token
            $result = Db::execute('INSERT INTO customer_links 
                (customer_id, token, enabled, created_at, updated_at) 
                VALUES 
                (:cid, :token, 1, :created_at, :updated_at)', [
                'cid' => $customerId,
                'token' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($result !== false) {
                error_log("客户恢复：已重新创建客户ID {$customerId} 的分享链接，token={$token}");
            } else {
                error_log("客户恢复：重新创建客户ID {$customerId} 的分享链接失败");
            }
        } else {
            // 如果链接存在但被禁用，恢复启用状态
            Db::execute('UPDATE customer_links SET enabled = 1, updated_at = :now WHERE customer_id = :cid', [
                'now' => $now,
                'cid' => $customerId
            ]);
            error_log("客户恢复：已自动启用客户ID {$customerId} 的分享链接");
        }
        
        // 4. 恢复文件的分享链接状态（如果链接存在但被禁用，则启用它）
        // 注意：链接本身不会被删除（因为文件只是软删除），但如果链接被禁用了，需要恢复启用状态
        if ($fileCount > 0) {
            $restoredFiles = Db::query('SELECT id FROM customer_files WHERE customer_id = :customer_id AND deleted_at IS NULL', 
                ['customer_id' => $customerId]);
            
            foreach ($restoredFiles as $file) {
                $fileId = (int)$file['id'];
                $link = FileLinkService::getByFileId($fileId);
                if ($link && !$link['enabled']) {
                    // 如果链接存在但被禁用，恢复启用状态
                    FileLinkService::update($link['id'], ['enabled' => 1]);
                    error_log("客户恢复：已自动启用文件ID {$fileId} 的分享链接");
                }
            }
        }
        
        // 5. 记录操作日志
        try {
            Db::execute(
                'INSERT INTO operation_logs 
                    (user_id, module, action, target_type, target_id, customer_id, file_id, description, extra, ip, user_agent, created_at) 
                 VALUES 
                    (:user_id, :module, :action, :target_type, :target_id, :customer_id, :file_id, :description, :extra, :ip, :user_agent, :created_at)',
                [
                    'user_id' => $user['id'],
                    'module' => 'customers',
                    'action' => 'restore',
                    'target_type' => 'customer',
                    'target_id' => $customerId,
                    'customer_id' => $customerId,
                    'file_id' => null,
                    'description' => "恢复客户: {$customer['name']} (ID: {$customerId}), 恢复 {$fileCount} 个文件",
                    'extra' => json_encode([
                        'customer_name' => $customer['name'],
                        'customer_code' => $customer['customer_code'] ?? null,
                        'files_restored' => $fileCount,
                        'restored_at' => $now
                    ]),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'created_at' => $now
                ]
            );
        } catch (Exception $logError) {
            // 日志记录失败不影响主操作
            error_log('客户恢复操作日志记录失败: ' . $logError->getMessage());
        }
        
        // 提交事务
        if (!Db::commit()) {
            throw new Exception('事务提交失败');
        }
        
        error_log("客户恢复成功: customer_id={$customerId}, user_id={$user['id']}, customer_name={$customer['name']}, files_restored={$fileCount}");
        
    } catch (Exception $e) {
        Db::rollBack();
        throw $e;
    }
    
    echo json_encode([
        'success' => true, 
        'message' => '客户恢复成功',
        'data' => [
            'files_restored' => $fileCount
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Customer restore PDO error: ' . $e->getMessage() . ' | Code: ' . $e->getCode() . ' | Trace: ' . $e->getTraceAsString());
    
    // 获取 PDO 错误信息
    $pdo = Db::pdo();
    $errorInfo = $pdo->errorInfo();
    $errorMessage = $e->getMessage();
    if (!empty($errorInfo[2])) {
        $errorMessage .= ' (SQL: ' . $errorInfo[2] . ')';
    }
    
    echo json_encode([
        'success' => false, 
        'message' => '恢复失败: ' . $errorMessage
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Customer restore error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => '恢复失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

