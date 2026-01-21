<?php
/**
 * 客户门户 - 文件分享独立页面
 * 访问分享链接后显示文件预览/下载页面
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$shareToken = trim($_GET['s'] ?? '');

if (empty($shareToken)) {
    http_response_code(404);
    showErrorPage('链接无效', '分享链接不存在或已失效');
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 查询分享记录
    $stmt = $pdo->prepare("
        SELECT ds.*, d.deliverable_name, d.file_path, d.file_size, d.file_category,
               p.project_name, p.project_code
        FROM deliverable_shares ds
        INNER JOIN deliverables d ON d.id = ds.deliverable_id
        INNER JOIN projects p ON p.id = d.project_id
        WHERE ds.share_token = ?
        AND ds.is_active = 1
        AND d.deleted_at IS NULL
        AND d.approval_status = 'approved'
        AND d.visibility_level = 'client'
        LIMIT 1
    ");
    $stmt->execute([$shareToken]);
    $share = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$share) {
        showErrorPage('链接无效', '分享链接不存在或文件已被删除');
        exit;
    }
    
    // 检查是否过期
    if (!empty($share['expire_at'])) {
        $expireTime = strtotime($share['expire_at']);
        if ($expireTime !== false && $expireTime < time()) {
            showErrorPage('链接已过期', '此分享链接已过期，请联系分享者获取新链接');
            exit;
        }
    }
    
    // 检查下载次数限制
    if (!empty($share['max_downloads']) && $share['download_count'] >= $share['max_downloads']) {
        showErrorPage('下载次数已用完', '此分享链接的下载次数已达上限');
        exit;
    }
    
    // 更新浏览次数
    $pdo->prepare("UPDATE deliverable_shares SET view_count = view_count + 1 WHERE id = ?")->execute([$share['id']]);
    
    // 获取文件URL（原始S3 URL 和 代理URL）
    $storageConfig = storage_config();
    $s3Config = $storageConfig['s3'] ?? [];
    $s3Endpoint = $s3Config['public_url'] 
        ?? rtrim($s3Config['endpoint'] ?? '', '/') . '/' . ($s3Config['bucket'] ?? '') . '/';
    $fileUrl = $s3Endpoint . $share['file_path'];
    
    // 代理URL（解决HTTP/HTTPS混合内容问题）
    $proxyUrl = '/api/portal_share_proxy.php?s=' . urlencode($shareToken) . '&url=' . urlencode($fileUrl);
    $downloadUrl = $proxyUrl . '&download=' . urlencode($share['deliverable_name']);
    
    // 判断文件类型
    $fileName = $share['deliverable_name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']);
    $isPdf = $ext === 'pdf';
    $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'avi']);
    $canPreview = $isImage || $isPdf || $isVideo;
    
    // 格式化文件大小
    $fileSize = (int)$share['file_size'];
    if ($fileSize >= 1073741824) {
        $fileSizeStr = number_format($fileSize / 1073741824, 2) . ' GB';
    } elseif ($fileSize >= 1048576) {
        $fileSizeStr = number_format($fileSize / 1048576, 2) . ' MB';
    } elseif ($fileSize >= 1024) {
        $fileSizeStr = number_format($fileSize / 1024, 2) . ' KB';
    } else {
        $fileSizeStr = $fileSize . ' B';
    }
    
    // 过期时间显示
    $expireStr = '永久有效';
    if (!empty($share['expire_at'])) {
        $expireTime = strtotime($share['expire_at']);
        if ($expireTime !== false) {
            $remaining = $expireTime - time();
            if ($remaining > 86400) {
                $expireStr = ceil($remaining / 86400) . ' 天后过期';
            } elseif ($remaining > 3600) {
                $expireStr = ceil($remaining / 3600) . ' 小时后过期';
            } else {
                $expireStr = ceil($remaining / 60) . ' 分钟后过期';
            }
        }
    }
    
    // 下载次数显示
    $downloadStr = '';
    if (!empty($share['max_downloads'])) {
        $remaining = $share['max_downloads'] - $share['download_count'];
        $downloadStr = "剩余 {$remaining} 次下载";
    }
    
} catch (Exception $e) {
    showErrorPage('服务器错误', $e->getMessage());
    exit;
}

function showErrorPage($title, $message) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            .error-card { background: white; border-radius: 16px; padding: 48px; text-align: center; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
            .error-icon { width: 80px; height: 80px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; color: #ef4444; font-size: 40px; }
            .error-title { font-size: 24px; font-weight: 600; color: #1f2937; margin-bottom: 12px; }
            .error-message { color: #6b7280; line-height: 1.6; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
            <p class="error-message"><?= htmlspecialchars($message) ?></p>
        </div>
    </body>
    </html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fileName) ?> - 文件分享</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(160deg, #e0f2fe 0%, #f0f9ff 30%, #faf5ff 60%, #fdf4ff 100%);
            min-height: 100vh; 
            padding: 40px 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        .share-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .card-header {
            padding: 32px 32px 24px;
            text-align: center;
        }
        .project-info {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #9ca3af;
            font-size: 12px;
            margin-bottom: 16px;
            background: #f3f4f6;
            padding: 6px 12px;
            border-radius: 20px;
        }
        .file-name {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
            word-break: break-all;
            line-height: 1.4;
        }
        .file-meta {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #6b7280;
            font-size: 13px;
        }
        .meta-item i { color: #9ca3af; font-size: 14px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        
        .preview-area {
            padding: 24px 32px;
            background: #f8fafc;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .preview-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .preview-placeholder {
            text-align: center;
            color: #d1d5db;
            padding: 40px 0;
        }
        .preview-placeholder i {
            font-size: 64px;
            margin-bottom: 12px;
            display: block;
        }
        .preview-placeholder p {
            font-size: 14px;
            color: #9ca3af;
        }
        
        .card-footer {
            padding: 24px 32px 32px;
            text-align: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 160px;
        }
        .btn-primary {
            background: #0891b2;
            color: white;
        }
        .btn-primary:hover { background: #0e7490; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(8,145,178,0.3); }
        .btn-outline {
            background: white;
            color: #0891b2;
            border: 2px solid #e5e7eb;
            margin-left: 12px;
        }
        .btn-outline:hover { border-color: #0891b2; background: #f0fdfa; }
        
        .powered-by {
            text-align: center;
            color: #9ca3af;
            font-size: 11px;
            margin-top: 24px;
        }
        
        /* 悬浮下载按钮 */
        .floating-download {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            background: #0891b2;
            color: white;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 4px 20px rgba(8,145,178,0.4);
            transition: all 0.2s;
        }
        .floating-download:hover {
            background: #0e7490;
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(8,145,178,0.5);
        }
        .floating-download i { font-size: 18px; }
        
        @media (max-width: 600px) {
            body { padding: 20px 16px; }
            .card-header { padding: 24px 20px 20px; }
            .file-name { font-size: 17px; }
            .file-meta { gap: 8px; }
            .preview-area { padding: 20px; }
            .card-footer { padding: 20px; }
            .btn { padding: 12px 24px; font-size: 14px; width: 100%; margin: 6px 0; }
            .btn-outline { margin-left: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="share-card">
            <div class="card-header">
                <div class="project-info">
                    <i class="bi bi-folder2"></i>
                    <span><?= htmlspecialchars($share['project_name']) ?></span>
                    <span>·</span>
                    <span><?= htmlspecialchars($share['project_code']) ?></span>
                </div>
                <h1 class="file-name"><?= htmlspecialchars($fileName) ?></h1>
                <div class="file-meta">
                    <span class="meta-item">
                        <i class="bi bi-file-earmark"></i>
                        <?= $fileSizeStr ?>
                    </span>
                    <span class="meta-item">
                        <i class="bi bi-clock"></i>
                        <?= $expireStr ?>
                    </span>
                    <?php if ($downloadStr): ?>
                    <span class="meta-item">
                        <i class="bi bi-download"></i>
                        <?= $downloadStr ?>
                    </span>
                    <?php endif; ?>
                    <span class="badge badge-success">
                        <i class="bi bi-check-circle"></i>
                        <?= $share['file_category'] === 'model_file' ? '模型文件' : '作品文件' ?>
                    </span>
                </div>
            </div>
            
            <div class="preview-area">
                <?php if ($isImage): ?>
                    <img src="<?= htmlspecialchars($proxyUrl) ?>" alt="<?= htmlspecialchars($fileName) ?>" class="preview-image" onerror="this.src='<?= htmlspecialchars($fileUrl) ?>'">
                <?php elseif ($isPdf): ?>
                    <iframe src="<?= htmlspecialchars($proxyUrl) ?>" style="width:100%;height:500px;border:none;border-radius:8px;"></iframe>
                <?php elseif ($isVideo): ?>
                    <video controls style="max-width:100%;max-height:500px;border-radius:8px;">
                        <source src="<?= htmlspecialchars($proxyUrl) ?>" type="video/mp4">
                        您的浏览器不支持视频播放
                    </video>
                <?php else: ?>
                    <div class="preview-placeholder">
                        <i class="bi bi-file-earmark-text"></i>
                        <p>此文件类型不支持预览</p>
                        <p style="font-size:14px;margin-top:8px;">请下载后查看</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card-footer">
                <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn btn-primary" id="downloadBtn">
                    <i class="bi bi-download"></i>
                    下载文件
                </a>
                <?php if ($canPreview): ?>
                <a href="<?= htmlspecialchars($proxyUrl) ?>" class="btn btn-outline" target="_blank">
                    <i class="bi bi-box-arrow-up-right"></i>
                    新窗口打开
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <p class="powered-by">Powered by 客户门户系统</p>
    </div>
    
    <!-- 悬浮下载按钮 -->
    <a href="<?= htmlspecialchars($downloadUrl) ?>" class="floating-download" id="floatingDownloadBtn">
        <i class="bi bi-download"></i>
        下载
    </a>
    
    <script>
        // 记录下载次数
        function recordDownload() {
            fetch('/api/portal_share_download.php?s=<?= urlencode($shareToken) ?>', { method: 'POST' });
        }
        document.getElementById('downloadBtn').addEventListener('click', recordDownload);
        document.getElementById('floatingDownloadBtn').addEventListener('click', recordDownload);
    </script>
</body>
</html>
