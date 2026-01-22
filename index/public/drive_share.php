<?php
/**
 * 网盘分享链接访问页面
 * 支持文件夹分享和单文件分享
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

// 获取token
$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    http_response_code(400);
    die('无效的分享链接');
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
        http_response_code(404);
        die('分享链接不存在或已失效');
    }
    
    // 检查是否过期
    if (strtotime($link['expires_at']) < time()) {
        $pdo->prepare("UPDATE drive_share_links SET status = 'expired' WHERE id = ?")->execute([$link['id']]);
        die('分享链接已过期');
    }
    
    // 检查访问次数
    if ($link['max_visits'] && $link['visit_count'] >= $link['max_visits']) {
        die('分享链接访问次数已达上限');
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
                    $error = '密码错误';
                }
            }
            
            if (!isset($_SESSION[$sessionKey])) {
                // 显示密码输入页面
                showPasswordPage($error ?? null);
                exit;
            }
        }
    }
    
    // 更新访问次数
    $pdo->prepare("UPDATE drive_share_links SET visit_count = visit_count + 1 WHERE id = ?")->execute([$link['id']]);
    
    // 判断是文件分享还是文件夹分享
    if ($link['file_id']) {
        // 单文件分享 - 直接下载或预览
        $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ? AND drive_id = ?");
        $stmt->execute([$link['file_id'], $link['drive_id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            die('文件不存在');
        }
        
        // 显示文件下载页面
        showFileDownloadPage($file, $link);
    } else {
        // 文件夹分享 - 显示文件列表
        $folderPath = $link['folder_path'];
        
        // 获取文件列表
        $stmt = $pdo->prepare("
            SELECT id, filename, original_filename, folder_path, storage_key, file_size, file_type, create_time
            FROM drive_files 
            WHERE drive_id = ? AND folder_path = ? AND filename != '.folder'
            ORDER BY create_time DESC
        ");
        $stmt->execute([$link['drive_id'], $folderPath]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取子文件夹
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
    http_response_code(500);
    die('服务器错误');
}

// 密码输入页面
function showPasswordPage($error = null) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>访问验证 - 网盘分享</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body p-4">
                        <h5 class="card-title text-center mb-4">🔒 访问验证</h5>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">请输入访问密码</label>
                                <input type="password" name="password" class="form-control" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">验证</button>
                        </form>
                    </div>
                </div>
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
    $fileIcon = getFileIcon($file['file_type']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($file['filename']) ?> - 网盘分享</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); min-height: 100vh; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .file-icon { font-size: 64px; color: #11998e; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-body p-4 text-center">
                        <div class="file-icon mb-3"><?= $fileIcon ?></div>
                        <h5 class="card-title mb-2"><?= htmlspecialchars($file['filename']) ?></h5>
                        <p class="text-muted mb-4"><?= $fileSize ?></p>
                        <a href="drive_share_download.php?token=<?= htmlspecialchars($link['token']) ?>&file_id=<?= $file['id'] ?>" 
                           class="btn btn-success btn-lg w-100">
                            <i class="bi bi-download me-2"></i>下载文件
                        </a>
                        <p class="text-muted mt-3 small">
                            分享者: <?= htmlspecialchars($link['owner_name']) ?><br>
                            有效期至: <?= date('Y-m-d H:i', strtotime($link['expires_at'])) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
}

// 文件夹列表页面
function showFolderPage($files, $subfolders, $link, $currentPath) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>网盘分享 - <?= htmlspecialchars($currentPath) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 0; }
        .file-item { background: white; border-radius: 8px; padding: 15px; margin-bottom: 10px; transition: all 0.2s; }
        .file-item:hover { box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .file-icon { font-size: 32px; margin-right: 15px; }
        .folder-icon { color: #ffc107; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h4 class="mb-1"><i class="bi bi-folder2-open me-2"></i>网盘分享</h4>
            <p class="mb-0 opacity-75">分享者: <?= htmlspecialchars($link['owner_name']) ?> | 路径: <?= htmlspecialchars($currentPath) ?></p>
        </div>
    </div>
    
    <div class="container py-4">
        <?php if (empty($files) && empty($subfolders)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-folder-x" style="font-size: 48px;"></i>
                <p class="mt-3">此文件夹为空</p>
            </div>
        <?php else: ?>
            <?php foreach ($subfolders as $folder): ?>
                <div class="file-item d-flex align-items-center">
                    <span class="file-icon folder-icon"><i class="bi bi-folder-fill"></i></span>
                    <div class="flex-grow-1">
                        <strong><?= htmlspecialchars($folder) ?></strong>
                        <div class="text-muted small">文件夹</div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php foreach ($files as $file): ?>
                <div class="file-item d-flex align-items-center">
                    <span class="file-icon"><?= getFileIcon($file['file_type']) ?></span>
                    <div class="flex-grow-1">
                        <strong><?= htmlspecialchars($file['filename']) ?></strong>
                        <div class="text-muted small"><?= formatFileSize($file['file_size']) ?> · <?= date('Y-m-d H:i', $file['create_time']) ?></div>
                    </div>
                    <a href="drive_share_download.php?token=<?= htmlspecialchars($link['token']) ?>&file_id=<?= $file['id'] ?>" 
                       class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-download"></i> 下载
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="text-center text-muted mt-4 small">
            有效期至: <?= date('Y-m-d H:i', strtotime($link['expires_at'])) ?>
            <?php if ($link['max_visits']): ?>
                | 剩余访问次数: <?= $link['max_visits'] - $link['visit_count'] ?>
            <?php endif; ?>
        </div>
    </div>
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

function getFileIcon($mimeType) {
    if (strpos($mimeType, 'image') !== false) return '<i class="bi bi-file-image text-success"></i>';
    if (strpos($mimeType, 'video') !== false) return '<i class="bi bi-file-play text-danger"></i>';
    if (strpos($mimeType, 'audio') !== false) return '<i class="bi bi-file-music text-info"></i>';
    if (strpos($mimeType, 'pdf') !== false) return '<i class="bi bi-file-pdf text-danger"></i>';
    if (strpos($mimeType, 'word') !== false || strpos($mimeType, 'document') !== false) return '<i class="bi bi-file-word text-primary"></i>';
    if (strpos($mimeType, 'excel') !== false || strpos($mimeType, 'sheet') !== false) return '<i class="bi bi-file-excel text-success"></i>';
    if (strpos($mimeType, 'zip') !== false || strpos($mimeType, 'rar') !== false || strpos($mimeType, 'archive') !== false) return '<i class="bi bi-file-zip text-warning"></i>';
    return '<i class="bi bi-file-earmark text-secondary"></i>';
}
