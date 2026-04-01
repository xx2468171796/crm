<?php
/**
 * 客户门户（客户级）- 重新设计版
 * 展示客户的所有项目列表和项目详情四看板
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_GET['token'] ?? '');
$projectId = intval($_GET['project_id'] ?? 0);

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>访问错误</title></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;"><h3>无效的访问链接</h3></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0891b2">
    <title>設計空間</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/portal-theme.css">
</head>
<body class="portal-page">
    <!-- 背景装饰 -->
    <div class="portal-bg-decoration"></div>
    <div class="portal-bg-decoration-extra"></div>
    
    <!-- 主容器 -->
    <div class="portal-container">
        <div class="portal-layout">
            <!-- 桌面端侧边导航 -->
            <nav class="portal-sidebar" id="portalSidebar">
                <div class="portal-logo">
                    <img src="images/logo-ankotti.svg" alt="ANKOTTI" style="height: 40px; width: auto;">
                </div>
                
                <ul class="portal-nav" id="portalNav">
                    <li class="portal-nav-item active" data-view="home" onclick="switchView('home')">
                        <i class="bi bi-house"></i>
                        <span>首页</span>
                    </li>
                    <li class="portal-nav-item" data-view="projects" onclick="switchView('projects')">
                        <i class="bi bi-folder"></i>
                        <span>我的项目</span>
                    </li>
                    <li class="portal-nav-item" data-view="forms" onclick="switchView('forms')">
                        <i class="bi bi-file-text"></i>
                        <span>需求表单</span>
                    </li>
                    <li class="portal-nav-item" data-view="deliverables" onclick="switchView('deliverables')">
                        <i class="bi bi-box"></i>
                        <span>交付作品</span>
                    </li>
                </ul>
                
                <div class="portal-sidebar-footer" style="margin-top: auto; padding-top: 16px; border-top: 1px solid var(--portal-border);">
                    <div style="font-size: 13px; color: var(--portal-text-muted);">
                        <i class="bi bi-person-circle"></i>
                        <span id="sidebarCustomerName">加载中...</span>
                    </div>
                </div>
            </nav>
            
            <!-- 主内容区 -->
            <main class="portal-main">
                <!-- 移动端顶部栏 -->
                <div class="portal-mobile-header">
                    <div class="portal-logo" style="margin-bottom: 0;">
                        <img src="images/logo-ankotti.svg" alt="ANKOTTI" style="height: 28px; width: auto;">
                    </div>
                    <span id="mobileCustomerName" style="font-size: 13px; color: var(--portal-text-muted);"></span>
                </div>
                
                <!-- 密码验证视图 -->
                <div id="passwordView" class="portal-view" style="display: none;">
                    <div style="min-height: 80vh; display: flex; align-items: center; justify-content: center;">
                        <div class="portal-card portal-card-solid" style="max-width: 400px; width: 100%;">
                            <div style="text-align: center; margin-bottom: 24px;">
                                <img src="images/logo-ankotti.svg" alt="ANKOTTI" style="height: 48px; width: auto; margin-bottom: 16px;">
                                <h2 style="font-size: 22px; font-weight: 700; margin: 0;">欢迎访问</h2>
                                <p style="color: var(--portal-text-secondary); margin-top: 8px;">请输入访问密码以继续</p>
                            </div>
                            <div class="portal-form-group">
                                <input type="password" class="portal-input" id="passwordInput" placeholder="请输入密码" autocomplete="off">
                            </div>
                            <button class="portal-btn portal-btn-primary portal-btn-block" onclick="verifyPassword()">
                                <i class="bi bi-unlock"></i> 验证访问
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- 首页视图 -->
                <div id="homeView" class="portal-view" style="display: none;">
                    <div class="portal-header">
                        <div>
                            <h1 class="portal-header-title">欢迎回来</h1>
                            <p class="portal-header-subtitle" id="welcomeCustomerName"></p>
                        </div>
                    </div>
                    
                    <!-- 快速统计 -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                        <div class="portal-card portal-card-solid">
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <div style="width: 48px; height: 48px; background: rgba(99, 102, 241, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--portal-primary); font-size: 22px;">
                                    <i class="bi bi-folder"></i>
                                </div>
                                <div>
                                    <div style="font-size: 28px; font-weight: 700; color: var(--portal-text);" id="statProjects">-</div>
                                    <div style="font-size: 13px; color: var(--portal-text-muted);">进行中项目</div>
                                </div>
                            </div>
                        </div>
                        <div class="portal-card portal-card-solid">
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <div style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--portal-success); font-size: 22px;">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div>
                                    <div style="font-size: 28px; font-weight: 700; color: var(--portal-text);" id="statCompleted">-</div>
                                    <div style="font-size: 13px; color: var(--portal-text-muted);">已完成项目</div>
                                </div>
                            </div>
                        </div>
                        <div class="portal-card portal-card-solid">
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <div style="width: 48px; height: 48px; background: rgba(245, 158, 11, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--portal-warning); font-size: 22px;">
                                    <i class="bi bi-file-text"></i>
                                </div>
                                <div>
                                    <div style="font-size: 28px; font-weight: 700; color: var(--portal-text);" id="statForms">-</div>
                                    <div style="font-size: 13px; color: var(--portal-text-muted);">待填表单</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 最近项目 -->
                    <div class="portal-card portal-card-solid">
                        <div class="portal-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="portal-card-title">最近项目</h3>
                            <button class="portal-btn portal-btn-text portal-btn-sm" onclick="switchView('projects')">
                                查看全部 <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                        <div id="recentProjects">
                            <div class="portal-loading">
                                <div class="portal-spinner"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 项目列表视图 -->
                <div id="projectsView" class="portal-view" style="display: none;">
                    <div class="portal-header">
                        <div>
                            <h1 class="portal-header-title">我的项目</h1>
                            <p class="portal-header-subtitle">查看和管理您的所有项目</p>
                        </div>
                    </div>
                    
                    <!-- 项目筛选工具栏 -->
                    <div class="portal-toolbar" id="projectToolbar">
                        <div class="portal-toolbar-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="projectSearch" placeholder="搜索项目名称或编号..." oninput="handleProjectSearch()">
                        </div>
                        <div class="portal-toolbar-filters">
                            <select id="projectStatusFilter" class="portal-select" onchange="applyProjectFilters()">
                                <option value="">全部状态</option>
                                <option value="待沟通">待沟通</option>
                                <option value="需求确认">需求确认</option>
                                <option value="设计中">设计中</option>
                                <option value="设计核对">设计核对</option>
                                <option value="客户完结">客户完结</option>
                                <option value="设计评价">设计评价</option>
                            </select>
                            <select id="projectSort" class="portal-select" onchange="applyProjectFilters()">
                                <option value="update_desc">最近更新 ↓</option>
                                <option value="update_asc">最近更新 ↑</option>
                                <option value="name_asc">名称 A-Z</option>
                            </select>
                            <select id="projectGroup" class="portal-select" onchange="applyProjectFilters()">
                                <option value="">不分组</option>
                                <option value="status">按状态分组</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="projectList"></div>
                </div>
                
                <!-- 项目详情视图 -->
                <div id="projectDetailView" class="portal-view" style="display: none;">
                    <div id="projectDetailContent"></div>
                </div>
                
                <!-- 需求表单视图 -->
                <div id="formsView" class="portal-view" style="display: none;">
                    <div class="portal-header">
                        <div>
                            <h1 class="portal-header-title">需求表单</h1>
                            <p class="portal-header-subtitle">填写和查看您的需求表单</p>
                        </div>
                    </div>
                    <div id="allFormsList"></div>
                </div>
                
                <!-- 交付物视图 -->
                <div id="deliverablesView" class="portal-view" style="display: none;">
                    <div class="portal-header">
                        <div>
                            <h1 class="portal-header-title">交付作品</h1>
                            <p class="portal-header-subtitle">查看和下载您的交付作品</p>
                        </div>
                    </div>
                    <div id="allDeliverablesList"></div>
                </div>
            </main>
        </div>
        
        <!-- 移动端底部导航 -->
        <nav class="portal-tabbar" id="portalTabbar">
            <a class="portal-tabbar-item active" data-view="home" onclick="switchView('home')">
                <i class="bi bi-house"></i>
                <span>首页</span>
            </a>
            <a class="portal-tabbar-item" data-view="projects" onclick="switchView('projects')">
                <i class="bi bi-folder"></i>
                <span>项目</span>
            </a>
            <a class="portal-tabbar-item" data-view="forms" onclick="switchView('forms')">
                <i class="bi bi-file-text"></i>
                <span>需求</span>
            </a>
            <a class="portal-tabbar-item" data-view="deliverables" onclick="switchView('deliverables')">
                <i class="bi bi-box"></i>
                <span>交付</span>
            </a>
        </nav>
    </div>

    <!-- 文件预览模态框 - 支持放大缩小和图片切换 -->
    <div id="previewModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.95); z-index: 9999; flex-direction: column;" onclick="if(event.target === this) closePreview()">
        <!-- 顶部工具栏 -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: rgba(0,0,0,0.5);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span id="previewTitle" style="color: white; font-size: 14px; font-weight: 500; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <span id="previewCounter" style="color: rgba(255,255,255,0.6); font-size: 13px;"></span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="zoomOut()" style="background: rgba(255,255,255,0.1); border: none; width: 40px; height: 40px; border-radius: 8px; color: white; font-size: 20px; cursor: pointer;" title="缩小">
                    <i class="bi bi-zoom-out"></i>
                </button>
                <button onclick="zoomIn()" style="background: rgba(255,255,255,0.1); border: none; width: 40px; height: 40px; border-radius: 8px; color: white; font-size: 20px; cursor: pointer;" title="放大">
                    <i class="bi bi-zoom-in"></i>
                </button>
                <button onclick="resetZoom()" style="background: rgba(255,255,255,0.1); border: none; width: 40px; height: 40px; border-radius: 8px; color: white; font-size: 20px; cursor: pointer;" title="重置">
                    <i class="bi bi-arrows-angle-expand"></i>
                </button>
                <button onclick="closePreview()" style="background: rgba(255,255,255,0.1); border: none; width: 40px; height: 40px; border-radius: 8px; color: white; font-size: 24px; cursor: pointer;" title="关闭">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <!-- 内容区域 -->
        <div id="previewContent" style="flex: 1; display: flex; align-items: center; justify-content: center; overflow: auto; position: relative; touch-action: pinch-zoom;"></div>
        <!-- 左右切换按钮 -->
        <button id="prevBtn" onclick="prevImage()" style="display: none; position: absolute; left: 20px; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); border: none; width: 50px; height: 50px; border-radius: 50%; color: white; font-size: 24px; cursor: pointer; backdrop-filter: blur(4px);">
            <i class="bi bi-chevron-left"></i>
        </button>
        <button id="nextBtn" onclick="nextImage()" style="display: none; position: absolute; right: 20px; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); border: none; width: 50px; height: 50px; border-radius: 50%; color: white; font-size: 24px; cursor: pointer; backdrop-filter: blur(4px);">
            <i class="bi bi-chevron-right"></i>
        </button>
    </div>

    <script src="js/portal-ui.js"></script>
    <script src="js/opencc-lite.js?v=2"></script>
    <script src="js/portal-i18n.js"></script>
    <script>
    const API_URL = '/api';
    const TOKEN = '<?= htmlspecialchars($token) ?>';
    const PROJECT_ID = <?= $projectId ?>;
    let customerData = null;
    let currentView = 'home';
    let currentProjectId = null;

    // ========== 视图切换 ==========
    function switchView(viewName, projectId = null) {
        currentView = viewName;
        
        // 隐藏所有视图
        document.querySelectorAll('.portal-view').forEach(v => v.style.display = 'none');
        
        // 更新导航状态
        document.querySelectorAll('.portal-nav-item, .portal-tabbar-item').forEach(item => {
            item.classList.toggle('active', item.dataset.view === viewName);
        });
        
        // 显示目标视图
        const viewMap = {
            'home': 'homeView',
            'projects': 'projectsView',
            'forms': 'formsView',
            'deliverables': 'deliverablesView',
            'projectDetail': 'projectDetailView'
        };
        
        const targetView = document.getElementById(viewMap[viewName]);
        if (targetView) {
            targetView.style.display = 'block';
            targetView.classList.add('portal-animate-fadeIn');
        }
        
        // 加载数据
        if (viewName === 'home') renderHome();
        if (viewName === 'projects') renderProjects();
        if (viewName === 'forms') renderAllForms();
        if (viewName === 'deliverables') renderAllDeliverables();
        if (viewName === 'projectDetail' && projectId) {
            currentProjectId = projectId;
            renderProjectDetail(projectId);
        }
    }

    // ========== 访问验证 ==========
    function checkAccess() {
        PortalUI.loading.show('正在验证...');
        
        fetch(`${API_URL}/portal_access.php?token=${TOKEN}`)
            .then(r => r.json())
            .then(data => {
                PortalUI.loading.hide();
                if (data.success && data.verified) {
                    // 转换API数据为繁体
                    customerData = typeof PortalI18n !== 'undefined' ? PortalI18n.convertApiData(data.data) : data.data;
                    updateCustomerInfo();
                    
                    if (PROJECT_ID > 0) {
                        switchView('projectDetail', PROJECT_ID);
                    } else {
                        switchView('home');
                    }
                } else {
                    showPasswordView();
                }
            })
            .catch(err => {
                PortalUI.loading.hide();
                PortalUI.Toast.error(t('訪問失敗: ') + err.message);
            });
    }

    function showPasswordView() {
        document.querySelectorAll('.portal-view').forEach(v => v.style.display = 'none');
        document.getElementById('passwordView').style.display = 'block';
        document.getElementById('portalSidebar').style.display = 'none';
        document.getElementById('portalTabbar').style.display = 'none';
    }

    function verifyPassword() {
        const password = document.getElementById('passwordInput').value;
        if (!password) {
            PortalUI.Toast.warning('请输入密码');
            return;
        }
        
        PortalUI.loading.show('正在验证...');
        
        fetch(`${API_URL}/portal_access.php?token=${TOKEN}&password=${encodeURIComponent(password)}`)
            .then(r => r.json())
            .then(data => {
                PortalUI.loading.hide();
                if (data.success && data.verified) {
                    // 转换API数据为繁体
                    customerData = typeof PortalI18n !== 'undefined' ? PortalI18n.convertApiData(data.data) : data.data;
                    updateCustomerInfo();
                    document.getElementById('portalSidebar').style.display = '';
                    document.getElementById('portalTabbar').style.display = '';
                    switchView('home');
                    PortalUI.Toast.success(t('驗證成功'));
                } else {
                    PortalUI.Toast.error(t(data.message || '密碼錯誤'));
                }
            })
            .catch(err => {
                PortalUI.loading.hide();
                PortalUI.Toast.error(t('驗證失敗: ') + err.message);
            });
    }

    function updateCustomerInfo() {
        // 优先显示别名，否则显示姓名
        const alias = customerData?.customer_alias || '';
        const name = customerData?.customer_name || '';
        const groupName = customerData?.customer_group || '';
        const displayName = alias || name;
        const fullDisplay = groupName ? `${displayName} · ${groupName}` : displayName;
        
        document.getElementById('sidebarCustomerName').textContent = fullDisplay;
        document.getElementById('mobileCustomerName').textContent = fullDisplay;
        document.getElementById('welcomeCustomerName').textContent = displayName;
    }

    // ========== 首页渲染 ==========
    function renderHome() {
        if (!customerData) return;
        
        const projects = customerData.projects || [];
        // 已完工：有 completed_at 字段或状态为"完工"
        const completed = projects.filter(p => p.completed_at || p.current_status === '完工').length;
        const inProgress = projects.length - completed;
        
        document.getElementById('statProjects').textContent = inProgress;
        document.getElementById('statCompleted').textContent = completed;
        document.getElementById('statForms').textContent = '-';
        
        // 渲染最近项目
        const recentProjects = projects.slice(0, 3);
        const container = document.getElementById('recentProjects');
        
        if (recentProjects.length === 0) {
            container.innerHTML = `
                <div class="portal-empty">
                    <div class="portal-empty-icon"><i class="bi bi-folder"></i></div>
                    <div class="portal-empty-title">暂无项目</div>
                    <div class="portal-empty-desc">您还没有任何项目</div>
                </div>
            `;
            return;
        }
        
        container.innerHTML = `
            <ul class="portal-list">
                ${recentProjects.map(p => `
                    <li class="portal-list-item" style="cursor: pointer;" onclick="switchView('projectDetail', ${p.id})">
                        <div style="width: 44px; height: 44px; background: var(--portal-gradient); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px;">
                            <i class="bi bi-folder"></i>
                        </div>
                        <div class="portal-list-item-content">
                            <div class="portal-list-item-title">${p.project_name}</div>
                            <div class="portal-list-item-desc">${p.project_code || '-'}</div>
                        </div>
                        <div style="text-align: right;">
                            ${getStatusBadge(p.current_status)}
                            <div style="font-size: 12px; color: var(--portal-text-muted); margin-top: 4px;">
                                ${formatTime(p.update_time)}
                            </div>
                        </div>
                    </li>
                `).join('')}
            </ul>
        `;
    }

    // ========== 项目列表渲染 ==========
    let projectSearchTimer = null;
    
    function handleProjectSearch() {
        clearTimeout(projectSearchTimer);
        projectSearchTimer = setTimeout(() => applyProjectFilters(), 300);
    }
    
    function applyProjectFilters() {
        if (!customerData) return;
        
        let projects = [...(customerData.projects || [])];
        const container = document.getElementById('projectList');
        
        // 搜索
        const searchText = (document.getElementById('projectSearch')?.value || '').trim().toLowerCase();
        if (searchText) {
            projects = projects.filter(p => 
                (p.project_name || '').toLowerCase().includes(searchText) ||
                (p.project_code || '').toLowerCase().includes(searchText)
            );
        }
        
        // 状态筛选
        const statusFilter = document.getElementById('projectStatusFilter')?.value || '';
        if (statusFilter) {
            projects = projects.filter(p => p.current_status === statusFilter);
        }
        
        // 排序
        const sortBy = document.getElementById('projectSort')?.value || 'update_desc';
        projects.sort((a, b) => {
            if (sortBy === 'update_desc') {
                return new Date(b.update_time || 0) - new Date(a.update_time || 0);
            } else if (sortBy === 'update_asc') {
                return new Date(a.update_time || 0) - new Date(b.update_time || 0);
            } else if (sortBy === 'name_asc') {
                return (a.project_name || '').localeCompare(b.project_name || '');
            }
            return 0;
        });
        
        // 渲染
        const groupBy = document.getElementById('projectGroup')?.value || '';
        
        if (projects.length === 0) {
            container.innerHTML = `
                <div class="portal-card portal-card-solid">
                    <div class="portal-empty">
                        <div class="portal-empty-icon"><i class="bi bi-folder"></i></div>
                        <div class="portal-empty-title">暂无匹配项目</div>
                        <div class="portal-empty-desc">尝试调整筛选条件</div>
                    </div>
                </div>
            `;
            return;
        }
        
        if (groupBy === 'status') {
            renderProjectsGrouped(projects, container);
        } else {
            renderProjectsList(projects, container);
        }
    }
    
    function renderProjectsList(projects, container) {
        container.innerHTML = projects.map(p => renderProjectCard(p)).join('');
    }
    
    // 分组展开状态存储
    const groupExpandState = {};
    
    function toggleGroup(groupId) {
        groupExpandState[groupId] = !groupExpandState[groupId];
        const content = document.getElementById(`group-content-${groupId}`);
        const icon = document.getElementById(`group-icon-${groupId}`);
        if (content) {
            content.style.display = groupExpandState[groupId] ? 'none' : 'block';
        }
        if (icon) {
            icon.style.transform = groupExpandState[groupId] ? 'rotate(-90deg)' : 'rotate(0deg)';
        }
    }
    
    function renderProjectsGrouped(projects, container) {
        const statusOrder = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
        const groups = {};
        
        projects.forEach(p => {
            const status = p.current_status || '未知';
            if (!groups[status]) groups[status] = [];
            groups[status].push(p);
        });
        
        let html = '';
        let groupIndex = 0;
        
        statusOrder.forEach(status => {
            if (groups[status] && groups[status].length > 0) {
                const groupId = `status-${groupIndex++}`;
                const isCollapsed = groupExpandState[groupId] || false;
                html += `
                    <div class="portal-group">
                        <div class="portal-group-header" onclick="toggleGroup('${groupId}')" style="cursor: pointer;">
                            <i class="bi bi-chevron-down portal-group-icon" id="group-icon-${groupId}" style="transition: transform 0.2s; ${isCollapsed ? 'transform: rotate(-90deg);' : ''}"></i>
                            <span class="portal-group-title">${typeof t !== 'undefined' ? t(status) : status}</span>
                            <span class="portal-group-count">${groups[status].length}</span>
                        </div>
                        <div class="portal-group-content" id="group-content-${groupId}" style="${isCollapsed ? 'display: none;' : ''}">
                            ${groups[status].map(p => renderProjectCard(p)).join('')}
                        </div>
                    </div>
                `;
            }
        });
        
        // 其他未知状态
        Object.keys(groups).forEach(status => {
            if (!statusOrder.includes(status)) {
                const groupId = `status-${groupIndex++}`;
                const isCollapsed = groupExpandState[groupId] || false;
                html += `
                    <div class="portal-group">
                        <div class="portal-group-header" onclick="toggleGroup('${groupId}')" style="cursor: pointer;">
                            <i class="bi bi-chevron-down portal-group-icon" id="group-icon-${groupId}" style="transition: transform 0.2s; ${isCollapsed ? 'transform: rotate(-90deg);' : ''}"></i>
                            <span class="portal-group-title">${typeof t !== 'undefined' ? t(status) : status}</span>
                            <span class="portal-group-count">${groups[status].length}</span>
                        </div>
                        <div class="portal-group-content" id="group-content-${groupId}" style="${isCollapsed ? 'display: none;' : ''}">
                            ${groups[status].map(p => renderProjectCard(p)).join('')}
                        </div>
                    </div>
                `;
            }
        });
        
        container.innerHTML = html;
    }
    
    function renderProjectCard(p) {
        // 已完工项目进度为100%
        const isCompleted = p.completed_at || p.current_status === '完工';
        const progress = isCompleted ? 100 : (p.overall_progress || 0);
        const progressColor = isCompleted ? '#10b981' : 'var(--portal-primary)';
        
        return `
            <div class="portal-card portal-card-solid portal-card-hover" style="cursor: pointer; margin-bottom: 16px;" onclick="switchView('projectDetail', ${p.id})">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                    <div style="flex: 1;">
                        <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 8px;">${p.project_name}</h3>
                        <p style="color: var(--portal-text-secondary); font-size: 14px; margin: 0 0 12px;">${p.project_code || '-'}</p>
                        ${getStatusBadge(p.current_status)}
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 13px; color: var(--portal-text-muted);">最近更新</div>
                        <div style="font-size: 14px; color: var(--portal-text-secondary);">${formatTime(p.update_time)}</div>
                        <div style="margin-top: 12px;">
                            <div class="portal-progress" style="width: 100px;">
                                <div class="portal-progress-bar" style="width: ${progress}%; background: ${progressColor};"></div>
                            </div>
                            <div style="font-size: 12px; color: ${isCompleted ? '#10b981' : 'var(--portal-text-muted)'}; margin-top: 4px;">${progress}%</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    function renderProjects() {
        applyProjectFilters();
    }

    // ========== 项目详情渲染 ==========
    function renderProjectDetail(projectId) {
        const project = customerData?.projects?.find(p => p.id == projectId);
        if (!project) {
            PortalUI.Toast.error('项目不存在');
            switchView('projects');
            return;
        }
        
        currentProjectData = project;
        
        // 检查评价状态
        checkEvaluationStatus(projectId);
        const container = document.getElementById('projectDetailContent');
        container.innerHTML = `
            <button class="portal-btn portal-btn-ghost portal-btn-sm" onclick="switchView('projects')" style="margin-bottom: 16px;">
                <i class="bi bi-arrow-left"></i> 返回项目列表
            </button>
            
            <div class="portal-card portal-card-solid" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="font-size: 24px; font-weight: 700; margin: 0 0 8px;">${project.project_name}</h2>
                        <p style="color: var(--portal-text-secondary); margin: 0;">${project.project_code || '-'}</p>
                    </div>
                    <div style="text-align: right;">
                        ${getStatusBadge(project.current_status)}
                        <div style="margin-top: 12px;">
                            <div class="portal-progress-with-label">
                                <div class="portal-progress" style="width: 120px;">
                                    <div class="portal-progress-bar" style="width: ${project.overall_progress || 0}%;"></div>
                                </div>
                                <span class="portal-progress-label">${project.overall_progress || 0}%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="portal-tabs" id="projectTabs">
                <a class="portal-tab active" data-tab="tab-progress" onclick="switchProjectTab(event, 'progress')">进度看板</a>
                <a class="portal-tab" data-tab="tab-requirements" onclick="switchProjectTab(event, 'requirements')">需求看板</a>
                <a class="portal-tab" data-tab="tab-deliverables" onclick="switchProjectTab(event, 'deliverables')">交付作品</a>
                <a class="portal-tab" data-tab="tab-upload" onclick="switchProjectTab(event, 'upload')">资料上传</a>
            </div>
            
            <div id="tab-progress" class="portal-tab-content active">
                <!-- 评价提醒卡片（仅在设计评价阶段显示） -->
                <div id="evaluationReminder" style="display: none;" class="portal-card portal-card-solid" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #f59e0b; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="font-size: 40px;">⭐</div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px; color: #92400e;">项目已完成，请评价</h4>
                            <p style="margin: 0; color: #a16207; font-size: 14px;" id="evaluationDeadlineText">请在 7 天内完成评价，您的反馈对我们很重要！</p>
                        </div>
                        <button id="evaluationBtn" class="portal-btn portal-btn-primary" onclick="showEvaluationModal()" style="white-space: nowrap;">
                            <i class="bi bi-star-fill"></i> 立即评价
                        </button>
                    </div>
                </div>
                
                <div class="portal-card portal-card-solid">
                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 20px;">📊 项目进度</h4>
                    ${renderStatusStepper(project.current_status)}
                    <div id="stageTimeProgress" style="display: flex; align-items: center; gap: 16px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--portal-border);">
                        <div id="progressPercent" style="font-size: 32px; font-weight: 700; color: var(--portal-primary);">${project.overall_progress || 0}%</div>
                        <div>
                            <div style="font-weight: 600; color: var(--portal-text);">当前阶段：${project.current_status}</div>
                            <div id="remainingDaysInfo" style="font-size: 13px; color: var(--portal-text-muted);">最近更新：${formatTime(project.update_time)}</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="tab-requirements" class="portal-tab-content" style="display: none;">
                <div id="formsList">
                    <div class="portal-loading">
                        <div class="portal-spinner"></div>
                        <span class="portal-loading-text">加载中...</span>
                    </div>
                </div>
            </div>
            
            <div id="tab-deliverables" class="portal-tab-content" style="display: none;">
                <div id="deliverablesList">
                    <div class="portal-loading">
                        <div class="portal-spinner"></div>
                        <span class="portal-loading-text">加载中...</span>
                    </div>
                </div>
            </div>
            
            <div id="tab-upload" class="portal-tab-content" style="display: none;">
                <div class="portal-card portal-card-solid">
                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 16px;">
                        <i class="bi bi-cloud-upload" style="color: var(--portal-primary);"></i> 上传资料文件
                    </h4>
                    <p style="color: var(--portal-text-muted); margin-bottom: 12px; font-size: 14px;">
                        在此处上传您的资料文件，文件将自动保存到项目的"客户文件"分类中。
                    </p>
                    
                    <div style="background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: var(--portal-primary); display: flex; align-items: center; gap: 8px;">
                        <i class="bi bi-info-circle"></i>
                        单次上传文件总大小上限为 <strong>3GB</strong>
                    </div>
                    
                    <div id="portalUploadZone" class="portal-upload-zone" onclick="document.getElementById('portalFileInput').click()">
                        <div class="portal-upload-icon">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <div class="portal-upload-text">拖拽文件到此处或点击选择文件</div>
                        <div class="portal-upload-hint">支持批量上传（单次总计上限 3GB）</div>
                        <input type="file" id="portalFileInput" multiple style="display: none;" onchange="handlePortalFileSelect(event)">
                    </div>
                    
                    <div id="portalUploadList" style="margin-top: 16px;"></div>
                    
                    <div id="portalOverallProgress" style="display: none; background: var(--portal-bg); border-radius: 8px; padding: 14px; margin-top: 12px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;">
                            <span style="font-weight: 600;">总体上传进度</span>
                            <span id="portalOverallStats">0 / 0 文件</span>
                        </div>
                        <div style="height: 6px; background: var(--portal-border); border-radius: 3px; overflow: hidden;">
                            <div id="portalOverallFill" style="height: 100%; background: var(--portal-gradient); width: 0%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                    
                    <div id="portalTotalSizeNotice" style="display: none; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 10px 14px; margin-top: 12px; font-size: 13px; color: #10b981; display: flex; align-items: center; gap: 8px;">
                        <i class="bi bi-hdd"></i>
                        已选文件总大小: <strong id="portalTotalSizeText">0 MB</strong> / 3GB
                    </div>
                    
                    <button id="portalUploadBtn" class="portal-btn portal-btn-primary" style="display: none; margin-top: 16px; width: 100%;" onclick="startPortalUpload()">
                        <i class="bi bi-upload"></i> 开始上传 (<span id="portalFileCount">0</span> 个文件)
                    </button>
                </div>
                
                <div class="portal-card portal-card-solid" style="margin-top: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                        <h4 style="font-size: 16px; font-weight: 600; margin: 0;">
                            <i class="bi bi-folder" style="color: var(--portal-primary);"></i> 已上传的文件
                        </h4>
                        <div id="portalFileActions" style="display: none; gap: 8px;">
                            <button class="portal-btn portal-btn-sm portal-btn-ghost" onclick="portalSelectAllFiles()" id="portalSelectAllBtn">
                                <i class="bi bi-check2-square"></i> 全选
                            </button>
                            <button class="portal-btn portal-btn-sm portal-btn-danger" onclick="portalBatchDelete()" id="portalBatchDeleteBtn" disabled>
                                <i class="bi bi-trash"></i> 删除选中 (<span id="portalSelectedCount">0</span>)
                            </button>
                        </div>
                    </div>
                    <div id="portalUploadedFiles">
                        <div class="portal-loading">
                            <div class="portal-spinner"></div>
                            <span class="portal-loading-text">加载中...</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        loadForms(projectId);
        loadDeliverables(projectId);
        loadStageTimes(projectId);
        loadPortalUploadedFiles(projectId);
    }

    function switchProjectTab(e, tabName) {
        e.preventDefault();
        document.querySelectorAll('#projectTabs .portal-tab').forEach(t => t.classList.remove('active'));
        e.target.classList.add('active');
        document.querySelectorAll('.portal-tab-content').forEach(c => c.style.display = 'none');
        document.getElementById(`tab-${tabName}`).style.display = 'block';
    }

    // ========== 表单相关 ==========
    function loadForms(projectId) {
        fetch(`${API_URL}/portal_forms.php?token=${TOKEN}&project_id=${projectId}`)
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('formsList');
                // 转换API数据为繁体
                const forms = typeof PortalI18n !== 'undefined' ? data.data.map(f => PortalI18n.convertApiData(f)) : data.data;
                if (data.success && forms.length > 0) {
                    container.innerHTML = forms.map(f => `
                        <div class="portal-card portal-card-solid" style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                                <div>
                                    <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 4px;">${f.instance_name}</h4>
                                    <div style="font-size: 13px; color: var(--portal-text-muted);">${f.template_name}</div>
                                </div>
                                <span class="portal-badge" style="background: ${f.requirement_status_color}20; color: ${f.requirement_status_color};">
                                    ${f.requirement_status_label}
                                </span>
                            </div>
                            <div style="margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
                                ${f.can_fill ? `
                                    <a href="form_fill.php?token=${f.fill_token}" class="portal-btn portal-btn-primary portal-btn-sm">
                                        <i class="bi bi-pencil"></i> 填写表单
                                    </a>
                                ` : ''}
                                ${f.can_view ? `
                                    <a href="form_view.php?token=${f.fill_token}&portal_token=${TOKEN}" class="portal-btn portal-btn-secondary portal-btn-sm">
                                        <i class="bi bi-eye"></i> 查看详情
                                    </a>
                                ` : ''}
                                ${f.can_request_modify ? `
                                    <button class="portal-btn portal-btn-ghost portal-btn-sm" onclick="requestModify(${f.id})">
                                        <i class="bi bi-pencil-square"></i> 申请修改
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = `
                        <div class="portal-card portal-card-solid">
                            <div class="portal-empty">
                                <div class="portal-empty-icon"><i class="bi bi-file-text"></i></div>
                                <div class="portal-empty-title">暂无需求表单</div>
                            </div>
                        </div>
                    `;
                }
            })
            .catch(err => {
                document.getElementById('formsList').innerHTML = `
                    <div class="portal-card portal-card-solid">
                        <div class="portal-empty">
                            <div class="portal-empty-icon portal-text-error"><i class="bi bi-exclamation-circle"></i></div>
                            <div class="portal-empty-title">加载失败</div>
                        </div>
                    </div>
                `;
            });
    }

    function renderAllForms() {
        const container = document.getElementById('allFormsList');
        container.innerHTML = `
            <div class="portal-loading">
                <div class="portal-spinner"></div>
                <span class="portal-loading-text">加载中...</span>
            </div>
        `;
        
        // 加载所有项目的表单
        if (!customerData?.projects?.length) {
            container.innerHTML = `
                <div class="portal-card portal-card-solid">
                    <div class="portal-empty">
                        <div class="portal-empty-icon"><i class="bi bi-file-text"></i></div>
                        <div class="portal-empty-title">暂无需求表单</div>
                    </div>
                </div>
            `;
            return;
        }
        
        // 为每个项目加载表单
        Promise.all(customerData.projects.map(p => 
            fetch(`${API_URL}/portal_forms.php?token=${TOKEN}&project_id=${p.id}`)
                .then(r => r.json())
                .then(data => ({ project: p, forms: data.success ? data.data : [] }))
        )).then(results => {
            let allForms = results.flatMap(r => r.forms.map(f => ({ ...f, projectName: r.project.project_name })));
            // 转换API数据为繁体
            if (typeof PortalI18n !== 'undefined') {
                allForms = allForms.map(f => PortalI18n.convertApiData(f));
            }
            
            if (allForms.length === 0) {
                container.innerHTML = `
                    <div class="portal-card portal-card-solid">
                        <div class="portal-empty">
                            <div class="portal-empty-icon"><i class="bi bi-file-text"></i></div>
                            <div class="portal-empty-title">暂无需求表单</div>
                        </div>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = allForms.map(f => `
                <div class="portal-card portal-card-solid" style="margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 4px;">${f.instance_name}</h4>
                            <div style="font-size: 13px; color: var(--portal-text-muted);">${f.projectName} · ${f.template_name}</div>
                        </div>
                        <span class="portal-badge" style="background: ${f.requirement_status_color}20; color: ${f.requirement_status_color};">
                            ${f.requirement_status_label}
                        </span>
                    </div>
                    <div style="margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
                        ${f.can_fill ? `
                            <a href="form_fill.php?token=${f.fill_token}" class="portal-btn portal-btn-primary portal-btn-sm">
                                <i class="bi bi-pencil"></i> 填写表单
                            </a>
                        ` : ''}
                        ${f.can_view ? `
                            <a href="form_view.php?token=${f.fill_token}&portal_token=${TOKEN}" class="portal-btn portal-btn-secondary portal-btn-sm">
                                <i class="bi bi-eye"></i> 查看详情
                            </a>
                        ` : ''}
                    </div>
                </div>
            `).join('');
        });
    }

    function viewFormSubmission(instanceId) {
        PortalUI.loading.show('加载中...');
        
        fetch(`${API_URL}/form_submissions.php?instance_id=${instanceId}&portal_token=${TOKEN}`)
            .then(r => r.json())
            .then(data => {
                PortalUI.loading.hide();
                if (!data.success) {
                    PortalUI.Toast.error('加载失败: ' + data.message);
                    return;
                }
                
                const { instance, schema, submissions } = data.data;
                const latestSubmission = submissions[0];
                
                if (!latestSubmission) {
                    PortalUI.Toast.warning('暂无提交记录');
                    return;
                }
                
                const submissionData = latestSubmission.submission_data || {};
                let contentHtml = '<div style="max-height: 60vh; overflow-y: auto;">';
                
                Object.entries(submissionData).forEach(([key, value]) => {
                    contentHtml += `
                        <div style="margin-bottom: 12px;">
                            <div style="font-size: 13px; color: var(--portal-text-muted); margin-bottom: 4px;">${key}</div>
                            <div style="font-size: 15px;">${Array.isArray(value) ? value.join(', ') : (value || '-')}</div>
                        </div>
                    `;
                });
                
                contentHtml += `
                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--portal-border); font-size: 13px; color: var(--portal-text-muted);">
                        提交时间：${latestSubmission.submitted_at_formatted}
                    </div>
                </div>`;
                
                PortalUI.Modal.show({
                    title: instance.instance_name,
                    html: contentHtml,
                    confirmText: '关闭',
                    cancelText: ''
                });
            })
            .catch(err => {
                PortalUI.loading.hide();
                PortalUI.Toast.error('加载失败: ' + err.message);
            });
    }

    function requestModify(instanceId) {
        PortalUI.confirm('确定要申请修改此需求吗？', '申请修改').then(confirmed => {
            if (!confirmed) return;
            
            PortalUI.loading.show('提交中...');
            
            fetch(`${API_URL}/form_requirement_status.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    instance_id: instanceId,
                    status: 'modifying',
                    portal_token: TOKEN
                })
            })
            .then(r => r.json())
            .then(result => {
                PortalUI.loading.hide();
                if (result.success) {
                    PortalUI.Toast.success('已提交修改申请，设计师会尽快联系您');
                    if (currentProjectId) loadForms(currentProjectId);
                } else {
                    PortalUI.Toast.error('操作失败: ' + result.message);
                }
            })
            .catch(err => {
                PortalUI.loading.hide();
                PortalUI.Toast.error('操作失败: ' + err.message);
            });
        });
    }

    // ========== 交付物相关 ==========
    async function loadDeliverables(projectId) {
        const container = document.getElementById('deliverablesList');
        const project = customerData?.projects?.find(p => p.id == projectId);
        
        // 获取作品文件（使用客户门户专用API）
        const artworkRes = await fetch(`${API_URL}/portal_deliverables.php?token=${TOKEN}&project_id=${projectId}&file_category=artwork_file`);
        const artworkData = await artworkRes.json();
        let visible = artworkData.success ? artworkData.data : [];
        
        // 如果项目启用了模型文件显示，也获取模型文件
        if (project?.show_model_files) {
            const modelRes = await fetch(`${API_URL}/portal_deliverables.php?token=${TOKEN}&project_id=${projectId}&file_category=model_file`);
            const modelData = await modelRes.json();
            if (modelData.success) {
                visible = visible.concat(modelData.data);
            }
        }
        
        // 转换API数据为繁体
        if (typeof PortalI18n !== 'undefined') {
            visible = visible.map(d => PortalI18n.convertApiData(d));
        }
        
        if (visible.length > 0) {
            container.innerHTML = `<div class="portal-card portal-card-solid">${visible.map(d => renderDeliverableItem(d)).join('')}</div>`;
        } else {
            container.innerHTML = `
                <div class="portal-card portal-card-solid">
                    <div class="portal-empty">
                        <div class="portal-empty-icon"><i class="bi bi-box"></i></div>
                        <div class="portal-empty-title">暂无交付作品</div>
                    </div>
                </div>
            `;
        }
    }

    function renderAllDeliverables() {
        const container = document.getElementById('allDeliverablesList');
        container.innerHTML = `
            <div class="portal-loading">
                <div class="portal-spinner"></div>
                <span class="portal-loading-text">加载中...</span>
            </div>
        `;
        
        if (!customerData?.projects?.length) {
            container.innerHTML = `
                <div class="portal-card portal-card-solid">
                    <div class="portal-empty">
                        <div class="portal-empty-icon"><i class="bi bi-box"></i></div>
                        <div class="portal-empty-title">暂无交付作品</div>
                    </div>
                </div>
            `;
            return;
        }
        
        // 获取每个项目的交付物（作品文件 + 模型文件如果启用）
        Promise.all(customerData.projects.map(async p => {
            // 获取作品文件（使用客户门户专用API）
            const artworkRes = await fetch(`${API_URL}/portal_deliverables.php?token=${TOKEN}&project_id=${p.id}&file_category=artwork_file`);
            const artworkData = await artworkRes.json();
            let deliverables = artworkData.success ? artworkData.data : [];
            
            // 如果项目启用了模型文件显示，也获取模型文件
            if (p.show_model_files) {
                const modelRes = await fetch(`${API_URL}/portal_deliverables.php?token=${TOKEN}&project_id=${p.id}&file_category=model_file`);
                const modelData = await modelRes.json();
                if (modelData.success) {
                    deliverables = deliverables.concat(modelData.data);
                }
            }
            
            return { project: p, deliverables };
        })).then(results => {
            // 按项目分组显示
            const projectsWithFiles = results.filter(r => r.deliverables.length > 0);
            
            if (projectsWithFiles.length === 0) {
                container.innerHTML = `
                    <div class="portal-card portal-card-solid">
                        <div class="portal-empty">
                            <div class="portal-empty-icon"><i class="bi bi-box"></i></div>
                            <div class="portal-empty-title">暂无交付作品</div>
                        </div>
                    </div>
                `;
                return;
            }
            
            // 多项目时按项目分组显示
            container.innerHTML = projectsWithFiles.map(r => {
                let deliverables = r.deliverables;
                if (typeof PortalI18n !== 'undefined') {
                    deliverables = deliverables.map(d => PortalI18n.convertApiData(d));
                }
                
                return `
                    <div class="portal-card portal-card-solid" style="margin-bottom: 16px;">
                        <div style="padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--portal-border);">
                            <h3 style="font-size: 16px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px;">
                                <i class="bi bi-folder" style="color: var(--portal-primary);"></i>
                                ${r.project.project_name}
                            </h3>
                        </div>
                        <div class="portal-deliverables-list">
                            ${deliverables.map(d => renderDeliverableItem(d)).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        });
    }

    // ========== 文件预览功能 ==========
    function getFileType(filename) {
        if (!filename) return 'other';
        const ext = filename.split('.').pop().toLowerCase();
        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        const videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
        const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac'];
        if (imageExts.includes(ext)) return 'image';
        if (videoExts.includes(ext)) return 'video';
        if (audioExts.includes(ext)) return 'audio';
        return 'other';
    }

    function getFileIcon(filename, fileCategory) {
        const type = getFileType(filename);
        if (type === 'image') return 'bi-image';
        if (type === 'video') return 'bi-play-circle';
        if (type === 'audio') return 'bi-music-note-beamed';
        return fileCategory === 'model_file' ? 'bi-box' : 'bi-file-earmark';
    }

    function canPreview(filename) {
        const type = getFileType(filename);
        return ['image', 'video', 'audio'].includes(type);
    }

    // 预览状态
    let previewImages = [];
    let currentPreviewIndex = 0;
    let currentZoom = 1;
    
    function openPreview(filePath, filename, fileType, allImages = null, imageIndex = 0) {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        const title = document.getElementById('previewTitle');
        const counter = document.getElementById('previewCounter');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        
        title.textContent = filename;
        currentZoom = 1;
        
        // 设置图片列表
        if (allImages && allImages.length > 1) {
            previewImages = allImages;
            currentPreviewIndex = imageIndex;
            counter.textContent = `${imageIndex + 1} / ${allImages.length}`;
            prevBtn.style.display = imageIndex > 0 ? 'block' : 'none';
            nextBtn.style.display = imageIndex < allImages.length - 1 ? 'block' : 'none';
        } else {
            previewImages = [{url: filePath, name: filename, type: fileType}];
            currentPreviewIndex = 0;
            counter.textContent = '';
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
        }
        
        renderPreviewContent(filePath, filename, fileType);
        
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function getProxyUrl(fileUrl, filename = null) {
        let url = `${API_URL}/portal_file_proxy.php?token=${TOKEN}&url=${encodeURIComponent(fileUrl)}`;
        if (filename) {
            url += `&download=${encodeURIComponent(filename)}`;
        }
        return url;
    }
    
    function renderPreviewContent(filePath, filename, fileType) {
        const content = document.getElementById('previewContent');
        
        if (fileType === 'image') {
            const proxyUrl = getProxyUrl(filePath);
            content.innerHTML = `<img id="previewImg" src="${proxyUrl}" alt="${filename}" style="max-width: 90vw; max-height: 80vh; transform: scale(${currentZoom}); transition: transform 0.2s; cursor: zoom-in;" ondblclick="toggleZoom()" onerror="this.src='${filePath}'">`;
        } else if (fileType === 'video') {
            const proxyUrl = getProxyUrl(filePath);
            content.innerHTML = `<video src="${proxyUrl}" controls style="max-width: 90vw; max-height: 80vh;" onerror="this.src='${filePath}'"></video>`;
        } else if (fileType === 'audio') {
            const proxyUrl = getProxyUrl(filePath);
            content.innerHTML = `
                <div style="padding: 40px; text-align: center; background: rgba(255,255,255,0.1); border-radius: 16px;">
                    <i class="bi bi-music-note-beamed" style="font-size: 64px; color: #22d3ee; margin-bottom: 20px; display: block;"></i>
                    <audio src="${proxyUrl}" controls style="width: 100%; max-width: 400px;" onerror="this.src='${filePath}'"></audio>
                </div>
            `;
        }
    }
    
    function zoomIn() {
        currentZoom = Math.min(currentZoom + 0.25, 5);
        updateZoom();
    }
    
    function zoomOut() {
        currentZoom = Math.max(currentZoom - 0.25, 0.25);
        updateZoom();
    }
    
    function resetZoom() {
        currentZoom = 1;
        updateZoom();
    }
    
    function toggleZoom() {
        currentZoom = currentZoom > 1 ? 1 : 2;
        updateZoom();
    }
    
    function updateZoom() {
        const img = document.getElementById('previewImg');
        if (img) {
            img.style.transform = `scale(${currentZoom})`;
            img.style.cursor = currentZoom > 1 ? 'zoom-out' : 'zoom-in';
        }
    }
    
    function prevImage() {
        if (currentPreviewIndex > 0) {
            currentPreviewIndex--;
            showImageAtIndex(currentPreviewIndex);
        }
    }
    
    function nextImage() {
        if (currentPreviewIndex < previewImages.length - 1) {
            currentPreviewIndex++;
            showImageAtIndex(currentPreviewIndex);
        }
    }
    
    function showImageAtIndex(index) {
        const img = previewImages[index];
        const title = document.getElementById('previewTitle');
        const counter = document.getElementById('previewCounter');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        
        title.textContent = img.name;
        counter.textContent = `${index + 1} / ${previewImages.length}`;
        prevBtn.style.display = index > 0 ? 'block' : 'none';
        nextBtn.style.display = index < previewImages.length - 1 ? 'block' : 'none';
        
        currentZoom = 1;
        renderPreviewContent(img.url, img.name, img.type);
    }

    function closePreview() {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        modal.style.display = 'none';
        content.innerHTML = '';
        document.body.style.overflow = '';
        previewImages = [];
        currentPreviewIndex = 0;
        currentZoom = 1;
    }
    
    // 键盘事件支持
    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('previewModal');
        if (modal.style.display !== 'flex') return;
        
        if (e.key === 'Escape') closePreview();
        if (e.key === 'ArrowLeft') prevImage();
        if (e.key === 'ArrowRight') nextImage();
        if (e.key === '+' || e.key === '=') zoomIn();
        if (e.key === '-') zoomOut();
    });

    function renderDeliverableItem(d, showProject = false) {
        const fileUrl = d.file_url || d.file_path;
        const shareUrl = d.share_url || '';
        const fileType = getFileType(d.deliverable_name);
        const icon = getFileIcon(d.deliverable_name, d.file_category);
        const previewable = canPreview(d.deliverable_name);
        const isImage = fileType === 'image';
        const thumbUrl = getProxyUrl(fileUrl);
        const downloadUrl = getProxyUrl(fileUrl, d.deliverable_name);
        
        return `
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; background: var(--portal-bg); margin-bottom: 8px;">
                ${isImage ? `
                    <div style="width: 60px; height: 60px; border-radius: 8px; overflow: hidden; flex-shrink: 0; cursor: pointer;" onclick="openPreview('${fileUrl}', '${d.deliverable_name}', '${fileType}')">
                        <img src="${thumbUrl}" alt="${d.deliverable_name}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='${fileUrl}'; this.onerror=function(){this.parentElement.innerHTML='<div style=\\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(99,102,241,0.1);color:var(--portal-primary);\\'><i class=\\'bi ${icon}\\' style=\\'font-size:24px;\\'></i></div>'}">
                    </div>
                ` : `
                    <div style="width: 48px; height: 48px; background: ${d.file_category === 'model_file' ? 'rgba(245, 158, 11, 0.1)' : 'rgba(99, 102, 241, 0.1)'}; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: ${d.file_category === 'model_file' ? '#f59e0b' : 'var(--portal-primary)'}; font-size: 20px; flex-shrink: 0;">
                        <i class="bi ${icon}"></i>
                    </div>
                `}
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${d.deliverable_name}</div>
                    <div style="font-size: 12px; color: var(--portal-text-muted);">
                        ${showProject && d.project_name ? d.project_name + ' · ' : ''}${d.file_category === 'model_file' ? '模型文件' : '作品文件'}
                    </div>
                </div>
                <div style="display: flex; gap: 8px; flex-shrink: 0;">
                    ${previewable ? `
                        <button onclick="openPreview('${fileUrl}', '${d.deliverable_name}', '${fileType}')" class="portal-btn portal-btn-outline portal-btn-sm" title="预览">
                            <i class="bi bi-eye"></i>
                        </button>
                    ` : ''}
                    <button class="portal-btn portal-btn-outline portal-btn-sm share-btn" data-id="${d.id}" data-name="${d.deliverable_name.replace(/"/g, '&quot;')}" title="生成分享链接">
                        <i class="bi bi-share"></i>
                    </button>
                    <a href="${downloadUrl}" class="portal-btn portal-btn-primary portal-btn-sm" title="下载">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            </div>
        `;
    }

    function showShareModal(deliverableId, fileName) {
        const modal = document.createElement('div');
        modal.className = 'portal-modal-overlay';
        modal.id = 'shareModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:9999;';
        modal.innerHTML = `
            <div style="max-width:400px; width:90%; background:white; border-radius:16px; box-shadow:0 10px 40px rgba(0,0,0,0.15); overflow:hidden;">
                <div style="padding:24px 24px 16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:16px; font-weight:600; color:#1f2937;">生成分享链接</h3>
                        <button onclick="document.getElementById('shareModal').remove()" style="background:none; border:none; font-size:20px; color:#9ca3af; cursor:pointer; padding:4px;">&times;</button>
                    </div>
                    <div style="display:flex; align-items:center; gap:12px; padding:12px; background:#f8fafc; border-radius:10px; margin-bottom:20px;">
                        <div style="width:40px; height:40px; background:#e0f2fe; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <i class="bi bi-file-earmark" style="font-size:18px; color:#0891b2;"></i>
                        </div>
                        <div style="overflow:hidden;">
                            <div style="font-size:13px; font-weight:500; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${fileName}</div>
                            <div style="font-size:11px; color:#9ca3af;">作品文件</div>
                        </div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:500; color:#6b7280; margin-bottom:6px;">链接有效期</label>
                        <select id="shareExpireHours" style="width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; color:#374151; background:white; cursor:pointer;">
                            <option value="24" selected>1 天</option>
                            <option value="72">3 天</option>
                            <option value="168">7 天</option>
                            <option value="720">30 天</option>
                            <option value="0">永久有效</option>
                        </select>
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="display:block; font-size:12px; font-weight:500; color:#6b7280; margin-bottom:6px;">下载次数限制</label>
                        <select id="shareMaxDownloads" style="width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; color:#374151; background:white; cursor:pointer;">
                            <option value="10" selected>10 次</option>
                            <option value="5">5 次</option>
                            <option value="1">1 次</option>
                            <option value="50">50 次</option>
                            <option value="0">不限制</option>
                        </select>
                    </div>
                    <div id="shareResult" style="display:none; background:#f0fdfa; border:1px solid #99f6e4; border-radius:8px; padding:12px; margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:500; color:#0d9488; margin-bottom:6px;">🌐 分享链接已生成</label>
                        <div id="regionLinksContainer"></div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; padding:16px 24px; background:#f8fafc; border-top:1px solid #f1f5f9;">
                    <button onclick="document.getElementById('shareModal').remove()" style="flex:1; padding:10px; border:1px solid #e5e7eb; background:white; color:#6b7280; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer;">取消</button>
                    <button onclick="generateShareLink(${deliverableId})" id="generateShareBtn" style="flex:1; padding:10px; border:none; background:#0891b2; color:white; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer;">生成链接</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    async function generateShareLink(deliverableId) {
        const expireHours = parseInt(document.getElementById('shareExpireHours').value) || 0;
        const maxDownloads = parseInt(document.getElementById('shareMaxDownloads').value) || 0;
        const btn = document.getElementById('generateShareBtn');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> 生成中...';
        
        try {
            const resp = await fetch('/api/portal_create_share.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    deliverable_id: deliverableId,
                    portal_token: TOKEN,
                    expire_hours: expireHours,
                    max_downloads: maxDownloads
                })
            });
            const data = await resp.json();
            
            if (data.success) {
                const container = document.getElementById('regionLinksContainer');
                if (data.region_urls && data.region_urls.length > 0) {
                    container.innerHTML = data.region_urls.map((r, idx) => `
                        <div style="display:flex; gap:8px; margin-bottom:8px;">
                            <span style="min-width:60px; font-size:11px; color:#666; padding:8px 0;">${r.is_default ? '⭐' : ''} ${r.region_name}</span>
                            <input type="text" id="regionLink_${idx}" value="${r.url}" readonly style="flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; background:#fff;">
                            <button onclick="copyPortalRegionLink('regionLink_${idx}')" style="padding:8px 12px; background:#0891b2; color:white; border:none; border-radius:6px; font-size:12px; cursor:pointer;">复制</button>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = `
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="shareUrlInput" value="${data.share_url}" readonly style="flex:1; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; background:#fff;">
                            <button onclick="copyGeneratedShareLink()" style="padding:8px 12px; background:#0891b2; color:white; border:none; border-radius:6px; font-size:12px; cursor:pointer;">复制</button>
                        </div>
                    `;
                }
                document.getElementById('shareResult').style.display = 'block';
                btn.innerHTML = '<i class="bi bi-check-circle"></i> 已生成';
                btn.disabled = true;
                PortalUI.Toast.success('分享链接已生成');
            } else {
                PortalUI.Toast.error(data.message || '生成失败');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-link-45deg"></i> 生成链接';
            }
        } catch (err) {
            PortalUI.Toast.error('网络错误');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-link-45deg"></i> 生成链接';
        }
    }
    
    function copyGeneratedShareLink() {
        const url = document.getElementById('shareUrlInput').value;
        copyShareLink(url);
    }
    
    function copyPortalRegionLink(inputId) {
        const input = document.getElementById(inputId);
        if (input) {
            copyShareLink(input.value);
        }
    }

    function copyShareLink(url) {
        console.log('[PORTAL_SHARE_DEBUG] copyShareLink called with:', url);
        if (!url) {
            PortalUI.Toast.error('分享链接为空');
            return;
        }
        
        // 尝试使用 Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(() => {
                console.log('[PORTAL_SHARE_DEBUG] Clipboard API success');
                PortalUI.Toast.success('分享链接已复制');
            }).catch((err) => {
                console.log('[PORTAL_SHARE_DEBUG] Clipboard API failed:', err);
                fallbackCopy(url);
            });
        } else {
            console.log('[PORTAL_SHARE_DEBUG] Using fallback copy');
            fallbackCopy(url);
        }
    }
    
    function fallbackCopy(url) {
        try {
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, url.length);
            const success = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (success) {
                PortalUI.Toast.success('分享链接已复制');
            } else {
                PortalUI.Toast.error('复制失败，请手动复制');
                prompt('请手动复制链接:', url);
            }
        } catch (err) {
            console.error('[PORTAL_SHARE_DEBUG] Fallback copy failed:', err);
            PortalUI.Toast.error('复制失败，请手动复制');
            prompt('请手动复制链接:', url);
        }
    }

    // ========== 工具函数 ==========
    function getStatusBadge(status) {
        const colors = {
            // 简体
            '待沟通': { bg: 'rgba(100, 116, 139, 0.1)', color: '#64748b' },
            '需求确认': { bg: 'rgba(59, 130, 246, 0.1)', color: '#3b82f6' },
            '设计中': { bg: 'rgba(99, 102, 241, 0.1)', color: '#6366f1' },
            '设计核对': { bg: 'rgba(245, 158, 11, 0.1)', color: '#f59e0b' },
            '客户完结': { bg: 'rgba(16, 185, 129, 0.1)', color: '#10b981' },
            '设计评价': { bg: 'rgba(16, 185, 129, 0.15)', color: '#059669' },
            // 繁体
            '待溝通': { bg: 'rgba(100, 116, 139, 0.1)', color: '#64748b' },
            '需求確認': { bg: 'rgba(59, 130, 246, 0.1)', color: '#3b82f6' },
            '設計中': { bg: 'rgba(99, 102, 241, 0.1)', color: '#6366f1' },
            '設計核對': { bg: 'rgba(245, 158, 11, 0.1)', color: '#f59e0b' },
            '設計完工': { bg: 'rgba(16, 185, 129, 0.1)', color: '#10b981' },
            '設計評價': { bg: 'rgba(16, 185, 129, 0.15)', color: '#059669' }
        };
        const c = colors[status] || colors['待沟通'];
        return `<span class="portal-badge" style="background: ${c.bg}; color: ${c.color};">${status}</span>`;
    }

    function getProgress(status) {
        // 优先使用从 API 获取的 overall_progress
        if (portalStageData && portalStageData.summary && portalStageData.summary.overall_progress !== undefined) {
            return portalStageData.summary.overall_progress;
        }
        // 回退到基于阶段的计算（与桌面端一致）
        const statuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
        const simplifiedStatus = OpenCCLite ? OpenCCLite.toSimplified(status) : status;
        const currentIndex = statuses.indexOf(simplifiedStatus);
        if (currentIndex === -1) return 0;
        // 基于时间的进度计算会在 API 返回后更新
        return Math.round(currentIndex / (statuses.length - 1) * 100);
    }

    let portalStageData = null;
    let currentProjectData = null;

    function renderStatusStepper(currentStatus, stageData = null) {
        const simplifiedStatus = OpenCCLite ? OpenCCLite.toSimplified(currentStatus) : currentStatus;
        const statuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
        let currentIndex = statuses.indexOf(simplifiedStatus);
        if (currentIndex === -1) currentIndex = 0;
        
        let progressWidth;
        if (stageData && stageData.summary && stageData.summary.total_days > 0) {
            progressWidth = Math.min(100, stageData.summary.overall_progress);
        } else {
            progressWidth = currentIndex > 0 ? ((currentIndex) / (statuses.length - 1) * 100) : 0;
        }
        
        const stepperStyle = 'display:flex;justify-content:space-between;position:relative;padding:10px 0;';
        const trackStyle = 'position:absolute;top:28px;left:8%;right:8%;height:3px;background:#e2e8f0;border-radius:2px;z-index:1;';
        
        // 检查项目是否已完工
        const projectCompleted = stageData && stageData.summary && stageData.summary.is_completed;
        
        // 完工项目用绿色进度条，未完工用紫色
        const progressColor = projectCompleted ? 'linear-gradient(135deg,#10b981 0%,#059669 100%)' : 'linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%)';
        const progressStyle = `position:absolute;top:28px;left:8%;height:3px;background:${progressColor};border-radius:2px;z-index:2;width:${progressWidth * 0.84}%;transition:width 0.5s ease;`;
        
        let stepsHtml = '';
        statuses.forEach((status, index) => {
            // 项目完工时，所有阶段都视为已完成
            const isCompleted = projectCompleted ? true : (index < currentIndex);
            const isActive = projectCompleted ? false : (index === currentIndex);
            
            let stageInfo = null;
            if (stageData && stageData.stages) {
                stageInfo = stageData.stages.find(s => {
                    const from = OpenCCLite ? OpenCCLite.toSimplified(s.stage_from) : s.stage_from;
                    return from === status;
                });
            }
            
            let circleStyle = 'width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;transition:all 0.3s;';
            let labelStyle = 'margin-top:8px;font-size:11px;font-weight:500;text-align:center;max-width:70px;line-height:1.3;';
            
            const isOverdue = stageInfo && isActive && stageInfo.remaining_days < 0;
            
            if (isCompleted) {
                // 已完成状态：绿色勾选（完工项目用绿色，未完工用紫色）
                if (projectCompleted) {
                    circleStyle += 'background:linear-gradient(135deg,#10b981 0%,#059669 100%);border:3px solid #10b981;color:white;';
                } else {
                    circleStyle += 'background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);border:3px solid #6366f1;color:white;';
                }
                labelStyle += 'color:#1e293b;';
            } else if (isActive) {
                if (isOverdue) {
                    circleStyle += 'background:#fef2f2;border:3px solid #ef4444;color:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,0.15);';
                } else {
                    circleStyle += 'background:white;border:3px solid #6366f1;color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,0.15);';
                }
                labelStyle += 'color:#1e293b;';
            } else {
                circleStyle += 'background:white;border:3px solid #e2e8f0;color:#94a3b8;';
                labelStyle += 'color:#94a3b8;';
            }
            
            const checkIcon = isCompleted ? '✓' : (index + 1);
            const displayStatus = OpenCCLite ? OpenCCLite.toTraditional(status) : status;
            
            let daysHtml = '';
            if (stageInfo) {
                const days = stageInfo.planned_days || 0;
                if (projectCompleted) {
                    // 项目已完工，显示完成标记
                    daysHtml = `<div style="font-size:10px;color:#10b981;margin-top:2px;">✓</div>`;
                } else if (isActive && stageInfo.remaining_days !== null) {
                    const remaining = parseInt(stageInfo.remaining_days);
                    if (remaining < 0) {
                        daysHtml = `<div style="font-size:10px;color:#ef4444;font-weight:600;margin-top:2px;">超${Math.abs(remaining)}天</div>`;
                    } else if (remaining === 0) {
                        daysHtml = `<div style="font-size:10px;color:#f59e0b;font-weight:600;margin-top:2px;">今日到期</div>`;
                    } else {
                        daysHtml = `<div style="font-size:10px;color:#6366f1;margin-top:2px;">剩${remaining}天</div>`;
                    }
                } else if (!isCompleted) {
                    daysHtml = `<div style="font-size:10px;color:#94a3b8;margin-top:2px;">${days}天</div>`;
                }
            }
            
            stepsHtml += `<div style="display:flex;flex-direction:column;align-items:center;position:relative;z-index:3;flex:1;"><div style="${circleStyle}">${checkIcon}</div><div style="${labelStyle}">${displayStatus}${daysHtml}</div></div>`;
        });
        
        return `<div id="portalStatusStepper" style="${stepperStyle}"><div style="${trackStyle}"></div><div style="${progressStyle}"></div>${stepsHtml}</div>`;
    }

    function formatTime(timestamp) {
        if (!timestamp) return '-';
        const date = new Date(timestamp * 1000);
        return date.toLocaleDateString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' });
    }

    // 加载阶段时间数据
    async function loadStageTimes(projectId) {
        try {
            console.log('[PORTAL_TIMELINE_DEBUG] Loading stage times for project:', projectId);
            const res = await fetch(`/api/portal_stage_times.php?project_id=${projectId}&token=${TOKEN}`);
            const data = await res.json();
            console.log('[PORTAL_TIMELINE_DEBUG] API response:', data);
            if (data.success && data.data) {
                renderStageTimes(data.data);
            } else {
                console.log('[PORTAL_TIMELINE_DEBUG] No data or failed:', data);
            }
        } catch (e) {
            console.error('[PORTAL_TIMELINE_DEBUG] Error:', e);
        }
    }

    function renderStageTimes(stageData) {
        console.log('[PORTAL_TIMELINE_DEBUG] renderStageTimes called with:', stageData);
        portalStageData = stageData;
        const summary = stageData.summary;
        if (!summary) {
            console.log('[PORTAL_TIMELINE_DEBUG] No summary data');
            return;
        }
        
        console.log('[PORTAL_TIMELINE_DEBUG] Summary:', summary);
        
        const progressEl = document.getElementById('progressPercent');
        if (progressEl && summary.overall_progress !== undefined) {
            progressEl.textContent = summary.overall_progress + '%';
        }
        
        const remainingEl = document.getElementById('remainingDaysInfo');
        if (remainingEl && summary.current_stage) {
            const remaining = summary.current_stage.remaining_days;
            if (remaining !== null && remaining !== undefined) {
                if (remaining < 0) {
                    remainingEl.innerHTML = `<span style="color: #ef4444; font-weight: 600;">当前阶段已超期 ${Math.abs(remaining)} 天</span>`;
                } else if (remaining === 0) {
                    remainingEl.innerHTML = `<span style="color: #f59e0b;">当前阶段今日到期</span>`;
                } else {
                    remainingEl.innerHTML = `预计 <strong>${remaining}</strong> 天后进入下一阶段`;
                }
            }
        }
        
        // 更新状态步骤条
        if (currentProjectData) {
            const stepperEl = document.getElementById('portalStatusStepper');
            if (stepperEl) {
                stepperEl.outerHTML = renderStatusStepper(currentProjectData.current_status, stageData);
            }
        }
        
        renderProjectTimeline(stageData);
    }

    function renderProjectTimeline(stageData) {
        const summary = stageData.summary;
        if (!summary || !summary.total_days) return;
        
        let timelineContainer = document.getElementById('projectTimelineInfo');
        if (!timelineContainer) {
            const progressCard = document.querySelector('#tab-progress .portal-card');
            if (!progressCard) return;
            
            timelineContainer = document.createElement('div');
            timelineContainer.id = 'projectTimelineInfo';
            timelineContainer.style.cssText = 'margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--portal-border);';
            progressCard.appendChild(timelineContainer);
        }
        
        const totalDays = summary.total_days;
        const isCompleted = summary.is_completed;
        const actualDays = summary.actual_days || summary.elapsed_days;
        const elapsedDays = summary.elapsed_days;
        
        let dateRange = '';
        if (stageData.stages && stageData.stages.length > 0) {
            const firstStage = stageData.stages[0];
            const lastStage = stageData.stages[stageData.stages.length - 1];
            if (firstStage.planned_start_date) {
                if (isCompleted && summary.completed_at) {
                    dateRange = `${firstStage.planned_start_date} ~ ${summary.completed_at.split(' ')[0]} (已完工)`;
                } else if (lastStage.planned_end_date) {
                    dateRange = `${firstStage.planned_start_date} ~ ${lastStage.planned_end_date}`;
                }
            }
        }
        
        // 已完工项目显示不同的内容
        if (isCompleted) {
            timelineContainer.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <div style="font-weight: 600; color: var(--portal-text);">📅 项目周期</div>
                    ${dateRange ? `<div style="font-size: 12px; color: var(--portal-text-muted);">${dateRange}</div>` : ''}
                </div>
                <div style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: var(--portal-bg); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--portal-primary);">${totalDays}</div>
                        <div style="font-size: 12px; color: var(--portal-text-muted);">计划天数</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: var(--portal-bg); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">${actualDays}</div>
                        <div style="font-size: 12px; color: var(--portal-text-muted);">实际用时</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: rgba(16, 185, 129, 0.1); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">✓</div>
                        <div style="font-size: 12px; color: #10b981;">已完工</div>
                    </div>
                </div>
                <div style="background: #e2e8f0; border-radius: 4px; height: 8px; overflow: hidden;">
                    <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); height: 100%; width: 100%; border-radius: 4px;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 11px; color: #10b981;">
                    <span>项目已完成</span>
                    <span>100%</span>
                </div>
            `;
        } else {
            const remainingDays = Math.max(0, totalDays - elapsedDays);
            const timeProgress = Math.min(100, Math.round(elapsedDays * 100 / totalDays));
            
            timelineContainer.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <div style="font-weight: 600; color: var(--portal-text);">📅 项目周期</div>
                    ${dateRange ? `<div style="font-size: 12px; color: var(--portal-text-muted);">${dateRange}</div>` : ''}
                </div>
                <div style="display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: var(--portal-bg); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--portal-primary);">${totalDays}</div>
                        <div style="font-size: 12px; color: var(--portal-text-muted);">总天数</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: var(--portal-bg); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">${elapsedDays}</div>
                        <div style="font-size: 12px; color: var(--portal-text-muted);">已进行</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; text-align: center; padding: 12px; background: var(--portal-bg); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: 700; color: ${remainingDays <= 3 ? '#ef4444' : '#f59e0b'};">${remainingDays}</div>
                        <div style="font-size: 12px; color: var(--portal-text-muted);">剩余天数</div>
                    </div>
                </div>
                <div style="background: #e2e8f0; border-radius: 4px; height: 8px; overflow: hidden;">
                    <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); height: 100%; width: ${timeProgress}%; transition: width 0.5s ease; border-radius: 4px;"></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 11px; color: var(--portal-text-muted);">
                    <span>时间进度</span>
                    <span>${timeProgress}%</span>
                </div>
            `;
        }
    }

    // ========== 评价功能 ==========
    let evaluationData = null;
    
    async function checkEvaluationStatus(projectId) {
        try {
            const res = await fetch(`${API_URL}/project_evaluations.php?project_id=${projectId}`);
            const data = await res.json();
            if (data.success) {
                evaluationData = data.data;
                updateEvaluationUI();
            }
        } catch (e) {
            console.error('[EVALUATION] Error:', e);
        }
    }
    
    function updateEvaluationUI() {
        const reminder = document.getElementById('evaluationReminder');
        if (!reminder || !currentProjectData || !evaluationData) return;
        
        const simplifiedStatus = OpenCCLite ? OpenCCLite.toSimplified(currentProjectData.current_status) : currentProjectData.current_status;
        
        // 仅在"设计评价"阶段且未评价时显示
        if (simplifiedStatus === '设计评价' && !evaluationData.evaluation && !evaluationData.completed_at) {
            reminder.style.display = 'block';
            reminder.style.background = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
            reminder.style.border = '1px solid #f59e0b';
            reminder.style.marginBottom = '16px';
            
            // 自动弹出评价弹窗（首次访问时）
            if (!sessionStorage.getItem('evaluation_modal_shown_' + currentProjectData.id)) {
                sessionStorage.setItem('evaluation_modal_shown_' + currentProjectData.id, 'true');
                setTimeout(() => showEvaluationModal(), 500);
            }
            
            const deadlineText = document.getElementById('evaluationDeadlineText');
            if (deadlineText && evaluationData.remaining_days !== null) {
                if (evaluationData.remaining_days <= 0) {
                    deadlineText.textContent = '评价时间即将截止，请尽快完成评价！';
                    deadlineText.style.color = '#dc2626';
                } else {
                    deadlineText.textContent = `请在 ${evaluationData.remaining_days} 天内完成评价，您的反馈对我们很重要！`;
                }
            }
            
            // 更新评价按钮：如果有评价表单则跳转表单，否则使用简单评分
            const evalBtn = document.getElementById('evaluationBtn');
            if (evalBtn && evaluationData.evaluation_form && evaluationData.evaluation_form.fill_token) {
                evalBtn.onclick = function() {
                    window.location.href = `form_fill.php?token=${evaluationData.evaluation_form.fill_token}`;
                };
                evalBtn.innerHTML = '<i class="bi bi-file-earmark-text"></i> 填写评价表单';
            }
        } else {
            reminder.style.display = 'none';
        }
    }
    
    function showEvaluationModal() {
        if (!currentProjectData) return;
        
        const modalHtml = `
            <div id="evaluationModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999;">
                <div style="background: white; border-radius: 16px; padding: 32px; max-width: 480px; width: 90%; max-height: 90vh; overflow-y: auto;">
                    <h3 style="margin: 0 0 8px; font-size: 20px;">⭐ 项目评价</h3>
                    <p style="margin: 0 0 24px; color: #6b7280; font-size: 14px;">${currentProjectData.project_name}</p>
                    
                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 12px;">请为本次服务打分</label>
                        <div id="ratingStars" style="display: flex; gap: 8px; font-size: 32px; cursor: pointer;">
                            ${[1,2,3,4,5].map(n => `<span data-rating="${n}" onclick="setRating(${n})" style="color: #fbbf24;">★</span>`).join('')}
                        </div>
                        <input type="hidden" id="ratingValue" value="5">
                    </div>
                    
                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">评价内容（选填）</label>
                        <textarea id="evaluationComment" rows="4" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; resize: none; font-size: 14px;" placeholder="请分享您的使用体验和建议..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button onclick="closeEvaluationModal()" style="flex: 1; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: white; cursor: pointer; font-size: 14px;">取消</button>
                        <button onclick="submitEvaluation()" style="flex: 1; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; cursor: pointer; font-size: 14px; font-weight: 600;">提交评价</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    function setRating(rating) {
        document.getElementById('ratingValue').value = rating;
        const stars = document.querySelectorAll('#ratingStars span');
        stars.forEach((star, idx) => {
            star.style.color = idx < rating ? '#fbbf24' : '#d1d5db';
        });
    }
    
    function closeEvaluationModal() {
        document.getElementById('evaluationModal')?.remove();
    }
    
    async function submitEvaluation() {
        if (!currentProjectData) return;
        
        const rating = parseInt(document.getElementById('ratingValue').value);
        const comment = document.getElementById('evaluationComment').value.trim();
        
        try {
            PortalUI.loading.show('正在提交...');
            const res = await fetch(`${API_URL}/project_evaluations.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: currentProjectData.id,
                    token: TOKEN,
                    rating: rating,
                    comment: comment
                })
            });
            const data = await res.json();
            PortalUI.loading.hide();
            
            if (data.success) {
                closeEvaluationModal();
                PortalUI.Toast.success('感谢您的评价！');
                // 刷新评价状态
                checkEvaluationStatus(currentProjectData.id);
            } else {
                PortalUI.Toast.error(data.message || '提交失败');
            }
        } catch (e) {
            PortalUI.loading.hide();
            PortalUI.Toast.error('网络错误，请稍后重试');
        }
    }

    // ========== 门户文件上传 ==========
    const PORTAL_CHUNK_SIZE = 90 * 1024 * 1024; // 90MB
    const PORTAL_MAX_TOTAL_SIZE = 3 * 1024 * 1024 * 1024; // 3GB 单次上传总大小限制
    let portalSelectedFiles = [];
    let portalConsecutiveFailures = 0;
    
    function handlePortalFileSelect(event) {
        const files = Array.from(event.target.files);
        files.forEach(file => {
            if (!portalSelectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                portalSelectedFiles.push(file);
            }
        });
        // 检查总大小
        const totalSize = portalSelectedFiles.reduce((sum, f) => sum + f.size, 0);
        if (totalSize > PORTAL_MAX_TOTAL_SIZE) {
            PortalUI.Toast.error(`单次上传总大小超过3GB限制！当前: ${formatFileSize(totalSize)}`);
        }
        renderPortalUploadList();
    }
    
    function renderPortalUploadList() {
        const listContainer = document.getElementById('portalUploadList');
        const uploadBtn = document.getElementById('portalUploadBtn');
        const fileCount = document.getElementById('portalFileCount');
        const totalSizeNotice = document.getElementById('portalTotalSizeNotice');
        const totalSizeText = document.getElementById('portalTotalSizeText');
        
        if (portalSelectedFiles.length === 0) {
            listContainer.innerHTML = '';
            uploadBtn.style.display = 'none';
            totalSizeNotice.style.display = 'none';
            return;
        }
        
        // 显示总大小提示
        const totalSize = portalSelectedFiles.reduce((sum, f) => sum + f.size, 0);
        totalSizeNotice.style.display = 'flex';
        totalSizeText.textContent = formatFileSize(totalSize);
        
        // 超过3GB时显示红色警告
        if (totalSize > PORTAL_MAX_TOTAL_SIZE) {
            totalSizeNotice.style.background = 'rgba(239, 68, 68, 0.1)';
            totalSizeNotice.style.borderColor = 'rgba(239, 68, 68, 0.2)';
            totalSizeNotice.style.color = '#ef4444';
        } else {
            totalSizeNotice.style.background = 'rgba(16, 185, 129, 0.1)';
            totalSizeNotice.style.borderColor = 'rgba(16, 185, 129, 0.2)';
            totalSizeNotice.style.color = '#10b981';
        }
        
        uploadBtn.style.display = 'block';
        fileCount.textContent = portalSelectedFiles.length;
        
        listContainer.innerHTML = portalSelectedFiles.map((file, idx) => {
            const totalChunks = Math.ceil(file.size / PORTAL_CHUNK_SIZE);
            const isSmallFile = file.size <= 90 * 1024 * 1024; // 90MB
            const sizeInfo = isSmallFile ? formatFileSize(file.size) : `${formatFileSize(file.size)} · ${totalChunks} 个分片`;
            return `
            <div class="portal-file-item" id="portal-file-${idx}">
                <div class="portal-file-icon"><i class="bi bi-file-earmark"></i></div>
                <div class="portal-file-info">
                    <div class="portal-file-name">${escapeHtml(file.name)}</div>
                    <div class="portal-file-size">${sizeInfo}</div>
                    <div id="portal-progress-${idx}" style="display: none; margin-top: 8px;">
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--portal-text-muted); margin-bottom: 4px;">
                            <span id="portal-chunk-${idx}">准备中...</span>
                            <span id="portal-percent-${idx}">0%</span>
                        </div>
                        <div style="height: 4px; background: var(--portal-border); border-radius: 2px; overflow: hidden;">
                            <div id="portal-bar-${idx}" style="height: 100%; background: var(--portal-gradient); width: 0%; transition: width 0.2s;"></div>
                        </div>
                    </div>
                </div>
                <div id="portal-status-${idx}">
                    <button class="portal-file-remove" onclick="removePortalFile(${idx})">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `}).join('');
    }
    
    function removePortalFile(idx) {
        portalSelectedFiles.splice(idx, 1);
        renderPortalUploadList();
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    async function startPortalUpload() {
        if (!currentProjectId || portalSelectedFiles.length === 0) return;
        
        // 检查总大小限制
        const totalSize = portalSelectedFiles.reduce((sum, f) => sum + f.size, 0);
        if (totalSize > PORTAL_MAX_TOTAL_SIZE) {
            PortalUI.Toast.error(`单次上传总大小超过3GB限制！当前: ${formatFileSize(totalSize)}，请移除部分文件`);
            return;
        }
        
        const uploadBtn = document.getElementById('portalUploadBtn');
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> 上传中...';
        
        // 显示总体进度
        document.getElementById('portalOverallProgress').style.display = 'block';
        
        let successCount = 0;
        let failCount = 0;
        const totalFiles = portalSelectedFiles.length;
        
        console.log('%c[门户上传开始] 共 ' + totalFiles + ' 个文件待上传', 'color: #6366f1; font-weight: bold;');
        
        for (let i = 0; i < portalSelectedFiles.length; i++) {
            const file = portalSelectedFiles[i];
            const progressDiv = document.getElementById(`portal-progress-${i}`);
            const chunkSpan = document.getElementById(`portal-chunk-${i}`);
            const percentSpan = document.getElementById(`portal-percent-${i}`);
            const barDiv = document.getElementById(`portal-bar-${i}`);
            const statusDiv = document.getElementById(`portal-status-${i}`);
            const fileItem = document.getElementById(`portal-file-${i}`);
            
            // 显示进度
            progressDiv.style.display = 'block';
            statusDiv.innerHTML = '<i class="bi bi-arrow-repeat spin" style="color: var(--portal-primary);"></i>';
            
            // 更新总体进度
            updatePortalOverallProgress(i, totalFiles, 0);
            
            console.log(`%c[文件 ${i + 1}/${totalFiles}] 开始上传: ${file.name} (${formatFileSize(file.size)})`, 'color: #0891b2;');
            
            try {
                await uploadPortalFileChunked(file, i, {
                    onChunkProgress: (chunkIndex, totalChunks, chunkPercent) => {
                        if (totalChunks === 1) {
                            chunkSpan.textContent = '上传中...';
                        } else {
                            chunkSpan.textContent = `分片 ${chunkIndex + 1}/${totalChunks}`;
                        }
                        const overallPercent = ((chunkIndex + chunkPercent / 100) / totalChunks) * 100;
                        barDiv.style.width = `${overallPercent}%`;
                        percentSpan.textContent = `${Math.round(overallPercent)}%`;
                        updatePortalOverallProgress(i, totalFiles, overallPercent);
                    },
                    onChunkComplete: (chunkIndex, totalChunks) => {
                        console.log(`  ✓ 分片 ${chunkIndex + 1}/${totalChunks} 完成`);
                    },
                    onMerging: () => {
                        chunkSpan.textContent = '正在处理中，请勿关闭页面...';
                        chunkSpan.style.color = '#f59e0b';
                        statusDiv.innerHTML = '<i class="bi bi-hourglass-split spin" style="color: #f59e0b; font-size: 18px;"></i>';
                    }
                });
                
                // 成功
                chunkSpan.textContent = '上传完成';
                chunkSpan.style.color = '';
                percentSpan.textContent = '100%';
                barDiv.style.width = '100%';
                statusDiv.innerHTML = '<i class="bi bi-check-circle-fill" style="color: #10b981; font-size: 18px;"></i>';
                successCount++;
                portalConsecutiveFailures = 0;
                
                console.log(`%c[文件 ${i + 1}/${totalFiles}] ✓ 上传成功: ${file.name}`, 'color: #10b981; font-weight: bold;');
                
            } catch (err) {
                // 失败
                chunkSpan.textContent = '上传失败';
                statusDiv.innerHTML = `<i class="bi bi-x-circle-fill" style="color: #ef4444; font-size: 18px;" title="${escapeHtml(err.message)}"></i>`;
                failCount++;
                portalConsecutiveFailures++;
                
                console.error(`%c[文件 ${i + 1}/${totalFiles}] ✗ 上传失败: ${file.name}`, 'color: #ef4444; font-weight: bold;');
                console.error('  错误详情:', err.message);
                console.log(`  连续失败次数: ${portalConsecutiveFailures}`);
                
                // 连续失败3次
                if (portalConsecutiveFailures >= 3) {
                    console.warn('%c[警告] 连续失败3次，建议联系客服', 'color: #f59e0b; font-weight: bold;');
                    showPortalContactModal();
                    break;
                }
            }
            
            updatePortalOverallProgress(i + 1, totalFiles, 0);
        }
        
        console.log('%c[上传完成] 成功: ' + successCount + ', 失败: ' + failCount, 
            failCount === 0 ? 'color: #10b981; font-weight: bold;' : 'color: #f59e0b; font-weight: bold;');
        
        uploadBtn.disabled = false;
        
        if (successCount > 0 && failCount === 0) {
            PortalUI.Toast.success(`成功上传 ${successCount} 个文件！`);
            uploadBtn.style.display = 'none';
            portalSelectedFiles = [];
            loadPortalUploadedFiles(currentProjectId);
        } else if (successCount > 0) {
            PortalUI.Toast.warning(`成功 ${successCount} 个，失败 ${failCount} 个`);
            loadPortalUploadedFiles(currentProjectId);
        } else if (failCount > 0 && portalConsecutiveFailures < 3) {
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> 重新上传';
            PortalUI.Toast.error(`上传失败: ${failCount} 个文件`);
        }
    }
    
    function updatePortalOverallProgress(completed, total, currentPercent) {
        const percent = ((completed + currentPercent / 100) / total) * 100;
        document.getElementById('portalOverallFill').style.width = `${percent}%`;
        document.getElementById('portalOverallStats').textContent = `${Math.min(completed + 1, total)} / ${total} 文件 (${Math.round(percent)}%)`;
    }
    
    // 小文件阈值：90MB以下直接上传，不分片
    // 超过90MB的文件使用分片上传，每片90MB
    const PORTAL_SMALL_FILE_THRESHOLD = 90 * 1024 * 1024;
    
    async function uploadPortalFileChunked(file, fileIndex, callbacks) {
        const totalChunks = Math.ceil(file.size / PORTAL_CHUNK_SIZE);
        const isSmallFile = file.size <= PORTAL_SMALL_FILE_THRESHOLD;
        
        console.log(`  文件大小: ${formatFileSize(file.size)}, 分片数: ${totalChunks}, 小文件模式: ${isSmallFile}`);
        
        // 小文件直接上传，不分片
        if (isSmallFile) {
            return await uploadPortalFileDirectly(file, fileIndex, callbacks);
        }
        
        // 1. 初始化
        const initRes = await fetch(`${API_URL}/portal_chunk_upload.php`, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'init',
                token: TOKEN,
                project_id: currentProjectId,
                file_name: file.name,
                file_size: file.size,
                file_type: file.type || 'application/octet-stream',
                total_chunks: totalChunks
            })
        });
        const initData = await initRes.json();
        if (!initData.success) throw new Error(initData.error || '初始化失败');
        
        const uploadId = initData.upload_id;
        console.log(`  ✓ 初始化成功, upload_id: ${uploadId}`);
        
        // 2. 并发上传分片（3并发）
        const CONCURRENT_UPLOADS = 3;
        const chunkProgress = {};
        let completedChunks = 0;
        
        const uploadSingleChunk = async (chunkIndex) => {
            const start = chunkIndex * PORTAL_CHUNK_SIZE;
            const end = Math.min(start + PORTAL_CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);
            
            await uploadPortalChunk(uploadId, chunkIndex, chunk, (percent) => {
                chunkProgress[chunkIndex] = percent;
                // 计算总体进度
                const totalProgress = Object.values(chunkProgress).reduce((a, b) => a + b, 0);
                const avgProgress = totalProgress / totalChunks;
                callbacks.onChunkProgress(completedChunks, totalChunks, avgProgress);
            });
            completedChunks++;
            callbacks.onChunkComplete(completedChunks - 1, totalChunks);
        };
        
        // 并发上传
        const chunkIndexes = Array.from({ length: totalChunks }, (_, i) => i);
        for (let i = 0; i < chunkIndexes.length; i += CONCURRENT_UPLOADS) {
            const batch = chunkIndexes.slice(i, i + CONCURRENT_UPLOADS);
            await Promise.all(batch.map(idx => uploadSingleChunk(idx)));
        }
        
        // 3. 完成 - 显示合并提示
        console.log('  → 合并分片中...');
        callbacks.onMerging && callbacks.onMerging();
        
        const completeRes = await fetch(`${API_URL}/portal_chunk_upload.php`, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'complete',
                token: TOKEN,
                upload_id: uploadId
            })
        });
        const completeData = await completeRes.json();
        if (!completeData.success) throw new Error(completeData.error || '合并失败');
        
        console.log('  ✓ 合并完成');
        return completeData;
    }
    
    // 小文件直接上传（不分片）
    async function uploadPortalFileDirectly(file, fileIndex, callbacks) {
        console.log('  → 使用直接上传模式（小文件）');
        
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('action', 'direct');
            formData.append('token', TOKEN);
            formData.append('project_id', currentProjectId);
            formData.append('file', file);
            
            let uploadComplete = false;
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round(e.loaded / e.total * 100);
                    callbacks.onChunkProgress(0, 1, percent);
                    // 传输完成100%后，显示服务器处理中状态
                    if (percent >= 100 && !uploadComplete) {
                        uploadComplete = true;
                        callbacks.onMerging && callbacks.onMerging();
                    }
                }
            });
            // 传输结束事件（备用，确保显示处理中状态）
            xhr.upload.addEventListener('loadend', () => {
                if (!uploadComplete) {
                    uploadComplete = true;
                    callbacks.onMerging && callbacks.onMerging();
                }
            });
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            // 打印服务器处理耗时
                            if (res.data?.timings_ms) {
                                console.log(`  ✓ 服务器处理耗时: S3=${res.data.timings_ms.s3_upload}ms, DB=${res.data.timings_ms.db_insert}ms, 总计=${res.data.timings_ms.total}ms`);
                            }
                            // 打印异步上传调试信息
                            if (res.data?.async_debug) {
                                console.log('  [ASYNC_DEBUG]', JSON.stringify(res.data.async_debug));
                            }
                            callbacks.onChunkComplete(0, 1);
                            resolve(res);
                        } else {
                            reject(new Error(res.error || '上传失败'));
                        }
                    } catch { reject(new Error('响应解析错误')); }
                } else {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        reject(new Error(res.error || `HTTP ${xhr.status}`));
                    } catch { reject(new Error(`HTTP ${xhr.status}`)); }
                }
            });
            xhr.addEventListener('error', () => reject(new Error('网络错误')));
            xhr.open('POST', `${API_URL}/portal_chunk_upload.php`);
            xhr.send(formData);
        });
    }
    
    function uploadPortalChunk(uploadId, chunkIndex, chunkBlob, onProgress) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('action', 'upload_chunk');
            formData.append('token', TOKEN);
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', chunkIndex);
            formData.append('chunk', chunkBlob, 'chunk');
            
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100));
            });
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        if (res.success) resolve(res);
                        else reject(new Error(res.error || '分片上传失败'));
                    } catch { reject(new Error('响应解析错误')); }
                } else {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        reject(new Error(res.error || `HTTP ${xhr.status}`));
                    } catch { reject(new Error(`HTTP ${xhr.status}`)); }
                }
            });
            xhr.addEventListener('error', () => reject(new Error('网络错误')));
            xhr.open('POST', `${API_URL}/portal_chunk_upload.php`);
            xhr.send(formData);
        });
    }
    
    function showPortalContactModal() {
        PortalUI.Modal.show({
            title: '上传遇到问题',
            content: `
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 64px; height: 64px; background: rgba(245, 158, 11, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                        <i class="bi bi-exclamation-triangle" style="font-size: 32px; color: #f59e0b;"></i>
                    </div>
                    <p style="color: var(--portal-text-muted); line-height: 1.6;">
                        您的文件已连续上传失败多次，可能是网络不稳定或文件过大导致。<br><br>
                        建议您联系客服人员，我们将协助您通过其他方式完成文件传输。
                    </p>
                </div>
            `,
            buttons: [
                { text: '稍后再试', type: 'secondary', onClick: () => { portalConsecutiveFailures = 0; PortalUI.Modal.hide(); } },
                { text: '联系客服', type: 'primary', onClick: () => { window.open('mailto:support@example.com?subject=文件上传问题', '_blank'); PortalUI.Modal.hide(); } }
            ]
        });
    }
    
    // 已上传文件选中状态
    let portalSelectedFileIds = new Set();
    let portalUploadedFilesData = [];
    
    function loadPortalUploadedFiles(projectId) {
        const container = document.getElementById('portalUploadedFiles');
        const actionsDiv = document.getElementById('portalFileActions');
        if (!container) return;
        
        portalSelectedFileIds.clear();
        
        fetch(`${API_URL}/portal_customer_files.php?token=${TOKEN}&project_id=${projectId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data && data.data.length > 0) {
                    portalUploadedFilesData = data.data;
                    if (actionsDiv) actionsDiv.style.display = 'flex';
                    container.innerHTML = data.data.map(f => `
                        <div class="portal-uploaded-file" data-file-id="${f.id}" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--portal-bg); border-radius: 8px; margin-bottom: 8px;">
                            <input type="checkbox" class="portal-file-checkbox" data-id="${f.id}" onchange="portalToggleFileSelect(${f.id})" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--portal-primary);">
                            <div class="portal-file-icon" style="width: 40px; height: 40px; background: rgba(99, 102, 241, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-file-earmark" style="font-size: 18px; color: var(--portal-primary);"></i>
                            </div>
                            <div class="portal-file-info" style="flex: 1; min-width: 0;">
                                <div class="portal-file-name" style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(f.file_name.replace(/^(客户上传\+|分享\+)/, ''))}</div>
                                <div class="portal-file-size" style="font-size: 12px; color: var(--portal-text-muted);">${formatFileSize(f.file_size)} · ${formatTime(f.create_time)}</div>
                            </div>
                            <div class="portal-file-actions" style="display: flex; gap: 4px;">
                                <button class="portal-btn portal-btn-ghost portal-btn-sm" onclick="portalRenameFile(${f.id}, '${escapeHtml(f.file_name.replace(/^(客户上传\+|分享\+)/, ''))}')" title="重命名">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="portal-btn portal-btn-ghost portal-btn-sm" onclick="portalDeleteFile(${f.id})" title="删除" style="color: #ef4444;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `).join('');
                } else {
                    portalUploadedFilesData = [];
                    if (actionsDiv) actionsDiv.style.display = 'none';
                    container.innerHTML = `
                        <div class="portal-empty" style="padding: 24px;">
                            <div class="portal-empty-icon"><i class="bi bi-folder2-open"></i></div>
                            <div class="portal-empty-title">暂无上传的文件</div>
                        </div>
                    `;
                }
                updatePortalSelectedCount();
            })
            .catch(() => {
                if (actionsDiv) actionsDiv.style.display = 'none';
                container.innerHTML = '<div style="color: #ef4444; text-align: center; padding: 20px;">加载失败</div>';
            });
    }
    
    function portalToggleFileSelect(fileId) {
        if (portalSelectedFileIds.has(fileId)) {
            portalSelectedFileIds.delete(fileId);
        } else {
            portalSelectedFileIds.add(fileId);
        }
        updatePortalSelectedCount();
    }
    
    function updatePortalSelectedCount() {
        const countSpan = document.getElementById('portalSelectedCount');
        const deleteBtn = document.getElementById('portalBatchDeleteBtn');
        const selectAllBtn = document.getElementById('portalSelectAllBtn');
        
        if (countSpan) countSpan.textContent = portalSelectedFileIds.size;
        if (deleteBtn) deleteBtn.disabled = portalSelectedFileIds.size === 0;
        
        if (selectAllBtn) {
            if (portalSelectedFileIds.size === portalUploadedFilesData.length && portalUploadedFilesData.length > 0) {
                selectAllBtn.innerHTML = '<i class="bi bi-x-square"></i> 取消全选';
            } else {
                selectAllBtn.innerHTML = '<i class="bi bi-check2-square"></i> 全选';
            }
        }
    }
    
    function portalSelectAllFiles() {
        const checkboxes = document.querySelectorAll('.portal-file-checkbox');
        const allSelected = portalSelectedFileIds.size === portalUploadedFilesData.length;
        
        if (allSelected) {
            portalSelectedFileIds.clear();
            checkboxes.forEach(cb => cb.checked = false);
        } else {
            portalUploadedFilesData.forEach(f => portalSelectedFileIds.add(f.id));
            checkboxes.forEach(cb => cb.checked = true);
        }
        updatePortalSelectedCount();
    }
    
    function portalDeleteFile(fileId) {
        if (!confirm('确定要删除这个文件吗？')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('token', TOKEN);
        formData.append('project_id', currentProjectId);
        formData.append('file_id', fileId);
        
        fetch(`${API_URL}/portal_file_manage.php`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                PortalUI.Toast.show('文件已删除', 'success');
                loadPortalUploadedFiles(currentProjectId);
            } else {
                PortalUI.Toast.show(data.error || '删除失败', 'error');
            }
        })
        .catch(() => PortalUI.Toast.show('删除失败', 'error'));
    }
    
    function portalBatchDelete() {
        if (portalSelectedFileIds.size === 0) return;
        
        if (!confirm(`确定要删除选中的 ${portalSelectedFileIds.size} 个文件吗？`)) return;
        
        const formData = new FormData();
        formData.append('action', 'batch_delete');
        formData.append('token', TOKEN);
        formData.append('project_id', currentProjectId);
        formData.append('file_ids', Array.from(portalSelectedFileIds).join(','));
        
        fetch(`${API_URL}/portal_file_manage.php`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                PortalUI.Toast.show(`已删除 ${data.deleted_count} 个文件`, 'success');
                loadPortalUploadedFiles(currentProjectId);
            } else {
                PortalUI.Toast.show(data.error || '删除失败', 'error');
            }
        })
        .catch(() => PortalUI.Toast.show('删除失败', 'error'));
    }
    
    function portalRenameFile(fileId, currentName) {
        const newName = prompt('请输入新的文件名：', currentName);
        if (!newName || newName === currentName) return;
        
        const formData = new FormData();
        formData.append('action', 'rename');
        formData.append('token', TOKEN);
        formData.append('project_id', currentProjectId);
        formData.append('file_id', fileId);
        formData.append('new_name', newName);
        
        fetch(`${API_URL}/portal_file_manage.php`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                PortalUI.Toast.show('重命名成功', 'success');
                loadPortalUploadedFiles(currentProjectId);
            } else {
                PortalUI.Toast.show(data.error || '重命名失败', 'error');
            }
        })
        .catch(() => PortalUI.Toast.show('重命名失败', 'error'));
    }
    
    // 拖拽上传
    document.addEventListener('dragover', function(e) {
        const uploadZone = document.getElementById('portalUploadZone');
        if (uploadZone && uploadZone.offsetParent !== null) {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        }
    });
    
    document.addEventListener('dragleave', function(e) {
        const uploadZone = document.getElementById('portalUploadZone');
        if (uploadZone) {
            uploadZone.classList.remove('dragover');
        }
    });
    
    document.addEventListener('drop', function(e) {
        const uploadZone = document.getElementById('portalUploadZone');
        if (uploadZone && uploadZone.offsetParent !== null) {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            const files = Array.from(e.dataTransfer.files);
            files.forEach(file => {
                if (!portalSelectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    portalSelectedFiles.push(file);
                }
            });
            renderPortalUploadList();
        }
    });

    // ========== 初始化 ==========
    document.addEventListener('DOMContentLoaded', function() {
        checkAccess();
        
        document.getElementById('passwordInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyPassword();
            }
        });
        
        // 分享按钮事件委托
        document.body.addEventListener('click', function(e) {
            const shareBtn = e.target.closest('.share-btn');
            if (shareBtn) {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(shareBtn.dataset.id, 10);
                const name = shareBtn.dataset.name || '';
                if (typeof showShareModal === 'function') {
                    showShareModal(id, name);
                } else {
                    alert('分享功能暂不可用，ID: ' + id);
                }
            }
        }, true);
    });
    </script>
</body>
</html>
