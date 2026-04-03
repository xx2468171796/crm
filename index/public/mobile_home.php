<?php
// 手机版主页 - iOS风格

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 需要登录
auth_require();
$user = current_user();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <title>主页 - ANKOTTI Mobile</title>
    <link rel="stylesheet" href="css/mobile-customer.css">
    <style>
        /* 主页特定样式 */
        .home-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px 16px;
            padding-bottom: calc(20px + var(--safe-area-bottom));
        }
        
        .home-header {
            text-align: center;
            margin-bottom: 32px;
            padding-top: calc(20px + var(--safe-area-top));
        }
        
        .home-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .home-subtitle {
            font-size: 15px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        
        .module-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .module-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 24px 20px;
            text-align: center;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 140px;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
            border: 0.5px solid rgba(0, 0, 0, 0.02);
        }
        
        .module-card:active {
            transform: scale(0.98);
            background: var(--bg-color);
        }
        
        .module-card-icon {
            width: 48px;
            height: 48px;
            margin-bottom: 12px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .module-card-icon svg {
            width: 24px;
            height: 24px;
        }
        
        .module-card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .module-card-desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        
        .module-card.full-width {
            grid-column: 1 / -1;
        }
        
        .module-card.secondary .module-card-icon {
            background: var(--bg-color);
            color: var(--text-primary);
        }
        
        .divider {
            height: 1px;
            background: var(--divider-color);
            margin: 24px 0;
        }
        
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .quick-action-item {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
            border: 0.5px solid rgba(0, 0, 0, 0.02);
        }
        
        .quick-action-item:active {
            background: var(--bg-color);
            transform: scale(0.98);
        }
        
        .quick-action-content {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .quick-action-icon {
            width: 36px;
            height: 36px;
            background: var(--bg-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            flex-shrink: 0;
        }
        
        .quick-action-icon svg {
            width: 20px;
            height: 20px;
        }
        
        .quick-action-text {
            flex: 1;
        }
        
        .quick-action-title {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 2px;
        }
        
        .quick-action-desc {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .quick-action-arrow {
            width: 24px;
            height: 24px;
            color: var(--text-secondary);
            flex-shrink: 0;
        }
        
        .quick-action-arrow svg {
            width: 16px;
            height: 16px;
        }
        
        /* 深色模式适配 */
        @media (prefers-color-scheme: dark) {
            .module-card:active {
                background: #2C2C2E;
            }
            
            .module-card.secondary .module-card-icon {
                background: #2C2C2E;
            }
            
            .quick-action-item:active {
                background: #2C2C2E;
            }
            
            .quick-action-icon {
                background: #2C2C2E;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="home-header">
        <h1 class="home-title">ANKOTTI</h1>
        <p class="home-subtitle">客户管理系统</p>
    </div>
    
    <!-- Content -->
    <div class="home-container">
        <!-- 主要功能模块 -->
        <div class="module-grid">
            <a href="mobile_my_customers.php" class="module-card">
                <div class="module-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <div class="module-card-title">我的客户</div>
                <div class="module-card-desc">查看和管理客户列表</div>
            </a>
            
            <a href="mobile_customer_detail.php" class="module-card">
                <div class="module-card-icon" style="background: var(--success-color);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </div>
                <div class="module-card-title">新增客户</div>
                <div class="module-card-desc">创建新的客户记录</div>
            </a>
            
            <a href="mobile_tools.php" class="module-card">
                <div class="module-card-icon" style="background: #FF9500;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
                    </svg>
                </div>
                <div class="module-card-title">小工具</div>
                <div class="module-card-desc">简繁转换、汇率换算等实用工具</div>
            </a>
            
            <a href="https://okr.ankotti.com/" target="_blank" class="module-card">
                <div class="module-card-icon" style="background: #007AFF;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                </div>
                <div class="module-card-title">OKR</div>
                <div class="module-card-desc">目标与关键结果管理</div>
            </a>
        </div>
        
        <div class="divider"></div>
        
        <!-- 快捷操作 -->
        <div class="quick-actions">
            <a href="index.php?mobile=0&page=dashboard" class="quick-action-item" onclick="setDevicePreference('desktop'); return true;">
                <div class="quick-action-content">
                    <div class="quick-action-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                            <line x1="8" y1="21" x2="16" y2="21"></line>
                            <line x1="12" y1="17" x2="12" y2="21"></line>
                        </svg>
                    </div>
                    <div class="quick-action-text">
                        <div class="quick-action-title">访问电脑版</div>
                        <div class="quick-action-desc">切换到桌面版界面</div>
                    </div>
                </div>
                <div class="quick-action-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </div>
            </a>
        </div>
    </div>
    
    <script>
        // 设置设备偏好
        function setDevicePreference(preference) {
            // 设置Cookie
            document.cookie = `device_preference=${preference}; path=/; max-age=${30 * 24 * 60 * 60}`;
            
            // 设置localStorage（用于视图模式）
            if (typeof Storage !== 'undefined') {
                localStorage.setItem('ankotti_view_mode', preference === 'mobile' ? 'mobile' : 'desktop');
            }
        }
        
        // 页面加载时设置视图模式
        (function() {
            if (typeof Storage !== 'undefined') {
                localStorage.setItem('ankotti_view_mode', 'mobile');
            }
        })();
    </script>
</body>
</html>

