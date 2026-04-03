<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端客户文件 API
 * 用于获取项目关联的客户文件列表，支持增量同步
 * 
 * GET 参数:
 * - project_id: 项目ID
 * - since: 可选，时间戳，只返回此时间之后更新的文件
 * - folder_type: 可选，文件夹类型 (customer/works/models)
 */

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error' => '该接口已下线，请使用 desktop_project_files.php（按新 S3 路径结构）获取项目文件列表',
], JSON_UNESCAPED_UNICODE);
exit;
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

if ($method === 'GET') {
    // 获取文件列表
    $projectId = (int)($_GET['project_id'] ?? 0);
    $since = $_GET['since'] ?? null;
    $folderType = $_GET['folder_type'] ?? null;
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '项目ID无效']);
        exit;
    }
    
    try {
        $pdo = Db::pdo();
        // 获取项目信息
        $stmt = $pdo->prepare("
            SELECT p.id, p.project_code, p.project_name, p.customer_id,
                   c.name as customer_name, c.group_code, c.group_name
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
        
        // 构建查询条件
        $conditions = ["cf.customer_id = ?"];
        $params = [$project['customer_id']];
        
        // 增量同步：只返回指定时间之后更新的文件
        if ($since) {
            $conditions[] = "cf.updated_at > ?";
            $params[] = date('Y-m-d H:i:s', (int)$since);
        }
        
        // 文件夹类型筛选
        if ($folderType) {
            $folderMap = [
                'customer' => '客户文件',
                'works' => '作品文件',
                'models' => '模型文件',
            ];
            if (isset($folderMap[$folderType])) {
                $conditions[] = "cf.folder_type = ?";
                $params[] = $folderMap[$folderType];
            }
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        // 获取文件列表
        $stmt = $pdo->prepare("
            SELECT 
                cf.id,
                cf.original_name,
                cf.file_path,
                cf.file_size,
                cf.mime_type,
                cf.folder_type,
                cf.status,
                cf.uploader_id,
                u.name as uploader_name,
                cf.created_at,
                cf.updated_at
            FROM customer_files cf
            LEFT JOIN users u ON cf.uploader_id = u.id
            WHERE {$whereClause}
            AND cf.deleted_at IS NULL
            ORDER BY cf.folder_type, cf.created_at DESC
        ");
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按文件夹类型分组
        $grouped = [
            '客户文件' => [],
            '作品文件' => [],
            '模型文件' => [],
        ];
        
        foreach ($files as $file) {
            $type = $file['folder_type'] ?? '客户文件';
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = [
                'id' => (int)$file['id'],
                'name' => $file['original_name'],
                'path' => $file['file_path'],
                'size' => (int)$file['file_size'],
                'mime_type' => $file['mime_type'],
                'status' => $file['status'],
                'uploader' => $file['uploader_name'],
                'created_at' => $file['created_at'],
                'updated_at' => $file['updated_at'],
            ];
        }
        
        echo json_encode([
            'success' => true,
            'project' => [
                'id' => (int)$project['id'],
                'code' => $project['project_code'],
                'name' => $project['project_name'],
                'customer_name' => $project['customer_name'],
                'group_code' => $project['group_code'],
                'group_name' => $project['group_name'],
            ],
            'files' => $grouped,
            'total' => count($files),
            'server_time' => time(),
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // 获取下载 URL
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = (int)($input['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '文件ID无效']);
        exit;
    }
    
    try {
        $pdo = Db::pdo();
        // 获取文件信息
        $stmt = $pdo->prepare("
            SELECT cf.*, c.group_name
            FROM customer_files cf
            LEFT JOIN customers c ON cf.customer_id = c.id
            WHERE cf.id = ? AND cf.deleted_at IS NULL
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '文件不存在']);
            exit;
        }
        
        // 生成预签名下载 URL
        require_once __DIR__ . '/../services/S3Service.php';
        $s3 = new S3Service();
        $downloadUrl = $s3->getPresignedUrl($file['file_path'], 3600); // 1小时有效
        
        echo json_encode([
            'success' => true,
            'file' => [
                'id' => (int)$file['id'],
                'name' => $file['original_name'],
                'size' => (int)$file['file_size'],
                'mime_type' => $file['mime_type'],
                'folder_type' => $file['folder_type'],
                'group_name' => $file['group_name'],
            ],
            'download_url' => $downloadUrl,
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
