<?php
// 文件分享链接访问页面
// 仅显示单个文件信息，支持下载

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../services/FileLinkService.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    die('无效的访问链接');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 查询文件分享链接
$link = FileLinkService::getByToken($token);

if (!$link) {
    die('分享链接不存在');
}

// 如果链接已停用，拒绝访问
if (!$link['enabled']) {
    die('此分享链接已停用');
}

// 获取文件信息
$file = Db::queryOne('SELECT * FROM customer_files WHERE id = :id AND deleted_at IS NULL', ['id' => $link['file_id']]);

if (!$file) {
    die('文件不存在或已被删除');
}

// 获取客户信息（用于权限检查）
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $file['customer_id']]);

if (!$customer) {
    die('客户不存在');
}

// 检查访问权限
$user = current_user();
$error = null;
$sessionKey = 'file_share_verified_' . $link['id'];
$passwordSessionKey = 'file_share_password_' . $link['id'];

// 处理密码验证（无论是否已登录，只要设置了密码都需要验证）
if (!empty($link['password']) && !isset($_SESSION[$sessionKey])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputPassword = trim($_POST['password'] ?? '');
        if (verifyLinkPassword($inputPassword, $link['password'])) {
            $_SESSION[$sessionKey] = true;
            $_SESSION[$passwordSessionKey] = $inputPassword;
            FileLinkService::recordAccess($link['id'], $_SERVER['REMOTE_ADDR'] ?? '');
        } else {
            $error = '密码错误';
        }
    }
}

// 如果需要密码但未验证，先显示密码输入页面（在权限检查之前）
if (!empty($link['password']) && !isset($_SESSION[$sessionKey])) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>访问验证 - ANKOTTI</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            /* 手机端背景色改为纯白色 */
            @media (max-width: 768px) {
                body.bg-light {
                    background-color: #ffffff !important;
                }
            }
        </style>
    </head>
    <body class="bg-light">
        <div class="container">
            <div class="row justify-content-center mt-5">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title text-center mb-4">访问验证</h5>
                            <?php if ($error): ?>
                                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">请输入访问密码</label>
                                    <input type="password" name="password" class="form-control" required autofocus>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">访问</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 使用权限检查函数判断权限（在密码验证之后）
$password = $_SESSION[$passwordSessionKey] ?? null;
$permission = FileLinkService::checkPermission($link, $user, $password);

// 如果权限为none，拒绝访问
if ($permission === 'none') {
    die('您没有权限访问此文件');
}

// 记录访问（如果还没记录过）
if (!isset($_SESSION['file_share_verified_' . $link['id']])) {
    FileLinkService::recordAccess($link['id'], $_SERVER['REMOTE_ADDR'] ?? '');
    $_SESSION['file_share_verified_' . $link['id']] = true;
}

// 设置权限标记
$isReadonly = ($permission === 'view');
$_SESSION['file_share_permission_' . $link['id']] = $permission;

// 获取上传人信息
$uploader = null;
if ($file['uploaded_by']) {
    $uploader = Db::queryOne('SELECT realname FROM users WHERE id = :id', ['id' => $file['uploaded_by']]);
}

// 格式化文件大小
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// 格式化时间
function formatTime($timestamp) {
    return date('Y-m-d H:i:s', $timestamp);
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>文件详情 - <?= htmlspecialchars($file['filename']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .file-info-card {
            max-width: 800px;
            margin: 2rem auto;
        }
        .file-icon {
            font-size: 4rem;
            color: #6c757d;
        }
        .permission-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .permission-badge.readonly {
            background-color: #ffc107;
            color: #000;
        }
        .permission-badge.editable {
            background-color: #28a745;
            color: #fff;
        }
        /* 手机端背景色改为纯白色 */
        @media (max-width: 768px) {
            body.bg-light {
                background-color: #ffffff !important;
            }
            /* 手机端生成分享链接按钮居中对齐 */
            .btn-share-link {
                display: block;
                margin: 0 auto;
                width: auto;
                min-width: 200px;
                max-width: 300px;
            }
            /* 确保按钮容器内的按钮垂直排列 */
            .d-grid.gap-2 {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .d-grid.gap-2 .btn-share-link {
                align-self: center;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="file-info-card">
            <div class="card">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="file-icon">📄</div>
                        <h4 class="mt-3"><?= htmlspecialchars($file['filename']) ?></h4>
                        <span class="permission-badge <?= $isReadonly ? 'readonly' : 'editable' ?>">
                            <?= $isReadonly ? '只读模式' : '可编辑模式' ?>
                        </span>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>文件大小：</strong>
                            <span><?= formatFileSize($file['filesize']) ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong>上传时间：</strong>
                            <span><?= formatTime($file['uploaded_at']) ?></span>
                        </div>
                    </div>
                    
                    <?php if ($uploader && $user): ?>
                    <!-- 只有登录用户才显示上传人信息，游客不显示 -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>上传人：</strong>
                            <span><?= htmlspecialchars($uploader['realname']) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($file['folder_path'] && $user): ?>
                    <!-- 只有登录用户才显示目录路径，避免泄露客户目录结构信息 -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <strong>所在目录：</strong>
                            <span><?= htmlspecialchars($file['folder_path']) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 多区域下载链接容器（通过API动态加载） -->
                    <div id="regionLinksContainer" class="mb-4" style="display:none;">
                        <h6 class="text-muted mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-globe" viewBox="0 0 16 16" style="margin-right: 6px;">
                                <path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/>
                            </svg>
                            选择下载节点
                        </h6>
                        <div id="regionLinksList"></div>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <a href="/api/customer_file_stream.php?id=<?= $file['id'] ?>&mode=download&token=<?= htmlspecialchars($token) ?>" 
                           class="btn btn-primary btn-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5h2.5a.5.5 0 0 1 0 1H3a1 1 0 0 0-1 1V14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-2.6a.5.5 0 0 1 1 0V14a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V11.4a2 2 0 0 1 .5-2.5z"/>
                                <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                            </svg>
                            直接下载
                        </a>
                        
                        <?php if (!$isReadonly): ?>
                        <!-- 可编辑模式下显示生成分享链接按钮 -->
                        <button type="button" class="btn btn-outline-secondary btn-share-link" onclick="showShareModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-share" viewBox="0 0 16 16" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                                <path d="M13.5 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM11 2.5a2.5 2.5 0 1 1 .603 1.628l-6.718 3.12a2.499 2.499 0 0 1 0 1.504l6.718 3.12a2.5 2.5 0 1 1-.488.876l-6.718-3.12a2.5 2.5 0 1 1 0-1.504l6.718-3.12A2.5 2.5 0 0 1 11 2.5zm-8.5 4a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm11 5.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/>
                            </svg>
                            生成分享链接
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($user): ?>
                        <!-- 只有组织内用户（已登录）才显示返回客户详情页链接 -->
                        <!-- 游客访问时不显示此链接，确保他们只能看到文件信息 -->
                        <a href="customer_detail.php?id=<?= $customer['id'] ?>" class="btn btn-outline-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-left" viewBox="0 0 16 16" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                            </svg>
                            返回客户详情
                        </a>
                        <?php else: ?>
                        <!-- 游客访问时只显示文件信息，不显示任何客户相关链接或信息 -->
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const SHARE_TOKEN = '<?= htmlspecialchars($token) ?>';
        
        function copyRegionUrl(url) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    showToast('链接已复制到剪贴板');
                }).catch(function() {
                    fallbackCopy(url);
                });
            } else {
                fallbackCopy(url);
            }
        }
        
        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showToast('链接已复制到剪贴板');
            } catch (err) {
                showToast('复制失败，请手动复制');
            }
            document.body.removeChild(textarea);
        }
        
        function showToast(message) {
            const toast = document.createElement('div');
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:12px 24px;border-radius:8px;z-index:9999;font-size:14px;';
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.remove();
            }, 2000);
        }
        
        // 调用统一的ShareRegionService获取区域链接
        function loadRegionLinks() {
            fetch('/api/share_region_urls.php?token=' + encodeURIComponent(SHARE_TOKEN))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success && data.data && data.data.length > 0) {
                        var container = document.getElementById('regionLinksContainer');
                        var list = document.getElementById('regionLinksList');
                        var html = '';
                        
                        data.data.forEach(function(region) {
                            var isDefault = region.is_default ? 'border-success bg-light' : '';
                            var badge = region.is_default ? '<span class="badge bg-success ms-2">推荐</span>' : '';
                            html += '<div class="region-card mb-2 p-3 border rounded ' + isDefault + '">' +
                                '<div class="d-flex justify-content-between align-items-center">' +
                                    '<div><strong>' + escapeHtml(region.region_name) + '</strong>' + badge + '</div>' +
                                    '<div class="btn-group">' +
                                        '<button class="btn btn-sm btn-outline-secondary" onclick="copyRegionUrl(\'' + escapeHtml(region.url) + '\')">' +
                                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">' +
                                                '<path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>' +
                                                '<path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>' +
                                            '</svg> 复制' +
                                        '</button>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="text-muted small mt-1 text-truncate" style="max-width:100%;">' + escapeHtml(region.url) + '</div>' +
                            '</div>';
                        });
                        
                        list.innerHTML = html;
                        container.style.display = 'block';
                    }
                })
                .catch(function(err) {
                    console.error('[CSREGION] 加载区域链接失败:', err);
                });
        }
        
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.addEventListener('DOMContentLoaded', loadRegionLinks);
        
        <?php if (!$isReadonly): ?>
        function showShareModal() {
            alert('分享链接功能：请通过文件管理页面生成分享链接');
        }
        <?php endif; ?>
    </script>
</body>
</html>

