<?php
/**
 * 删除网盘文件 API
 * POST /api/personal_drive_delete.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$fileId = intval($input['file_id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '无效的文件ID']);
    exit;
}

try {
    $pdo = Db::getConnection();
    
    // 获取文件信息
    $stmt = $pdo->prepare("
        SELECT df.*, pd.id as drive_id 
        FROM drive_files df
        JOIN personal_drives pd ON pd.id = df.drive_id
        WHERE df.id = ? AND df.user_id = ?
    ");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => '文件不存在或无权限']);
        exit;
    }
    
    // 从S3删除文件
    $config = require __DIR__ . '/../config/storage.php';
    $storageConfig = $config['s3'] ?? [];
    
    if (!empty($file['storage_key'])) {
        try {
            $s3 = new S3StorageProvider($storageConfig, []);
            $s3->deleteFile($file['storage_key']);
        } catch (Exception $e) {
            // 记录日志但不阻止删除
            error_log("删除S3文件失败: " . $e->getMessage());
        }
    }
    
    // 从数据库删除
    $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id = ?");
    $stmt->execute([$fileId]);
    
    // 更新已用空间
    $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage - ?, update_time = ? WHERE id = ?");
    $stmt->execute([$file['file_size'], time(), $file['drive_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => '文件已删除'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
}
