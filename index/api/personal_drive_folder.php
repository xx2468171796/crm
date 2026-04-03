<?php
/**
 * 个人网盘文件夹管理 API
 * POST action=create - 创建文件夹
 * POST action=rename - 重命名文件夹
 * POST action=delete - 删除文件夹
 */

require_once __DIR__ . '/../core/api_init.php';

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

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
    
    switch ($action) {
        case 'create':
            $parentPath = trim($input['parent_path'] ?? '/');
            $folderName = trim($input['folder_name'] ?? '');
            
            if (empty($folderName)) {
                http_response_code(400);
                echo json_encode(['error' => '文件夹名称不能为空']);
                exit;
            }
            
            // 检查名称是否合法
            if (preg_match('/[\\\\\/\:\*\?\"\<\>\|]/', $folderName)) {
                http_response_code(400);
                echo json_encode(['error' => '文件夹名称不能包含特殊字符']);
                exit;
            }
            
            $newPath = rtrim($parentPath, '/') . '/' . $folderName;
            if ($parentPath === '/') {
                $newPath = '/' . $folderName;
            }
            
            // 检查是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM drive_files WHERE drive_id = ? AND folder_path = ?");
            $stmt->execute([$drive['id'], $newPath]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['error' => '该文件夹已存在文件']);
                exit;
            }
            
            // 创建一个占位文件标记文件夹存在
            $stmt = $pdo->prepare("
                INSERT INTO drive_files 
                (drive_id, user_id, filename, original_filename, folder_path, storage_key, file_size, file_type, upload_source, create_time)
                VALUES (?, ?, '.folder', '.folder', ?, '', 0, 'folder', 'system', ?)
            ");
            $stmt->execute([$drive['id'], $user['id'], $newPath, time()]);
            
            echo json_encode([
                'success' => true,
                'data' => ['path' => $newPath, 'name' => $folderName]
            ]);
            break;
            
        case 'rename':
            $oldPath = trim($input['old_path'] ?? '');
            $newName = trim($input['new_name'] ?? '');
            
            if (empty($oldPath) || empty($newName)) {
                http_response_code(400);
                echo json_encode(['error' => '参数不完整']);
                exit;
            }
            
            // 计算新路径
            $parentPath = dirname($oldPath);
            $newPath = ($parentPath === '/' ? '/' : $parentPath . '/') . $newName;
            
            // 更新所有该文件夹下的文件路径
            $stmt = $pdo->prepare("
                UPDATE drive_files 
                SET folder_path = REPLACE(folder_path, ?, ?)
                WHERE drive_id = ? AND (folder_path = ? OR folder_path LIKE ?)
            ");
            $stmt->execute([$oldPath, $newPath, $drive['id'], $oldPath, $oldPath . '/%']);
            
            echo json_encode([
                'success' => true,
                'data' => ['old_path' => $oldPath, 'new_path' => $newPath]
            ]);
            break;
            
        case 'delete':
            $folderPath = trim($input['folder_path'] ?? '');
            
            if (empty($folderPath) || $folderPath === '/') {
                http_response_code(400);
                echo json_encode(['error' => '无法删除根目录']);
                exit;
            }
            
            // 获取文件夹下所有文件
            $stmt = $pdo->prepare("
                SELECT id, storage_key, file_size FROM drive_files 
                WHERE drive_id = ? AND (folder_path = ? OR folder_path LIKE ?)
            ");
            $stmt->execute([$drive['id'], $folderPath, $folderPath . '/%']);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 删除S3文件
            $config = require __DIR__ . '/../config/storage.php';
            $storageConfig = $config['s3'] ?? [];
            if (!empty($storageConfig)) {
                require_once __DIR__ . '/../core/storage/storage_provider.php';
                $s3 = new S3StorageProvider($storageConfig, []);
                foreach ($files as $file) {
                    if (!empty($file['storage_key'])) {
                        try {
                            $s3->deleteObject($file['storage_key']);
                        } catch (Exception $e) {
                            error_log("删除S3文件失败: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // 计算总大小
            $totalSize = array_sum(array_column($files, 'file_size'));
            
            // 删除数据库记录
            $stmt = $pdo->prepare("
                DELETE FROM drive_files 
                WHERE drive_id = ? AND (folder_path = ? OR folder_path LIKE ?)
            ");
            $stmt->execute([$drive['id'], $folderPath, $folderPath . '/%']);
            
            // 更新已用空间
            $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage - ?, update_time = ? WHERE id = ?");
            $stmt->execute([$totalSize, time(), $drive['id']]);
            
            echo json_encode([
                'success' => true,
                'data' => ['deleted_files' => count($files), 'freed_space' => $totalSize]
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
