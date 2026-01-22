<?php
/**
 * 获取个人网盘文件列表 API
 * GET /api/personal_drive_list.php?folder_path=/
 */

require_once __DIR__ . '/../core/api_init.php';

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$folderPath = trim($_GET['folder_path'] ?? '/');
if (empty($folderPath)) $folderPath = '/';

try {
    $pdo = Db::pdo();
    
    // 获取或创建用户网盘
    $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$drive) {
        // 自动创建网盘
        $stmt = $pdo->prepare("INSERT INTO personal_drives (user_id, create_time) VALUES (?, ?)");
        $stmt->execute([$user['id'], time()]);
        $driveId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("SELECT * FROM personal_drives WHERE id = ?");
        $stmt->execute([$driveId]);
        $drive = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 获取文件列表
    $stmt = $pdo->prepare("
        SELECT id, filename, original_filename, folder_path, storage_key, file_size, file_type, upload_source, create_time
        FROM drive_files 
        WHERE drive_id = ? AND folder_path = ?
        ORDER BY create_time DESC
    ");
    $stmt->execute([$drive['id'], $folderPath]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取子文件夹列表
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            SUBSTRING_INDEX(SUBSTRING(folder_path, LENGTH(?)+1), '/', 1) as subfolder
        FROM drive_files 
        WHERE drive_id = ? AND folder_path LIKE ? AND folder_path != ?
    ");
    $likePath = rtrim($folderPath, '/') . '/%';
    $stmt->execute([$folderPath, $drive['id'], $likePath, $folderPath]);
    $subfolders = array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'subfolder'));
    
    // 格式化存储空间
    $usedGB = round($drive['used_storage'] / (1024 * 1024 * 1024), 2);
    $limitGB = round($drive['storage_limit'] / (1024 * 1024 * 1024), 2);
    $usedPercent = $drive['storage_limit'] > 0 ? round($drive['used_storage'] / $drive['storage_limit'] * 100, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'drive_id' => $drive['id'],
            'files' => $files,
            'folders' => array_values(array_unique($subfolders)),
            'current_path' => $folderPath,
            'storage' => [
                'used' => $drive['used_storage'],
                'limit' => $drive['storage_limit'],
                'used_gb' => $usedGB,
                'limit_gb' => $limitGB,
                'percent' => $usedPercent
            ]
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
}
