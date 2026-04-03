<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 分片上传初始化 API
 * POST /api/desktop_upload_init.php
 * 
 * 参数:
 * - group_code: 群码（必填）
 * - project_id: 项目ID（可选，用于按项目分目录）
 * - asset_type: 文件类型 works/models/customer（必填）
 * - rel_path: 相对路径（必填）
 * - filename: 文件名（必填）
 * - filesize: 文件大小（必填）
 * - mime_type: MIME类型（可选）
 * 
 * S3 路径结构:
 * - 项目级: groups/{groupCode}/{项目名称}/客户文件|作品文件|模型文件/{filename}
 * - 客户级: groups/{groupCode}/信息文件|公司文件/{filename}
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$groupCode = $input['group_code'] ?? '';
$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? 'works';
$relPath = $input['rel_path'] ?? '';
$filename = $input['filename'] ?? '';
$filesize = (int)($input['filesize'] ?? 0);
$mimeType = $input['mime_type'] ?? 'application/octet-stream';

if (!$groupCode || !$filename || !$filesize) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取项目名称和客户信息（如果有 project_id）
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
            // 使用项目名称作为文件夹名，如果为空则使用项目编号
            $projectName = $project['project_name'] ?: $project['project_code'];
            // 清理项目名称中的特殊字符
            $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
            
            // 如果前端传的 groupCode 像 "P123" 这样（客户group_code为空时的后备值），则用客户信息替换
            if (preg_match('/^P\d+$/', $groupCode)) {
                $betterGroupCode = $project['customer_group_code'] ?: $project['group_name'] ?: $project['customer_name'];
                if ($betterGroupCode) {
                    $groupCode = preg_replace('/[\/\\\\:*?"<>|]/', '_', $betterGroupCode);
                }
            }
        }
    }
    
    // 确定文件类型目录（中文目录名）
    switch ($assetType) {
        case 'works':
            $assetTypeDir = '作品文件';
            break;
        case 'models':
            $assetTypeDir = '模型文件';
            break;
        case 'customer':
            $assetTypeDir = '客户文件';
            break;
        case 'info':
            $assetTypeDir = '信息文件';
            break;
        case 'company':
            $assetTypeDir = '公司文件';
            break;
        default:
            $assetTypeDir = '作品文件';
            break;
    }
    
    // 构建存储键（使用项目名称和中文目录名）
    // 项目级文件: groups/{groupCode}/{项目名称}/作品文件/{filename}
    // 客户级文件: groups/{groupCode}/信息文件/{filename}
    if ($projectName && in_array($assetType, ['works', 'models', 'customer'])) {
        $storageKey = "groups/{$groupCode}/{$projectName}/{$assetTypeDir}/" . ltrim($relPath, '/');
    } else {
        $storageKey = "groups/{$groupCode}/{$assetTypeDir}/" . ltrim($relPath, '/');
    }

    // 使用 MultipartUploadService 初始化分片上传
    $uploadService = new MultipartUploadService();
    $result = $uploadService->initiate($storageKey, $mimeType);
    
    // 分片大小 50MB
    $partSize = 50 * 1024 * 1024;
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

} catch (Exception $e) {
    error_log('[API] desktop_upload_init 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
