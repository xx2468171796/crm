<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目客户文件 API
 * 
 * GET: 获取项目的客户文件列表
 * POST: 上传客户文件
 * DELETE: 删除客户文件
 * 
 * S3 路径: groups/{groupCode}/{项目名称}/客户文件/{filename}
 */

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/S3Service.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = current_user();
if (!$user) {
    // 尝试通过分享链接访问
    $shareActor = null;
    $projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
    if ($projectId > 0) {
        $project = Db::queryOne("SELECT customer_id FROM projects WHERE id = ?", [$projectId]);
        if ($project) {
            $shareActor = resolveShareActor($project['customer_id']);
        }
    }
    if (!$shareActor) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => '未登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user = $shareActor;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 获取项目信息
function getProjectInfo(int $projectId): ?array {
    return Db::queryOne("
        SELECT p.id, p.project_name, p.project_code, p.customer_id,
               c.group_code, c.customer_group as customer_group_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ", [$projectId]);
}

// 构建 S3 前缀
function buildS3Prefix(array $project): string {
    $groupCode = $project['group_code'] ?? '';
    $projectName = $project['project_name'] ?: $project['project_code'];
    $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
    
    $config = storage_config();
    $s3Config = $config['s3'] ?? [];
    $prefix = trim($s3Config['prefix'] ?? '', '/');
    
    $path = "groups/{$groupCode}/{$projectName}/客户文件/";
    if ($prefix) {
        $path = $prefix . '/' . $path;
    }
    return $path;
}

try {
    switch ($method) {
        case 'GET':
            // 获取项目客户文件列表
            $projectId = (int)($_GET['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new RuntimeException('缺少 project_id 参数');
            }
            
            $project = getProjectInfo($projectId);
            if (!$project) {
                throw new RuntimeException('项目不存在');
            }
            
            $s3 = new S3Service();
            $searchPrefix = buildS3Prefix($project);
            
            $files = $s3->listObjects($searchPrefix, '');
            
            // 为每个文件生成下载 URL
            $formattedFiles = [];
            foreach ($files as $file) {
                $storageKey = $file['storage_key'] ?? $file['Key'] ?? '';
                $downloadUrl = '';
                try {
                    $downloadUrl = $s3->getPresignedUrl($storageKey, 3600);
                } catch (Exception $e) {
                    error_log("[project_customer_files] 生成下载URL失败: " . $e->getMessage());
                }
                
                $formattedFiles[] = [
                    'filename' => $file['filename'] ?? basename($storageKey),
                    'file_size' => $file['file_size'] ?? $file['Size'] ?? 0,
                    'storage_key' => $storageKey,
                    'download_url' => $downloadUrl,
                    'last_modified' => $file['last_modified'] ?? $file['LastModified'] ?? '',
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'files' => $formattedFiles,
                    'count' => count($formattedFiles),
                    'project' => [
                        'id' => $project['id'],
                        'project_name' => $project['project_name'],
                        'project_code' => $project['project_code'],
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'POST':
            // 上传客户文件（初始化分片上传）
            $input = json_decode(file_get_contents('php://input'), true);
            $projectId = (int)($input['project_id'] ?? 0);
            $filename = $input['filename'] ?? '';
            $filesize = (int)($input['filesize'] ?? 0);
            $mimeType = $input['mime_type'] ?? 'application/octet-stream';
            
            if ($projectId <= 0 || !$filename || !$filesize) {
                throw new RuntimeException('缺少必要参数');
            }
            
            $project = getProjectInfo($projectId);
            if (!$project) {
                throw new RuntimeException('项目不存在');
            }
            
            // 构建存储键
            $groupCode = $project['group_code'] ?? '';
            $projectName = $project['project_name'] ?: $project['project_code'];
            $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
            $storageKey = "groups/{$groupCode}/{$projectName}/客户文件/{$filename}";
            
            // 初始化分片上传
            $uploadService = new MultipartUploadService();
            $result = $uploadService->initiate($storageKey, $mimeType);
            
            $partSize = 50 * 1024 * 1024; // 50MB
            $totalParts = (int)ceil($filesize / $partSize);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'upload_id' => $result['upload_id'],
                    'storage_key' => $storageKey,
                    'part_size' => $partSize,
                    'total_parts' => $totalParts
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'DELETE':
            // 删除客户文件
            $input = json_decode(file_get_contents('php://input'), true);
            $projectId = (int)($input['project_id'] ?? 0);
            $storageKey = $input['storage_key'] ?? '';
            
            if ($projectId <= 0 || !$storageKey) {
                throw new RuntimeException('缺少必要参数');
            }
            
            $project = getProjectInfo($projectId);
            if (!$project) {
                throw new RuntimeException('项目不存在');
            }
            
            // 验证存储键是否属于该项目
            $groupCode = $project['group_code'] ?? '';
            $projectName = $project['project_name'] ?: $project['project_code'];
            $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
            $expectedPrefix = "groups/{$groupCode}/{$projectName}/客户文件/";
            
            if (strpos($storageKey, $expectedPrefix) !== 0) {
                throw new RuntimeException('无权删除此文件');
            }
            
            $s3 = new S3Service();
            $s3->deleteObject($storageKey);
            
            echo json_encode([
                'success' => true,
                'message' => '文件已删除'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] project_customer_files 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
