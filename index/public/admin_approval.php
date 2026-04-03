<?php
/**
 * 审批工作台
 * 管理员查看待审交付物并进行审批
 * 按 客户→项目→作品 层级结构展示
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    header('Location: /public/index.php');
    exit;
}

$pageTitle = '审批工作台';
layout_header($pageTitle);
?>

<style>
.customer-group {
    margin-bottom: 16px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}
.customer-header {
    background: #f8fafc;
    padding: 12px 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
}
.customer-header:hover {
    background: #f1f5f9;
}
.customer-header .toggle-icon {
    transition: transform 0.2s;
    margin-right: 8px;
}
.customer-header.collapsed .toggle-icon {
    transform: rotate(-90deg);
}
.customer-content {
    padding: 0;
}
.project-group {
    border-top: 1px solid #e2e8f0;
}
.project-header {
    background: #fff;
    padding: 10px 16px 10px 32px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
}
.project-header:hover {
    background: #fafafa;
}
.project-header.collapsed .toggle-icon {
    transform: rotate(-90deg);
}
.project-content {
    padding: 0 16px 16px 32px;
}
.deliverable-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 6px;
    margin-bottom: 8px;
}
.deliverable-item:last-child {
    margin-bottom: 0;
}
.deliverable-info {
    flex: 1;
}
.deliverable-name {
    font-weight: 500;
    color: #1e293b;
}
.deliverable-meta {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}
.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="mb-1">审批工作台</h2>
            <p class="text-muted mb-0">按客户→项目→作品层级审批，仅审批作品文件</p>
        </div>
        <div class="d-flex flex-column flex-md-row gap-2 w-100 w-md-auto">
            <select id="filterUser" class="form-select form-select-sm" style="min-width: 180px;">
                <option value="">所有人员</option>
            </select>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllGroups(true)">全部展开</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleAllGroups(false)">全部收起</button>
        </div>
    </div>

    <!-- 批量操作栏 -->
    <div id="batchActionBar" class="mb-3 p-3 bg-primary text-white rounded" style="display: none;">
        <div class="d-flex align-items-center justify-content-between">
            <span>已选择 <strong id="selectedCount">0</strong> 个文件</span>
            <div>
                <button class="btn btn-success btn-sm me-2" onclick="batchApprove()">
                    <i class="bi bi-check-lg"></i> 批量通过
                </button>
                <button class="btn btn-danger btn-sm me-2" onclick="batchReject()">
                    <i class="bi bi-x-lg"></i> 批量驳回
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="clearSelection()">
                    取消选择
                </button>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#pending" onclick="switchTab('pending')">
                待审批 <span class="badge bg-warning" id="pendingCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#approved" onclick="switchTab('approved')">
                已通过 <span class="badge bg-success" id="approvedCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#rejected" onclick="switchTab('rejected')">
                已驳回 <span class="badge bg-danger" id="rejectedCount">0</span>
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pending">
            <div id="pendingList"></div>
        </div>
        <div class="tab-pane fade" id="approved">
            <div id="approvedList"></div>
        </div>
        <div class="tab-pane fade" id="rejected">
            <div id="rejectedList"></div>
        </div>
    </div>
</div>

<script>
var APPROVAL_API_URL = '/api';
var currentTab = 'pending';
var currentFilters = { userId: '' };
var deliverableData = { pending: [], approved: [], rejected: [] };

// 加载用户列表
function loadUsers() {
    fetch(`${APPROVAL_API_URL}/users.php`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('filterUser');
                data.data.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.name || user.username;
                    select.appendChild(option);
                });
            }
        });
}

// 切换标签页
function switchTab(tab) {
    currentTab = tab;
}

// 加载交付物数据（层级结构）
function loadDeliverables() {
    const statuses = ['pending', 'approved', 'rejected'];
    const promises = statuses.map(status => {
        let url = `${APPROVAL_API_URL}/deliverables.php?approval_status=${status}&file_category=artwork_file&group_by=hierarchy`;
        if (currentFilters.userId) {
            url += `&user_id=${currentFilters.userId}`;
        }
        return fetch(url)
            .then(r => r.json())
            .then(data => ({ status, data: data.success ? data.data : [] }));
    });
    
    Promise.all(promises).then(results => {
        results.forEach(({status, data}) => {
            deliverableData[status] = data;
            renderHierarchicalList(status, data);
            // 计算总数
            let count = 0;
            data.forEach(c => c.projects.forEach(p => count += p.deliverables.length));
            document.getElementById(`${status}Count`).textContent = count;
        });
    });
}

// 渲染层级列表（表格形式）
function renderHierarchicalList(status, customers) {
    const container = document.getElementById(`${status}List`);
    
    if (!customers || customers.length === 0) {
        container.innerHTML = '<div class="empty-state">暂无数据</div>';
        return;
    }
    
    let html = `
    <table class="table table-hover" style="margin-bottom: 0;">
        <thead class="table-light">
            <tr>
                ${status === 'pending' ? '<th style="width: 40px;"><input type="checkbox" id="selectAll-' + status + '" onchange="toggleSelectAll(this, \'' + status + '\')" title="全选"></th>' : ''}
                <th style="width: 200px;">作品名称</th>
                <th style="width: 100px;">类型</th>
                <th style="width: 100px;">提交人</th>
                <th style="width: 160px;">提交时间</th>
                <th style="width: 80px;">状态</th>
                ${status === 'pending' ? '<th style="width: 140px;">操作</th>' : ''}
                ${status === 'approved' ? '<th style="width: 100px;">审批人</th><th style="width: 160px;">审批时间</th><th style="width: 80px;">操作</th>' : ''}
                ${status === 'rejected' ? '<th style="width: 200px;">驳回原因</th><th style="width: 100px;">审批人</th><th style="width: 80px;">操作</th>' : ''}
            </tr>
        </thead>
        <tbody>`;
    
    customers.forEach((customer, cIdx) => {
        const customerId = `${status}-customer-${cIdx}`;
        let deliverableCount = 0;
        customer.projects.forEach(p => deliverableCount += p.deliverables.length);
        
        // 客户分组行
        html += `
            <tr class="group-header" onclick="toggleCustomer('${customerId}')" style="cursor: pointer; user-select: none;">
                <td colspan="${status === 'pending' ? 7 : 8}" style="background: #f1f5f9; font-weight: 600;">
                    <span class="toggle-icon" id="${customerId}-icon" style="margin-right: 8px;">▼</span>
                    客户: ${customer.customer_name || '未知客户'}
                    <span style="color: #64748b; font-weight: normal; margin-left: 8px;">(${deliverableCount})</span>
                </td>
            </tr>`;
        
        customer.projects.forEach((project, pIdx) => {
            const projectId = `${customerId}-project-${pIdx}`;
            
            // 项目分组行
            html += `
                <tr class="project-row ${customerId}-content" onclick="toggleProject('${projectId}')" style="cursor: pointer; user-select: none;">
                    <td colspan="${status === 'pending' ? 7 : 8}" style="background: #fafafa; padding-left: 32px;">
                        <span class="toggle-icon" id="${projectId}-icon" style="margin-right: 8px;">▼</span>
                        项目: ${project.project_name || '未知项目'}
                        <span style="color: #64748b; margin-left: 8px;">${project.project_code || ''}</span>
                        <span class="badge bg-secondary ms-2">${project.deliverables.length}</span>
                    </td>
                </tr>`;
            
            // 作品行
            project.deliverables.forEach(d => {
                const submitTime = new Date(d.submitted_at * 1000).toLocaleString('zh-CN');
                const statusClass = `status-${d.approval_status}`;
                const statusText = d.approval_status === 'pending' ? '待审批' : 
                                   d.approval_status === 'approved' ? '已通过' : '已驳回';
                
                html += `
                    <tr class="${customerId}-content ${projectId}-content deliverable-row" data-id="${d.id}" style="background: #fff;">
                        ${status === 'pending' ? '<td><input type="checkbox" class="deliverable-checkbox" value="' + d.id + '" onchange="updateSelection()"></td>' : ''}
                        <td style="padding-left: ${status === 'pending' ? '16px' : '48px'};">${d.deliverable_name}</td>
                        <td>${d.deliverable_type || '-'}</td>
                        <td>${d.submitted_by_name || '-'}</td>
                        <td>${submitTime}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>`;
                
                if (status === 'pending') {
                    html += `<td>
                        <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); approveDeliverable(${d.id})">通过</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); rejectDeliverable(${d.id})">驳回</button>
                    </td>`;
                } else if (status === 'approved') {
                    const approveTime = d.approved_at ? new Date(d.approved_at * 1000).toLocaleString('zh-CN') : '-';
                    html += `<td>${d.approved_by_name || '-'}</td><td>${approveTime}</td>
                             <td><button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation(); resetDeliverable(${d.id})">重置</button></td>`;
                } else if (status === 'rejected') {
                    html += `<td style="max-width: 200px; white-space: normal;">${d.reject_reason || '-'}</td>
                             <td>${d.approved_by_name || '-'}</td>
                             <td><button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation(); resetDeliverable(${d.id})">重置</button></td>`;
                }
                
                html += `</tr>`;
            });
        });
    });
    
    html += `</tbody></table>`;
    container.innerHTML = html;
}

// 展开/收起客户（表格行）
function toggleCustomer(id) {
    const rows = document.querySelectorAll(`.${id}-content`);
    const icon = document.getElementById(`${id}-icon`);
    const isHidden = rows.length > 0 && rows[0].style.display === 'none';
    
    rows.forEach(row => {
        row.style.display = isHidden ? '' : 'none';
    });
    if (icon) {
        icon.textContent = isHidden ? '▼' : '▶';
    }
}

// 展开/收起项目（表格行）
function toggleProject(id) {
    const rows = document.querySelectorAll(`.${id}-content`);
    const icon = document.getElementById(`${id}-icon`);
    const isHidden = rows.length > 0 && rows[0].style.display === 'none';
    
    rows.forEach(row => {
        row.style.display = isHidden ? '' : 'none';
    });
    if (icon) {
        icon.textContent = isHidden ? '▼' : '▶';
    }
}

// 全部展开/收起
function toggleAllGroups(expand) {
    // 展开/收起所有内容行
    document.querySelectorAll('tr[class*="-content"]').forEach(row => {
        row.style.display = expand ? '' : 'none';
    });
    // 更新所有图标
    document.querySelectorAll('.toggle-icon').forEach(icon => {
        icon.textContent = expand ? '▼' : '▶';
    });
}

// 审批通过
function approveDeliverable(deliverableId) {
    showConfirmModal('确认审批通过', '确定要通过此作品吗？通过后客户将可见。', function() {
        fetch(`${APPROVAL_API_URL}/deliverables.php?action=approve`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: deliverableId, approve_action: 'approve' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('审批通过', 'success');
                loadDeliverables();
            } else {
                showAlertModal('操作失败: ' + data.message, 'error');
            }
        });
    });
}

// 重置审批状态（调回待审批）
function resetDeliverable(deliverableId) {
    showConfirmModal('确认重置', '确定要将此作品状态重置为"待审批"吗？', function() {
        fetch(`${APPROVAL_API_URL}/deliverables.php?action=reset_approval`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: deliverableId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('已重置为待审批', 'success');
                loadDeliverables();
            } else {
                showAlertModal('操作失败: ' + data.message, 'error');
            }
        });
    });
}

// 审批驳回
function rejectDeliverable(deliverableId) {
    const html = `
        <div class="mb-3">
            <label class="form-label">驳回原因（可选）</label>
            <textarea class="form-control" id="rejectReason" rows="3" placeholder="请输入驳回原因..."></textarea>
        </div>
    `;
    
    showConfirmModal('审批驳回', html, function() {
        const reason = document.getElementById('rejectReason').value.trim();
        
        fetch(`${APPROVAL_API_URL}/deliverables.php?action=approve`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: deliverableId, approve_action: 'reject', reject_reason: reason })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('已驳回', 'success');
                loadDeliverables();
            } else {
                showAlertModal('操作失败: ' + data.message, 'error');
            }
        });
    });
}

// 全选/取消全选
function toggleSelectAll(checkbox, status) {
    const checkboxes = document.querySelectorAll(`#${status}List .deliverable-checkbox`);
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateSelection();
}

// 更新选中状态
function updateSelection() {
    const checked = document.querySelectorAll('.deliverable-checkbox:checked');
    const count = checked.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('batchActionBar').style.display = count > 0 ? 'block' : 'none';
}

// 清除选择
function clearSelection() {
    document.querySelectorAll('.deliverable-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('[id^="selectAll-"]').forEach(cb => cb.checked = false);
    updateSelection();
}

// 批量通过
function batchApprove() {
    const ids = Array.from(document.querySelectorAll('.deliverable-checkbox:checked')).map(cb => parseInt(cb.value));
    if (ids.length === 0) return;
    
    showConfirmModal('批量审批通过', `确定要通过这 ${ids.length} 个文件吗？`, function() {
        fetch(`${APPROVAL_API_URL}/deliverables.php?action=batch_approve`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ids: ids, approve_action: 'approve' })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal(`已通过 ${data.affected} 个文件`, 'success');
                clearSelection();
                loadDeliverables();
            } else {
                showAlertModal('操作失败: ' + data.message, 'error');
            }
        });
    });
}

// 批量驳回
function batchReject() {
    const ids = Array.from(document.querySelectorAll('.deliverable-checkbox:checked')).map(cb => parseInt(cb.value));
    if (ids.length === 0) return;
    
    const html = `
        <p>确定要驳回这 ${ids.length} 个文件吗？</p>
        <div class="mb-3">
            <label class="form-label">驳回原因（可选，将应用到所有文件）</label>
            <textarea class="form-control" id="batchRejectReason" rows="2" placeholder="请输入驳回原因..."></textarea>
        </div>
    `;
    
    showConfirmModal('批量驳回', html, function() {
        const reason = document.getElementById('batchRejectReason').value.trim();
        
        fetch(`${APPROVAL_API_URL}/deliverables.php?action=batch_approve`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ids: ids, approve_action: 'reject', reject_reason: reason })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal(`已驳回 ${data.affected} 个文件`, 'success');
                clearSelection();
                loadDeliverables();
            } else {
                showAlertModal('操作失败: ' + data.message, 'error');
            }
        });
    });
}

// 应用筛选
function applyFilters() {
    currentFilters.userId = document.getElementById('filterUser').value;
    loadDeliverables();
}

document.addEventListener('DOMContentLoaded', function() {
    loadUsers();
    loadDeliverables();
    document.getElementById('filterUser').addEventListener('change', applyFilters);
});
</script>

<?php layout_footer(); ?>
