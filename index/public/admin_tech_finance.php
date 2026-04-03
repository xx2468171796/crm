<?php
/**
 * 管理层 - 技术财务报表页面
 */
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

// 权限检查：仅管理员
if (!isAdmin($user)) {
    header('Location: /public/dashboard.php');
    exit;
}

$pageTitle = '技术财务报表';
layout_header($pageTitle);
?>

<div class="container-fluid py-4">
    <!-- 页面标题 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-bar-chart-line-fill me-2"></i>技术财务报表</h4>
        <button class="btn btn-success" onclick="exportReport()">
            <i class="bi bi-download me-1"></i>导出报表
        </button>
    </div>

    <!-- 筛选区域 -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">开始日期</label>
                    <input type="date" class="form-control" id="filterStartDate">
                </div>
                <div class="col-md-3">
                    <label class="form-label">结束日期</label>
                    <input type="date" class="form-control" id="filterEndDate">
                </div>
                <div class="col-md-3">
                    <label class="form-label">部门</label>
                    <select class="form-select" id="filterDepartment">
                        <option value="">全部部门</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-primary me-2" onclick="loadReport()">
                        <i class="bi bi-search me-1"></i>查询
                    </button>
                    <button class="btn btn-outline-secondary" onclick="resetFilters()">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 汇总卡片 -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-subtitle mb-2 opacity-75">总支出（提成）</h6>
                            <h3 class="card-title mb-0" id="totalCommission">¥0.00</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-coin fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-subtitle mb-2 opacity-75">涉及项目数</h6>
                            <h3 class="card-title mb-0" id="totalProjects">0</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-folder2-open fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-subtitle mb-2 opacity-75">技术人员数</h6>
                            <h3 class="card-title mb-0" id="userCount">0</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 按技术人员汇总 -->
    <div class="card">
        <div class="card-header">
            <span class="fw-bold">按技术人员汇总</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>技术人员</th>
                            <th>部门</th>
                            <th class="text-center">项目数</th>
                            <th class="text-end">提成金额</th>
                            <th class="text-end">占比</th>
                        </tr>
                    </thead>
                    <tbody id="reportTable">
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">加载中...</td>
                        </tr>
                    </tbody>
                    <tfoot class="table-light" id="reportFooter">
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = '/api/tech_commission.php';
let reportData = null;

document.addEventListener('DOMContentLoaded', function() {
    // 设置默认日期（本月）
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    document.getElementById('filterStartDate').value = formatDate(firstDay);
    document.getElementById('filterEndDate').value = formatDate(now);
    
    loadDepartments();
    loadReport();
});

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function loadDepartments() {
    fetch('/api/departments.php?action=list')
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

function loadReport() {
    const startDate = document.getElementById('filterStartDate').value;
    const endDate = document.getElementById('filterEndDate').value;
    const departmentId = document.getElementById('filterDepartment').value;
    
    let url = `${API_URL}?action=report`;
    if (startDate) url += `&start_date=${startDate}`;
    if (endDate) url += `&end_date=${endDate}`;
    if (departmentId) url += `&department_id=${departmentId}`;
    
    fetch(url)
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                reportData = result.data;
                renderReport(result.data);
                updateSummary(result.data.summary);
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
    const tbody = document.getElementById('reportTable');
    const tfoot = document.getElementById('reportFooter');
    const byUser = data.by_user || [];
    const total = data.summary?.total_commission || 0;
    
    if (byUser.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">暂无数据</td></tr>';
        tfoot.innerHTML = '';
        return;
    }
    
    tbody.innerHTML = byUser.map(u => {
        const commission = parseFloat(u.total_commission || 0);
        const percent = total > 0 ? (commission / total * 100).toFixed(1) : 0;
        
        return `
            <tr>
                <td><strong>${escapeHtml(u.tech_username)}</strong></td>
                <td>${escapeHtml(u.department_name || '-')}</td>
                <td class="text-center">${u.project_count}</td>
                <td class="text-end fw-bold text-danger">¥${commission.toFixed(2)}</td>
                <td class="text-end">
                    <div class="d-flex align-items-center justify-content-end">
                        <div class="progress flex-grow-1 me-2" style="width: 100px; height: 8px;">
                            <div class="progress-bar bg-danger" style="width: ${percent}%"></div>
                        </div>
                        <span>${percent}%</span>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    // 合计行
    tfoot.innerHTML = `
        <tr>
            <th colspan="2">合计</th>
            <th class="text-center">${data.summary.total_projects}</th>
            <th class="text-end text-danger">¥${total.toFixed(2)}</th>
            <th class="text-end">100%</th>
        </tr>
    `;
}

function updateSummary(summary) {
    document.getElementById('totalCommission').textContent = `¥${(summary.total_commission || 0).toFixed(2)}`;
    document.getElementById('totalProjects').textContent = summary.total_projects || 0;
    document.getElementById('userCount').textContent = summary.user_count || 0;
}

function resetFilters() {
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    document.getElementById('filterStartDate').value = formatDate(firstDay);
    document.getElementById('filterEndDate').value = formatDate(now);
    document.getElementById('filterDepartment').value = '';
    loadReport();
}

function exportReport() {
    if (!reportData || !reportData.by_user || reportData.by_user.length === 0) {
        alert('没有数据可导出');
        return;
    }
    
    // 简单 CSV 导出
    let csv = '\uFEFF技术人员,部门,项目数,提成金额\n';
    reportData.by_user.forEach(u => {
        csv += `${u.tech_username},${u.department_name || ''},${u.project_count},${u.total_commission}\n`;
    });
    csv += `合计,,${reportData.summary.total_projects},${reportData.summary.total_commission}\n`;
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `技术提成报表_${document.getElementById('filterStartDate').value}_${document.getElementById('filterEndDate').value}.csv`;
    link.click();
}

function showError(message) {
    const tbody = document.getElementById('reportTable');
    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-danger">${escapeHtml(message)}</td></tr>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php layout_footer(); ?>
