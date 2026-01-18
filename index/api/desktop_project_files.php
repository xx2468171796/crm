<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 项目文件列表 API
 * 
 * GET /api/desktop_project_files.php?project_id=123
 * 
 * 返回项目的所有文件（按分类组织）
 * - 客户文件：从 customer_files 表读取（后台上传）
 * - 作品文件/模型文件：从 S3 groups/ 路径读取（桌面端上传）
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

/**
 * 将平铺的文件列表构建为目录树结构
 */
function buildFileTree(array $files): array {
    $root = ['name' => '', 'type' => 'folder', 'children' => []];
    
    foreach ($files as $file) {
        $path = $file['relative_path'] ?? $file['filename'];
        $parts = explode('/', $path);
        $current = &$root;
        
        // 遍历路径的每一部分
        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            $isLast = ($i === count($parts) - 1);
            
            if ($isLast) {
                // 最后一部分是文件
                $current['children'][] = [
                    'name' => $part,
                    'type' => 'file',
                    'path' => $path,
                    'file' => $file,
                ];
            } else {
                // 中间部分是文件夹，查找或创建
                $found = false;
                foreach ($current['children'] as &$child) {
                    if ($child['type'] === 'folder' && $child['name'] === $part) {
                        $current = &$child;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $newFolder = [
                        'name' => $part,
                        'type' => 'folder',
                        'path' => implode('/', array_slice($parts, 0, $i + 1)),
                        'children' => [],
                    ];
                    $current['children'][] = &$newFolder;
                    $current = &$newFolder;
                }
            }
        }
        unset($current);
    }
    
    return $root['children'];
}

$user = desktop_auth_require();

$projectId = $_GET['project_id'] ?? '';

if (!$projectId) {
    echo json_encode(['success' => false, 'error' => '缺少 project_id 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取项目和客户信息
    $project = Db::queryOne("
        SELECT p.id, p.project_name, p.project_code, p.customer_id, 
               c.id as cust_id, c.group_code, c.customer_group as customer_group_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ", [(int)$projectId]);
    
    if (!$project) {
        echo json_encode(['success' => false, 'error' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $customerId = (int)($project['customer_id'] ?? 0);
    $groupCode = $project['group_code'] ?? '';
    $groupName = $project['customer_group_name'] ?? '';
    
    $result = [
        '客户文件' => ['files' => [], 'count' => 0],
        '作品文件' => ['files' => [], 'count' => 0],
        '模型文件' => ['files' => [], 'count' => 0],
    ];
    
    // ========== 所有文件统一从 S3 groups/{groupCode}/{项目名称}/ 路径读取 ==========
    if ($groupCode) {
        $config = storage_config();
        $s3Config = $config['s3'] ?? [];
        $prefix = trim($s3Config['prefix'] ?? '', '/');
        
        // 获取项目名称用于构建路径
        $projectName = $project['project_name'] ?: $project['project_code'];
        // 清理项目名称中的特殊字符
        $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
        
        $s3 = new S3Service();
        $uploadService = new MultipartUploadService();
        
        foreach (['客户文件', '作品文件', '模型文件'] as $category) {
            // 新路径结构: groups/{groupCode}/{项目名称}/客户文件/
            $searchPrefix = "groups/{$groupCode}/{$projectName}/{$category}/";
            if ($prefix) {
                $searchPrefix = $prefix . '/' . $searchPrefix;
            }
            
            try {
                $files = $s3->listObjects($searchPrefix, '');
                
                // 为每个文件生成下载 URL
                $formattedFiles = [];
                foreach ($files as $file) {
                    $downloadUrl = '';
                    $fullKey = (string)($file['storage_key'] ?? $file['Key'] ?? '');
                    if ($fullKey === '') {
                        continue;
                    }

                    $storageKeyNoPrefix = $fullKey;
                    if ($prefix && strpos($storageKeyNoPrefix, $prefix . '/') === 0) {
                        $storageKeyNoPrefix = substr($storageKeyNoPrefix, strlen($prefix) + 1);
                    }
                    try {
                        $downloadUrl = $uploadService->getDownloadPresignedUrl($storageKeyNoPrefix, 3600);
                    } catch (Exception $e) {
                        error_log("[desktop_project_files] 生成下载URL失败: " . $e->getMessage());
                    }
                    $filename = $file['filename'] ?? basename($storageKeyNoPrefix);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                    $thumbnailUrl = '';
                    
                    // 为图片生成缩略图URL（使用下载URL作为缩略图）
                    if (in_array($ext, $imageExts) && $downloadUrl) {
                        $thumbnailUrl = $downloadUrl;
                    }
                    
                    // 计算相对路径（去掉category前缀）
                    $relativePath = $filename;
                    $categoryPrefix = "groups/{$groupCode}/{$projectName}/{$category}/";
                    if (strpos($storageKeyNoPrefix, $categoryPrefix) === 0) {
                        $relativePath = substr($storageKeyNoPrefix, strlen($categoryPrefix));
                    } elseif (strpos($storageKeyNoPrefix, "{$category}/") !== false) {
                        $relativePath = substr($storageKeyNoPrefix, strpos($storageKeyNoPrefix, "{$category}/") + strlen("{$category}/"));
                    }
                    
                    $formattedFiles[] = [
                        'filename' => $filename,
                        'relative_path' => $relativePath,
                        'file_size' => (int)($file['size'] ?? 0),
                        'storage_key' => $storageKeyNoPrefix,
                        'download_url' => $downloadUrl,
                        'thumbnail_url' => $thumbnailUrl,
                        'last_modified' => $file['modified_at'] ?? null,
                    ];
                }
                
                // 构建目录树
                $tree = buildFileTree($formattedFiles);
                
                $result[$category] = [
                    'files' => $formattedFiles,
                    'tree' => $tree,
                    'count' => count($formattedFiles)
                ];
            } catch (Exception $e) {
                error_log("[desktop_project_files] S3 listObjects 失败 ({$category}): " . $e->getMessage());
                $result[$category] = [
                    'files' => [],
                    'count' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'categories' => $result,
            'project' => $project
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_project_files 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
