<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 群资源列表 API
 * GET /api/desktop_group_resources.php
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';

$user = desktop_auth_require();

$groupCode = $_GET['group_code'] ?? '';
$assetType = $_GET['asset_type'] ?? 'works';
$prefix = $_GET['prefix'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(500, max(10, (int)($_GET['per_page'] ?? 100)));

if (!$groupCode) {
    echo json_encode(['success' => false, 'error' => '缺少 group_code 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = storage_config();
    $s3Config = $config['s3'] ?? [];
    $storagePrefix = trim($s3Config['prefix'] ?? '', '/');
    
    // 统一使用 groupCode 作为文件夹名称（不再拼接群名称）
    $folderName = $groupCode;
    
    // 构建搜索前缀
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
        default:
            $assetTypeDir = '作品文件';
            break;
    }
    
    $searchPrefix = "groups/{$folderName}/{$assetTypeDir}/";
    if ($prefix) {
        $searchPrefix .= ltrim($prefix, '/');
        $len = strlen($searchPrefix);
        if ($len === 0 || substr($searchPrefix, $len - 1) !== '/') {
            $searchPrefix .= '/';
        }
    }
    if ($storagePrefix) {
        $searchPrefix = $storagePrefix . '/' . $searchPrefix;
    }

    // 使用 S3Service 列出文件
    $s3 = new S3Service();
    $allFiles = $s3->listObjects($searchPrefix, '/');
    
    // 分页
    $total = count($allFiles);
    $offset = ($page - 1) * $perPage;
    $items = array_slice($allFiles, $offset, $perPage);

    echo json_encode([
        'success' => true,
        'data' => [
            'group_code' => $groupCode,
            'asset_type' => $assetType,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
