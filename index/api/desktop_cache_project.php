<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端项目缓存 API
 * 用于管理员/主管选择性缓存项目文件
 * 
 * GET: 获取可缓存的项目列表
 * POST: 获取项目文件的下载链接列表
 */

// CORS 配置
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

// 验证 Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未登录']);
    exit;
}

$tokenRecord = Db::queryOne('SELECT user_id, expire_at FROM desktop_tokens WHERE token = ? LIMIT 1', [$token]);
if (!$tokenRecord || $tokenRecord['expire_at'] < time()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token 无效或已过期']);
    exit;
}

$user = Db::queryOne('SELECT id, username, realname, role, department_id FROM users WHERE id = ? AND status = 1 LIMIT 1', [$tokenRecord['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户不存在或已禁用']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = Db::pdo();

if ($method === 'GET') {
    // 获取可缓存的项目列表
    try {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.project_code,
                    p.project_name,
                    p.current_status,
                    c.name as customer_name,
                    c.group_name,
                    c.group_code,
                    (SELECT COUNT(*) FROM customer_files cf 
                     WHERE cf.customer_id = p.customer_id 
                     AND cf.deleted_at IS NULL 
                     AND cf.folder_type IN ('作品文件', '模型文件')) as file_count,
                    (SELECT SUM(cf.file_size) FROM customer_files cf 
                     WHERE cf.customer_id = p.customer_id 
                     AND cf.deleted_at IS NULL 
                     AND cf.folder_type IN ('作品文件', '模型文件')) as total_size
                FROM projects p
                LEFT JOIN customers c ON p.customer_id = c.id
                WHERE p.deleted_at IS NULL
                ORDER BY p.updated_at DESC
                LIMIT 100
            ");
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // 兼容新表结构：projects.update_time + customer_files.folder_path/filesize
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.project_code,
                    p.project_name,
                    p.current_status,
                    c.name as customer_name,
                    c.group_name,
                    c.group_code,
                    (SELECT COUNT(*) FROM customer_files cf 
                     WHERE cf.customer_id = p.customer_id 
                     AND cf.deleted_at IS NULL 
                     AND cf.folder_path IN ('作品文件', '模型文件')) as file_count,
                    (SELECT SUM(cf.filesize) FROM customer_files cf 
                     WHERE cf.customer_id = p.customer_id 
                     AND cf.deleted_at IS NULL 
                     AND cf.folder_path IN ('作品文件', '模型文件')) as total_size
                FROM projects p
                LEFT JOIN customers c ON p.customer_id = c.id
                WHERE p.deleted_at IS NULL
                ORDER BY p.update_time DESC
                LIMIT 100
            ");
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'projects' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'code' => $p['project_code'],
                    'name' => $p['project_name'],
                    'status' => $p['current_status'],
                    'customer_name' => $p['customer_name'],
                    'group_name' => $p['group_name'],
                    'group_code' => $p['group_code'],
                    'file_count' => (int)$p['file_count'],
                    'total_size' => (int)$p['total_size'],
                ];
            }, $projects),
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // 获取项目文件的下载链接列表
    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = (int)($input['project_id'] ?? 0);
    $folderTypes = $input['folder_types'] ?? ['作品文件', '模型文件'];
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '项目ID无效']);
        exit;
    }
    
    try {
        // 获取项目信息
        $stmt = $pdo->prepare("
            SELECT p.id, p.customer_id, c.group_name
            FROM projects p
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE p.id = ?
        ");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '项目不存在']);
            exit;
        }
        
        // 构建文件夹类型条件
        $typeConditions = [];
        $params = [$project['customer_id']];
        foreach ($folderTypes as $type) {
            $typeConditions[] = '?';
            $params[] = $type;
        }
        $typePlaceholders = implode(',', $typeConditions);
        
        // 获取文件列表（兼容旧/新表结构）
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    cf.id,
                    cf.original_name,
                    cf.file_path,
                    cf.file_size,
                    cf.mime_type,
                    cf.folder_type,
                    cf.created_at
                FROM customer_files cf
                WHERE cf.customer_id = ?
                AND cf.folder_type IN ({$typePlaceholders})
                AND cf.deleted_at IS NULL
                ORDER BY cf.folder_type, cf.created_at DESC
            ");
            $stmt->execute($params);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $stmt = $pdo->prepare("
                SELECT 
                    cf.id,
                    cf.filename as original_name,
                    cf.storage_key as file_path,
                    cf.filesize as file_size,
                    cf.mime_type,
                    cf.folder_path as folder_type,
                    cf.uploaded_at as created_at
                FROM customer_files cf
                WHERE cf.customer_id = ?
                AND cf.folder_path IN ({$typePlaceholders})
                AND cf.deleted_at IS NULL
                ORDER BY cf.folder_path, cf.uploaded_at DESC
            ");
            $stmt->execute($params);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 生成预签名下载链接（统一走 MultipartUploadService，避免 prefix 双拼）
        $uploadService = new MultipartUploadService();
        $config = storage_config();
        $s3Config = $config['s3'] ?? [];
        $prefix = trim((string)($s3Config['prefix'] ?? ''), '/');
        
        $downloadList = [];
        foreach ($files as $file) {
            $downloadUrl = '';
            $fullKey = (string)($file['file_path'] ?? '');
            $storageKeyNoPrefix = $fullKey;
            if ($prefix && $storageKeyNoPrefix !== '' && strpos($storageKeyNoPrefix, $prefix . '/') === 0) {
                $storageKeyNoPrefix = substr($storageKeyNoPrefix, strlen($prefix) + 1);
            }
            if ($storageKeyNoPrefix !== '') {
                try {
                    $downloadUrl = $uploadService->getDownloadPresignedUrl($storageKeyNoPrefix, 3600);
                } catch (Exception $e) {
                    $downloadUrl = '';
                }
            }
            $downloadList[] = [
                'id' => (int)$file['id'],
                'name' => $file['original_name'],
                'path' => $file['file_path'],
                'size' => (int)$file['file_size'],
                'storage_key' => $storageKeyNoPrefix,
                'mime_type' => $file['mime_type'],
                'folder_type' => $file['folder_type'],
                'download_url' => $downloadUrl,
            ];
        }
        
        echo json_encode([
            'success' => true,
            'project' => [
                'id' => (int)$project['id'],
                'group_name' => $project['group_name'],
            ],
            'files' => $downloadList,
            'total' => count($downloadList),
            'expires_in' => 3600,
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
}
