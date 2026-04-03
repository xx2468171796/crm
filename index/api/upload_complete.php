<?php
/**
 * 统一上传完成 API（三端共用）
 * POST /api/upload_complete.php
 * 
 * 参数:
 * - upload_id: 上传ID（必填）
 * - storage_key: 存储键（必填）
 * - parts: 分片列表（必填）
 * - project_id: 项目ID（可选，用于落库）
 * - asset_type: 文件类型（可选）
 * - filename: 文件名（可选）
 * - filesize: 文件大小（可选）
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/unified_auth.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

// 统一认证
$authResult = unified_auth_require();
$user = $authResult['user'];
$authType = $authResult['type'];

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = $input['upload_id'] ?? '';
$storageKey = $input['storage_key'] ?? '';
$parts = $input['parts'] ?? [];
$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? '';
$filename = $input['filename'] ?? '';
$filesize = (int)($input['filesize'] ?? 0);
$relPath = $input['rel_path'] ?? '';

if (!$uploadId || !$storageKey || empty($parts)) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 从 storage_key 解析信息（兼容旧版本）
if ($projectId <= 0 || !$assetType || !$filename) {
    $keyParts = explode('/', $storageKey);
    if (count($keyParts) >= 5 && $keyParts[0] === 'groups') {
        $groupCode = $keyParts[1] ?? '';
        $projectName = $keyParts[2] ?? '';
        $categoryFolder = $keyParts[3] ?? '';
        $parsedFilename = $keyParts[count($keyParts) - 1] ?? '';
        
        // 解析文件分类
        if (!$assetType) {
            $assetType = match($categoryFolder) {
                '客户文件' => 'customer',
                '模型文件' => 'models',
                '信息文件' => 'info',
                '公司文件' => 'company',
                default => 'works',
            };
        }
        
        if (!$filename) {
            $filename = $parsedFilename;
        }
        
        // 通过 group_code 查找 project_id
        if ($projectId <= 0 && $groupCode) {
            try {
                $customer = Db::queryOne('SELECT id FROM customers WHERE group_code = ? LIMIT 1', [$groupCode]);
                if ($customer) {
                    $project = Db::queryOne(
                        'SELECT id FROM projects WHERE customer_id = ? AND project_name = ? AND deleted_at IS NULL LIMIT 1',
                        [$customer['id'], $projectName]
                    );
                    if ($project) {
                        $projectId = (int)$project['id'];
                    }
                }
            } catch (Exception $e) {
                // 忽略查询错误
            }
        }
    }
}

try {
    $service = new FolderUploadService();
    
    // 完成分片上传
    $result = $service->completeUpload($storageKey, $uploadId, $parts);
    
    // 记录到 deliverables
    $deliverableId = 0;
    $userId = $user['type'] === 'customer' ? 0 : (int)($user['id'] ?? 0);
    
    if ($projectId > 0) {
        try {
            $deliverableId = $service->recordDeliverable(
                $projectId,
                $storageKey,
                $filename,
                $filesize,
                $assetType,
                $userId,
                $relPath
            );
        } catch (Exception $e) {
            error_log('[API] upload_complete 落库失败: ' . $e->getMessage());
        }
    }
    
    // 记录上传日志
    try {
        $service->logUpload($userId, $projectId, $filename, $assetType, $filesize);
    } catch (Exception $e) {
        error_log('[API] upload_complete 日志失败: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'etag' => $result['etag'] ?? '',
            'location' => $result['location'] ?? '',
            'storage_key' => $storageKey,
            'deliverable_id' => $deliverableId,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] upload_complete 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
