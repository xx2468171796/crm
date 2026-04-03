<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户门户 - 文件夹打包下载 API
 * 
 * POST /api/portal_download_folder.php
 * {
 *   "project_id": 123,
 *   "folder_path": "作品文件/folder1",
 *   "file_keys": ["key1", "key2"] // 可选，指定文件列表
 * }
 * 
 * 返回打包后的ZIP下载链接
 */

require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../services/S3Service.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

// 门户不需要登录，但需要项目访问权限验证
$input = json_decode(file_get_contents('php://input'), true);

$projectId = $input['project_id'] ?? '';
$folderPath = $input['folder_path'] ?? '';
$fileKeys = $input['file_keys'] ?? [];

if (!$projectId) {
    echo json_encode(['success' => false, 'error' => '缺少 project_id 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Db::getInstance();
    
    // 获取项目信息
    $stmt = $pdo->prepare("SELECT p.*, g.group_code FROM projects p LEFT JOIN `groups` g ON p.group_id = g.id WHERE p.id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        echo json_encode(['success' => false, 'error' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $groupCode = $project['group_code'] ?? '';
    $projectName = $project['project_name'] ?: $project['project_code'];
    $projectName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $projectName);
    
    $config = storage_config();
    $s3Config = $config['s3'] ?? [];
    $prefix = trim($s3Config['prefix'] ?? '', '/');
    
    $s3 = new S3Service();
    $uploadService = new MultipartUploadService();
    
    // 收集要打包的文件
    $filesToZip = [];
    
    if (!empty($fileKeys)) {
        // 指定了文件列表
        foreach ($fileKeys as $key) {
            $filesToZip[] = [
                'storage_key' => $key,
                'relative_path' => basename($key),
            ];
        }
    } elseif ($folderPath) {
        // 按文件夹路径获取
        $searchPrefix = "groups/{$groupCode}/{$projectName}/{$folderPath}/";
        if ($prefix) {
            $searchPrefix = $prefix . '/' . $searchPrefix;
        }
        
        $files = $s3->listObjects($searchPrefix, '');
        foreach ($files as $file) {
            $fullKey = (string)($file['storage_key'] ?? $file['Key'] ?? '');
            if ($fullKey === '') continue;
            
            $storageKeyNoPrefix = $fullKey;
            if ($prefix && strpos($storageKeyNoPrefix, $prefix . '/') === 0) {
                $storageKeyNoPrefix = substr($storageKeyNoPrefix, strlen($prefix) + 1);
            }
            
            // 计算相对路径
            $relativePath = $file['filename'] ?? basename($storageKeyNoPrefix);
            $folderPrefix = "groups/{$groupCode}/{$projectName}/{$folderPath}/";
            if (strpos($storageKeyNoPrefix, $folderPrefix) === 0) {
                $relativePath = substr($storageKeyNoPrefix, strlen($folderPrefix));
            }
            
            $filesToZip[] = [
                'storage_key' => $storageKeyNoPrefix,
                'relative_path' => $relativePath,
            ];
        }
    } else {
        echo json_encode(['success' => false, 'error' => '缺少 folder_path 或 file_keys 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($filesToZip)) {
        echo json_encode(['success' => false, 'error' => '没有找到要打包的文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 创建临时ZIP文件
    $zipFilename = 'download_' . time() . '_' . uniqid() . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new Exception('无法创建ZIP文件');
    }
    
    // 添加文件到ZIP
    foreach ($filesToZip as $fileInfo) {
        try {
            // 获取文件内容
            $presignedUrl = $uploadService->getDownloadPresignedUrl($fileInfo['storage_key'], 300);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $presignedUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $content) {
                $zip->addFromString($fileInfo['relative_path'], $content);
            }
        } catch (Exception $e) {
            error_log('[portal_download_folder] 添加文件失败: ' . $fileInfo['storage_key'] . ' - ' . $e->getMessage());
        }
    }
    
    $zip->close();
    
    // 检查ZIP文件是否创建成功
    if (!file_exists($zipPath) || filesize($zipPath) === 0) {
        throw new Exception('ZIP文件创建失败');
    }
    
    // 上传ZIP到S3
    $zipStorageKey = "temp/downloads/{$zipFilename}";
    $uploadService->uploadFile($zipPath, $zipStorageKey);
    
    // 生成下载URL
    $downloadUrl = $uploadService->getDownloadPresignedUrl($zipStorageKey, 3600);
    
    // 清理临时文件
    @unlink($zipPath);
    
    // 返回下载链接
    $folderName = $folderPath ? basename($folderPath) : 'files';
    echo json_encode([
        'success' => true,
        'data' => [
            'download_url' => $downloadUrl,
            'filename' => $folderName . '.zip',
            'file_count' => count($filesToZip),
            'expires_in' => 3600,
        ],
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] portal_download_folder 错误: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '打包下载失败: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
