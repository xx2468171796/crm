<?php
/**
 * 个人网盘文件操作 API
 * POST action=rename - 重命名文件
 * POST action=move - 移动文件
 * POST action=batch_delete - 批量删除
 * POST action=batch_move - 批量移动
 */

require_once __DIR__ . '/../core/api_init.php';

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    $pdo = Db::pdo();
    
    // 获取用户网盘
    $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$drive) {
        http_response_code(404);
        echo json_encode(['error' => '网盘不存在']);
        exit;
    }
    
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    switch ($action) {
        case 'rename':
            $fileId = intval($input['file_id'] ?? 0);
            $newName = trim($input['new_name'] ?? '');
            
            if ($fileId <= 0 || empty($newName)) {
                http_response_code(400);
                echo json_encode(['error' => '参数不完整']);
                exit;
            }
            
            // 检查名称是否合法
            if (preg_match('/[\\\\\/\:\*\?\"\<\>\|]/', $newName)) {
                http_response_code(400);
                echo json_encode(['error' => '文件名不能包含特殊字符']);
                exit;
            }
            
            // 获取文件
            $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
            $stmt->execute([$fileId, $drive['id']]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$file) {
                http_response_code(404);
                echo json_encode(['error' => '文件不存在']);
                exit;
            }
            
            // 更新文件名
            $stmt = $pdo->prepare("UPDATE drive_files SET filename = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$newName, time(), $fileId]);
            
            echo json_encode([
                'success' => true,
                'data' => ['id' => $fileId, 'new_name' => $newName]
            ]);
            break;
            
        case 'move':
            $fileId = intval($input['file_id'] ?? 0);
            $targetPath = trim($input['target_path'] ?? '/');
            
            if ($fileId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => '参数不完整']);
                exit;
            }
            
            // 获取文件
            $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
            $stmt->execute([$fileId, $drive['id']]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$file) {
                http_response_code(404);
                echo json_encode(['error' => '文件不存在']);
                exit;
            }
            
            // 更新文件路径
            $stmt = $pdo->prepare("UPDATE drive_files SET folder_path = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$targetPath, time(), $fileId]);
            
            echo json_encode([
                'success' => true,
                'data' => ['id' => $fileId, 'new_path' => $targetPath]
            ]);
            break;
            
        case 'batch_delete':
            $fileIds = $input['file_ids'] ?? [];
            
            if (empty($fileIds) || !is_array($fileIds)) {
                http_response_code(400);
                echo json_encode(['error' => '请选择要删除的文件']);
                exit;
            }
            
            $deletedCount = 0;
            $freedSpace = 0;
            
            $s3 = !empty($storageConfig) ? new S3StorageProvider($storageConfig, []) : null;
            
            foreach ($fileIds as $fileId) {
                $fileId = intval($fileId);
                
                // 获取文件
                $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
                $stmt->execute([$fileId, $drive['id']]);
                $file = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$file) continue;
                
                // 从S3删除
                if ($s3 && !empty($file['storage_key'])) {
                    try {
                        $s3->deleteObject($file['storage_key']);
                    } catch (Exception $e) {
                        error_log("删除S3文件失败: " . $e->getMessage());
                    }
                }
                
                // 从数据库删除
                $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id = ?");
                $stmt->execute([$fileId]);
                
                $deletedCount++;
                $freedSpace += $file['file_size'];
            }
            
            // 更新已用空间
            if ($freedSpace > 0) {
                $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage - ?, update_time = ? WHERE id = ?");
                $stmt->execute([$freedSpace, time(), $drive['id']]);
            }
            
            echo json_encode([
                'success' => true,
                'data' => ['deleted_count' => $deletedCount, 'freed_space' => $freedSpace]
            ]);
            break;
            
        case 'batch_move':
            $fileIds = $input['file_ids'] ?? [];
            $targetPath = trim($input['target_path'] ?? '/');
            
            if (empty($fileIds) || !is_array($fileIds)) {
                http_response_code(400);
                echo json_encode(['error' => '请选择要移动的文件']);
                exit;
            }
            
            $movedCount = 0;
            
            foreach ($fileIds as $fileId) {
                $fileId = intval($fileId);
                
                // 检查文件是否属于当前用户
                $stmt = $pdo->prepare("SELECT id FROM drive_files WHERE id = ? AND drive_id = ?");
                $stmt->execute([$fileId, $drive['id']]);
                if (!$stmt->fetch()) continue;
                
                // 更新文件路径
                $stmt = $pdo->prepare("UPDATE drive_files SET folder_path = ?, update_time = ? WHERE id = ?");
                $stmt->execute([$targetPath, time(), $fileId]);
                $movedCount++;
            }
            
            echo json_encode([
                'success' => true,
                'data' => ['moved_count' => $movedCount, 'target_path' => $targetPath]
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => '未知操作']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '操作失败: ' . $e->getMessage()]);
}
