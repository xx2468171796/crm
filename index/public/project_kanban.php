<?php
/**
 * 技术项目看板（Kanban）
 * 按项目状态分列展示，技术只看分配给自己的项目
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();

// 使用权限检查代替硬编码角色判断
if (!canOrAdmin(PermissionCode::PROJECT_VIEW)) {
    header('Location: /public/index.php');
    exit;
}

$pageTitle = '项目看板';
layout_header($pageTitle);
?>

<style>
.kanban-container {
    display: flex;
    height: calc(100vh - 120px);
    overflow-x: auto;
    gap: 20px;
    padding: 20px;
    background: #f6f7f8;
}

.kanban-column {
    min-width: 320px;
    max-width: 320px;
    display: flex;
    flex-direction: column;
    background: rgba(241, 245, 249, 0.5);
    border-radius: 12px;
    border: 2px dashed #e2e8f0;
}

.kanban-column.active-status {
    background: rgba(19, 127, 236, 0.05);
    border-color: rgba(19, 127, 236, 0.2);
}

.kanban-header {
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.kanban-header h3 {
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.status-count {
    background: #e2e8f0;
    color: #64748b;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.kanban-cards {
    flex: 1;
    overflow-y: auto;
    padding: 0 12px 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.project-card {
    background: white;
    border-radius: 8px;
    padding: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.project-card:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.project-card .card-menu {
    position: absolute;
    top: 12px;
    right: 12px;
}

.project-card .card-menu-btn {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    color: #64748b;
    transition: all 0.2s;
}

.project-card .card-menu-btn:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.project-card .card-actions {
    display: none;
    position: absolute;
    top: 40px;
    right: 12px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 100;
    min-width: 140px;
    overflow: hidden;
}

.project-card .card-actions.show {
    display: block;
}

.project-card .card-actions a {
    display: block;
    padding: 10px 14px;
    color: #334155;
    text-decoration: none;
    font-size: 13px;
    transition: background 0.2s;
}

.project-card .card-actions a:hover {
    background: #f1f5f9;
}

.project-card-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 8px;
}

.project-code {
    font-size: 12px;
    font-family: monospace;
    color: #94a3b8;
}

.project-name {
    font-weight: 700;
    color: #1e293b;
    margin: 4px 0;
    font-size: 15px;
}

.customer-name {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 12px;
}

.project-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
}

.tag {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}

.project-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}

.update-time {
    font-size: 12px;
    color: #94a3b8;
}

.stage-remaining {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    background: #e0f2fe;
    color: #0369a1;
    margin-right: 8px;
}
.stage-remaining.warning {
    background: #fef3c7;
    color: #d97706;
}
.stage-remaining.overdue {
    background: #fee2e2;
    color: #dc2626;
    font-weight: 600;
}

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
    padding: 12px 16px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.filter-bar .filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-bar label {
    font-size: 13px;
    color: #64748b;
    white-space: nowrap;
}

.filter-bar select {
    min-width: 140px;
    font-size: 13px;
    padding: 6px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: white;
}

.view-toggle {
    display: flex;
    gap: 4px;
    margin-left: auto;
}

.view-toggle button {
    padding: 6px 12px;
    border: 1px solid #e2e8f0;
    background: white;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.view-toggle button:first-child {
    border-radius: 6px 0 0 6px;
}

.view-toggle button:last-child {
    border-radius: 0 6px 6px 0;
}

.view-toggle button.active {
    background: #137fec;
    border-color: #137fec;
    color: white;
}

.view-toggle button:hover:not(.active) {
    border-color: #137fec;
    color: #137fec;
}

/* 表格视图样式 */
.table-view {
    display: none;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.table-view.active {
    display: block;
}

.kanban-container.hidden {
    display: none;
}

.project-table {
    width: 100%;
    border-collapse: collapse;
}

.project-table th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.project-table th.sortable {
    cursor: pointer;
    user-select: none;
}

.project-table th.sortable:hover {
    background: #f1f5f9;
}

.project-table th .sort-icon {
    margin-left: 4px;
    opacity: 0.5;
}

.project-table th.sort-asc .sort-icon,
.project-table th.sort-desc .sort-icon {
    opacity: 1;
}

.project-table td {
    padding: 12px 16px;
    font-size: 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.project-table tr:hover td {
    background: #f8fafc;
}

.project-table .status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.project-table .action-btn {
    padding: 4px 10px;
    font-size: 12px;
    border: 1px solid #e2e8f0;
    background: white;
    border-radius: 4px;
    cursor: pointer;
    margin-right: 4px;
}

.project-table .action-btn:hover {
    border-color: #137fec;
    color: #137fec;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

/* 负责人头像样式 */
.tech-avatars {
    display: flex;
    align-items: center;
    gap: 2px;
}

.tech-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    font-size: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
    margin-left: -6px;
}

.tech-avatar:first-child {
    margin-left: 0;
}

.tech-avatar.more {
    background: #e2e8f0;
    color: #64748b;
}

.tech-avatar[title] {
    cursor: default;
}

/* 人员视图样式 */
.person-view-container {
    padding: 20px;
}

.person-group {
    background: white;
    border-radius: 12px;
    margin-bottom: 16px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

.person-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    cursor: pointer;
    transition: background 0.2s;
}

.person-group-header:hover {
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
}

.person-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.person-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
}

.person-name {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
}

.person-project-count {
    font-size: 13px;
    color: #64748b;
    background: #e2e8f0;
    padding: 4px 10px;
    border-radius: 12px;
}

.person-expand-icon {
    font-size: 12px;
    color: #64748b;
    transition: transform 0.2s;
}

.person-projects {
    border-top: 1px solid #e2e8f0;
}

.person-project-table {
    width: 100%;
    border-collapse: collapse;
}

.person-project-table th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
}

.person-project-table td {
    padding: 12px 16px;
    font-size: 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}

.person-project-table tr:hover td {
    background: #f8fafc;
}

.person-project-table .status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.person-project-table .action-btn {
    padding: 4px 10px;
    font-size: 12px;
    border: 1px solid #e2e8f0;
    background: white;
    border-radius: 4px;
    cursor: pointer;
}

.person-project-table .action-btn:hover {
    border-color: #137fec;
    color: #137fec;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="mb-1">项目管理看板</h2>
        <p class="text-muted small mb-0">管理项目全生命周期及客户交付成果</p>
    </div>
</div>

<div class="filter-bar">
    <div class="filter-group">
            <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="搜索项目/客户/客户群..." style="width: 200px;" onkeyup="debounceSearch()">
        </div>
        <div class="filter-group">
            <label>人员类型:</label>
            <select id="filterUserType" onchange="loadUsersByType()">
                <option value="tech">技术人员</option>
                <option value="sales">销售人员</option>
            </select>
        </div>
        <div class="filter-group">
            <label>选择人员:</label>
            <select id="filterUser" onchange="applyFilters()">
                <option value="">所有人员</option>
            </select>
        </div>
        <div class="filter-group">
            <label>项目状态:</label>
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">所有状态</option>
                <option value="待沟通">待沟通</option>
                <option value="需求确认">需求确认</option>
                <option value="设计中">设计中</option>
                <option value="设计核对">设计核对</option>
                <option value="客户完结">客户完结</option>
                <option value="设计评价">设计评价</option>
            </select>
        </div>
        <div class="filter-group">
            <label>需求状态:</label>
            <select id="filterRequirementStatus" onchange="applyFilters()">
                <option value="">全部</option>
                <option value="has_pending">有待处理需求</option>
                <option value="all_confirmed">需求全部确认</option>
                <option value="no_form">无表单</option>
            </select>
        </div>
        <div class="filter-group">
            <label>排序:</label>
            <select id="sortBy" onchange="applyFilters()">
                <option value="update_time-desc">更新时间 ↓</option>
                <option value="update_time-asc">更新时间 ↑</option>
                <option value="create_time-desc">创建时间 ↓</option>
                <option value="create_time-asc">创建时间 ↑</option>
            </select>
        </div>
        <!-- 客户分类筛选 -->
        <div class="filter-group" id="customerFilterFieldsContainer"></div>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">清除</button>
        <div class="filter-chips" style="display: flex; gap: 8px; margin-left: 8px;">
            <button class="btn btn-sm btn-outline-warning" id="chipPending" onclick="toggleChip('has_pending')">
                <i class="bi bi-exclamation-circle"></i> 待处理需求
            </button>
            <button class="btn btn-sm btn-outline-primary" id="chipMine" onclick="toggleChip('my_assigned')">
                <i class="bi bi-person"></i> 我负责的
            </button>
        </div>
        <div class="filter-group" id="groupByContainer" style="display: none;">
            <label>分组:</label>
            <select id="groupBy" onchange="renderTable()">
                <option value="">不分组</option>
                <option value="current_status">按项目状态</option>
                <option value="requirement_status">按需求状态</option>
                <option value="customer_name">按客户</option>
                <option value="tech_user">按技术人员</option>
                <option value="creator">按销售/创建人</option>
            </select>
        </div>
        <div class="view-toggle">
            <button id="btnKanban" class="active" onclick="switchView('kanban')">📊 看板</button>
            <button id="btnTable" onclick="switchView('table')">📝 表格</button>
            <button id="btnPerson" onclick="switchView('person')">👤 人员</button>
        </div>
</div>

<div class="kanban-container">
        <div class="kanban-column" data-status="待沟通">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #94a3b8;"></span>
                    待沟通
                    <span class="status-count" id="count-待沟通">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-待沟通"></div>
        </div>

        <div class="kanban-column" data-status="需求确认">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #3b82f6;"></span>
                    需求确认
                    <span class="status-count" id="count-需求确认">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-需求确认"></div>
        </div>

        <div class="kanban-column active-status" data-status="设计中">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #137fec; animation: pulse 2s infinite;"></span>
                    设计中
                    <span class="status-count" id="count-设计中">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-设计中"></div>
        </div>

        <div class="kanban-column" data-status="设计核对">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #a855f7;"></span>
                    设计核对
                    <span class="status-count" id="count-设计核对">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-设计核对"></div>
        </div>

        <div class="kanban-column" data-status="客户完结">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #22c55e;"></span>
                    客户完结
                    <span class="status-count" id="count-客户完结">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-客户完结"></div>
        </div>

        <div class="kanban-column" data-status="设计评价">
            <div class="kanban-header">
                <h3>
                    <span class="status-dot" style="background: #1e293b;"></span>
                    设计评价
                    <span class="status-count" id="count-设计评价">0</span>
                </h3>
            </div>
            <div class="kanban-cards" id="cards-设计评价"></div>
        </div>
    </div>

    <!-- 表格视图 -->
    <div class="table-view" id="tableView">
        <table class="project-table">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortByColumn('project_code')">项目编号 <span class="sort-icon" id="sort-icon-project_code">↕</span></th>
                    <th>项目名称</th>
                    <th>客户</th>
                    <th class="sortable" onclick="sortByColumn('current_status')">状态 <span class="sort-icon" id="sort-icon-current_status">↕</span></th>
                    <th>技术人员</th>
                    <th class="sortable" onclick="sortByColumn('update_time')">更新时间 <span class="sort-icon" id="sort-icon-update_time">↕</span></th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>

    <!-- 人员视图 -->
    <div class="person-view" id="personView" style="display: none;">
        <div id="personViewContent"></div>
    </div>

<script>
// API_URL 已在 layout_header 中定义
const CURRENT_USER_ID = <?= $user['id'] ?? 0 ?>;
const CURRENT_USER_ROLE = '<?= $user['role'] ?? '' ?>';
const IS_ADMIN = <?= json_encode(isAdmin($user)) ?>;
let allProjects = [];
let currentView = localStorage.getItem('kanban_view') || 'kanban';
let usersCache = { tech: [], sales: [] };
let searchTimeout = null;
let tableSortColumn = null;
let tableSortOrder = 'asc';
let projectAssignees = {}; // 项目负责人缓存

// 表格列排序
function sortByColumn(column) {
    // 切换排序方向
    if (tableSortColumn === column) {
        tableSortOrder = tableSortOrder === 'asc' ? 'desc' : 'asc';
    } else {
        tableSortColumn = column;
        tableSortOrder = 'asc';
    }
    
    // 更新排序图标
    updateSortIcons();
    
    // 对数据排序
    allProjects.sort((a, b) => {
        let valA = a[column];
        let valB = b[column];
        
        // 处理空值
        if (valA === null || valA === undefined) valA = '';
        if (valB === null || valB === undefined) valB = '';
        
        // 数字比较（时间戳）
        if (column === 'update_time' || column === 'create_time') {
            valA = parseInt(valA) || 0;
            valB = parseInt(valB) || 0;
        }
        
        // 字符串比较
        if (typeof valA === 'string') {
            valA = valA.toLowerCase();
            valB = valB.toLowerCase();
        }
        
        let result = 0;
        if (valA < valB) result = -1;
        if (valA > valB) result = 1;
        
        return tableSortOrder === 'asc' ? result : -result;
    });
    
    // 重新渲染表格
    renderTable();
}

// 更新排序图标
function updateSortIcons() {
    // 重置所有图标
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.textContent = '↕';
        icon.style.opacity = '0.5';
    });
    
    // 设置当前排序列的图标
    if (tableSortColumn) {
        const icon = document.getElementById(`sort-icon-${tableSortColumn}`);
        if (icon) {
            icon.textContent = tableSortOrder === 'asc' ? '↑' : '↓';
            icon.style.opacity = '1';
        }
    }
}

// 状态颜色配置
const statusColors = {
    '待沟通': { bg: '#f1f5f9', color: '#64748b' },
    '需求确认': { bg: '#dbeafe', color: '#2563eb' },
    '设计中': { bg: '#e0f2fe', color: '#0284c7' },
    '设计核对': { bg: '#f3e8ff', color: '#9333ea' },
    '客户完结': { bg: '#dcfce7', color: '#16a34a' },
    '设计评价': { bg: '#f1f5f9', color: '#1e293b' }
};

// 搜索防抖
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
}

// 根据类型加载人员列表
function loadUsersByType() {
    const userType = document.getElementById('filterUserType').value;
    const select = document.getElementById('filterUser');
    select.innerHTML = '<option value="">所有人员</option>';
    
    // 如果缓存中有数据，直接使用
    if (usersCache[userType] && usersCache[userType].length > 0) {
        usersCache[userType].forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = user.realname || user.username;
            select.appendChild(option);
        });
        return;
    }
    
    // 否则从 API 加载
    fetch(`${API_URL}/users.php?role=${userType}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                usersCache[userType] = data.data;
                data.data.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.realname || user.username;
                    select.appendChild(option);
                });
            }
        });
}

// 加载项目列表
function loadProjects() {
    const params = new URLSearchParams();
    
    const userType = document.getElementById('filterUserType').value;
    const userId = document.getElementById('filterUser').value;
    const status = document.getElementById('filterStatus').value;
    const sortValue = document.getElementById('sortBy').value;
    const search = document.getElementById('searchInput').value.trim();
    const [sort, order] = sortValue.split('-');
    
    // 根据人员类型设置不同的筛选参数
    if (userId) {
        if (userType === 'tech') {
            params.append('tech_user_id', userId);
        } else {
            params.append('sales_user_id', userId);
        }
    }
    if (status) params.append('status', status);
    if (search) params.append('search', search);
    params.append('sort', sort);
    params.append('order', order);
    
    fetch(`${API_URL}/projects.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                allProjects = data.data;
                renderCurrentView();
                // 批量加载项目负责人
                loadBatchAssignees();
            } else {
                showAlertModal('加载失败: ' + data.message, 'error');
            }
        })
        .catch(err => {
            showAlertModal('加载失败: ' + err.message, 'error');
        });
}

// 批量加载项目负责人
function loadBatchAssignees() {
    if (allProjects.length === 0) return;
    
    const projectIds = allProjects.map(p => p.id).join(',');
    
    fetch(`${API_URL}/projects.php?action=batch_assignees&project_ids=${projectIds}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                projectAssignees = data.data;
                // 更新卡片上的负责人显示
                updateAssigneesDisplay();
            }
        })
        .catch(err => console.error('加载负责人失败:', err));
}

// 更新所有卡片的负责人显示
function updateAssigneesDisplay() {
    allProjects.forEach(project => {
        const card = document.getElementById(`card-${project.id}`);
        if (!card) return;
        
        const avatarContainer = card.querySelector('.tech-avatars');
        if (!avatarContainer) return;
        
        const assignees = projectAssignees[project.id] || [];
        avatarContainer.innerHTML = renderTechAvatars(assignees);
    });
}

// 渲染负责人头像
function renderTechAvatars(assignees) {
    if (!assignees || assignees.length === 0) {
        return '<span style="font-size: 11px; color: #94a3b8;">未分配</span>';
    }
    
    const maxShow = 3;
    let html = '';
    
    assignees.slice(0, maxShow).forEach(a => {
        const name = a.realname || a.username || '?';
        const initial = name.charAt(0);
        html += `<span class="tech-avatar" title="${name}">${initial}</span>`;
    });
    
    if (assignees.length > maxShow) {
        html += `<span class="tech-avatar more">+${assignees.length - maxShow}</span>`;
    }
    
    return html;
}

// 应用筛选
function applyFilters() {
    loadProjects();
}

// 清除筛选
function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterUser').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterRequirementStatus').value = '';
    document.getElementById('sortBy').value = 'update_time-desc';
    document.getElementById('groupBy').value = '';
    // 清除快捷筛选状态
    document.getElementById('chipPending').classList.remove('active', 'btn-warning');
    document.getElementById('chipPending').classList.add('btn-outline-warning');
    document.getElementById('chipMine').classList.remove('active', 'btn-primary');
    document.getElementById('chipMine').classList.add('btn-outline-primary');
    loadProjects();
}

// 快捷筛选切换
function toggleChip(chipType) {
    if (chipType === 'has_pending') {
        const chip = document.getElementById('chipPending');
        const reqFilter = document.getElementById('filterRequirementStatus');
        
        if (chip.classList.contains('active')) {
            chip.classList.remove('active', 'btn-warning');
            chip.classList.add('btn-outline-warning');
            reqFilter.value = '';
        } else {
            chip.classList.add('active', 'btn-warning');
            chip.classList.remove('btn-outline-warning');
            reqFilter.value = 'has_pending';
        }
        applyFilters();
    } else if (chipType === 'my_assigned') {
        const chip = document.getElementById('chipMine');
        const userFilter = document.getElementById('filterUser');
        const userType = document.getElementById('filterUserType');
        
        if (chip.classList.contains('active')) {
            chip.classList.remove('active', 'btn-primary');
            chip.classList.add('btn-outline-primary');
            userFilter.value = '';
        } else {
            chip.classList.add('active', 'btn-primary');
            chip.classList.remove('btn-outline-primary');
            // 设置为当前用户
            userType.value = 'tech';
            loadUsersByType().then(() => {
                userFilter.value = CURRENT_USER_ID;
                applyFilters();
            });
            return;
        }
        applyFilters();
    }
}

// 切换视图
function switchView(view) {
    currentView = view;
    localStorage.setItem('kanban_view', view);
    
    document.getElementById('btnKanban').classList.toggle('active', view === 'kanban');
    document.getElementById('btnTable').classList.toggle('active', view === 'table');
    document.getElementById('btnPerson').classList.toggle('active', view === 'person');
    
    document.querySelector('.kanban-container').classList.toggle('hidden', view !== 'kanban');
    document.getElementById('tableView').classList.toggle('active', view === 'table');
    document.getElementById('personView').style.display = view === 'person' ? 'block' : 'none';
    
    // 显示/隐藏分组选项
    document.getElementById('groupByContainer').style.display = view === 'table' ? 'flex' : 'none';
    
    renderCurrentView();
}

// 获取筛选后的项目列表
function getFilteredProjects() {
    const reqStatusFilter = document.getElementById('filterRequirementStatus').value;
    
    if (!reqStatusFilter) {
        return allProjects;
    }
    
    return allProjects.filter(project => {
        const stats = project.form_stats;
        
        if (reqStatusFilter === 'has_pending') {
            // 有待处理需求（communicating 或 modifying）
            return stats && ((stats.communicating || 0) + (stats.modifying || 0)) > 0;
        } else if (reqStatusFilter === 'all_confirmed') {
            // 需求全部确认
            return stats && stats.total > 0 && stats.confirmed === stats.total;
        } else if (reqStatusFilter === 'no_form') {
            // 无表单
            return !stats || stats.total === 0;
        }
        return true;
    });
}

// 渲染当前视图
function renderCurrentView() {
    if (currentView === 'kanban') {
        renderKanban();
    } else if (currentView === 'table') {
        renderTable();
    } else if (currentView === 'person') {
        renderPersonView();
    }
}

// 渲染人员视图
function renderPersonView() {
    const filteredProjects = getFilteredProjects();
    const container = document.getElementById('personViewContent');
    
    // 按客户分组项目
    const customerGroups = {};
    filteredProjects.forEach(project => {
        const customerId = project.customer_id;
        const customerName = project.customer_name || '未知客户';
        if (!customerGroups[customerId]) {
            customerGroups[customerId] = {
                id: customerId,
                name: customerName,
                projects: []
            };
        }
        customerGroups[customerId].projects.push(project);
    });
    
    // 转为数组并按项目数量排序
    const customers = Object.values(customerGroups).sort((a, b) => b.projects.length - a.projects.length);
    
    let html = '<div class="person-view-container">';
    
    if (customers.length === 0) {
        html += '<div class="empty-state"><p>暂无项目数据</p></div>';
    } else {
        customers.forEach(customer => {
            const isExpanded = customer.projects.length <= 3; // 默认展开3个以下项目的客户
            html += `
                <div class="person-group" data-customer-id="${customer.id}">
                    <div class="person-group-header" onclick="togglePersonGroup(${customer.id})">
                        <div class="person-info">
                            <div class="person-avatar">${customer.name.substring(0, 1)}</div>
                            <div class="person-name">${customer.name}</div>
                            <span class="person-project-count">${customer.projects.length} 个项目</span>
                        </div>
                        <div class="person-expand-icon" id="expand-icon-${customer.id}">${isExpanded ? '▼' : '▶'}</div>
                    </div>
                    <div class="person-projects" id="person-projects-${customer.id}" style="display: ${isExpanded ? 'block' : 'none'};">
                        <table class="person-project-table">
                            <thead>
                                <tr>
                                    <th>项目名称</th>
                                    <th>状态</th>
                                    <th>技术人员</th>
                                    <th>更新时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>`;
            
            customer.projects.forEach(project => {
                const statusColors = {
                    '待沟通': '#94a3b8', '需求确认': '#3b82f6', '设计中': '#137fec',
                    '设计核对': '#a855f7', '客户完结': '#22c55e', '设计评价': '#1e293b'
                };
                const statusColor = statusColors[project.current_status] || '#64748b';
                const techNames = (project.tech_users || []).map(t => t.realname || t.name).join(', ') || '未分配';
                const updateTime = project.update_time ? new Date(project.update_time * 1000).toLocaleDateString('zh-CN') : '-';
                
                html += `
                    <tr onclick="goToProjectDetail(${project.id})" style="cursor: pointer;">
                        <td>${project.project_name || '默认项目'}</td>
                        <td><span class="status-badge" style="background: ${statusColor}; color: white;">${project.current_status || '未知'}</span></td>
                        <td>${techNames}</td>
                        <td>${updateTime}</td>
                        <td>
                            <button class="action-btn" onclick="event.stopPropagation(); goToProjectDetail(${project.id})">
                                <i class="bi bi-pencil"></i> 详情
                            </button>
                        </td>
                    </tr>`;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>`;
        });
    }
    
    html += '</div>';
    container.innerHTML = html;
}

// 切换人员组展开/收起
function togglePersonGroup(customerId) {
    const projectsDiv = document.getElementById('person-projects-' + customerId);
    const icon = document.getElementById('expand-icon-' + customerId);
    
    if (projectsDiv.style.display === 'none') {
        projectsDiv.style.display = 'block';
        icon.textContent = '▼';
    } else {
        projectsDiv.style.display = 'none';
        icon.textContent = '▶';
    }
}

// 渲染看板
function renderKanban() {
    const statuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
    const filteredProjects = getFilteredProjects();
    
    // 清空所有列
    statuses.forEach(status => {
        document.getElementById(`cards-${status}`).innerHTML = '';
        document.getElementById(`count-${status}`).textContent = '0';
    });
    
    // 按状态分组
    const grouped = {};
    statuses.forEach(s => grouped[s] = []);
    
    filteredProjects.forEach(project => {
        if (grouped[project.current_status]) {
            grouped[project.current_status].push(project);
        }
    });
    
    // 渲染卡片
    statuses.forEach(status => {
        const projects = grouped[status];
        document.getElementById(`count-${status}`).textContent = projects.length;
        
        const container = document.getElementById(`cards-${status}`);
        projects.forEach(project => {
            container.appendChild(createProjectCard(project));
        });
    });
}

// 创建项目卡片
function createProjectCard(project) {
    const card = document.createElement('div');
    card.className = 'project-card';
    card.onclick = () => viewProjectDetail(project.id);
    
    const updateTime = formatRelativeTime(project.update_time);
    
    const cardId = `card-${project.id}`;
    
    // 需求状态摘要
    let requirementSummary = '';
    if (project.form_stats) {
        const stats = project.form_stats;
        const total = stats.total || 0;
        const confirmed = stats.confirmed || 0;
        const pending = stats.pending || 0;
        const communicating = stats.communicating || 0;
        const modifying = stats.modifying || 0;
        
        if (total > 0) {
            const needAttention = communicating + modifying;
            if (needAttention > 0) {
                requirementSummary = `<div class="requirement-summary" style="margin-top: 8px;">
                    <span class="badge bg-warning text-dark" style="font-size: 11px;">${needAttention}个待处理</span>
                    <span class="text-muted" style="font-size: 11px; margin-left: 4px;">已确认 ${confirmed}/${total}</span>
                </div>`;
            } else if (confirmed === total) {
                requirementSummary = `<div class="requirement-summary" style="margin-top: 8px;">
                    <span class="badge bg-success" style="font-size: 11px;">需求已确认 ✓</span>
                </div>`;
            } else {
                requirementSummary = `<div class="requirement-summary" style="margin-top: 8px;">
                    <span class="text-muted" style="font-size: 11px;">已确认 ${confirmed}/${total}</span>
                </div>`;
            }
        }
    }
    
    const deleteAction = IS_ADMIN ? `<a href="#" onclick="event.stopPropagation(); event.preventDefault(); confirmDeleteProject(${project.id}, '${escapeHtml(project.project_name)}', '${project.project_code}')" style="color: #dc2626;">删除项目</a>` : '';
    
    card.innerHTML = `
        <div class="card-menu">
            <button class="card-menu-btn" onclick="event.stopPropagation(); toggleCardMenu('${cardId}')">⋯</button>
            <div class="card-actions" id="menu-${cardId}">
                <a href="#" onclick="event.stopPropagation(); event.preventDefault(); changeStatus(${project.id}, '${project.current_status}')">变更状态</a>
                <a href="#" onclick="event.stopPropagation(); event.preventDefault(); viewProjectDetail(${project.id})">查看详情</a>
                ${deleteAction}
            </div>
        </div>
        <div class="project-card-header">
            <span class="project-code">${project.project_code}</span>
        </div>
        <h4 class="project-name">${project.project_name}</h4>
        <p class="customer-name">客户：${project.customer_name || '未知'}</p>
        ${requirementSummary}
        ${project.group_code ? `<div class="project-tags"><span class="tag" style="background: #e0e7ff; color: #4f46e5;">${project.group_code}</span></div>` : ''}
        <div class="project-footer">
            <div class="tech-avatars"></div>
            ${renderStageTime(project.stage_time)}
            <span class="update-time">⏱ ${updateTime}</span>
        </div>
    `;
    
    card.id = cardId;
    
    return card;
}

// 渲染阶段剩余时间
function renderStageTime(stageTime) {
    if (!stageTime) return '';
    const remaining = stageTime.remaining_days;
    if (remaining === null || remaining === undefined) return '';
    
    if (remaining < 0) {
        return `<span class="stage-remaining overdue">超${Math.abs(remaining)}天</span>`;
    } else if (remaining === 0) {
        return `<span class="stage-remaining warning">今日到期</span>`;
    } else if (remaining <= 2) {
        return `<span class="stage-remaining warning">剩${remaining}天</span>`;
    } else {
        return `<span class="stage-remaining">剩${remaining}天</span>`;
    }
}

// 格式化相对时间
function formatRelativeTime(timestamp) {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    return new Date(timestamp * 1000).toLocaleDateString('zh-CN');
}

// 变更状态 - 优化版弹窗
function changeStatus(projectId, currentStatus) {
    const statuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
    const statusColors = {
        '待沟通': '#94a3b8',
        '需求确认': '#f59e0b',
        '设计中': '#6366f1',
        '设计核对': '#8b5cf6',
        '客户完结': '#10b981',
        '设计评价': '#06b6d4'
    };
    
    // 创建状态选择弹窗
    const modalHtml = `
        <div class="modal fade" id="statusSelectModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 360px;">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                    <div class="modal-header border-0" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 20px 24px;">
                        <div>
                            <h6 class="modal-title mb-1" style="color: #fff; font-weight: 600;">变更项目状态</h6>
                            <small style="color: rgba(255,255,255,0.8);">当前: ${currentStatus}</small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity: 0.8;"></button>
                    </div>
                    <div class="modal-body" style="padding: 16px 20px;">
                        <div class="status-list">
                            ${statuses.map((s, index) => {
                                const isCurrent = s === currentStatus;
                                const color = statusColors[s] || '#6366f1';
                                return `
                                    <div class="status-option ${isCurrent ? 'current' : ''}" 
                                         onclick="${isCurrent ? '' : `submitStatusChange(${projectId}, '${s}', '${currentStatus}')`}"
                                         style="display: flex; align-items: center; padding: 12px 16px; margin-bottom: 8px; 
                                                border-radius: 10px; cursor: ${isCurrent ? 'default' : 'pointer'}; 
                                                transition: all 0.2s; border: 2px solid ${isCurrent ? color : '#e2e8f0'};
                                                background: ${isCurrent ? color + '10' : '#fff'};"
                                         ${!isCurrent ? `onmouseover="this.style.borderColor='${color}'; this.style.background='${color}10';"
                                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.background='#fff';"` : ''}>
                                        <div style="width: 10px; height: 10px; border-radius: 50%; background: ${color}; margin-right: 12px;"></div>
                                        <span style="flex: 1; font-weight: 500; color: ${isCurrent ? color : '#334155'};">${s}</span>
                                        ${isCurrent ? '<span style="font-size: 12px; color: ' + color + '; background: ' + color + '20; padding: 2px 8px; border-radius: 4px;">当前</span>' : '<i class="bi bi-chevron-right" style="color: #94a3b8;"></i>'}
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const oldModal = document.getElementById('statusSelectModal');
    if (oldModal) oldModal.remove();
    
    // 添加新弹窗
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalElement = document.getElementById('statusSelectModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
}

// 提交状态变更
function submitStatusChange(projectId, newStatus, currentStatus) {
    if (newStatus === currentStatus) return;
    
    // 关闭弹窗
    const modalElement = document.getElementById('statusSelectModal');
    const modal = bootstrap.Modal.getInstance(modalElement);
    if (modal) modal.hide();
    
    fetch(`${API_URL}/projects.php`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            id: projectId,
            current_status: newStatus
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('状态变更成功', 'success');
            loadProjects();
        } else {
            showAlertModal('变更失败: ' + data.message, 'error');
        }
    })
    .catch(err => {
        showAlertModal('变更失败: ' + err.message, 'error');
    });
}

// 切换卡片菜单
function toggleCardMenu(cardId) {
    // 关闭所有其他菜单
    document.querySelectorAll('.card-actions.show').forEach(menu => {
        if (menu.id !== `menu-${cardId}`) {
            menu.classList.remove('show');
        }
    });
    
    const menu = document.getElementById(`menu-${cardId}`);
    if (menu) {
        menu.classList.toggle('show');
    }
}

// 点击其他地方关闭菜单
document.addEventListener('click', function(e) {
    if (!e.target.closest('.card-menu')) {
        document.querySelectorAll('.card-actions.show').forEach(menu => {
            menu.classList.remove('show');
        });
    }
});

// 查看项目详情
function viewProjectDetail(projectId) {
    openProjectSidebar(projectId);
}

// 跳转到项目详情页
function goToProjectDetail(projectId) {
    window.location.href = `project_detail.php?id=${projectId}`;
}

// 分组展开/收起状态
let groupCollapseState = {};

// 切换分组展开/收起
function toggleGroup(groupId, event) {
    // 如果点击的是门户按钮，不触发展开/收起
    if (event && (event.target.classList.contains('portal-settings-btn') || 
                  event.target.classList.contains('portal-copy-btn'))) {
        return;
    }
    groupCollapseState[groupId] = !groupCollapseState[groupId];
    renderTable();
}

// 全部展开
function expandAllGroups() {
    groupCollapseState = {};
    renderTable();
}

// 全部收起
function collapseAllGroups() {
    const groupBy = document.getElementById('groupBy').value;
    if (!groupBy) return;
    
    const groups = getGroupedProjects(groupBy);
    Object.keys(groups).forEach(key => {
        groupCollapseState[key] = true;
    });
    renderTable();
}

// 获取分组数据
function getGroupedProjects(groupBy) {
    const groups = {};
    const filteredProjects = getFilteredProjects();
    
    filteredProjects.forEach(project => {
        let key;
        
        if (groupBy === 'tech_user') {
            // 按技术人员分组（一个项目可能有多个技术）
            if (project.tech_users && project.tech_users.length > 0) {
                project.tech_users.forEach(tech => {
                    key = tech.realname || tech.username || '未分配';
                    if (!groups[key]) groups[key] = [];
                    if (!groups[key].find(p => p.id === project.id)) {
                        groups[key].push(project);
                    }
                });
            } else {
                key = '未分配';
                if (!groups[key]) groups[key] = [];
                groups[key].push(project);
            }
        } else if (groupBy === 'creator') {
            key = project.creator_name || '未知';
            if (!groups[key]) groups[key] = [];
            groups[key].push(project);
        } else if (groupBy === 'requirement_status') {
            // 按需求状态分组
            const stats = project.form_stats;
            if (!stats || stats.total === 0) {
                key = '无表单';
            } else if ((stats.communicating || 0) + (stats.modifying || 0) > 0) {
                key = '有待处理需求';
            } else if (stats.confirmed === stats.total) {
                key = '需求全部确认';
            } else {
                key = '待填写';
            }
            if (!groups[key]) groups[key] = [];
            groups[key].push(project);
        } else {
            key = project[groupBy] || '未分类';
            if (!groups[key]) groups[key] = [];
            groups[key].push(project);
        }
    });
    
    return groups;
}

// 获取分组标签
function getGroupLabel(groupBy) {
    const labels = {
        'current_status': '项目状态',
        'requirement_status': '需求状态',
        'customer_name': '客户',
        'tech_user': '技术人员',
        'creator': '销售/创建人'
    };
    return labels[groupBy] || '分组';
}

// 渲染表格视图
function renderTable() {
    const tbody = document.getElementById('tableBody');
    const groupBy = document.getElementById('groupBy').value;
    const filteredProjects = getFilteredProjects();
    
    if (!filteredProjects || filteredProjects.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">暂无项目数据</td></tr>';
        return;
    }
    
    let html = '';
    
    if (groupBy) {
        // 添加展开/收起全部按钮
        html += `
            <tr class="group-controls">
                <td colspan="7" style="background: #fff; padding: 8px 16px; border-bottom: 2px solid #e2e8f0;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="expandAllGroups()" style="margin-right: 8px;">全部展开</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="collapseAllGroups()">全部收起</button>
                </td>
            </tr>
        `;
        
        // 分组渲染
        const groups = getGroupedProjects(groupBy);
        const groupLabel = getGroupLabel(groupBy);
        
        Object.keys(groups).sort().forEach(groupName => {
            const projects = groups[groupName];
            const isCollapsed = groupCollapseState[groupName];
            const icon = isCollapsed ? '▶' : '▼';
            
            const safeGroupName = groupName.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            
            // 按客户分组时，添加门户访问设置和复制链接按钮
            let portalActions = '';
            if (groupBy === 'customer_name' && projects.length > 0 && projects[0].customer_id) {
                const customerId = projects[0].customer_id;
                // 使用 data 属性存储参数，避免 onclick 中的字符转义问题
                portalActions = `
                    <span style="float: right; margin-left: auto;">
                        <a href="#" class="portal-settings-btn" data-customer-id="${customerId}" data-customer-name="${groupName.replace(/"/g, '&quot;')}"
                           style="color: #137fec; text-decoration: none; font-weight: normal; font-size: 13px; margin-right: 16px;">
                            访问设置
                        </a>
                        <a href="#" class="portal-copy-btn" data-customer-id="${customerId}" data-customer-name="${groupName.replace(/"/g, '&quot;')}"
                           style="color: #137fec; text-decoration: none; font-weight: normal; font-size: 13px;">
                            复制链接
                        </a>
                    </span>
                `;
            }
            
            html += `
                <tr class="group-header" onclick="toggleGroup('${safeGroupName}', event)" style="cursor: pointer; user-select: none;">
                    <td colspan="7" style="background: #f1f5f9; font-weight: 600; padding: 10px 16px;">
                        <span class="toggle-icon" style="margin-right: 8px; font-size: 12px; display: inline-block; width: 16px;">${icon}</span>
                        ${groupLabel}: ${groupName}
                        <span style="color: #64748b; font-weight: normal; margin-left: 8px;">(${projects.length})</span>
                        ${portalActions}
                    </td>
                </tr>
            `;
            
            if (!isCollapsed) {
                projects.forEach(project => {
                    html += renderTableRow(project);
                });
            }
        });
    } else {
        // 不分组渲染
        filteredProjects.forEach(project => {
            html += renderTableRow(project);
        });
    }
    
    tbody.innerHTML = html;
}

// 渲染表格行
function renderTableRow(project) {
    const statusStyle = statusColors[project.current_status] || { bg: '#f1f5f9', color: '#64748b' };
    const updateTime = formatRelativeTime(project.update_time);
    const techNames = project.tech_users ? project.tech_users.map(u => u.realname || u.username).join(', ') : '-';
    const deleteBtn = IS_ADMIN ? `<button class="action-btn" style="color: #dc2626; border-color: #fecaca;" onclick="confirmDeleteProject(${project.id}, '${escapeHtml(project.project_name)}', '${project.project_code}')">删除</button>` : '';
    
    return `
        <tr>
            <td><code>${project.project_code}</code></td>
            <td><a href="project_detail.php?id=${project.id}" style="color: #334155; text-decoration: none;">${project.project_name}</a></td>
            <td>${project.customer_name || '-'}</td>
            <td><span class="status-badge" style="background: ${statusStyle.bg}; color: ${statusStyle.color};">${project.current_status}</span></td>
            <td>${techNames}</td>
            <td>${updateTime}</td>
            <td>
                <button class="action-btn" onclick="viewProjectDetail(${project.id})">查看</button>
                <button class="action-btn" onclick="changeStatus(${project.id}, '${project.current_status}')">变更状态</button>
                ${deleteBtn}
            </td>
        </tr>
    `;
}

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', function() {
    // 加载技术人员列表（默认）
    loadUsersByType();
    
    // 根据角色设置默认分组
    setDefaultGroupByRole();
    
    // 加载项目
    loadProjects();
    
    // 恢复上次的视图模式
    if (currentView === 'table') {
        switchView('table');
    }
});

// 根据角色设置默认分组
function setDefaultGroupByRole() {
    const groupBySelect = document.getElementById('groupBy');
    const savedGroup = localStorage.getItem('kanban_group_by');
    
    if (savedGroup) {
        groupBySelect.value = savedGroup;
        return;
    }
    
    // 根据角色设置默认分组
    switch (CURRENT_USER_ROLE) {
        case 'tech':
            groupBySelect.value = 'requirement_status';
            break;
        case 'dept_leader':
        case 'dept_admin':
            groupBySelect.value = 'tech_user';
            break;
        case 'sales':
            groupBySelect.value = 'current_status';
            break;
        default:
            groupBySelect.value = '';
    }
}

// 保存分组设置
document.getElementById('groupBy').addEventListener('change', function() {
    localStorage.setItem('kanban_group_by', this.value);
});

// 门户按钮事件委托
document.addEventListener('click', function(e) {
    // 访问设置按钮
    if (e.target.classList.contains('portal-settings-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const customerId = e.target.dataset.customerId;
        const customerName = e.target.dataset.customerName;
        console.log('[PORTAL_DEBUG] Settings clicked:', customerId, customerName);
        openPortalSettings(parseInt(customerId), customerName);
    }
    // 复制链接按钮
    if (e.target.classList.contains('portal-copy-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const customerId = e.target.dataset.customerId;
        const customerName = e.target.dataset.customerName;
        console.log('[PORTAL_DEBUG] Copy clicked:', customerId, customerName);
        copyPortalLink(parseInt(customerId), customerName);
    }
});

// ============ 门户访问设置功能 ============

// 门户信息缓存
let portalInfoCache = {};

// 打开门户设置弹窗
function openPortalSettings(customerId, customerName) {
    // 先获取门户信息
    fetch(`${API_URL}/portal_password.php?customer_id=${customerId}`)
        .then(r => r.json())
        .then(data => {
            const portalInfo = data.data || {};
            portalInfoCache[customerId] = portalInfo;
            
            const currentPassword = portalInfo.current_password || '';
            const isEnabled = portalInfo.enabled ? true : false;
            const expiresAt = portalInfo.expires_at ? new Date(portalInfo.expires_at * 1000).toISOString().split('T')[0] : '';
            
            const modalHtml = `
                <div class="modal fade" id="portalSettingsModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-primary text-white border-0 py-2">
                                <h6 class="modal-title mb-0">🔐 门户访问设置 - ${customerName}</h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body py-3">
                                <div class="mb-3">
                                    <label class="form-label">当前密码</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="portalCurrentPassword" value="${currentPassword}" placeholder="未设置密码">
                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('portalPassword').value = document.getElementById('portalCurrentPassword').value">
                                            复用
                                        </button>
                                    </div>
                                    <div class="form-text">${currentPassword ? '当前已设置密码，可直接修改' : '当前未设置密码'}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">设置新密码</label>
                                    <input type="text" class="form-control" id="portalPassword" placeholder="输入新密码，留空则清除密码">
                                    <div class="form-text">设置密码后，访问门户需要输入密码验证</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">有效期至</label>
                                    <input type="date" class="form-control" id="portalExpiresAt" value="${expiresAt}">
                                    <div class="form-text">留空表示永不过期</div>
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="portalEnabled" ${isEnabled ? 'checked' : ''}>
                                    <label class="form-check-label" for="portalEnabled">启用门户访问</label>
                                </div>
                            </div>
                            <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="savePortalSettings(${customerId})">保存设置</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // 移除旧弹窗
            const oldModal = document.getElementById('portalSettingsModal');
            if (oldModal) oldModal.remove();
            
            // 添加新弹窗
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modalElement = document.getElementById('portalSettingsModal');
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        })
        .catch(err => {
            console.error('[PORTAL_DEBUG] 获取门户信息失败:', err);
            showAlertModal('获取门户信息失败: ' + err.message, 'error');
        });
}

// 保存门户设置
function savePortalSettings(customerId) {
    const password = document.getElementById('portalPassword').value;
    const expiresAt = document.getElementById('portalExpiresAt').value;
    const enabled = document.getElementById('portalEnabled').checked ? 1 : 0;
    
    fetch(`${API_URL}/portal_password.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            customer_id: customerId,
            password: password,
            expires_at: expiresAt,
            enabled: enabled
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 关闭弹窗
            const modalElement = document.getElementById('portalSettingsModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
            
            // 更新缓存
            if (data.data && data.data.token) {
                portalInfoCache[customerId] = { ...portalInfoCache[customerId], token: data.data.token };
            }
            
            showAlertModal(data.message || '设置已保存', 'success');
        } else {
            showAlertModal('保存失败: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('[PORTAL_DEBUG] 保存门户设置失败:', err);
        showAlertModal('保存失败: ' + err.message, 'error');
    });
}

// 复制门户链接
function copyPortalLink(customerId, customerName) {
    console.log('[PORTAL_DEBUG] copyPortalLink called:', customerId, customerName);
    
    // 先获取门户信息（如果没有缓存）
    const cached = portalInfoCache[customerId];
    
    if (cached && cached.token) {
        console.log('[PORTAL_DEBUG] Using cached token');
        doCopyPortalLink(cached.token, customerName, cached.enabled);
        return;
    }
    
    fetch(`${API_URL}/portal_password.php?customer_id=${customerId}`)
        .then(r => r.json())
        .then(data => {
            console.log('[PORTAL_DEBUG] API response:', data);
            
            if (data.success && data.data && data.data.token) {
                portalInfoCache[customerId] = data.data;
                doCopyPortalLink(data.data.token, customerName, data.data.enabled);
            } else {
                // 没有门户链接，提示用户先设置
                console.log('[PORTAL_DEBUG] No portal link, prompting to create');
                showConfirmModal(
                    '创建门户链接',
                    `客户 "${customerName}" 还没有门户链接，是否立即创建？`,
                    function() {
                        // 创建门户链接
                        fetch(`${API_URL}/portal_password.php`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                customer_id: customerId,
                                password: '',
                                enabled: 1
                            })
                        })
                        .then(r => r.json())
                        .then(result => {
                            console.log('[PORTAL_DEBUG] Create result:', result);
                            if (result.success) {
                                // 新创建的门户，需要重新获取完整信息（包含token）
                                fetch(`${API_URL}/portal_password.php?customer_id=${customerId}`)
                                    .then(r => r.json())
                                    .then(freshData => {
                                        console.log('[PORTAL_DEBUG] Fresh data after create:', freshData);
                                        if (freshData.success && freshData.data && freshData.data.token) {
                                            portalInfoCache[customerId] = freshData.data;
                                            doCopyPortalLink(freshData.data.token, customerName, freshData.data.enabled);
                                        } else {
                                            showAlertModal('获取门户链接失败', 'error');
                                        }
                                    });
                            } else {
                                showAlertModal('创建门户链接失败: ' + (result.message || ''), 'error');
                            }
                        });
                    }
                );
            }
        })
        .catch(err => {
            console.error('[PORTAL_DEBUG] 获取门户信息失败:', err);
            showAlertModal('获取门户信息失败: ' + err.message, 'error');
        });
}

// 执行复制门户链接
function doCopyPortalLink(token, customerName, enabled) {
    console.log('[PORTAL_DEBUG] doCopyPortalLink:', token, customerName, enabled);
    
    // enabled 可能是 0/1 数字，转换为布尔值检查
    if (enabled === 0 || enabled === '0' || enabled === false) {
        showAlertModal('门户访问已禁用，请先在"访问设置"中启用', 'warning');
        return;
    }
    
    const portalUrl = `${window.location.origin}/portal.php?token=${token}`;
    console.log('[PORTAL_DEBUG] Portal URL:', portalUrl);
    
    // 使用兼容性更好的复制方法
    copyToClipboard(portalUrl, customerName);
}

// 兼容性复制到剪贴板
function copyToClipboard(text, label) {
    if (!text || text === '-') {
        showAlertModal('无内容可复制', 'info');
        return;
    }
    
    // 优先使用 execCommand（兼容性更好，支持 HTTP）
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2em';
    textArea.style.height = '2em';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    let success = false;
    try {
        success = document.execCommand('copy');
    } catch (err) {
        console.error('[COPY_DEBUG] execCommand copy failed:', err);
    }
    
    document.body.removeChild(textArea);
    
    const msg = label ? `已复制 "${label}"` : `已复制: ${text.length > 20 ? text.substring(0, 20) + '...' : text}`;
    
    if (success) {
        showAlertModal(msg, 'success');
    } else {
        // 如果 execCommand 也失败，尝试 clipboard API
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                showAlertModal(msg, 'success');
            }).catch(() => {
                prompt('请手动复制:', text);
            });
        } else {
            prompt('请手动复制:', text);
        }
    }
}
</script>

<!-- 侧边栏组件 -->
<link rel="stylesheet" href="css/sidebar-panel.css?v=1.3">
<script src="js/sidebar-panel.js?v=1.0"></script>
<script src="js/filter-fields.js"></script>
<script>
let projectSidebar = null;

document.addEventListener('DOMContentLoaded', function() {
    projectSidebar = initSidebarPanel({
        title: '项目详情',
        icon: 'bi-folder',
        openPageText: '打开项目详情页'
    });
    
    // 初始化客户分类筛选字段
    if (typeof CustomerFilterFields !== 'undefined') {
        CustomerFilterFields.render('customerFilterFieldsContainer', {
            selectedValues: {},
            showLabel: true,
            size: 'sm',
            onChange: function(fieldId, optionId, field, option) {
                applyFilters();
            }
        });
    }
});

function openProjectSidebar(projectId) {
    currentSidebarProjectId = projectId; // 保存当前项目ID
    projectSidebar.open({
        title: '项目详情',
        pageUrl: 'project_detail.php?id=' + projectId,
        loadContent: function(panel) {
            loadProjectDetail(projectId, panel);
        }
    });
}

async function loadProjectDetail(projectId, panel) {
    try {
        // 加载项目基本信息
        const res = await fetch(API_URL + '/projects.php?id=' + projectId);
        const data = await res.json();
        
        if (!data.success) {
            panel.showError(data.message || '加载失败');
            return;
        }
        
        const project = data.data;
        let html = '';
        
        // 更新标题
        document.getElementById('sidebarTitleText').textContent = project.project_name || '项目详情';
        
        // 基本信息
        const groupName = project.customer_group_name || project.customer_group || '-';
        const groupCode = project.customer_group_code || project.group_code || '-';
        html += createSidebarSection('基本信息', createSidebarInfoGrid([
            { label: '项目编号', value: `<span class="copyable" onclick="copyToClipboard('${project.project_code || ''}')" title="点击复制">${project.project_code || '-'}</span>` },
            { label: '当前状态', value: `<span class="sidebar-badge sidebar-badge-info">${project.current_status || '-'}</span>` },
            { label: '客户名称', value: `<span class="copyable" onclick="copyToClipboard('${(project.customer_name || '').replace(/'/g, "\\'")}')" title="点击复制">${project.customer_name || '-'}</span>` },
            { label: '群名称', value: `<span class="copyable" onclick="copyToClipboard('${groupName.replace(/'/g, "\\'")}')" title="点击复制">${groupName}</span>` },
            { label: '群码', value: `<span class="copyable" onclick="copyToClipboard('${groupCode}')" title="点击复制">${groupCode}</span>` }
        ]));
        
        // 加载项目负责人
        const canSetCommission = <?= json_encode(isAdmin($user) || $user['role'] === 'dept_leader') ?>;
        try {
            const assigneesRes = await fetch(API_URL + '/projects.php?action=assignees&project_id=' + projectId);
            const assigneesData = await assigneesRes.json();
            
            if (assigneesData.success && assigneesData.data.length > 0) {
                let assigneesHtml = '<div class="sidebar-assignees-list">';
                assigneesData.data.forEach(a => {
                    const name = a.realname || a.username || '?';
                    const initial = name.charAt(0);
                    const commission = a.commission_amount ? `<span class="text-success">¥${parseFloat(a.commission_amount).toFixed(0)}</span>` : '<span class="text-muted">未设置</span>';
                    const clickable = canSetCommission ? `onclick="openSidebarCommissionModal(${a.assignment_id}, '${name.replace(/'/g, "\\'")}', ${a.commission_amount || 0})" style="cursor: pointer;" title="点击设置提成"` : '';
                    assigneesHtml += `
                        <div class="sidebar-assignee-item ${canSetCommission ? 'clickable' : ''}" ${clickable}>
                            <div class="sidebar-assignee-avatar">${initial}</div>
                            <div class="sidebar-assignee-info">
                                <div class="sidebar-assignee-name">${name}</div>
                                <div class="sidebar-assignee-dept">${a.department_name || ''}</div>
                            </div>
                            <div class="sidebar-assignee-commission">${commission}</div>
                            ${canSetCommission ? '<i class="bi bi-pencil sidebar-assignee-edit"></i>' : ''}
                        </div>
                    `;
                });
                assigneesHtml += '</div>';
                if (canSetCommission) {
                    assigneesHtml += '<div class="sidebar-stage-hint"><i class="bi bi-info-circle"></i> 点击负责人可设置提成</div>';
                }
                html += createSidebarSection('项目负责人', assigneesHtml);
            } else {
                html += createSidebarSection('项目负责人', '<div class="text-muted small">暂未分配技术人员</div>');
            }
        } catch (e) {
            console.error('[SIDEBAR_DEBUG] 加载负责人失败:', e);
        }
        
        // 加载阶段进度
        try {
            const stageRes = await fetch(API_URL + '/project_stage_times.php?project_id=' + projectId);
            const stageData = await stageRes.json();
            
            if (stageData.success && stageData.data) {
                const summary = stageData.data.summary;
                const stages = stageData.data.stages;
                
                if (summary && summary.total_days) {
                    const pct = summary.overall_progress || 0;
                    const remaining = Math.max(0, summary.total_days - summary.elapsed_days);
                    
                    html += createSidebarSection('项目进度', `
                        <div class="sidebar-info-grid">
                            <div class="sidebar-info-item">
                                <div class="sidebar-info-label">总天数</div>
                                <div class="sidebar-info-value">${summary.total_days} 天</div>
                            </div>
                            <div class="sidebar-info-item">
                                <div class="sidebar-info-label">已进行</div>
                                <div class="sidebar-info-value">${summary.elapsed_days} 天</div>
                            </div>
                        </div>
                        <div style="margin-top: 12px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="font-size: 12px; color: #64748b;">时间进度</span>
                                <span style="font-size: 12px; color: ${remaining <= 3 ? '#ef4444' : '#64748b'};">剩余 ${remaining} 天</span>
                            </div>
                            <div style="background: #e2e8f0; border-radius: 4px; height: 8px; overflow: hidden;">
                                <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); height: 100%; width: ${pct}%;"></div>
                            </div>
                        </div>
                    `);
                }
                
                // 阶段列表（可点击调整）
                if (stages && stages.length > 0) {
                    let stageHtml = '<div class="sidebar-stage-list">';
                    stages.forEach(s => {
                        let statusClass = 'pending';
                        let statusText = '待开始';
                        let statusIcon = '○';
                        if (s.status === 'completed') {
                            statusClass = 'completed';
                            statusText = '已完成';
                            statusIcon = '✓';
                        } else if (s.status === 'in_progress') {
                            statusClass = 'in-progress';
                            statusText = '进行中';
                            statusIcon = '●';
                        }
                        stageHtml += `
                            <div class="sidebar-stage-item ${statusClass} clickable" onclick="openStageAdjust(${projectId}, ${s.id}, '${s.stage_from} → ${s.stage_to}', ${s.planned_days})">
                                <div class="sidebar-stage-icon">${statusIcon}</div>
                                <div class="sidebar-stage-content">
                                    <div class="sidebar-stage-name">${s.stage_from} → ${s.stage_to}</div>
                                    <div class="sidebar-stage-info">${s.planned_days}天 · ${statusText}</div>
                                </div>
                                <div class="sidebar-stage-edit"><i class="bi bi-pencil"></i></div>
                            </div>
                        `;
                    });
                    stageHtml += '</div>';
                    stageHtml += '<div class="sidebar-stage-hint"><i class="bi bi-info-circle"></i> 点击阶段可调整天数</div>';
                    html += createSidebarSection('阶段时间', stageHtml);
                }
            }
        } catch (e) {
            console.error('[SIDEBAR_DEBUG] 加载阶段失败:', e);
        }
        
        // 快速操作按钮
        html += `
            <div class="sidebar-section">
                <div class="sidebar-section-title">快速操作</div>
                <div class="sidebar-actions">
                    <button class="sidebar-action-btn" onclick="goToProjectDetail(${projectId})">
                        <i class="bi bi-pencil"></i> 编辑项目
                    </button>
                    <button class="sidebar-action-btn" onclick="changeStatus(${projectId}, '${project.current_status}'); projectSidebar.close();">
                        <i class="bi bi-arrow-repeat"></i> 变更状态
                    </button>
                </div>
            </div>
        `;
        
        panel.setContent(html);
        
    } catch (e) {
        console.error('[SIDEBAR_DEBUG] 加载项目详情失败:', e);
        panel.showError('加载失败: ' + e.message);
    }
}

// 侧边栏设置提成弹窗
let currentSidebarProjectId = null;
function openSidebarCommissionModal(assignmentId, userName, currentAmount) {
    const modalHtml = `
        <div class="modal fade" id="sidebarCommissionModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 360px;">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                    <div class="modal-header border-0" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 20px 24px;">
                        <div>
                            <h6 class="modal-title mb-1" style="color: #fff; font-weight: 600;">设置提成</h6>
                            <small style="color: rgba(255,255,255,0.8);">${userName}</small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity: 0.8;"></button>
                    </div>
                    <div class="modal-body" style="padding: 24px;">
                        <div class="mb-3">
                            <label class="form-label" style="font-weight: 500;">提成金额 (元)</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background: #f8fafc;">¥</span>
                                <input type="number" class="form-control form-control-lg" id="sidebarCommissionAmount" 
                                       value="${currentAmount || ''}" placeholder="0.00" step="0.01" min="0"
                                       style="font-size: 20px; font-weight: 600; text-align: center;">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-weight: 500;">备注（可选）</label>
                            <input type="text" class="form-control" id="sidebarCommissionNote" placeholder="如：项目完成后发放">
                        </div>
                        <button type="button" class="btn w-100" onclick="submitSidebarCommission(${assignmentId})" 
                                style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; border: none; 
                                       padding: 12px; border-radius: 10px; font-weight: 600; font-size: 15px;">
                            <i class="bi bi-check-lg"></i> 确认设置
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const old = document.getElementById('sidebarCommissionModal');
    if (old) old.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('sidebarCommissionModal'));
    modal.show();
    
    setTimeout(() => document.getElementById('sidebarCommissionAmount').focus(), 300);
}

function submitSidebarCommission(assignmentId) {
    const amount = parseFloat(document.getElementById('sidebarCommissionAmount').value) || 0;
    const note = document.getElementById('sidebarCommissionNote').value;
    
    fetch(`${API_URL}/tech_commission.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'set_commission',
            assignment_id: assignmentId,
            commission_amount: amount,
            commission_note: note
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('sidebarCommissionModal')).hide();
            showAlertModal('提成设置成功', 'success');
            // 刷新侧边栏和看板
            if (currentSidebarProjectId) {
                openProjectSidebar(currentSidebarProjectId);
            }
            loadBatchAssignees();
        } else {
            showAlertModal(data.message || '设置失败', 'error');
        }
    });
}

// 打开阶段调整弹窗 - 美化版
function openStageAdjust(projectId, stageId, stageName, currentDays) {
    // 创建弹窗
    const modalHtml = `
        <div class="modal fade" id="stageAdjustModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 340px;">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                    <div class="modal-header border-0" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 20px 24px;">
                        <div>
                            <h6 class="modal-title mb-1" style="color: #fff; font-weight: 600;">调整阶段时间</h6>
                            <small style="color: rgba(255,255,255,0.8);">${stageName}</small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity: 0.8;"></button>
                    </div>
                    <div class="modal-body" style="padding: 24px;">
                        <div style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 20px;">
                            <button type="button" class="btn-adjust-minus" onclick="adjustDaysInput(-1)" style="width: 44px; height: 44px; border: 2px solid #e2e8f0; background: #fff; border-radius: 12px; font-size: 20px; cursor: pointer; transition: all 0.2s;">−</button>
                            <input type="number" id="stageAdjustDays" value="${currentDays}" min="1" 
                                   style="width: 80px; height: 56px; text-align: center; font-size: 24px; font-weight: 600; 
                                          border: 2px solid #e2e8f0; border-radius: 12px; color: #1e293b;">
                            <button type="button" class="btn-adjust-plus" onclick="adjustDaysInput(1)" style="width: 44px; height: 44px; border: 2px solid #e2e8f0; background: #fff; border-radius: 12px; font-size: 20px; cursor: pointer; transition: all 0.2s;">+</button>
                        </div>
                        <div style="text-align: center; color: #64748b; font-size: 14px; margin-bottom: 20px;">
                            当前: <strong>${currentDays}</strong> 天
                        </div>
                        <button type="button" class="btn w-100" onclick="submitStageAdjust(${projectId}, ${stageId}, ${currentDays})" 
                                style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff; border: none; 
                                       padding: 12px; border-radius: 10px; font-weight: 600; font-size: 15px;">
                            确认调整
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const oldModal = document.getElementById('stageAdjustModal');
    if (oldModal) oldModal.remove();
    
    // 添加新弹窗
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalElement = document.getElementById('stageAdjustModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    
    // 聚焦输入框
    setTimeout(() => document.getElementById('stageAdjustDays').select(), 300);
}

// 调整天数输入
function adjustDaysInput(delta) {
    const input = document.getElementById('stageAdjustDays');
    const newValue = Math.max(1, parseInt(input.value || 1) + delta);
    input.value = newValue;
}

// 提交阶段调整
function submitStageAdjust(projectId, stageId, currentDays) {
    const input = document.getElementById('stageAdjustDays');
    const days = parseInt(input.value);
    
    if (isNaN(days) || days < 1) {
        showAlertModal('请输入有效的天数（大于0的整数）', 'error');
        return;
    }
    
    if (days === currentDays) {
        showAlertModal('天数未变化', 'info');
        return;
    }
    
    // 关闭弹窗
    const modalElement = document.getElementById('stageAdjustModal');
    const modal = bootstrap.Modal.getInstance(modalElement);
    if (modal) modal.hide();
    
    // 调用 API 调整阶段时间
    fetch(API_URL + '/project_stage_times.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'adjust',
            stage_id: stageId,
            new_days: days
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('阶段时间已调整', 'success');
            // 重新加载侧边栏内容
            openProjectSidebar(projectId);
        } else {
            showAlertModal('调整失败: ' + (data.message || '未知错误'), 'error');
        }
    })
    .catch(err => {
        console.error('[SIDEBAR_DEBUG] 调整阶段失败:', err);
        showAlertModal('调整失败: ' + err.message, 'error');
    });
}

// 删除项目确认
function confirmDeleteProject(projectId, projectName, projectCode) {
    if (!IS_ADMIN) {
        showAlertModal('您没有删除项目的权限', 'error');
        return;
    }
    
    showConfirmModal(
        '确认删除项目',
        `<div class="text-start">
            <p>确定要删除项目 <strong>${escapeHtml(projectName)}</strong> 吗？</p>
            <p class="text-muted small mb-2">项目编号：${escapeHtml(projectCode)}</p>
            <div class="alert alert-warning py-2 mb-0">
                <i class="bi bi-exclamation-triangle"></i> 删除后项目及相关交付物将移至回收站，15天后自动永久删除。
            </div>
        </div>`,
        function() {
            deleteProject(projectId);
        }
    );
}

// 执行删除项目
function deleteProject(projectId) {
    fetch(API_URL + '/projects.php?id=' + projectId, {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('项目已删除', 'success');
            // 重新加载项目列表
            loadProjects();
        } else {
            showAlertModal('删除失败: ' + data.message, 'error');
        }
    })
    .catch(err => {
        showAlertModal('删除失败: ' + err.message, 'error');
    });
}

// HTML转义函数
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>

<?php layout_footer(); ?>
