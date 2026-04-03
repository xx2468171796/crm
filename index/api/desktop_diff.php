<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件差异对比 API
 * POST /api/desktop_diff.php
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$groupCode = $input['group_code'] ?? '';
$assetType = $input['asset_type'] ?? 'works';
$localFiles = $input['local_files'] ?? [];

if (!$groupCode) {
    echo json_encode(['success' => false, 'error' => '缺少 group_code 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = storage_config();
    $s3Config = $config['s3'] ?? [];
    $prefix = trim($s3Config['prefix'] ?? '', '/');
    
    // 统一使用 groupCode 作为文件夹名称（不再拼接群名称）
    $folderName = $groupCode;
    
    // 构建搜索前缀
    $assetTypeDir = $assetType === 'works' ? '作品文件' : '模型文件';
    $searchPrefix = "groups/{$folderName}/{$assetTypeDir}/";
    if ($prefix) {
        $searchPrefix = $prefix . '/' . $searchPrefix;
    }

    // 获取远程文件列表
    $s3 = new S3Service();
    $remoteFiles = $s3->listObjects($searchPrefix);
    
    // 构建远程文件映射
    $remoteMap = [];
    foreach ($remoteFiles as $file) {
        $remoteMap[$file['rel_path']] = $file;
    }
    
    // 对比文件
    $toUpload = [];
    $toDownload = [];
    $conflicts = [];
    $unchanged = [];
    
    // 检查本地文件
    foreach ($localFiles as $local) {
        $relPath = $local['rel_path'];
        if (isset($remoteMap[$relPath])) {
            $remote = $remoteMap[$relPath];
            if ($local['size'] == $remote['size']) {
                $unchanged[] = $relPath;
            } else {
                $conflicts[] = ['rel_path' => $relPath, 'local' => $local, 'remote' => $remote];
            }
            unset($remoteMap[$relPath]);
        } else {
            $toUpload[] = $local;
        }
    }
    
    // 剩余的远程文件需要下载
    foreach ($remoteMap as $remote) {
        $toDownload[] = $remote;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'to_upload' => $toUpload,
            'to_download' => $toDownload,
            'conflicts' => $conflicts,
            'unchanged' => $unchanged,
            'summary' => [
                'upload_count' => count($toUpload),
                'download_count' => count($toDownload),
                'conflict_count' => count($conflicts),
                'unchanged_count' => count($unchanged)
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
