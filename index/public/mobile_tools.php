<?php
// 手机版小工具页面 - iOS风格

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 需要登录
auth_require();
$user = current_user();

// 读取 tools_standalone.html 文件内容
$htmlFile = __DIR__ . '/tools_standalone.html';
$htmlContent = file_get_contents($htmlFile);

// 提取body内容
preg_match('/<body>(.*?)<\/body>/s', $htmlContent, $bodyMatches);
$bodyContent = isset($bodyMatches[1]) ? $bodyMatches[1] : '';

// 提取style内容
preg_match('/<style>(.*?)<\/style>/s', $htmlContent, $styleMatches);
$styleContent = isset($styleMatches[1]) ? $styleMatches[1] : '';

// 提取script内容（内联script）
preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $htmlContent, $scriptMatches);
$scriptContents = !empty($scriptMatches[1]) ? $scriptMatches[1] : [];

// 提取script src
preg_match_all('/<script[^>]+src="([^"]+)"[^>]*><\/script>/s', $htmlContent, $srcMatches);
$scriptSrcs = !empty($srcMatches[1]) ? $srcMatches[1] : [];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <title>小工具 - ANKOTTI Mobile</title>
    <link rel="stylesheet" href="css/mobile-customer.css">
    <style>
        /* ========== 小工具页面特定样式 ========== */
        
        /* 覆盖原有的CSS变量，使用iOS风格 */
        :root {
            --tools-bg: var(--bg-color);
            --tools-panel: var(--card-bg);
            --tools-accent: var(--primary-color);
            --tools-text: var(--text-primary);
            --tools-muted: var(--text-secondary);
            --tools-danger: var(--danger-color);
            --tools-ok: var(--success-color);
            --tools-border: var(--divider-color);
        }
        
        /* 主容器 */
        .tools-container {
            max-width: 100%;
            margin: 0 auto;
            padding: calc(16px + var(--safe-area-top)) 16px calc(80px + var(--safe-area-bottom));
            background: var(--bg-color);
            min-height: 100vh;
        }
        
        /* 头部 */
        .tools-header {
            background: var(--card-bg);
            padding: 16px 20px;
            margin: -16px -16px 16px;
            border-bottom: 0.5px solid var(--divider-color);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .tools-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .tools-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        
        .tools-back-btn {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 16px;
            padding: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .tools-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin: 0;
        }
        
        .tools-meta {
            font-size: 11px;
            color: var(--text-tertiary);
            margin-top: 4px;
        }
        
        /* 标签页导航 */
        .tab-buttons {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 12px 0;
            margin-bottom: 16px;
            border-bottom: 0.5px solid var(--divider-color);
        }
        
        .tab-buttons::-webkit-scrollbar {
            display: none;
        }
        
        .tab-button {
            flex-shrink: 0;
            padding: 10px 16px;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
            min-height: 44px;
        }
        
        .tab-button:active {
            background: var(--bg-color);
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        /* 标签页内容 */
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* 卡片样式 */
        .card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 16px;
            overflow: hidden;
        }
        
        .card h3 {
            margin: 0;
            padding: 14px 16px;
            border-bottom: 0.5px solid var(--divider-color);
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .section {
            padding: 16px;
        }
        
        /* 工具栏 */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .spacer {
            flex: 1;
        }
        
        /* 按钮样式 */
        .btn {
            min-height: 44px;
            padding: 10px 16px;
            border-radius: var(--radius-md);
            border: 0.5px solid var(--divider-color);
            background: var(--card-bg);
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn:active {
            transform: scale(0.98);
            background: var(--bg-color);
        }
        
        .btn.primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .btn.primary:active {
            background: #0056b3;
        }
        
        .btn.ghost {
            background: transparent;
        }
        
        .btn.warn {
            background: transparent;
            border-color: var(--danger-color);
            color: var(--danger-color);
        }
        
        .btn.ok {
            background: transparent;
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* 输入框和选择框 */
        select, input, textarea {
            min-height: 44px;
            padding: 10px 14px;
            border: 0.5px solid var(--divider-color);
            border-radius: var(--radius-md);
            background: var(--card-bg);
            color: var(--text-primary);
            font-size: 16px; /* 防止iOS自动缩放 */
            width: 100%;
        }
        
        select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        textarea {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }
        
        /* 双列布局（移动端改为单列） */
        .cols {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        /* 原有.grid布局改为单列 */
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        /* 表单组 */
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        /* 列表 */
        .list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .item {
            padding: 12px;
            border: 0.5px solid var(--divider-color);
            border-radius: var(--radius-md);
            background: var(--card-bg);
        }
        
        .item-title {
            font-weight: 600;
            font-size: 15px;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        
        .item-meta {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        /* 分隔线 */
        .hr {
            height: 0.5px;
            background: var(--divider-color);
            margin: 16px 0;
        }
        
        /* 小字体 */
        .small {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        /* 空状态 */
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        /* 计算器样式 */
        .calculator {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 16px;
        }
        
        .calculator input {
            grid-column: 1 / -1;
            margin-bottom: 8px;
            text-align: right;
            font-size: 24px;
            font-weight: 600;
            padding: 16px;
        }
        
        .calculator button {
            min-height: 60px;
            font-size: 18px;
            font-weight: 500;
        }
        
        .calculator button.operator {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .calculator button.clear {
            background: var(--danger-color);
            border-color: var(--danger-color);
            color: white;
        }
        
        .calculator button.equals {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }
        
        /* 历史记录 */
        .calc-history {
            margin-top: 16px;
            max-height: 200px;
            overflow-y: auto;
            border: 0.5px solid var(--divider-color);
            border-radius: var(--radius-md);
            padding: 12px;
            background: var(--card-bg);
        }
        
        .history-item {
            padding: 8px 0;
            border-bottom: 0.5px solid var(--divider-color);
            font-family: monospace;
            font-size: 13px;
            color: var(--text-primary);
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .history-item:active {
            background: var(--bg-color);
        }
        
        /* Toast通知（iOS风格） - 使用统一的样式 */
        .toast {
            position: fixed;
            bottom: calc(80px + var(--safe-area-bottom));
            left: 50%;
            transform: translateX(-50%) translateY(120px) scale(0.9);
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            color: white;
            padding: 14px 20px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.4;
            z-index: 10000;
            opacity: 0;
            pointer-events: none;
            max-width: calc(100% - 32px);
            min-width: 120px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 0 0.5px rgba(255, 255, 255, 0.1);
            transition: opacity 0.35s cubic-bezier(0.32, 0.72, 0, 1),
                        transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
            word-wrap: break-word;
            word-break: break-all;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0) scale(1);
            pointer-events: auto;
        }
        
        /* Toast类型样式 */
        .toast.success {
            background: rgba(34, 197, 94, 0.9);
            box-shadow: 0 8px 32px rgba(34, 197, 94, 0.3), 0 0 0 0.5px rgba(255, 255, 255, 0.1);
        }
        
        .toast.error {
            background: rgba(239, 68, 68, 0.9);
            box-shadow: 0 8px 32px rgba(239, 68, 68, 0.3), 0 0 0 0.5px rgba(255, 255, 255, 0.1);
        }
        
        .toast.warning {
            background: rgba(245, 158, 11, 0.9);
            box-shadow: 0 8px 32px rgba(245, 158, 11, 0.3), 0 0 0 0.5px rgba(255, 255, 255, 0.1);
        }
        
        .toast.info {
            background: rgba(59, 130, 246, 0.9);
            box-shadow: 0 8px 32px rgba(59, 130, 246, 0.3), 0 0 0 0.5px rgba(255, 255, 255, 0.1);
        }
        
        /* Toast图标样式 */
        .toast.with-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 18px;
        }
        
        .toast-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
        }
        
        .toast-text {
            flex: 1;
            text-align: left;
        }
        
        /* 货币项 */
        .currency-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--divider-color);
        }
        
        .currency-item:last-child {
            border-bottom: none;
        }
        
        /* 工具按钮组 */
        .tools {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        /* 原有样式覆盖（从tools_standalone.html提取的样式，进行移动端优化） */
        <?php echo $styleContent; ?>
        
        /* 移动端特定优化 */
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr !important;
            }
            
            .tab-buttons {
                padding: 12px 16px;
            }
            
            .calculator button {
                min-height: 56px;
            }
        }
    </style>
    
    <!-- 引入opencc-js -->
    <?php foreach ($scriptSrcs as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
</head>
<body>
    <div class="tools-container">
        <!-- 头部 -->
        <div class="tools-header">
            <div class="tools-header-top">
                <button class="tools-back-btn" onclick="window.location.href='mobile_home.php'">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                    返回
                </button>
                <h1 class="tools-title">小工具</h1>
                <div style="width: 60px;"></div> <!-- 占位，保持居中 -->
            </div>
            <p class="tools-hint">仅浏览器本地运行 · 数据存储在本机 · 导入/导出密码：123456</p>
            <div class="tools-meta">转换内核：opencc-js@1.0.5 | <span id="rate-update-time">汇率更新中...</span></div>
        </div>
        
        <!-- 提取的body内容 -->
        <?php echo $bodyContent; ?>
        
        <!-- Toast通知 -->
        <div class="toast" id="toast"></div>
    </div>
    
    <script>
        // 在tools_standalone.html脚本执行之前，定义统一的Toast函数（iOS风格）
        // HTML转义辅助函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 统一的Toast函数（iOS风格）- 优先定义，覆盖tools_standalone.html中的实现
        window.showToast = function(message, type = 'info', duration = null) {
            // 使用页面中已存在的toast元素（mobile_tools.php中已定义）
            let toastEl = document.getElementById('toast');
            if (!toastEl) {
                // 如果toast元素不存在，创建它
                toastEl = document.createElement('div');
                toastEl.id = 'toast';
                toastEl.className = 'toast';
                document.body.appendChild(toastEl);
            }
            
            // 移除之前的类型类
            toastEl.className = 'toast';
            
            // 计算显示时间（根据内容长度）
            let displayDuration = duration;
            if (displayDuration === null) {
                const messageLength = (message || '').length;
                if (messageLength < 20) {
                    displayDuration = 2000; // 短消息：2秒
                } else if (messageLength < 40) {
                    displayDuration = 3000; // 中等消息：3秒
                } else {
                    displayDuration = 5000; // 长消息：5秒
                }
            }
            
            // 设置类型
            if (type && type !== 'info') {
                toastEl.classList.add(type);
            }
            
            // 图标映射
            const iconMap = {
                'success': '✓',
                'error': '✕',
                'warning': '⚠',
                'info': 'ℹ'
            };
            
            const icon = iconMap[type] || '';
            
            // 设置内容
            if (icon) {
                toastEl.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-text">${escapeHtml(message)}</span>`;
                toastEl.classList.add('with-icon');
            } else {
                toastEl.textContent = message;
                toastEl.classList.remove('with-icon');
            }
            
            // 触发动画（使用requestAnimationFrame确保DOM更新）
            requestAnimationFrame(() => {
                toastEl.classList.add('show');
            });
            
            // 自动隐藏
            setTimeout(() => {
                toastEl.classList.remove('show');
                // 等待动画完成后重置内容
                setTimeout(() => {
                    toastEl.className = 'toast';
                    toastEl.textContent = '';
                }, 350);
            }, displayDuration);
        };
    </script>
    
    <!-- 提取的JavaScript内容 -->
    <?php foreach ($scriptContents as $script): ?>
        <script><?php echo $script; ?></script>
    <?php endforeach; ?>
    
    <script>
        // 页面加载时设置视图模式
        (function() {
            if (typeof Storage !== 'undefined') {
                localStorage.setItem('ankotti_view_mode', 'mobile');
            }
        })();
    </script>
</body>
</html>

