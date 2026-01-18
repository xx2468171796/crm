<?php
// 手机版"我的客户"列表页面 - iOS风格

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 需要登录
auth_require();
$user = current_user();

// 获取筛选条件
$search = trim($_GET['search'] ?? '');
$timeFilter = trim($_GET['time_filter'] ?? ''); // 快捷时间筛选
$startDate = trim($_GET['start_date'] ?? ''); // 开始日期
$endDate = trim($_GET['end_date'] ?? ''); // 结束日期

// 分页参数（使用p避免与路由page冲突）
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50])) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

// 构建查询条件
$where = '1=1';
$params = [];

// 权限过滤
if ($user['role'] === 'sales' || $user['role'] === 'service') {
    // 销售和客服只能看自己的客户
    $where .= ' AND c.create_user_id = :create_user_id';
    $params['create_user_id'] = $user['id'];
}
// 管理员不加限制，可以看所有客户

// 过滤已删除客户（默认不显示已删除客户）
$where .= ' AND c.deleted_at IS NULL';

// 搜索条件
if ($search !== '') {
    $where .= ' AND (c.name LIKE :search OR c.mobile LIKE :search OR c.customer_code LIKE :search OR c.custom_id LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

// 快捷时间筛选
if ($timeFilter !== '' && $startDate === '' && $endDate === '') {
    $now = time();
    $startTime = 0;
    
    switch ($timeFilter) {
        case 'today':
            $startTime = strtotime('today');
            $endTime = strtotime('tomorrow') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'yesterday':
            $startTime = strtotime('yesterday');
            $endTime = strtotime('today') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'day_before':
            $startTime = strtotime('-2 days', strtotime('today'));
            $endTime = strtotime('yesterday') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'week':
            $startTime = strtotime('-7 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
        case 'two_weeks':
            $startTime = strtotime('-14 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
        case 'month':
            $startTime = strtotime('-30 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
    }
}

// 自定义日期范围筛选（优先级高于快捷筛选）
if ($startDate !== '' && $endDate !== '') {
    $startTime = strtotime($startDate . ' 00:00:00');
    $endTime = strtotime($endDate . ' 23:59:59');
    $where .= ' AND c.create_time BETWEEN :date_start AND :date_end';
    $params['date_start'] = $startTime;
    $params['date_end'] = $endTime;
} elseif ($startDate !== '') {
    $startTime = strtotime($startDate . ' 00:00:00');
    $where .= ' AND c.create_time >= :date_start';
    $params['date_start'] = $startTime;
} elseif ($endDate !== '') {
    $endTime = strtotime($endDate . ' 23:59:59');
    $where .= ' AND c.create_time <= :date_end';
    $params['date_end'] = $endTime;
}

// 先查询总数
$countSql = "SELECT COUNT(*) as total FROM customers c WHERE {$where}";
$totalResult = Db::queryOne($countSql, $params);
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

// 查询客户列表
$sql = "SELECT 
    c.*,
    u.realname as owner_name,
    fc.next_follow_time,
    (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND category = 'client_material' AND deleted_at IS NULL) as customer_file_count,
    (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND category = 'internal_solution' AND deleted_at IS NULL) as company_file_count,
    cl.enabled as link_enabled
FROM customers c
LEFT JOIN users u ON c.create_user_id = u.id
LEFT JOIN first_contact fc ON c.id = fc.customer_id
LEFT JOIN customer_links cl ON c.id = cl.customer_id
WHERE {$where}
ORDER BY c.create_time DESC
LIMIT {$perPage} OFFSET {$offset}";

$customers = Db::query($sql, $params);

// 构建分页URL的辅助函数
function buildPageUrl($pageNum, $currentParams) {
    $params = $currentParams;
    $params['p'] = $pageNum;
    return 'mobile_my_customers.php?' . http_build_query($params);
}

$currentParams = $_GET;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <title>我的客户 - ANKOTTI Mobile</title>
    <link rel="stylesheet" href="css/mobile-customer.css">
    <style>
        /* 搜索栏样式 */
        .search-section {
            padding: 16px;
            background: var(--card-bg);
            margin-bottom: 12px;
            border-bottom: 1px solid var(--divider-color);
        }
        
        .search-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input-wrapper input {
            width: 100%;
            padding: 10px 40px 10px 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 16px;
            background: var(--bg-color);
        }
        
        .search-input-wrapper .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            opacity: 0.5;
        }
        
        .filter-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-row .form-input {
            flex: 1;
            min-width: 0;
        }
        
        .filter-badge {
            display: inline-block;
            padding: 4px 8px;
            background: var(--bg-color);
            border-radius: var(--radius-sm);
            font-size: 12px;
            color: var(--text-secondary);
            margin: 4px 4px 0 0;
        }
        
        /* 客户卡片样式 */
        .customer-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
        }
        
        .customer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .customer-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 4px 0;
        }
        
        .customer-id {
            font-size: 13px;
            color: var(--text-secondary);
            font-family: monospace;
            margin: 0;
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .customer-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .customer-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 12px;
            border-top: 1px solid var(--divider-color);
        }
        
        .customer-actions .btn {
            flex: 1;
            min-width: 0;
            font-size: 14px;
            padding: 8px 12px;
        }
        
        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        /* 分页样式 */
        .pagination-wrapper {
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .pagination-info {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .pagination-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .pagination-buttons .btn {
            min-width: 44px;
            height: 44px;
            padding: 0 12px;
        }
        
        /* 快捷筛选按钮 */
        .quick-filter-btn {
            padding: 8px 12px;
            font-size: 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-primary);
        }
        
        .quick-filter-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="mobile_home.php" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
        </a>
        <div class="logo">我的客户</div>
        <div style="display: flex; gap: 8px;">
            <a href="https://okr.ankotti.com/" target="_blank" class="back-btn" style="cursor: pointer;" title="OKR">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
            </a>
            <a href="mobile_customer_detail.php" class="back-btn" style="cursor: pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
            </a>
        </div>
    </header>
    
    <!-- 搜索栏 -->
    <div class="search-section">
        <form method="get" class="search-form" id="searchForm">
            <input type="hidden" name="p" value="1">
            
            <div class="search-input-wrapper">
                <input type="text" name="search" class="form-input" placeholder="搜索客户姓名/手机/编号" 
                       value="<?= htmlspecialchars($search) ?>">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
            </div>
            
            <div class="filter-row">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 12px; color: var(--text-secondary);">开始日期</label>
                    <input type="date" name="start_date" class="form-input" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 12px; color: var(--text-secondary);">结束日期</label>
                    <input type="date" name="end_date" class="form-input" value="<?= htmlspecialchars($endDate) ?>">
                </div>
            </div>
            
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" class="quick-filter-btn <?= $timeFilter === 'today' ? 'active' : '' ?>" 
                        data-filter="today">今天</button>
                <button type="button" class="quick-filter-btn <?= $timeFilter === 'yesterday' ? 'active' : '' ?>" 
                        data-filter="yesterday">昨天</button>
                <button type="button" class="quick-filter-btn <?= $timeFilter === 'week' ? 'active' : '' ?>" 
                        data-filter="week">一周</button>
                <button type="button" class="quick-filter-btn <?= $timeFilter === 'month' ? 'active' : '' ?>" 
                        data-filter="month">一月</button>
                <button type="button" class="quick-filter-btn" onclick="clearFilters()">清除</button>
            </div>
            
            <input type="hidden" name="time_filter" id="timeFilterInput" value="<?= htmlspecialchars($timeFilter) ?>">
            
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">搜索</button>
                <button type="button" class="btn btn-outline" onclick="resetSearch()">重置</button>
            </div>
        </form>
        
        <!-- 筛选条件提示 -->
        <?php if ($timeFilter || $startDate || $endDate || $search): ?>
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--divider-color);">
            <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">当前筛选:</div>
            <div>
                <?php if ($timeFilter): ?>
                    <?php 
                    $filterNames = [
                        'today' => '今天',
                        'yesterday' => '昨天',
                        'day_before' => '前天',
                        'week' => '一周内',
                        'two_weeks' => '2周内',
                        'month' => '一个月内'
                    ];
                    ?>
                    <span class="filter-badge"><?= $filterNames[$timeFilter] ?? $timeFilter ?></span>
                <?php endif; ?>
                <?php if ($startDate || $endDate): ?>
                    <span class="filter-badge">
                        <?= $startDate ? date('Y-m-d', strtotime($startDate)) : '...' ?> 
                        至 
                        <?= $endDate ? date('Y-m-d', strtotime($endDate)) : '...' ?>
                    </span>
                <?php endif; ?>
                <?php if ($search): ?>
                    <span class="filter-badge">搜索: <?= htmlspecialchars($search) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 客户列表 -->
    <div class="container">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <div>暂无客户数据</div>
            </div>
        <?php else: ?>
            <?php foreach ($customers as $customer): ?>
            <div class="customer-card">
                <div class="customer-card-header">
                    <div style="flex: 1;">
                        <h3 class="customer-name"><?= htmlspecialchars($customer['name']) ?></h3>
                        <p class="customer-id"><?= htmlspecialchars($customer['customer_code']) ?></p>
                        <?php if ($customer['custom_id']): ?>
                            <p class="customer-id" style="margin-top: 4px;">填写ID: <?= htmlspecialchars($customer['custom_id']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right;">
                        <?php if ($customer['link_enabled'] !== null): ?>
                            <span style="font-size: 12px; color: var(--text-secondary); display: block;">
                                <?= $customer['link_enabled'] ? '✅ 链接已启用' : '⭕ 链接已停用' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="customer-info">
                    <?php if ($customer['mobile']): ?>
                        <div class="customer-info-item">
                            <span>📱</span>
                            <span><?= htmlspecialchars($customer['mobile']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="customer-info-item">
                        <span>📁</span>
                        <span>客户文件: <?= $customer['customer_file_count'] ?> 个</span>
                        <span style="margin-left: 12px;">我们的文件: <?= $customer['company_file_count'] ?> 个</span>
                    </div>
                    <div class="customer-info-item">
                        <span>🕐</span>
                        <span>创建: <?= date('Y-m-d H:i', $customer['create_time']) ?></span>
                    </div>
                    <?php if ($customer['next_follow_time']): ?>
                        <div class="customer-info-item">
                            <span>⏰</span>
                            <span>下次跟进: <?= date('Y-m-d H:i', $customer['next_follow_time']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="customer-actions">
                    <a href="mobile_customer_detail.php?id=<?= $customer['id'] ?>" class="btn btn-primary">编辑</a>
                    <a href="mobile_customer_detail.php?id=<?= $customer['id'] ?>#module-file" class="btn btn-outline">文件</a>
                    <?php if ($customer['customer_code']): ?>
                        <button type="button" class="btn btn-outline" onclick="showLinkManageModal(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['customer_code'], ENT_QUOTES) ?>')">链接</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline" onclick="deleteCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">删除</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- 分页导航 -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-wrapper">
        <div class="pagination-info">
            共 <?= $total ?> 条，每页
            <select class="form-select" style="display: inline-block; width: auto; padding: 4px 8px; margin: 0 4px;" 
                    onchange="changePerPage(this.value)">
                <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
                <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
            </select>
            条
        </div>
        <div class="pagination-buttons">
            <?php if ($page > 1): ?>
                <a href="<?= buildPageUrl(1, $currentParams) ?>" class="btn btn-outline">首页</a>
                <a href="<?= buildPageUrl($page - 1, $currentParams) ?>" class="btn btn-outline">上一页</a>
            <?php endif; ?>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1): ?>
                <span class="btn btn-outline" style="opacity: 0.5;">...</span>
            <?php endif;
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="<?= buildPageUrl($i, $currentParams) ?>" 
                   class="btn <?= $i == $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
            <?php endfor;
            if ($endPage < $totalPages): ?>
                <span class="btn btn-outline" style="opacity: 0.5;">...</span>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= buildPageUrl($page + 1, $currentParams) ?>" class="btn btn-outline">下一页</a>
                <a href="<?= buildPageUrl($totalPages, $currentParams) ?>" class="btn btn-outline">末页</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Toast -->
    <div class="toast" id="toast"></div>
    
    <!-- 链接管理模态框 -->
    <div class="link-manage-modal" id="linkManageModal" style="display: none;">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">链接管理</h3>
                <button class="modal-close" id="linkManageClose">✕</button>
            </div>
            <div class="modal-body" id="linkManageBody">
                <div class="loading-state">加载中...</div>
            </div>
            <div class="modal-footer" id="linkManageFooter" style="display: none;">
                <button class="btn btn-outline" id="linkManageCancel">取消</button>
                <button class="btn btn-primary" id="linkManageSave">保存设置</button>
            </div>
        </div>
    </div>
    
    <script>
        // 视图模式管理
        (function() {
            const VIEW_MODE_KEY = 'ankotti_view_mode';
            
            function setViewMode(mode) {
                if (mode === 'mobile' || mode === 'desktop') {
                    localStorage.setItem(VIEW_MODE_KEY, mode);
                }
            }
            
            // 页面加载时自动设置视图模式（手机版）
            const currentPath = window.location.pathname;
            if (currentPath.includes('mobile_my_customers.php') || currentPath.includes('mobile_customer_detail.php')) {
                setViewMode('mobile');
            }
        })();
        
        // Toast 通知
        // Toast提示（iOS风格）
        function showToast(message, type = 'info', duration = null) {
            const toast = document.getElementById('toast');
            if (!toast) return;
            
            // 移除之前的类型类
            toast.className = 'toast';
            
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
                toast.classList.add(type);
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
                toast.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-text">${escapeHtml(message)}</span>`;
                toast.classList.add('with-icon');
            } else {
                toast.textContent = message;
                toast.classList.remove('with-icon');
            }
            
            // 触发动画（使用requestAnimationFrame确保DOM更新）
            requestAnimationFrame(() => {
                toast.classList.add('show');
            });
            
            // 自动隐藏
            setTimeout(() => {
                toast.classList.remove('show');
                // 等待动画完成后重置内容
                setTimeout(() => {
                    toast.className = 'toast';
                    toast.textContent = '';
                }, 350);
            }, displayDuration);
        }
        
        // HTML转义辅助函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 快捷时间筛选
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            if (btn.dataset.filter) {
                btn.addEventListener('click', function() {
                    const filter = this.dataset.filter;
                    const isActive = this.classList.contains('active');
                    
                    // 清除所有活动状态
                    document.querySelectorAll('.quick-filter-btn').forEach(b => b.classList.remove('active'));
                    
                    // 如果点击的是已激活的，则清除筛选
                    if (isActive) {
                        document.getElementById('timeFilterInput').value = '';
                        document.querySelectorAll('.search-form input[type="date"]').forEach(input => input.value = '');
                    } else {
                        this.classList.add('active');
                        document.getElementById('timeFilterInput').value = filter;
                        document.querySelectorAll('.search-form input[type="date"]').forEach(input => input.value = '');
                    }
                    
                    document.getElementById('searchForm').submit();
                });
            }
        });
        
        // 清除筛选
        function clearFilters() {
            document.getElementById('timeFilterInput').value = '';
            document.querySelectorAll('.search-form input[type="text"]').forEach(input => {
                if (input.name === 'search' || input.name === 'start_date' || input.name === 'end_date') {
                    input.value = '';
                }
            });
            document.querySelectorAll('.quick-filter-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('searchForm').submit();
        }
        
        // 重置搜索
        function resetSearch() {
            window.location.href = 'mobile_my_customers.php';
        }
        
        // 改变每页显示数量
        function changePerPage(perPage) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPage);
            url.searchParams.set('p', '1'); // 重置到第一页
            window.location.href = url.toString();
        }
        
        // ========== 链接管理功能 ==========
        let linkCustomerId = null;
        let linkCustomerCode = null;
        let linkData = null;
        const BASE_URL = window.location.origin;
        
        // 显示链接管理模态框
        function showLinkManageModal(customerId, customerCode) {
            linkCustomerId = customerId;
            linkCustomerCode = customerCode;
            
            const modal = document.getElementById('linkManageModal');
            if (!modal) return;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // 加载链接信息
            loadLinkInfo();
        }
        
        // 隐藏链接管理模态框
        function hideLinkManageModal() {
            const modal = document.getElementById('linkManageModal');
            if (!modal) return;
            
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // 加载链接信息
        function loadLinkInfo() {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body || !linkCustomerId) return;
            
            body.innerHTML = '<div class="loading-state">加载中...</div>';
            footer.style.display = 'none';
            
            const formData = new URLSearchParams({
                action: 'get',
                customer_id: linkCustomerId
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    linkData = data.data;
                    renderLinkManagement(linkData);
                } else {
                    renderGenerateLink();
                }
            })
            .catch(err => {
                console.error('加载链接信息失败:', err);
                body.innerHTML = '<div class="error-state">加载失败，请重试</div>';
            });
        }
        
        // 渲染链接管理界面
        function renderLinkManagement(link) {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body) return;
            
            const shareUrl = BASE_URL + '/share.php?code=' + linkCustomerCode;
            const hasPassword = link.has_password || false;
            const orgPermission = link.org_permission || 'edit';
            const passwordPermission = link.password_permission || 'editable';
            
            body.innerHTML = `
                <div class="link-manage-section">
                    <label class="form-label">🌐 分享链接</label>
                    <div id="regionLinksContainer">
                        <div style="color:#999;font-size:12px;">加载区域链接中...</div>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <div class="option-row">
                        <label>启用分享</label>
                        <label class="switch">
                            <input type="checkbox" id="linkEnabledSwitch" ${link.enabled ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">密码保护</label>
                    <input type="text" class="form-input" id="linkPasswordInput" placeholder="留空表示无密码" ${hasPassword ? 'value="********"' : ''}>
                    <small class="form-hint">未登录用户需要输入密码才能访问</small>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">密码权限级别</label>
                    <div class="options-group">
                        <div class="option-chip">
                            <input type="radio" name="passwordPermission" id="pwdReadonly" value="readonly" ${passwordPermission === 'readonly' ? 'checked' : ''}>
                            <label for="pwdReadonly">只读</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="passwordPermission" id="pwdEditable" value="editable" ${passwordPermission === 'editable' ? 'checked' : ''}>
                            <label for="pwdEditable">可编辑</label>
                        </div>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">组织内权限</label>
                    <div class="options-group">
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgNone" value="none" ${orgPermission === 'none' ? 'checked' : ''}>
                            <label for="orgNone">禁止访问</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgView" value="view" ${orgPermission === 'view' ? 'checked' : ''}>
                            <label for="orgView">只读</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgEdit" value="edit" ${orgPermission === 'edit' ? 'checked' : ''}>
                            <label for="orgEdit">可编辑</label>
                        </div>
                    </div>
                    <small class="form-hint">登录用户的默认权限</small>
                </div>
                
                ${link.access_count ? `
                <div class="link-manage-section">
                    <div class="info-card">
                        <strong>访问统计</strong>
                        <p>访问次数：${link.access_count}</p>
                        ${link.last_access_at ? `<p>最后访问：${new Date(link.last_access_at * 1000).toLocaleString('zh-CN')}</p>` : ''}
                    </div>
                </div>
                ` : ''}
            `;
            
            footer.style.display = 'flex';
            
            // 加载多区域链接
            loadRegionLinks();
            
            // 绑定保存按钮事件
            document.getElementById('linkManageSave')?.addEventListener('click', updateLinkSettings);
        }
        
        // 加载多区域分享链接
        function loadRegionLinks() {
            const container = document.getElementById('regionLinksContainer');
            if (!container) return;
            
            fetch('/api/customer_link.php?action=get_region_urls&customer_code=' + encodeURIComponent(linkCustomerCode))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.regions && data.regions.length > 0) {
                        container.innerHTML = data.regions.map((r, idx) => `
                            <div class="share-link-display" style="margin-bottom:8px;">
                                <span style="min-width:60px;font-size:12px;color:#666;">${r.is_default ? '⭐' : ''} ${r.region_name}</span>
                                <input type="text" class="form-input" id="regionLink_${idx}" value="${r.url}" readonly style="flex:1;font-size:12px;">
                                <button class="btn btn-primary" data-link-idx="${idx}" style="font-size:12px;padding:6px 10px;">复制</button>
                            </div>
                        `).join('');
                        
                        container.querySelectorAll('button[data-link-idx]').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const idx = this.dataset.linkIdx;
                                const input = document.getElementById('regionLink_' + idx);
                                if (input) {
                                    input.select();
                                    document.execCommand('copy');
                                    showToast('链接已复制');
                                }
                            });
                        });
                    } else {
                        const defaultUrl = BASE_URL + '/share.php?code=' + linkCustomerCode;
                        container.innerHTML = `
                            <div class="share-link-display">
                                <input type="text" class="form-input" id="shareLinkInput" value="${defaultUrl}" readonly>
                                <button class="btn btn-primary" id="copyDefaultBtn">复制</button>
                            </div>
                        `;
                        document.getElementById('copyDefaultBtn')?.addEventListener('click', copyShareLink);
                    }
                })
                .catch(err => {
                    container.innerHTML = '<div style="color:#f00;font-size:12px;">加载失败</div>';
                });
        }
        
        // 渲染生成链接界面
        function renderGenerateLink() {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body) return;
            
            body.innerHTML = `
                <div class="empty-state">
                    <p>该客户还未生成分享链接</p>
                    <button class="btn btn-primary" id="generateLinkBtn">生成分享链接</button>
                </div>
            `;
            
            footer.style.display = 'none';
            
            // 绑定生成按钮事件
            document.getElementById('generateLinkBtn')?.addEventListener('click', generateLink);
        }
        
        // 生成分享链接
        function generateLink() {
            if (!linkCustomerId) return;
            
            const formData = new URLSearchParams({
                action: 'generate',
                customer_id: linkCustomerId
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('分享链接生成成功');
                    loadLinkInfo();
                } else {
                    showToast(data.message || '生成失败');
                }
            })
            .catch(err => {
                console.error('生成链接失败:', err);
                showToast('生成失败，请重试');
            });
        }
        
        // 复制分享链接
        function copyShareLink() {
            const input = document.getElementById('shareLinkInput');
            if (!input) return;
            
            input.select();
            input.setSelectionRange(0, 99999);
            
            try {
                document.execCommand('copy');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        showToast('链接已复制');
                    });
                } else {
                    showToast('链接已复制');
                }
            } catch (err) {
                console.error('复制失败:', err);
                showToast('复制失败，请手动复制');
            }
        }
        
        // 更新链接设置
        function updateLinkSettings() {
            if (!linkCustomerId) return;
            
            const enabled = document.getElementById('linkEnabledSwitch')?.checked ? 1 : 0;
            const password = document.getElementById('linkPasswordInput')?.value.trim() || '';
            const orgPermission = document.querySelector('input[name="orgPermission"]:checked')?.value || 'edit';
            const passwordPermission = document.querySelector('input[name="passwordPermission"]:checked')?.value || 'editable';
            
            const formData = new URLSearchParams({
                action: 'update',
                customer_id: linkCustomerId,
                enabled: enabled,
                password: password,
                org_permission: orgPermission,
                password_permission: passwordPermission
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('设置保存成功');
                    hideLinkManageModal();
                    if (data.data) {
                        linkData = data.data;
                    }
                    // 刷新页面以更新链接状态显示
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                } else {
                    showToast(data.message || '保存失败');
                }
            })
            .catch(err => {
                console.error('保存设置失败:', err);
                showToast('保存失败，请重试');
            });
        }
        
        // 绑定链接管理模态框事件
        document.addEventListener('DOMContentLoaded', function() {
            const linkManageClose = document.getElementById('linkManageClose');
            if (linkManageClose) {
                linkManageClose.addEventListener('click', hideLinkManageModal);
            }
            
            const linkManageCancel = document.getElementById('linkManageCancel');
            if (linkManageCancel) {
                linkManageCancel.addEventListener('click', hideLinkManageModal);
            }
            
            const modalOverlay = document.querySelector('.link-manage-modal .modal-overlay');
            if (modalOverlay) {
                modalOverlay.addEventListener('click', hideLinkManageModal);
            }
        });
        
        // 删除客户
        function deleteCustomer(customerId, customerName) {
            showConfirmModal('删除客户', '确定要删除客户 "' + customerName + '" 吗？<br><br><strong>⚠️ 此操作不可恢复，将删除该客户的所有相关数据（首通、异议、成交、文件等）！</strong>', function() {
                const formData = new FormData();
                formData.append('customer_id', customerId);
                
                fetch('../api/customer_delete.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || '删除成功');
                        // 1.5秒后刷新页面
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast(data.message || '删除失败');
                    }
                })
                .catch(error => {
                    console.error('删除客户错误:', error);
                    showToast('删除失败，请重试');
                });
            });
        }
    </script>
</body>
</html>

