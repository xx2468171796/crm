<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 文件夹上传初始化 API
 * POST /api/folder_upload_init.php
 * 
 * 参数:
 * - group_code: 群码（必填）
 * - project_id: 项目ID（可选）
 * - asset_type: 文件类型 works/models/customer（必填）
 * - files: 文件列表数组，每项包含:
 *   - rel_path: 相对路径（包含文件夹结构）
 *   - filename: 文件名
 *   - filesize: 文件大小
 *   - mime_type: MIME类型（可选）
 * 
 * 返回:
 * - upload_sessions: 每个文件的上传会话信息
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$groupCode = $input['group_code'] ?? '';
$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? 'works';
$files = $input['files'] ?? [];

if (!$groupCode || empty($files)) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取项目名称（如果有 project_id）
    $projectName = '';
    if ($projectId > 0) {
        $project = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code as customer_group_code, c.group_name, c.name as customer_name
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );
        if ($project) {
            $projectName = $project['project_name'] ?: $project['project_code'];
            $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
            
            // 优化groupCode
            if (preg_match('/^P\d+$/', $groupCode)) {
                $betterGroupCode = $project['customer_group_code'] ?: $project['group_name'] ?: $project['customer_name'];
                if ($betterGroupCode) {
                    $groupCode = preg_replace('/[\/\\\\:*?"<>|]/', '_', $betterGroupCode);
                }
            }
        }
    }
    
    // 确定文件类型目录
    $assetTypeDir = match($assetType) {
        'works' => '作品文件',
        'models' => '模型文件',
        'customer' => '客户文件',
        'info' => '信息文件',
        'company' => '公司文件',
        default => '作品文件',
    };
    
    $uploadService = new MultipartUploadService();
    $partSize = 50 * 1024 * 1024; // 50MB
    $uploadSessions = [];
    
    foreach ($files as $index => $file) {
        $relPath = $file['rel_path'] ?? '';
        $filename = $file['filename'] ?? '';
        $filesize = (int)($file['filesize'] ?? 0);
        $mimeType = $file['mime_type'] ?? 'application/octet-stream';
        
        if (empty($filename) || $filesize <= 0) {
            continue;
        }
        
        // 构建存储键 - 保持原始文件夹结构和文件名
        if ($projectName && in_array($assetType, ['works', 'models', 'customer'])) {
            $storageKey = "groups/{$groupCode}/{$projectName}/{$assetTypeDir}/" . ltrim($relPath, '/');
        } else {
            $storageKey = "groups/{$groupCode}/{$assetTypeDir}/" . ltrim($relPath, '/');
        }
        
        // 初始化分片上传
        $result = $uploadService->initiate($storageKey, $mimeType);
        $totalParts = (int)ceil($filesize / $partSize);
        
        $uploadSessions[] = [
            'index' => $index,
            'rel_path' => $relPath,
            'filename' => $filename,
            'upload_id' => $result['upload_id'],
            'storage_key' => $storageKey,
            'part_size' => $partSize,
            'total_parts' => $totalParts,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_files' => count($uploadSessions),
            'upload_sessions' => $uploadSessions,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] folder_upload_init 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
