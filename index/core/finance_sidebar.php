<?php
/**
 * 财务模块侧边栏组件
 * 用法: 在页面顶部调用 finance_sidebar_start()，结尾调用 finance_sidebar_end()
 * 支持折叠/展开功能，状态通过 localStorage 持久化
 */

function finance_sidebar_start($currentPage = '') {
    ?>
    <style>
    .finance-sidebar {
        width: 200px;
        min-height: calc(100vh - 70px);
        max-height: calc(100vh - 70px);
        background: #f8f9fa;
        border-right: 1px solid #dee2e6;
        position: sticky;
        top: 70px;
        flex-shrink: 0;
        transition: width 0.25s ease;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }
    .finance-sidebar.collapsed {
        width: 60px;
    }
    .finance-sidebar .nav-link {
        color: #495057;
        padding: 12px 16px;
        border-left: 3px solid transparent;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        position: relative;
    }
    .finance-sidebar .nav-link:hover {
        background: #e9ecef;
    }
    .finance-sidebar .nav-link.active {
        background: #e7f1ff;
        color: #0d6efd;
        border-left-color: #0d6efd;
        font-weight: 500;
    }
    .finance-sidebar .nav-link i {
        flex-shrink: 0;
        font-size: 16px;
    }
    .finance-sidebar .nav-link .nav-text {
        opacity: 1;
        transition: opacity 0.2s ease;
    }
    .finance-sidebar.collapsed .nav-link {
        padding: 12px;
        justify-content: center;
    }
    .finance-sidebar.collapsed .nav-link .nav-text {
        opacity: 0;
        width: 0;
        overflow: hidden;
    }
    .finance-sidebar .nav-header {
        padding: 12px 16px 6px;
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        overflow: hidden;
        transition: opacity 0.2s ease;
    }
    .finance-sidebar.collapsed .nav-header {
        opacity: 0;
        height: 0;
        padding: 0;
        margin: 0;
    }
    .finance-sidebar .sidebar-toggle {
        margin-top: auto;
        padding: 12px;
        border-top: 1px solid #dee2e6;
        text-align: center;
    }
    .finance-sidebar .sidebar-toggle button {
        background: none;
        border: none;
        color: #6c757d;
        cursor: pointer;
        padding: 8px;
        border-radius: 4px;
        transition: all 0.2s;
    }
    .finance-sidebar .sidebar-toggle button:hover {
        background: #e9ecef;
        color: #495057;
    }
    .finance-sidebar .sidebar-toggle button i {
        font-size: 16px;
        transition: transform 0.25s ease;
    }
    .finance-sidebar.collapsed .sidebar-toggle button i {
        transform: rotate(180deg);
    }
    /* Tooltip for collapsed state */
    .finance-sidebar.collapsed .nav-link[data-title]:hover::after {
        content: attr(data-title);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: #333;
        color: #fff;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        white-space: nowrap;
        z-index: 1000;
        margin-left: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .finance-sidebar.collapsed .nav-link[data-title]:hover::before {
        content: '';
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        border: 6px solid transparent;
        border-right-color: #333;
        margin-left: -4px;
        z-index: 1000;
    }
    .finance-content {
        flex: 1;
        min-width: 0;
        padding: 1rem;
        transition: margin-left 0.25s ease;
    }
    @media (max-width: 991px) {
        .finance-sidebar { display: none !important; }
    }
    </style>

    <div class="d-flex">
        <!-- 侧边栏导航 -->
        <div class="finance-sidebar d-none d-lg-block" id="financeSidebar">
            <nav class="nav flex-column py-3">
                <div class="nav-header">收款管理</div>
                <a class="nav-link <?= $currentPage === 'finance_cashier' ? 'active' : '' ?>" href="index.php?page=finance_cashier" data-title="收款台">
                    <i class="bi bi-calculator"></i><span class="nav-text">收款台</span>
                </a>
                <a class="nav-link <?= $currentPage === 'finance_dashboard' ? 'active' : '' ?>" href="index.php?page=finance_dashboard" data-title="收款工作台">
                    <i class="bi bi-cash-stack"></i><span class="nav-text">收款工作台</span>
                </a>
                <a class="nav-link <?= $currentPage === 'my_receivables' ? 'active' : '' ?>" href="index.php?page=my_receivables" data-title="催款列表">
                    <i class="bi bi-bell"></i><span class="nav-text">催款列表</span>
                </a>
                
                <div class="nav-header mt-3">预付款</div>
                <a class="nav-link <?= $currentPage === 'finance_prepay' ? 'active' : '' ?>" href="index.php?page=finance_prepay" data-title="预付款管理">
                    <i class="bi bi-wallet2"></i><span class="nav-text">预付款管理</span>
                </a>
                <a class="nav-link <?= $currentPage === 'finance_prepay_report' ? 'active' : '' ?>" href="index.php?page=finance_prepay_report" data-title="预付款报表">
                    <i class="bi bi-file-earmark-bar-graph"></i><span class="nav-text">预付款报表</span>
                </a>
                
                <div class="nav-header mt-3">提成</div>
                <a class="nav-link <?= $currentPage === 'commission_calculator' ? 'active' : '' ?>" href="index.php?page=commission_calculator" data-title="提成计算">
                    <i class="bi bi-calculator"></i><span class="nav-text">提成计算</span>
                </a>
                <a class="nav-link <?= $currentPage === 'commission_rules' ? 'active' : '' ?>" href="index.php?page=commission_rules" data-title="提成规则">
                    <i class="bi bi-gear"></i><span class="nav-text">提成规则</span>
                </a>
                
                <div class="nav-header mt-3">报表</div>
                <a class="nav-link <?= $currentPage === 'finance_fee_report' ? 'active' : '' ?>" href="index.php?page=finance_fee_report" data-title="手续费报表">
                    <i class="bi bi-receipt"></i><span class="nav-text">手续费报表</span>
                </a>
                
                <div class="nav-header mt-3">设置</div>
                <a class="nav-link <?= $currentPage === 'exchange_rate' ? 'active' : '' ?>" href="index.php?page=exchange_rate" data-title="汇率管理">
                    <i class="bi bi-currency-exchange"></i><span class="nav-text">汇率管理</span>
                </a>
                <a class="nav-link <?= $currentPage === 'admin_payment_methods' ? 'active' : '' ?>" href="index.php?page=admin_payment_methods" data-title="支付方式">
                    <i class="bi bi-credit-card"></i><span class="nav-text">支付方式</span>
                </a>
            </nav>
            
            <!-- 折叠/展开按钮 -->
            <div class="sidebar-toggle">
                <button onclick="toggleFinanceSidebar()" title="折叠/展开侧边栏">
                    <i class="bi bi-chevron-left"></i>
                </button>
            </div>
        </div>
        
        <!-- 主内容区 -->
        <div class="finance-content">
    <?php
}

function finance_sidebar_end() {
    ?>
        </div><!-- /.finance-content -->
    </div><!-- /.d-flex -->
    
    <script>
    // 侧边栏折叠/展开功能
    (function() {
        const STORAGE_KEY = 'finance_sidebar_collapsed';
        const sidebar = document.getElementById('financeSidebar');
        
        if (!sidebar) return;
        
        // 页面加载时恢复状态
        const isCollapsed = localStorage.getItem(STORAGE_KEY) === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        // 定义全局切换函数
        window.toggleFinanceSidebar = function() {
            sidebar.classList.toggle('collapsed');
            const nowCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem(STORAGE_KEY, nowCollapsed);
        };
    })();
    </script>
    <?php
}
