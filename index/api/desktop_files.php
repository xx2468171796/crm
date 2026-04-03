<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 远程文件列表 API
 * 
 * GET /api/desktop_files.php?group_code=Q2025122001&asset_type=works&path=
 * 
 * 参数：
 * - group_code: 群码
 * - asset_type: works/models
 * - path: 相对路径（可选，用于获取子目录）
 * - project_id: 项目 ID（可选）
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';

$user = desktop_auth_require();

$groupCode = $_GET['group_code'] ?? '';
$projectId = (int)($_GET['project_id'] ?? 0);
$assetType = $_GET['asset_type'] ?? 'works';
$path = $_GET['path'] ?? '';

if (!$groupCode && $projectId <= 0) {
    echo json_encode(['success' => false, 'error' => '缺少 group_code 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = storage_config();
    if (($config['type'] ?? 'local') !== 's3') {
        throw new RuntimeException('仅支持 S3 存储');
    }

    $s3Config = $config['s3'] ?? [];
    $prefix = trim($s3Config['prefix'] ?? '', '/');
    
    $folderName = $groupCode;
    $projectName = '';

    if ($projectId > 0) {
        $project = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );

        if (!$project) {
            echo json_encode(['success' => false, 'error' => '项目不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $folderName = $project['group_code'] ?: $folderName;
        $projectName = $project['project_name'] ?: $project['project_code'];
        $projectName = preg_replace('/[\/\\:*?"<>|]/', '_', $projectName);
    }
    
    // 构建搜索前缀
    $assetTypeDir = $assetType === 'works' ? '作品文件' : '模型文件';
    if ($projectName) {
        $searchPrefix = "groups/{$folderName}/{$projectName}/{$assetTypeDir}/";
    } else {
        $searchPrefix = "groups/{$folderName}/{$assetTypeDir}/";
    }
    if ($path) {
        $searchPrefix .= ltrim($path, '/') . '/';
    }
    if ($prefix) {
        $searchPrefix = $prefix . '/' . $searchPrefix;
    }

    // 使用 S3Service 列出文件
    $s3 = new S3Service();
    $files = $s3->listObjects($searchPrefix, '/');
    
    echo json_encode([
        'success' => true,
        'data' => [
            'files' => $files,
            'total' => count($files),
            'prefix' => $searchPrefix
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
