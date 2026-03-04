<?php
/**
 * 设计问卷看板 - 内部管理页面
 * 
 * 功能：
 * - 问卷列表（卡片/表格视图）
 * - 筛选：房屋类型、预算类型、服务项目、负责人
 * - 分组：按负责人/房屋类型/预算类型
 * - 权限：管理员看全部，普通用户看自己分配的
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'superadmin']);

// 获取用户列表（管理员用于筛选和分配）
$allUsers = [];
if ($isAdmin) {
    $allUsers = Db::query("SELECT id, username, realname, role FROM users WHERE status = 1 ORDER BY realname");
}

$pageTitle = '设计问卷看板';
Layout::header($pageTitle);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root {
        --primary: #6366f1;
        --primary-light: #818cf8;
        --primary-bg: #eef2ff;
    }

    .board-header {
        background: white;
        border-bottom: 1px solid #e5e7eb;
        padding: 16px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .board-title {
        font-size: 20px;
        font-weight: 700;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .board-title i { color: var(--primary); }

    .filter-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-bar select, .filter-bar input {
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 13px;
        background: white;
    }

    .filter-bar select:focus, .filter-bar input:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    .stats-bar {
        display: flex;
        gap: 12px;
        padding: 16px 24px;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
        overflow-x: auto;
    }

    .stat-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 12px 20px;
        min-width: 120px;
        text-align: center;
        flex-shrink: 0;
    }

    .stat-card .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
    }

    .stat-card .stat-label {
        font-size: 12px;
        color: #6b7280;
        margin-top: 2px;
    }

    .board-content {
        padding: 20px 24px;
    }

    .view-toggle {
        display: flex;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
    }

    .view-toggle button {
        border: none;
        background: white;
        padding: 6px 14px;
        font-size: 13px;
        cursor: pointer;
        color: #6b7280;
        transition: all 0.2s;
    }

    .view-toggle button.active {
        background: var(--primary);
        color: white;
    }

    .view-toggle button:hover:not(.active) {
        background: #f3f4f6;
    }

    /* 卡片视图 */
    .card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 16px;
    }

    .q-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 20px;
        transition: all 0.2s;
        cursor: pointer;
        position: relative;
    }

    .q-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border-color: var(--primary-light);
    }

    .q-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .q-card-name {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
    }

    .q-card-group {
        font-size: 12px;
        color: #6b7280;
        margin-top: 2px;
    }

    .q-card-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 12px;
    }

    .q-tag {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 20px;
        font-weight: 500;
    }

    .q-tag.house { background: #dbeafe; color: #1d4ed8; }
    .q-tag.budget { background: #fef3c7; color: #92400e; }
    .q-tag.service { background: #d1fae5; color: #065f46; }
    .q-tag.style { background: #ede9fe; color: #5b21b6; }

    .q-card-info {
        font-size: 13px;
        color: #6b7280;
        line-height: 1.8;
    }

    .q-card-info span {
        display: inline-block;
        margin-right: 16px;
    }

    .q-card-footer {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #f3f4f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        color: #9ca3af;
    }

    .q-card-owner {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .q-card-owner .avatar {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
    }

    /* 表格视图 */
    .table-view {
        overflow-x: auto;
    }

    .table-view table {
        width: 100%;
        border-collapse: collapse;
    }

    .table-view th {
        background: #f9fafb;
        padding: 10px 14px;
        font-size: 12px;
        font-weight: 600;
        color: #6b7280;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }

    .table-view td {
        padding: 12px 14px;
        font-size: 13px;
        color: #374151;
        border-bottom: 1px solid #f3f4f6;
    }

    .table-view tr:hover {
        background: #f9fafb;
    }

    .table-view .link-cell {
        color: var(--primary);
        cursor: pointer;
        font-weight: 500;
    }

    .table-view .link-cell:hover {
        text-decoration: underline;
    }

    /* 分组标题 */
    .group-header {
        font-size: 14px;
        font-weight: 600;
        color: #374151;
        padding: 12px 0;
        margin-top: 20px;
        border-bottom: 2px solid var(--primary);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .group-header:first-child {
        margin-top: 0;
    }

    .group-count {
        background: var(--primary-bg);
        color: var(--primary);
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 20px;
    }

    .pagination-bar {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin-top: 24px;
        padding: 16px 0;
    }

    .pagination-bar button {
        border: 1px solid #e5e7eb;
        background: white;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
    }

    .pagination-bar button:hover:not(:disabled) {
        border-color: var(--primary);
        color: var(--primary);
    }

    .pagination-bar button:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .pagination-bar .page-info {
        font-size: 13px;
        color: #6b7280;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #9ca3af;
    }

    .empty-state i {
        font-size: 48px;
        display: block;
        margin-bottom: 12px;
    }

    .action-btn {
        border: none;
        background: none;
        padding: 4px 8px;
        cursor: pointer;
        color: #6b7280;
        border-radius: 4px;
        font-size: 14px;
    }

    .action-btn:hover { background: #f3f4f6; color: var(--primary); }
</style>

<div class="board-header">
    <div class="board-title">
        <i class="bi bi-palette2"></i> <?= $pageTitle ?>
    </div>
    <div class="filter-bar">
        <input type="text" id="searchInput" placeholder="搜索客户名/群名称..." style="width:180px;">
        <?php if ($isAdmin): ?>
        <select id="filterOwner">
            <option value="0">全部负责人</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['realname'] ?: $u['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select id="filterHouseStatus">
            <option value="">全部房屋类型</option>
            <option value="rough">毛坯房</option>
            <option value="decorated">精装房</option>
            <option value="renovation">旧屋翻新</option>
            <option value="commercial">商业空间</option>
        </select>
        <select id="filterBudget">
            <option value="">全部预算</option>
            <option value="economy">经济型</option>
            <option value="standard">标准型</option>
            <option value="premium">高端订制</option>
            <option value="custom">自定义</option>
        </select>
        <select id="filterService">
            <option value="">全部服务</option>
            <option value="floor_plan">平面图</option>
            <option value="rendering">效果图</option>
            <option value="construction">施工图</option>
            <option value="exterior">外立面</option>
        </select>
        <select id="groupBy">
            <option value="">不分组</option>
            <option value="owner">按负责人</option>
            <option value="house_status">按房屋类型</option>
            <option value="budget_type">按预算类型</option>
        </select>
        <div class="view-toggle">
            <button class="active" id="btnCardView" onclick="setView('card')"><i class="bi bi-grid-3x3-gap"></i></button>
            <button id="btnTableView" onclick="setView('table')"><i class="bi bi-list-ul"></i></button>
        </div>
    </div>
</div>

<div class="stats-bar" id="statsBar">
    <div class="stat-card">
        <div class="stat-value" id="statTotal">-</div>
        <div class="stat-label">总问卷</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" id="statFloorPlan">-</div>
        <div class="stat-label">平面图</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" id="statRendering">-</div>
        <div class="stat-label">效果图</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" id="statConstruction">-</div>
        <div class="stat-label">施工图</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" id="statExterior">-</div>
        <div class="stat-label">外立面</div>
    </div>
</div>

<div class="board-content">
    <div id="boardContainer"></div>
    <div class="pagination-bar" id="paginationBar" style="display:none;">
        <button onclick="changePage(-1)" id="btnPrev" disabled>&laquo; 上一页</button>
        <span class="page-info" id="pageInfo"></span>
        <button onclick="changePage(1)" id="btnNext"">下一页 &raquo;</button>
    </div>
</div>

<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const LABEL_MAPS = {
    house_status: {rough:'毛坯房', decorated:'精装房', renovation:'旧屋翻新', commercial:'商业空间'},
    budget_type: {economy:'经济型', standard:'标准型', premium:'高端订制', custom:'自定义'},
    style_maturity: {has_reference:'有参考图', rough_idea:'有大致意向', no_idea:'设计师建议'},
    service_items: {floor_plan:'平面图', rendering:'效果图', construction:'施工图', exterior:'外立面'},
};

let currentView = 'card';
let currentPage = 1;
let totalPages = 1;
let currentData = [];
let debounceTimer = null;

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    loadStats();

    // 筛选监听
    ['filterHouseStatus', 'filterBudget', 'filterService', 'filterOwner', 'groupBy'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => { currentPage = 1; loadData(); });
    });

    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => { currentPage = 1; loadData(); }, 300);
    });
});

function setView(view) {
    currentView = view;
    document.getElementById('btnCardView').classList.toggle('active', view === 'card');
    document.getElementById('btnTableView').classList.toggle('active', view === 'table');
    renderData();
}

function getFilters() {
    const params = new URLSearchParams();
    params.set('page', currentPage);
    params.set('page_size', 20);

    const search = document.getElementById('searchInput').value.trim();
    if (search) params.set('search', search);

    const ownerEl = document.getElementById('filterOwner');
    if (ownerEl && ownerEl.value !== '0') params.set('assigned_to', ownerEl.value);

    const house = document.getElementById('filterHouseStatus').value;
    if (house) params.set('house_status', house);

    const budget = document.getElementById('filterBudget').value;
    if (budget) params.set('budget_type', budget);

    const service = document.getElementById('filterService').value;
    if (service) params.set('service_items', service);

    return params.toString();
}

async function loadData() {
    const container = document.getElementById('boardContainer');
    container.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass-split"></i>加载中...</div>';

    try {
        const resp = await fetch('/api/design_questionnaire_list.php?action=list&' + getFilters());
        const result = await resp.json();

        if (result.success) {
            currentData = result.data.list;
            totalPages = result.data.total_pages;
            currentPage = result.data.page;
            renderData();
            updatePagination(result.data);
        } else {
            container.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle"></i>' + (result.message || '加载失败') + '</div>';
        }
    } catch (e) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle"></i>加载失败</div>';
    }
}

async function loadStats() {
    try {
        const resp = await fetch('/api/design_questionnaire_list.php?action=stats');
        const result = await resp.json();
        if (result.success) {
            document.getElementById('statTotal').textContent = result.data.total;
            document.getElementById('statFloorPlan').textContent = result.data.by_service.floor_plan || 0;
            document.getElementById('statRendering').textContent = result.data.by_service.rendering || 0;
            document.getElementById('statConstruction').textContent = result.data.by_service.construction || 0;
            document.getElementById('statExterior').textContent = result.data.by_service.exterior || 0;
        }
    } catch (e) { console.error(e); }
}

function renderData() {
    const container = document.getElementById('boardContainer');
    if (currentData.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i>暂无问卷数据</div>';
        return;
    }

    const groupBy = document.getElementById('groupBy').value;

    if (groupBy) {
        renderGroupedData(container, groupBy);
    } else if (currentView === 'card') {
        renderCardView(container, currentData);
    } else {
        renderTableView(container, currentData);
    }
}

function renderGroupedData(container, groupKey) {
    const groups = {};
    currentData.forEach(item => {
        let key;
        if (groupKey === 'owner') {
            key = item.owner_name || '未分配';
        } else {
            key = LABEL_MAPS[groupKey]?.[item[groupKey]] || item[groupKey] || '未设置';
        }
        if (!groups[key]) groups[key] = [];
        groups[key].push(item);
    });

    let html = '';
    Object.keys(groups).forEach(key => {
        html += `<div class="group-header">${key} <span class="group-count">${groups[key].length}</span></div>`;
        if (currentView === 'card') {
            html += '<div class="card-grid" style="margin-bottom:16px;">';
            groups[key].forEach(item => { html += buildCard(item); });
            html += '</div>';
        } else {
            html += buildTable(groups[key]);
        }
    });

    container.innerHTML = html;
}

function renderCardView(container, data) {
    let html = '<div class="card-grid">';
    data.forEach(item => { html += buildCard(item); });
    html += '</div>';
    container.innerHTML = html;
}

function renderTableView(container, data) {
    container.innerHTML = buildTable(data);
}

function buildCard(item) {
    const displayName = item.customer_alias || item.client_name || item.customer_name || '未命名';
    const services = (item.service_items || []).map(s => LABEL_MAPS.service_items[s] || s);
    const houseLabel = LABEL_MAPS.house_status[item.house_status] || '';
    const budgetLabel = LABEL_MAPS.budget_type[item.budget_type] || '';
    const styleLabel = LABEL_MAPS.style_maturity[item.style_maturity] || '';
    const ownerInitial = (item.owner_name || '?').charAt(0);

    let tagsHtml = '';
    if (houseLabel) tagsHtml += `<span class="q-tag house">${houseLabel}</span>`;
    if (budgetLabel) tagsHtml += `<span class="q-tag budget">${budgetLabel}</span>`;
    services.forEach(s => { tagsHtml += `<span class="q-tag service">${s}</span>`; });
    if (styleLabel) tagsHtml += `<span class="q-tag style">${styleLabel}</span>`;

    let infoHtml = '';
    if (item.total_area) infoHtml += `<span><i class="bi bi-arrows-angle-expand"></i> ${item.total_area}${item.area_unit === 'ping' ? '坪' : '㎡'}</span>`;
    if (item.household_members) infoHtml += `<span><i class="bi bi-people"></i> ${item.household_members}</span>`;
    if (item.delivery_deadline) infoHtml += `<span><i class="bi bi-calendar"></i> ${item.delivery_deadline}</span>`;

    return `
    <div class="q-card" onclick="openQuestionnaire(${item.customer_id}, '${item.token}')">
        <div class="q-card-header">
            <div>
                <div class="q-card-name">${escapeHtml(displayName)}</div>
                ${item.customer_group ? '<div class="q-card-group"><i class="bi bi-people-fill"></i> ' + escapeHtml(item.customer_group) + '</div>' : ''}
            </div>
            <button class="action-btn" onclick="event.stopPropagation(); copyLink('${item.token}')" title="复制外部链接">
                <i class="bi bi-link-45deg"></i>
            </button>
        </div>
        ${tagsHtml ? '<div class="q-card-tags">' + tagsHtml + '</div>' : ''}
        ${infoHtml ? '<div class="q-card-info">' + infoHtml + '</div>' : ''}
        ${item.style_description ? '<div class="q-card-info" style="margin-top:6px;"><i class="bi bi-brush"></i> ' + escapeHtml(item.style_description) + '</div>' : ''}
        <div class="q-card-footer">
            <div class="q-card-owner">
                <div class="avatar">${ownerInitial}</div>
                <span>${escapeHtml(item.owner_name || '未分配')}</span>
            </div>
            <span>${item.update_time || item.create_time || ''}</span>
        </div>
    </div>`;
}

function buildTable(data) {
    let html = '<div class="table-view"><table>';
    html += '<thead><tr>';
    html += '<th>客户</th><th>群名称</th><th>房屋类型</th><th>面积</th><th>预算</th><th>服务项目</th><th>负责人</th><th>更新时间</th><th>操作</th>';
    html += '</tr></thead><tbody>';

    data.forEach(item => {
        const displayName = item.customer_alias || item.client_name || item.customer_name || '未命名';
        const houseLabel = LABEL_MAPS.house_status[item.house_status] || '-';
        const budgetLabel = LABEL_MAPS.budget_type[item.budget_type] || '-';
        const services = (item.service_items || []).map(s => LABEL_MAPS.service_items[s] || s).join('、') || '-';
        const area = item.total_area ? item.total_area + (item.area_unit === 'ping' ? '坪' : '㎡') : '-';

        html += `<tr>
            <td class="link-cell" onclick="openQuestionnaire(${item.customer_id}, '${item.token}')">${escapeHtml(displayName)}</td>
            <td>${escapeHtml(item.customer_group || '-')}</td>
            <td><span class="q-tag house">${houseLabel}</span></td>
            <td>${area}</td>
            <td><span class="q-tag budget">${budgetLabel}</span></td>
            <td>${services}</td>
            <td>${escapeHtml(item.owner_name || '-')}</td>
            <td>${item.update_time || '-'}</td>
            <td>
                <button class="action-btn" onclick="copyLink('${item.token}')" title="复制链接"><i class="bi bi-link-45deg"></i></button>
                <button class="action-btn" onclick="openQuestionnaire(${item.customer_id}, '${item.token}')" title="打开"><i class="bi bi-box-arrow-up-right"></i></button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    return html;
}

function openQuestionnaire(customerId, token) {
    if (token) {
        window.open('/design_questionnaire.php?token=' + token, '_blank');
    } else {
        window.open('/design_questionnaire.php?customer_id=' + customerId, '_blank');
    }
}

function copyLink(token) {
    if (!token) return;
    const url = window.location.origin + '/design_questionnaire.php?token=' + token;
    navigator.clipboard.writeText(url).then(() => {
        showToast('链接已复制');
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed';
        ta.style.opacity = 0;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('链接已复制');
    });
}

function updatePagination(data) {
    const bar = document.getElementById('paginationBar');
    if (data.total_pages <= 1) {
        bar.style.display = 'none';
        return;
    }
    bar.style.display = 'flex';
    document.getElementById('btnPrev').disabled = data.page <= 1;
    document.getElementById('btnNext').disabled = data.page >= data.total_pages;
    document.getElementById('pageInfo').textContent = `第 ${data.page} / ${data.total_pages} 页 (共 ${data.total} 条)`;
}

function changePage(delta) {
    currentPage += delta;
    if (currentPage < 1) currentPage = 1;
    if (currentPage > totalPages) currentPage = totalPages;
    loadData();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(msg) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:80px;right:20px;background:#10b981;color:white;padding:10px 20px;border-radius:8px;z-index:9999;font-size:14px;transition:opacity 0.3s;';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 2000);
}
</script>

<?php Layout::footer(); ?>
