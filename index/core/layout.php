<?php
// 页面布局相关函数

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/../version.php';

// 版本号 - 用于清除浏览器缓存
define('APP_VERSION', get_app_version());

/**
 * 获取带版本号的资源URL
 */
function asset_url($path, $addVersion = true) {
    $url = $path;
    if ($addVersion) {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'v=' . APP_VERSION;
    }
    return $url;
}

/**
 * 输出页面头部
 */
function layout_header(string $title = 'ANKOTTI 客户跟进系统', bool $showNavbar = true): void
{
    $user = current_user();
    $now  = date('Y-m-d H:i:s');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($title) ?> - ANKOTTI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* 全局字体和样式统一 */
        body { 
            font-size: 16px; 
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* 表单元素统一 */
        .form-control, .form-select {
            font-size: 16px;
        }
        
        .form-label {
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        /* 按钮统一 */
        .btn {
            font-size: 16px;
            padding: 8px 20px;
        }
        
        .btn-sm {
            font-size: 15px;
            padding: 6px 16px;
        }
        
        /* 表格统一 */
        .table {
            font-size: 16px;
        }
        
        .table th {
            font-size: 16px;
            font-weight: 600;
        }
        
        /* 导航栏统一 */
        .navbar {
            font-size: 16px;
        }
        
        .navbar-brand {
            font-size: 20px;
            font-weight: 700;
        }
        
        /* 卡片统一 */
        .card {
            font-size: 16px;
        }
        
        .card-header {
            font-size: 17px;
            font-weight: 600;
        }
        
        /* Alert统一 */
        .alert {
            font-size: 16px;
        }
        
        /* Modal统一 */
        .modal-body {
            font-size: 16px;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        /* 输入框和选择框统一高度 */
        .form-control-sm, .form-select-sm {
            font-size: 15px;
            height: 36px;
        }
        
        /* 复选框和单选框统一大小 */
        input[type="radio"],
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 6px;
        }
        
        /* 标签统一 */
        label {
            font-size: 17px;
            margin-bottom: 6px;
        }
        
        /* 小文本统一 */
        small, .small {
            font-size: 14px;
        }
        
        /* 链接统一 */
        a {
            font-size: inherit;
        }
        
        /* 文本域统一 */
        textarea.form-control {
            font-size: 16px;
            line-height: 1.5;
        }
        
        /* ========== 移动端响应式优化 ========== */
        
        /* iPhone 15 Pro 及类似设备 (393px 宽度) */
        @media (max-width: 768px) {
            /* 容器适配 */
            .container, .container-fluid {
                padding-left: 12px;
                padding-right: 12px;
            }
            
            /* 导航栏优化 */
            .navbar {
                padding: 8px 12px;
            }
            
            .navbar-brand {
                font-size: 18px;
            }
            
            .nav-link {
                padding: 10px 12px;
                font-size: 15px;
            }
            
            /* 表格响应式 */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table {
                font-size: 13px;
                min-width: 800px; /* 保持表格可读性 */
            }
            
            .table th, .table td {
                padding: 8px 6px;
                white-space: nowrap;
            }
            
            /* 按钮适配 */
            .btn {
                font-size: 14px;
                padding: 8px 16px;
                min-height: 44px; /* iOS 推荐最小点击区域 */
            }
            
            .btn-sm {
                font-size: 13px;
                padding: 6px 12px;
                min-height: 36px;
            }
            
            /* 表单优化 */
            .form-control, .form-select {
                font-size: 16px; /* 防止iOS自动缩放 */
                min-height: 44px;
            }
            
            .form-label {
                font-size: 15px;
                margin-bottom: 6px;
            }
            
            /* 卡片优化 */
            .card {
                margin-bottom: 12px;
                border-radius: 12px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            /* 模态框优化 */
            .modal-dialog {
                margin: 10px;
                max-width: calc(100% - 20px);
            }
            
            .modal-content {
                border-radius: 12px;
            }
            
            /* 列表间距 */
            .mb-3 {
                margin-bottom: 12px !important;
            }
            
            /* 隐藏部分列以适应小屏幕 */
            .d-none-mobile {
                display: none !important;
            }
            
            /* 文字大小调整 */
            h1 { font-size: 24px; }
            h2 { font-size: 20px; }
            h3 { font-size: 18px; }
            h4 { font-size: 16px; }
            h5 { font-size: 15px; }
            
            /* 固定底部按钮 */
            .fixed-bottom-btn {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 12px;
                background: white;
                box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
                z-index: 1000;
            }
            
            /* 安全区域适配 (iPhone X 及以上) */
            body {
                padding-bottom: env(safe-area-inset-bottom);
            }
            
            .navbar {
                padding-top: max(8px, env(safe-area-inset-top));
            }
        }
        
        /* 超小屏幕优化 (iPhone SE 等) */
        @media (max-width: 375px) {
            body {
                font-size: 14px;
            }
            
            .btn {
                font-size: 13px;
                padding: 6px 12px;
            }
            
            .table {
                font-size: 12px;
            }
            
            .navbar-brand {
                font-size: 16px;
            }
        }
        
        /* 横屏优化 */
        @media (max-width: 768px) and (orientation: landscape) {
            .navbar {
                padding: 4px 12px;
            }
            
            .nav-link {
                padding: 6px 10px;
            }
        }
        
        /* 触摸优化 */
        @media (hover: none) and (pointer: coarse) {
            /* 增大可点击区域 */
            a, button, .btn, .nav-link {
                min-height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            
            /* 移除hover效果 */
            .btn:hover, .nav-link:hover {
                transform: none;
            }
            
            /* 添加点击反馈 */
            .btn:active, .nav-link:active {
                opacity: 0.7;
                transform: scale(0.98);
            }
        }
    </style>
    <script>
        // 全局JavaScript常量
        const BASE_URL = '<?= Url::base() ?>';
        const API_URL = '<?= Url::api() ?>';
        const CSRF_TOKEN = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
    </script>
</head>
<body>
<?php if ($showNavbar && $user): ?>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-3">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php?page=dashboard">ANKOTTI</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (canOrAdmin(PermissionCode::CUSTOMER_VIEW)): ?>
                <li class="nav-item"><a class="nav-link" href="index.php?page=customer_detail">新增客户</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php?page=my_customers">我的客户</a></li>
                <?php endif; ?>
                <?php if (canOrAdmin(PermissionCode::FINANCE_VIEW)): ?>
                <li class="nav-item"><a class="nav-link" href="index.php?page=my_receivables">我的应收/催款</a></li>
                <?php endif; ?>
                <?php if (canOrAdmin(PermissionCode::FINANCE_DASHBOARD)): ?>
                <li class="nav-item"><a class="nav-link" href="index.php?page=finance_dashboard">财务</a></li>
                <?php endif; ?>
                <?php if (canOrAdmin(PermissionCode::PROJECT_VIEW)): ?>
                <li class="nav-item"><a class="nav-link" href="index.php?page=project_kanban">项目</a></li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">收入</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="index.php?page=tech_my_projects">我的项目</a></li>
                        <li><a class="dropdown-item" href="my_salary_slip.php">我的工资条</a></li>
                        <?php if ($user && ($user['role'] === 'dept_leader' || isAdmin($user))): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?page=tech_commission_manage">提成管理</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php if (canOrAdmin(PermissionCode::ANALYTICS_VIEW)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">数据</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="index.php?page=analytics">数据分析</a></li>
                        <li><a class="dropdown-item" href="index.php?page=okr">OKR管理</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link" href="index.php?page=help_center">帮助</a></li>
                <?php if (isAdmin($user)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        后台管理
                    </a>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">业务管理</h6></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_tech_finance">💰 技术财务报表</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_customers">总客户管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_files">总文件管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_deleted_customers">🗑️ 已删除客户管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_deleted_customer_files">🗑️ 已删除客户文件</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_deleted_deliverables">🗑️ 已删除交付物</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">系统管理</h6></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_departments">部门管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_users">员工管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_roles">角色管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_stage_templates">⏱️ 阶段时间模板</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_evaluation_config">⭐ 评价模板配置</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_share_regions">🌐 分享节点配置</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_s3_acceleration">🚀 S3加速节点</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">表单配置</h6></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_form_templates">📝 需求表单管理</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">字段配置</h6></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_modules">📦 菜单管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_fields_new">📝 维度管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=system_dict">📋 字典管理</a></li>
                        <li><a class="dropdown-item" href="index.php?page=admin_customer_filter_fields">🏷️ 客户分类字段</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">运维诊断</h6></li>
                        <li><a class="dropdown-item" href="index.php?page=storage_health">🩺 存储健康检查</a></li>
                        <li><a class="dropdown-item" href="index.php?page=upload_config_check">📤 上传配置诊断</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text me-3">当前时间: <span id="navbar-datetime"><?= $now ?></span></span>
            <span class="navbar-text me-3">当前用户: <?= htmlspecialchars($user['name'] ?? $user['username']) ?></span>
            <a class="btn btn-outline-danger" href="logout.php">退出登录</a>
        </div>
    </div>
</nav>
<script>
// 简单前端定时更新时间
setInterval(function () {
    var el = document.getElementById('navbar-datetime');
    if (el) {
        var d = new Date();
        var pad = n => n.toString().padStart(2, '0');
        el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
}, 1000);
</script>
<?php endif; ?>
<div class="container-fluid mt-3">
<?php
}

/**
 * 输出页面尾部
 */
function layout_footer(): void
{
    ?>
</div>
<script src="<?= asset_url(Url::js('jquery.min.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
// 设置全局用户信息
<?php $currentUser = current_user(); ?>
window.currentUserName = '<?= $currentUser ? htmlspecialchars($currentUser['name'] ?? $currentUser['username']) : '' ?>';
</script>
<script src="<?= asset_url(Url::js('url-config.js')) ?>"></script>
<script src="<?= asset_url(Url::js('modal.js')) ?>"></script>
<script src="<?= asset_url(Url::js('ajax-config.js')) ?>"></script>
<script src="<?= asset_url(Url::js('copy-to-image.js')) ?>"></script>
<script src="<?= asset_url(Url::js('click-effect.js')) ?>"></script>
<script src="<?= asset_url(Url::js('mobile-optimize.js')) ?>"></script>
<script>
// 视图模式管理
(function() {
    const VIEW_MODE_KEY = 'ankotti_view_mode';
    
    // 设置视图模式
    function setViewMode(mode) {
        if (mode === 'mobile' || mode === 'desktop') {
            localStorage.setItem(VIEW_MODE_KEY, mode);
        }
    }
    
    // 页面加载时自动设置视图模式（电脑版）
    (function() {
        const currentPath = window.location.pathname;
        const currentSearch = window.location.search;
        // 如果不是手机版页面，设置为 desktop
        if (!currentPath.includes('mobile_customer_detail.php')) {
            setViewMode('desktop');
        }
    })();
    
    // 处理导航栏中的"进入手机版"按钮
    const navMobileLink = document.getElementById('navMobileLink');
    if (navMobileLink) {
        navMobileLink.addEventListener('click', function(e) {
            setViewMode('mobile');
            // 设置Cookie偏好
            document.cookie = 'device_preference=mobile; path=/; max-age=' + (30 * 24 * 60 * 60);
            // 链接的 href 已经正确，让它正常跳转
        });
    }
    
    // 处理页面中所有"进入手机版"按钮（使用class选择器）
    document.querySelectorAll('.enter-mobile-link').forEach(link => {
        link.addEventListener('click', function(e) {
            setViewMode('mobile');
            // 设置Cookie偏好
            document.cookie = 'device_preference=mobile; path=/; max-age=' + (30 * 24 * 60 * 60);
            // 链接的 href 已经正确，让它正常跳转
        });
    });
})();
</script>
</body>
</html>
<?php
}
