<?php
/**
 * 网盘分享文件下载
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$token = trim($_GET['token'] ?? '');
$fileId = intval($_GET['file_id'] ?? 0);
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';

if (empty($token) || !$fileId) {
    http_response_code(400);
    die('参数错误');
}

try {
    $pdo = Db::pdo();
    
    // 验证分享链接
    $stmt = $pdo->prepare("
        SELECT dsl.*, pd.user_id as drive_owner_id
        FROM drive_share_links dsl
        JOIN personal_drives pd ON pd.id = dsl.drive_id
        WHERE dsl.token = ? AND dsl.status = 'active'
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        http_response_code(404);
        die('分享链接不存在或已失效');
    }
    
    // 检查过期
    if (strtotime($link['expires_at']) < time()) {
        die('分享链接已过期');
    }
    
    // 检查访问次数
    if ($link['max_visits'] && $link['visit_count'] >= $link['max_visits']) {
        die('访问次数已达上限');
    }
    
    // 密码验证
    if ($link['password']) {
        session_start();
        if (!isset($_SESSION['drive_share_verified_' . $link['id']])) {
            die('请先验证密码');
        }
    }
    
    // 获取文件信息
    $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
    $stmt->execute([$fileId, $link['drive_id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        die('文件不存在');
    }
    
    // 验证文件是否在分享范围内
    if ($link['file_id']) {
        // 单文件分享，必须是指定的文件
        if ($file['id'] != $link['file_id']) {
            http_response_code(403);
            die('无权访问此文件');
        }
    } else {
        // 文件夹分享，文件必须在分享的文件夹路径下
        if ($file['folder_path'] !== $link['folder_path'] && strpos($file['folder_path'], rtrim($link['folder_path'], '/') . '/') !== 0) {
            http_response_code(403);
            die('无权访问此文件');
        }
    }
    
    // 从存储获取文件并输出（使用工厂函数，兼容 S3 和 local 存储）
    $storage = storage_provider();
    
    $stream = null;
    $fromCache = false;
    
    try {
        $stream = $storage->readStream($file['storage_key']);
    } catch (Exception $storageError) {
        // 存储文件不存在，尝试从本地上传缓存读取（异步上传尚未完成的文件）
        $cacheDir = __DIR__ . '/../../storage/upload_cache';
        $cacheFiles = glob($cacheDir . '/*.json');
        
        foreach ($cacheFiles as $metaFile) {
            $meta = @json_decode(file_get_contents($metaFile), true);
            if ($meta && ($meta['storage_key'] ?? '') === $file['storage_key']) {
                $dataFile = str_replace('.json', '', $metaFile);
                if (file_exists($dataFile)) {
                    $stream = fopen($dataFile, 'rb');
                    $fromCache = true;
                    
                    // 后台尝试同步到存储（使用复制文件避免 putObject 删除源文件导致后续下载失败）
                    register_shutdown_function(function() use ($storage, $meta, $dataFile, $metaFile) {
                        try {
                            // 复制缓存文件用于上传，避免 putObject 删除源文件
                            $tmpCopy = $dataFile . '.uploading';
                            if (copy($dataFile, $tmpCopy)) {
                                $storage->putObject($meta['storage_key'], $tmpCopy, ['mime_type' => $meta['mime_type'] ?? 'application/octet-stream']);
                                // S3 上传成功后删除缓存文件
                                @unlink($dataFile);
                                @unlink($metaFile);
                                error_log("[DRIVE_SHARE] Auto-synced to storage: " . $meta['storage_key']);
                            }
                        } catch (Exception $e) {
                            @unlink($dataFile . '.uploading');
                            error_log("[DRIVE_SHARE] Auto-sync failed: " . $e->getMessage());
                        }
                    });
                    break;
                }
            }
        }
        
        if (!$stream) {
            throw $storageError; // 重新抛出原始错误
        }
    }
    
    if (!$stream) {
        throw new Exception('无法获取文件流');
    }
    
    // 设置响应头
    $filename = $file['original_filename'] ?: $file['filename'];
    $encodedFilename = rawurlencode($filename);
    $mimeType = $file['file_type'] ?: 'application/octet-stream';
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $file['file_size']);
    
    if ($isPreview) {
        // 预览模式：inline 显示，允许浏览器直接渲染
        header('Content-Disposition: inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Cache-Control: public, max-age=3600');
    } else {
        // 下载模式：attachment 强制下载
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Cache-Control: no-cache');
    }
    
    // 输出文件内容
    fpassthru($stream);
    fclose($stream);
    
} catch (Exception $e) {
    error_log('Drive share download error: ' . $e->getMessage() . ' | storage_key: ' . ($file['storage_key'] ?? 'unknown'));
    http_response_code(500);
    
    // 提供更详细的错误信息
    $errorMsg = '下载失败';
    if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'NoSuchKey') !== false) {
        $errorMsg = '文件不存在于存储服务器，可能已被删除或未成功上传';
    } elseif (strpos($e->getMessage(), '403') !== false) {
        $errorMsg = '存储服务器访问被拒绝';
    } elseif (strpos($e->getMessage(), 'timeout') !== false || strpos($e->getMessage(), 'Timeout') !== false) {
        $errorMsg = '存储服务器连接超时，请稍后重试';
    }
    
    die($errorMsg);
}
