<?php
/**
 * 桌面端文件上传 API
 * 
 * POST action=get_upload_url: 获取预签名上传 URL
 * POST action=confirm_upload: 确认上传完成，创建审批记录
 */

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error' => '该接口已下线，请升级客户端并使用 desktop_upload_init.php / desktop_upload_part_url.php / desktop_upload_complete.php',
], JSON_UNESCAPED_UNICODE);
exit;

// CORS 配置
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/S3Service.php';

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

$user = Db::queryOne('SELECT id, username, realname as name, role, department_id FROM users WHERE id = ? AND status = 1 LIMIT 1', [$tokenRecord['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户不存在或已禁用']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$pdo = Db::pdo();

switch ($action) {
    case 'get_upload_url':
        // 获取预签名上传 URL
        $projectId = (int)($input['project_id'] ?? 0);
        $fileName = $input['file_name'] ?? '';
        $fileSize = (int)($input['file_size'] ?? 0);
        $mimeType = $input['mime_type'] ?? 'application/octet-stream';
        $folderType = $input['folder_type'] ?? '作品文件';
        
        if ($projectId <= 0 || empty($fileName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '参数不完整']);
            exit;
        }
        
        try {
            // 获取项目信息
            $stmt = $pdo->prepare("
                SELECT p.id, p.customer_id, c.group_code, c.group_name
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
            
            // 生成存储路径
            // 统一使用 groupCode 作为文件夹名称（不再拼接群名称）
            $groupCode = $project['group_code'] ?: ('P' . $projectId);
            $folderName = $groupCode;
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $uuid = bin2hex(random_bytes(16));
            $storageKey = "groups/{$folderName}/{$folderType}/{$uuid}.{$ext}";
            
            // 生成预签名上传 URL
            $s3 = new S3Service();
            $uploadUrl = $s3->getPresignedUploadUrl($storageKey, 3600, $mimeType);
            
            echo json_encode([
                'success' => true,
                'upload_url' => $uploadUrl,
                'storage_key' => $storageKey,
                'expires_in' => 3600,
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
        }
        break;
        
    case 'confirm_upload':
        // 确认上传完成，创建审批记录
        $projectId = (int)($input['project_id'] ?? 0);
        $storageKey = $input['storage_key'] ?? '';
        $fileName = $input['file_name'] ?? '';
        $fileSize = (int)($input['file_size'] ?? 0);
        $mimeType = $input['mime_type'] ?? 'application/octet-stream';
        $folderType = $input['folder_type'] ?? '作品文件';
        $needApproval = $input['need_approval'] ?? true;
        
        if ($projectId <= 0 || empty($storageKey) || empty($fileName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '参数不完整']);
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
            
            // 创建文件记录
            $now = time();
            $stmt = $pdo->prepare("
                INSERT INTO customer_files 
                (customer_id, folder_path, filename, storage_disk, storage_key, filesize, mime_type, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $project['customer_id'],
                $folderType,
                $fileName,
                's3',
                $storageKey,
                $fileSize,
                $mimeType,
                $user['id'],
                $now
            ]);
            $fileId = $pdo->lastInsertId();
            
            $approvalId = null;
            
            // 如果需要审批，创建审批记录
            if ($needApproval) {
                $stmt = $pdo->prepare("
                    INSERT INTO file_approvals (file_id, submitter_id, status, submit_time)
                    VALUES (?, ?, 'pending', NOW())
                ");
                $stmt->execute([$fileId, $user['id']]);
                $approvalId = $pdo->lastInsertId();
                
                // 通知管理员
                $stmt = $pdo->prepare("
                    SELECT id FROM users WHERE role IN ('admin', 'tech_lead') AND status = 1
                ");
                $stmt->execute();
                $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($managers as $managerId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, create_time)
                        VALUES (?, 'file_approval', '新文件待审批', ?, 'file', ?, ?)
                    ");
                    $stmt->execute([
                        $managerId,
                        "{$user['name']} 上传了文件 {$fileName} 待审批",
                        $fileId,
                        $now
                    ]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'file_id' => $fileId,
                'approval_id' => $approvalId,
                'status' => $status,
                'message' => $needApproval ? '文件已上传，等待审批' : '文件上传成功',
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效的操作']);
}

