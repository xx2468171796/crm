<?php
/**
 * 网盘分享链接访问页面 - Portal风格重构版
 * 支持文件夹分享和单文件分享
 * 支持图片预览和音频播放
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

// 获取token
$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    showErrorPage('无效的分享链接', '请检查链接是否正确');
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 查询分享链接
    $stmt = $pdo->prepare("
        SELECT dsl.*, pd.user_id as drive_owner_id, u.realname as owner_name
        FROM drive_share_links dsl
        JOIN personal_drives pd ON pd.id = dsl.drive_id
        JOIN users u ON u.id = pd.user_id
        WHERE dsl.token = ? AND dsl.status = 'active'
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        showErrorPage('分享链接不存在', '链接可能已失效或被删除');
        exit;
    }
    
    // 检查是否过期
    if (strtotime($link['expires_at']) < time()) {
        $pdo->prepare("UPDATE drive_share_links SET status = 'expired' WHERE id = ?")->execute([$link['id']]);
        showErrorPage('分享链接已过期', '此链接已超过有效期');
        exit;
    }
    
    // 检查访问次数
    if ($link['max_visits'] && $link['visit_count'] >= $link['max_visits']) {
        showErrorPage('访问次数已达上限', '此链接的访问次数已用完');
        exit;
    }
    
    // 密码验证
    if ($link['password']) {
        session_start();
        $sessionKey = 'drive_share_verified_' . $link['id'];
        
        if (!isset($_SESSION[$sessionKey])) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $inputPassword = trim($_POST['password'] ?? '');
                if (password_verify($inputPassword, $link['password'])) {
                    $_SESSION[$sessionKey] = true;
                } else {
                    $error = '密码错误，请重试';
                }
            }
            
            if (!isset($_SESSION[$sessionKey])) {
                showPasswordPage($error ?? null, $link);
                exit;
            }
        }
    }
    
    // 更新访问次数
    $pdo->prepare("UPDATE drive_share_links SET visit_count = visit_count + 1 WHERE id = ?")->execute([$link['id']]);
    
    // 判断是文件分享还是文件夹分享
    if ($link['file_id']) {
        // 单文件分享
        $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
        $stmt->execute([$link['file_id'], $link['drive_id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            showErrorPage('文件不存在', '文件可能已被删除');
            exit;
        }
        
        showFileDownloadPage($file, $link);
    } else {
        // 文件夹分享
        $folderPath = $link['folder_path'];
        
        $stmt = $pdo->prepare("
            SELECT id, filename, original_filename, folder_path, storage_key, file_size, file_type, create_time
            FROM drive_files 
            WHERE drive_id = ? AND folder_path = ? AND filename != '.folder'
            ORDER BY create_time DESC
        ");
        $stmt->execute([$link['drive_id'], $folderPath]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                SUBSTRING_INDEX(SUBSTRING(folder_path, LENGTH(?)+1), '/', 1) as subfolder
            FROM drive_files 
            WHERE drive_id = ? AND folder_path LIKE ? AND folder_path != ?
        ");
        $likePath = rtrim($folderPath, '/') . '/%';
        $stmt->execute([$folderPath, $link['drive_id'], $likePath, $folderPath]);
        $subfolders = array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'subfolder'));
        
        showFolderPage($files, $subfolders, $link, $folderPath);
    }
    
} catch (Exception $e) {
    error_log('Drive share error: ' . $e->getMessage());
    showErrorPage('服务器错误', '请稍后重试');
    exit;
}

// 输出页面头部
function renderPageHeader($title = '文件分享') {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0891b2">
    <title><?= htmlspecialchars($title) ?> - 安科帝設計空間</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/portal-theme.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .share-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .share-card {
            max-width: 600px;
            width: 100%;
            animation: fadeInUp 0.4s ease;
        }
        
        .share-header {
            background: var(--portal-gradient-bg);
            color: white;
            padding: 28px 24px;
            border-radius: var(--portal-radius-lg) var(--portal-radius-lg) 0 0;
            text-align: center;
        }
        
        .share-header .brand {
            font-size: 12px;
            font-weight: 500;
            opacity: 0.8;
            letter-spacing: 2px;
            margin-bottom: 12px;
            text-transform: uppercase;
        }
        
        .share-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .share-header .subtitle {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 8px;
        }
        
        .share-body {
            background: var(--portal-card-solid);
            padding: 24px;
            border-radius: 0 0 var(--portal-radius-lg) var(--portal-radius-lg);
            box-shadow: var(--portal-shadow-lg);
        }
        
        /* 文件列表样式 */
        .file-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            background: var(--portal-bg);
            border-radius: var(--portal-radius);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .file-item:hover {
            background: rgba(99, 102, 241, 0.08);
            transform: translateX(4px);
        }
        
        .file-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--portal-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        
        .file-icon.image { background: rgba(16, 185, 129, 0.12); color: var(--portal-success); }
        .file-icon.audio { background: rgba(99, 102, 241, 0.12); color: var(--portal-primary); }
        .file-icon.video { background: rgba(239, 68, 68, 0.12); color: var(--portal-error); }
        .file-icon.pdf { background: rgba(239, 68, 68, 0.12); color: var(--portal-error); }
        .file-icon.doc { background: rgba(59, 130, 246, 0.12); color: var(--portal-info); }
        .file-icon.folder { background: rgba(245, 158, 11, 0.12); color: var(--portal-warning); }
        .file-icon.default { background: rgba(100, 116, 139, 0.12); color: var(--portal-text-secondary); }
        
        .file-info {
            flex: 1;
            min-width: 0;
        }
        
        .file-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--portal-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .file-meta {
            font-size: 12px;
            color: var(--portal-text-muted);
            margin-top: 2px;
        }
        
        .file-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: var(--portal-radius);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 16px;
        }
        
        .btn-preview {
            background: rgba(99, 102, 241, 0.1);
            color: var(--portal-primary);
        }
        
        .btn-preview:hover {
            background: var(--portal-primary);
            color: white;
        }
        
        .btn-download {
            background: rgba(16, 185, 129, 0.1);
            color: var(--portal-success);
        }
        
        .btn-download:hover {
            background: var(--portal-success);
            color: white;
        }
        
        /* 单文件大卡片 */
        .single-file {
            text-align: center;
            padding: 20px 0;
        }
        
        .single-file .file-icon-large {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: var(--portal-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        
        .single-file .file-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            word-break: break-all;
        }
        
        .single-file .file-size {
            font-size: 14px;
            color: var(--portal-text-muted);
            margin-bottom: 24px;
        }
        
        /* 预览容器 */
        .preview-container {
            margin-bottom: 24px;
            border-radius: var(--portal-radius);
            overflow: hidden;
            background: #000;
        }
        
        .preview-container img {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
            display: block;
        }
        
        .audio-player {
            width: 100%;
            margin-bottom: 24px;
        }
        
        .audio-player audio {
            width: 100%;
            border-radius: var(--portal-radius);
        }
        
        /* 分享信息 */
        .share-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--portal-border);
            margin-top: 20px;
        }
        
        .share-info-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--portal-text-muted);
        }
        
        .share-info-item i {
            font-size: 14px;
        }
        
        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--portal-text-muted);
            opacity: 0.5;
            margin-bottom: 16px;
        }
        
        .empty-state p {
            color: var(--portal-text-secondary);
            margin: 0;
        }
        
        /* 图片预览弹窗 */
        .preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .preview-modal.active {
            display: flex;
        }
        
        .preview-modal img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border-radius: var(--portal-radius);
        }
        
        .preview-modal .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* 响应式 */
        @media (max-width: 640px) {
            .share-container {
                padding: 12px;
                align-items: flex-start;
                padding-top: 40px;
            }
            
            .share-header {
                padding: 20px 16px;
            }
            
            .share-header h1 {
                font-size: 1.1rem;
            }
            
            .share-body {
                padding: 16px;
            }
            
            .file-item {
                padding: 12px;
            }
            
            .file-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
            
            .file-name {
                font-size: 13px;
            }
            
            .btn-action {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            .share-info {
                flex-direction: column;
                gap: 8px;
                align-items: center;
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body class="portal-page">
    <div class="portal-bg-decoration"></div>
    <div class="portal-bg-decoration-extra"></div>
<?php
}

// 错误页面
function showErrorPage($title, $message) {
    renderPageHeader('访问错误');
?>
    <div class="share-container">
        <div class="share-card">
            <div class="share-header" style="background: linear-gradient(135deg, #ef4444 0%, #f97316 100%);">
                <div class="brand">ANKOTTI DESIGN</div>
                <h1><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($title) ?></h1>
            </div>
            <div class="share-body">
                <div class="empty-state">
                    <i class="bi bi-emoji-frown"></i>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
}

// 密码输入页面
function showPasswordPage($error = null, $link = null) {
    renderPageHeader('访问验证');
?>
    <div class="share-container">
        <div class="share-card" style="max-width: 420px;">
            <div class="share-header">
                <div class="brand">ANKOTTI DESIGN</div>
                <h1><i class="bi bi-shield-lock"></i> 访问验证</h1>
                <div class="subtitle">此分享已设置密码保护</div>
            </div>
            <div class="share-body">
                <?php if ($error): ?>
                    <div style="background: rgba(239, 68, 68, 0.1); color: var(--portal-error); padding: 12px 16px; border-radius: var(--portal-radius); margin-bottom: 20px; font-size: 14px;">
                        <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="post">
                    <div class="portal-form-group">
                        <label class="portal-label">请输入访问密码</label>
                        <input type="password" name="password" class="portal-input" placeholder="输入密码" required autofocus>
                    </div>
                    <button type="submit" class="portal-btn portal-btn-primary portal-btn-block portal-btn-lg">
                        <i class="bi bi-unlock"></i> 验证访问
                    </button>
                </form>
                
                <?php if ($link): ?>
                <div class="share-info">
                    <div class="share-info-item">
                        <i class="bi bi-person"></i>
                        <span>分享者: <?= htmlspecialchars($link['owner_name']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
}

// 单文件下载页面
function showFileDownloadPage($file, $link) {
    $fileSize = formatFileSize($file['file_size']);
    $fileType = getFileType($file['file_type']);
    $isImage = $fileType === 'image';
    $isAudio = $fileType === 'audio';
    $downloadUrl = 'drive_share_download.php?token=' . htmlspecialchars($link['token']) . '&file_id=' . $file['id'];
    $previewUrl = $downloadUrl . '&preview=1';
    
    renderPageHeader($file['filename']);
?>
    <div class="share-container">
        <div class="share-card">
            <div class="share-header">
                <div class="brand">ANKOTTI DESIGN</div>
                <h1><i class="bi bi-cloud-arrow-down"></i> 文件分享</h1>
                <div class="subtitle">来自 <?= htmlspecialchars($link['owner_name']) ?> 的分享</div>
            </div>
            <div class="share-body">
                <div class="single-file">
                    <?php if ($isImage): ?>
                        <div class="preview-container">
                            <img src="<?= $previewUrl ?>" alt="<?= htmlspecialchars($file['filename']) ?>" onclick="openPreview(this.src)">
                        </div>
                    <?php elseif ($isAudio): ?>
                        <div class="audio-player">
                            <audio controls preload="metadata">
                                <source src="<?= $previewUrl ?>" type="<?= htmlspecialchars($file['file_type']) ?>">
                                您的浏览器不支持音频播放
                            </audio>
                        </div>
                    <?php else: ?>
                        <div class="file-icon-large <?= $fileType ?>">
                            <?= getFileIconHtml($file['file_type']) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="file-name"><?= htmlspecialchars($file['filename']) ?></div>
                    <div class="file-size"><?= $fileSize ?></div>
                    
                    <a href="<?= $downloadUrl ?>" class="portal-btn portal-btn-primary portal-btn-lg portal-btn-block">
                        <i class="bi bi-download"></i> 下载文件
                    </a>
                </div>
                
                <div class="share-info">
                    <div class="share-info-item">
                        <i class="bi bi-calendar3"></i>
                        <span>有效期至: <?= date('Y-m-d H:i', strtotime($link['expires_at'])) ?></span>
                    </div>
                    <?php if ($link['max_visits']): ?>
                    <div class="share-info-item">
                        <i class="bi bi-eye"></i>
                        <span>剩余访问: <?= max(0, $link['max_visits'] - $link['visit_count']) ?> 次</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 图片预览弹窗 -->
    <div class="preview-modal" id="previewModal" onclick="closePreview()">
        <button class="close-btn" onclick="closePreview()"><i class="bi bi-x"></i></button>
        <img id="previewImage" src="" alt="预览">
    </div>
    
    <script>
        function openPreview(src) {
            document.getElementById('previewImage').src = src;
            document.getElementById('previewModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closePreview() {
            document.getElementById('previewModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePreview();
        });
    </script>
</body>
</html>
<?php
}

// 文件夹列表页面
function showFolderPage($files, $subfolders, $link, $currentPath) {
    $folderName = basename($currentPath) ?: '根目录';
    $totalFiles = count($files);
    $totalFolders = count($subfolders);
    
    renderPageHeader($folderName);
?>
    <div class="share-container">
        <div class="share-card">
            <div class="share-header">
                <div class="brand">ANKOTTI DESIGN</div>
                <h1><i class="bi bi-folder2-open"></i> <?= htmlspecialchars($folderName) ?></h1>
                <div class="subtitle">共 <?= $totalFiles ?> 个文件<?= $totalFolders ? '，' . $totalFolders . ' 个文件夹' : '' ?></div>
            </div>
            <div class="share-body">
                <?php if (empty($files) && empty($subfolders)): ?>
                    <div class="empty-state">
                        <i class="bi bi-folder-x"></i>
                        <p>此文件夹为空</p>
                    </div>
                <?php else: ?>
                    <div class="file-list">
                        <?php foreach ($subfolders as $folder): ?>
                            <div class="file-item">
                                <div class="file-icon folder">
                                    <i class="bi bi-folder-fill"></i>
                                </div>
                                <div class="file-info">
                                    <div class="file-name"><?= htmlspecialchars($folder) ?></div>
                                    <div class="file-meta">文件夹</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php foreach ($files as $file): 
                            $fileType = getFileType($file['file_type']);
                            $isPreviewable = in_array($fileType, ['image', 'audio']);
                            $downloadUrl = 'drive_share_download.php?token=' . htmlspecialchars($link['token']) . '&file_id=' . $file['id'];
                            $previewUrl = $downloadUrl . '&preview=1';
                        ?>
                            <div class="file-item" <?php if ($fileType === 'image'): ?>onclick="openPreview('<?= $previewUrl ?>')"<?php endif; ?>>
                                <div class="file-icon <?= $fileType ?>">
                                    <?= getFileIconHtml($file['file_type']) ?>
                                </div>
                                <div class="file-info">
                                    <div class="file-name"><?= htmlspecialchars($file['filename']) ?></div>
                                    <div class="file-meta"><?= formatFileSize($file['file_size']) ?> · <?= date('m-d H:i', $file['create_time']) ?></div>
                                </div>
                                <div class="file-actions" onclick="event.stopPropagation()">
                                    <?php if ($fileType === 'audio'): ?>
                                        <button class="btn-action btn-preview" onclick="playAudio('<?= $previewUrl ?>', '<?= htmlspecialchars($file['filename']) ?>')" title="播放">
                                            <i class="bi bi-play-fill"></i>
                                        </button>
                                    <?php elseif ($fileType === 'image'): ?>
                                        <button class="btn-action btn-preview" onclick="openPreview('<?= $previewUrl ?>')" title="预览">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="<?= $downloadUrl ?>" class="btn-action btn-download" title="下载">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div class="share-info">
                    <div class="share-info-item">
                        <i class="bi bi-person"></i>
                        <span>分享者: <?= htmlspecialchars($link['owner_name']) ?></span>
                    </div>
                    <div class="share-info-item">
                        <i class="bi bi-calendar3"></i>
                        <span>有效期至: <?= date('Y-m-d H:i', strtotime($link['expires_at'])) ?></span>
                    </div>
                    <?php if ($link['max_visits']): ?>
                    <div class="share-info-item">
                        <i class="bi bi-eye"></i>
                        <span>剩余: <?= max(0, $link['max_visits'] - $link['visit_count']) ?> 次</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 图片预览弹窗 -->
    <div class="preview-modal" id="previewModal" onclick="closePreview()">
        <button class="close-btn" onclick="closePreview()"><i class="bi bi-x"></i></button>
        <img id="previewImage" src="" alt="预览">
    </div>
    
    <!-- 音频播放器弹窗 -->
    <div class="preview-modal" id="audioModal" onclick="closeAudio()">
        <div style="background: white; padding: 24px; border-radius: 16px; max-width: 400px; width: 90%;" onclick="event.stopPropagation()">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span id="audioTitle" style="font-weight: 600; font-size: 14px; color: #1e293b;"></span>
                <button onclick="closeAudio()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <audio id="audioPlayer" controls style="width: 100%;">
                您的浏览器不支持音频播放
            </audio>
        </div>
    </div>
    
    <script>
        function openPreview(src) {
            document.getElementById('previewImage').src = src;
            document.getElementById('previewModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closePreview() {
            document.getElementById('previewModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function playAudio(src, title) {
            document.getElementById('audioTitle').textContent = title;
            var audio = document.getElementById('audioPlayer');
            audio.src = src;
            document.getElementById('audioModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            audio.play();
        }
        
        function closeAudio() {
            var audio = document.getElementById('audioPlayer');
            audio.pause();
            audio.src = '';
            document.getElementById('audioModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePreview();
                closeAudio();
            }
        });
    </script>
</body>
</html>
<?php
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getFileType($mimeType) {
    if (strpos($mimeType, 'image') !== false) return 'image';
    if (strpos($mimeType, 'audio') !== false) return 'audio';
    if (strpos($mimeType, 'video') !== false) return 'video';
    if (strpos($mimeType, 'pdf') !== false) return 'pdf';
    if (strpos($mimeType, 'word') !== false || strpos($mimeType, 'document') !== false) return 'doc';
    if (strpos($mimeType, 'excel') !== false || strpos($mimeType, 'sheet') !== false) return 'doc';
    return 'default';
}

function getFileIconHtml($mimeType) {
    if (strpos($mimeType, 'image') !== false) return '<i class="bi bi-file-image"></i>';
    if (strpos($mimeType, 'audio') !== false) return '<i class="bi bi-file-music"></i>';
    if (strpos($mimeType, 'video') !== false) return '<i class="bi bi-file-play"></i>';
    if (strpos($mimeType, 'pdf') !== false) return '<i class="bi bi-file-pdf"></i>';
    if (strpos($mimeType, 'word') !== false || strpos($mimeType, 'document') !== false) return '<i class="bi bi-file-word"></i>';
    if (strpos($mimeType, 'excel') !== false || strpos($mimeType, 'sheet') !== false) return '<i class="bi bi-file-excel"></i>';
    if (strpos($mimeType, 'zip') !== false || strpos($mimeType, 'rar') !== false) return '<i class="bi bi-file-zip"></i>';
    return '<i class="bi bi-file-earmark"></i>';
}
