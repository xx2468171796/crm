<?php
/**
 * 技术主管 - 提成设置页面
 */
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

// 权限检查：部门主管或管理员
if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
    header('Location: /public/dashboard.php');
    exit;
}

$pageTitle = '技术提成管理';
layout_header($pageTitle);
?>

<style>
.stat-card {
    background: linear-gradient(135deg, var(--start) 0%, var(--end) 100%);
    border-radius: 12px;
    padding: 20px;
    color: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.stat-card.blue { --start: #667eea; --end: #764ba2; }
.stat-card.green { --start: #11998e; --end: #38ef7d; }
.stat-card.orange { --start: #f093fb; --end: #f5576c; }
.stat-card.purple { --start: #4facfe; --end: #00f2fe; }
.stat-card .stat-value { font-size: 2rem; font-weight: 700; }
.stat-card .stat-label { font-size: 0.85rem; opacity: 0.9; }

.user-row { cursor: pointer; transition: all 0.2s; }
.user-row:hover { background: #f8f9fa; }
.user-row .expand-arrow { display: inline-block; transition: transform 0.2s; color: #6366f1; font-size: 12px; }
.user-row.expanded .expand-arrow { transform: rotate(90deg); }

.project-details { display: none; background: #f8f9fa; }
.project-details.show { display: table-row; }
.project-detail-row { font-size: 0.9rem; }
.project-detail-row:hover { background: #e9ecef !important; }

.filter-bar { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.filter-bar .form-label { font-weight: 500; font-size: 0.85rem; color: #666; }

.commission-badge { padding: 4px 12px; border-radius: 20px; font-weight: 600; }
.commission-badge.set { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.commission-badge.unset { background: rgba(148, 163, 184, 0.2); color: #64748b; }
</style>

<div class="container-fluid py-4">
    <!-- 页面标题 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-currency-yen me-2 text-primary"></i>技术提成管理</h4>
            <p class="text-muted mb-0 small">管理技术人员项目提成，点击人员行展开查看项目明细</p>
        </div>
        <div>
            <button class="btn btn-outline-success" onclick="exportData()">
                <i class="bi bi-download me-1"></i>导出
            </button>
        </div>
    </div>

    <!-- 筛选区域 -->
    <div class="filter-bar">
        <div class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">时间范围</label>
                <select class="form-select" id="filterPeriod" onchange="handlePeriodChange()">
                    <option value="">全部时间</option>
                    <option value="this_month">本月</option>
                    <option value="last_month">上月</option>
                    <option value="this_quarter">本季度</option>
                    <option value="custom">自定义</option>
                </select>
            </div>
            <div class="col-md-2" id="dateStartGroup" style="display:none;">
                <label class="form-label">开始日期</label>
                <input type="date" class="form-control" id="filterDateStart">
            </div>
            <div class="col-md-2" id="dateEndGroup" style="display:none;">
                <label class="form-label">结束日期</label>
                <input type="date" class="form-control" id="filterDateEnd">
            </div>
            <div class="col-md-2">
                <label class="form-label">部门</label>
                <select class="form-select" id="filterDepartment">
                    <option value="">全部部门</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">技术人员</label>
                <select class="form-select" id="filterTechUser">
                    <option value="">全部人员</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">搜索</label>
                <input type="text" class="form-control" id="filterKeyword" placeholder="项目/客户/人员">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" onclick="loadData()">
                    <i class="bi bi-search me-1"></i>查询
                </button>
            </div>
        </div>
    </div>

    <!-- 汇总卡片 -->
    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="stat-card blue">
                <div class="stat-label">总提成金额</div>
                <div class="stat-value" id="totalCommission">¥0</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card green">
                <div class="stat-label">已设置提成</div>
                <div class="stat-value" id="setCount">0 <small style="font-size:0.5em">个</small></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card orange">
                <div class="stat-label">未设置提成</div>
                <div class="stat-value" id="unsetCount">0 <small style="font-size:0.5em">个</small></div>
            </div>
        </div>
    </div>

    <!-- 分组列表 -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-list-ul me-2"></i>按人员分组</span>
                <span class="text-muted small">点击行展开项目明细</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="reportTable">
                    <thead class="table-light">
                        <tr>
                            <th width="40"></th>
                            <th>技术人员</th>
                            <th>部门</th>
                            <th class="text-center">项目数</th>
                            <th class="text-center">已设置</th>
                            <th class="text-center">未设置</th>
                            <th class="text-end">提成合计</th>
                        </tr>
                    </thead>
                    <tbody id="reportBody">
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <div class="spinner-border spinner-border-sm me-2"></div>加载中...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 设置提成弹窗 -->
<div class="modal fade" id="commissionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">设置提成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editAssignmentId">
                <div class="mb-3">
                    <label class="form-label">技术人员</label>
                    <input type="text" class="form-control" id="editTechName" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">项目名称</label>
                    <input type="text" class="form-control" id="editProjectName" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">提成金额 <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">¥</span>
                        <input type="number" step="0.01" min="0" class="form-control" id="editCommissionAmount" placeholder="请输入金额">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">备注</label>
                    <textarea class="form-control" id="editCommissionNote" rows="2" placeholder="可选，提成说明"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveCommission()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const COMMISSION_API = API_URL + '/tech_commission.php';
const isAdmin = <?= isAdmin($user) ? 'true' : 'false' ?>;
let commissionModal;
let reportData = null;

document.addEventListener('DOMContentLoaded', function() {
    commissionModal = new bootstrap.Modal(document.getElementById('commissionModal'));
    loadDepartments();
    loadTechUsers();
    loadData();
});

function handlePeriodChange() {
    const period = document.getElementById('filterPeriod').value;
    const showCustom = period === 'custom';
    document.getElementById('dateStartGroup').style.display = showCustom ? 'block' : 'none';
    document.getElementById('dateEndGroup').style.display = showCustom ? 'block' : 'none';
}

function loadDepartments() {
    fetch(API_URL + '/departments.php?action=list')
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data) {
                const select = document.getElementById('filterDepartment');
                result.data.forEach(d => {
                    select.innerHTML += `<option value="${d.id}">${escapeHtml(d.name)}</option>`;
                });
            }
        })
        .catch(err => console.error('加载部门失败:', err));
}

function loadTechUsers() {
    fetch(API_URL + '/users.php')
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data) {
                const select = document.getElementById('filterTechUser');
                result.data.forEach(u => {
                    select.innerHTML += `<option value="${u.id}">${escapeHtml(u.realname || u.username)}</option>`;
                });
            }
        })
        .catch(err => console.error('加载技术人员失败:', err));
}

function getDateRange() {
    const period = document.getElementById('filterPeriod').value;
    const now = new Date();
    let start = '', end = '';
    
    if (period === 'this_month') {
        start = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
        end = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
    } else if (period === 'last_month') {
        start = new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().split('T')[0];
        end = new Date(now.getFullYear(), now.getMonth(), 0).toISOString().split('T')[0];
    } else if (period === 'this_quarter') {
        const q = Math.floor(now.getMonth() / 3);
        start = new Date(now.getFullYear(), q * 3, 1).toISOString().split('T')[0];
        end = new Date(now.getFullYear(), (q + 1) * 3, 0).toISOString().split('T')[0];
    } else if (period === 'custom') {
        start = document.getElementById('filterDateStart').value;
        end = document.getElementById('filterDateEnd').value;
    }
    return { start, end };
}

function loadData() {
    const departmentId = document.getElementById('filterDepartment').value;
    const techUserId = document.getElementById('filterTechUser').value;
    const keyword = document.getElementById('filterKeyword').value;
    const { start, end } = getDateRange();
    
    let url = `${COMMISSION_API}?action=report_detailed`;
    if (departmentId) url += `&department_id=${departmentId}`;
    if (techUserId) url += `&tech_user_id=${techUserId}`;
    if (keyword) url += `&keyword=${encodeURIComponent(keyword)}`;
    if (start) url += `&date_start=${start}`;
    if (end) url += `&date_end=${end}`;
    
    document.getElementById('reportBody').innerHTML = '<tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>加载中...</td></tr>';
    
    fetch(url)
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                reportData = result.data;
                renderReport(result.data);
                updateSummaryCards(result.data.summary);
            } else {
                showError(result.message || '加载失败');
            }
        })
        .catch(err => {
            console.error('加载失败:', err);
            showError('网络请求失败');
        });
}

function renderReport(data) {
    const tbody = document.getElementById('reportBody');
    
    if (!data.users || data.users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">暂无数据</td></tr>';
        return;
    }
    
    let html = '';
    data.users.forEach((user, idx) => {
        const total = parseFloat(user.total_commission || 0);
        const totalFormatted = total > 0 ? `¥${total.toFixed(2)}` : '<span class="text-muted">¥0</span>';
        
        // 用户汇总行
        html += `
            <tr class="user-row" data-user-id="${user.user_id}" onclick="toggleUserDetails(${user.user_id})" style="cursor:pointer;">
                <td class="text-center"><span class="expand-arrow">▶</span></td>
                <td><strong>${escapeHtml(user.realname || user.username)}</strong></td>
                <td>${escapeHtml(user.department_name || '-')}</td>
                <td class="text-center">${user.project_count}</td>
                <td class="text-center"><span class="badge bg-success">${user.set_count}</span></td>
                <td class="text-center"><span class="badge bg-secondary">${user.unset_count}</span></td>
                <td class="text-end fw-bold text-success">${totalFormatted}</td>
            </tr>
        `;
        
        // 项目明细行（默认隐藏）
        if (user.projects && user.projects.length > 0) {
            html += `<tr class="project-details" id="details-${user.user_id}"><td colspan="7" class="p-0">`;
            html += `<table class="table table-sm mb-0" style="background:#f8f9fa;">`;
            html += `<thead><tr class="small text-muted">
                <th width="40"></th>
                <th>项目编号</th>
                <th>项目名称</th>
                <th>客户</th>
                <th>状态</th>
                <th class="text-end">提成</th>
                <th>操作</th>
            </tr></thead><tbody>`;
            
            user.projects.forEach(p => {
                const commission = p.commission_amount > 0 
                    ? `<span class="commission-badge set">¥${parseFloat(p.commission_amount).toFixed(2)}</span>`
                    : `<span class="commission-badge unset">未设置</span>`;
                
                html += `
                    <tr class="project-detail-row">
                        <td></td>
                        <td><small class="text-muted">${escapeHtml(p.project_code || '-')}</small></td>
                        <td><a href="/index.php?page=project_detail&id=${p.project_id}" class="text-decoration-none">${escapeHtml(p.project_name)}</a></td>
                        <td>${escapeHtml(p.customer_name || '-')}</td>
                        <td><span class="badge bg-info">${escapeHtml(p.current_status || '-')}</span></td>
                        <td class="text-end">${commission}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="event.stopPropagation(); openEditModal({
                                assignment_id: ${p.assignment_id},
                                tech_username: '${escapeHtml(user.realname || user.username)}',
                                project_name: '${escapeHtml(p.project_name).replace(/'/g, "\\'")}',
                                commission_amount: ${p.commission_amount || 0},
                                commission_note: '${escapeHtml(p.commission_note || '').replace(/'/g, "\\'")}'
                            })">
                                编辑
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></td></tr>`;
        }
    });
    
    tbody.innerHTML = html;
}

function toggleUserDetails(userId) {
    const userRow = document.querySelector(`.user-row[data-user-id="${userId}"]`);
    const detailsRow = document.getElementById(`details-${userId}`);
    
    if (userRow && detailsRow) {
        userRow.classList.toggle('expanded');
        detailsRow.classList.toggle('show');
    }
}

function updateSummaryCards(summary) {
    document.getElementById('totalCommission').textContent = `¥${parseFloat(summary.total_commission || 0).toLocaleString()}`;
    document.getElementById('setCount').innerHTML = `${summary.set_count || 0} <small style="font-size:0.5em">个</small>`;
    document.getElementById('unsetCount').innerHTML = `${summary.unset_count || 0} <small style="font-size:0.5em">个</small>`;
}

function exportData() {
    showToast('导出功能开发中...', 'info');
}

function showError(message) {
    document.getElementById('reportBody').innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">${escapeHtml(message)}</td></tr>`;
}

function unusedUpdateTechUserFilter(byUser) {
    const select = document.getElementById('filterTechUser');
    const currentValue = select.value;
    select.innerHTML = '<option value="">全部人员</option>';
    
    if (byUser) {
        byUser.forEach(u => {
            select.innerHTML += `<option value="${u.tech_user_id}">${escapeHtml(u.tech_username)}</option>`;
        });
    }
    
    select.value = currentValue;
}

function openEditModal(assignment) {
    document.getElementById('editAssignmentId').value = assignment.assignment_id;
    document.getElementById('editTechName').value = assignment.tech_username;
    document.getElementById('editProjectName').value = assignment.project_name;
    document.getElementById('editCommissionAmount').value = assignment.commission_amount || '';
    document.getElementById('editCommissionNote').value = assignment.commission_note || '';
    commissionModal.show();
}

function saveCommission() {
    const assignmentId = document.getElementById('editAssignmentId').value;
    const amount = document.getElementById('editCommissionAmount').value;
    const note = document.getElementById('editCommissionNote').value;
    
    if (!amount || isNaN(amount) || parseFloat(amount) < 0) {
        showToast('请输入有效的提成金额', 'warning');
        return;
    }
    
    fetch(`${COMMISSION_API}?action=set_commission`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            assignment_id: assignmentId,
            commission_amount: parseFloat(amount),
            commission_note: note
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            commissionModal.hide();
            loadData();
            showToast('设置成功', 'success');
        } else {
            showToast(result.message || '设置失败', 'danger');
        }
    })
    .catch(err => {
        console.error('保存失败:', err);
        showToast('网络请求失败', 'danger');
    });
}

function showError(message) {
    const tbody = document.getElementById('assignmentList');
    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${escapeHtml(message)}</td></tr>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Toast 通知组件
function showToast(message, type = 'info') {
    // 创建 toast 容器（如果不存在）
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;';
        document.body.appendChild(container);
    }
    
    const colors = {
        success: '#10b981',
        danger: '#ef4444',
        warning: '#f59e0b',
        info: '#3b82f6'
    };
    
    const toast = document.createElement('div');
    toast.style.cssText = `
        background: ${colors[type] || colors.info};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease;
        max-width: 300px;
    `;
    toast.textContent = message;
    container.appendChild(toast);
    
    // 3秒后自动移除
    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// 添加动画样式
if (!document.getElementById('toastStyles')) {
    const style = document.createElement('style');
    style.id = 'toastStyles';
    style.textContent = `
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
    `;
    document.head.appendChild(style);
}
</script>

<?php layout_footer(); ?>
