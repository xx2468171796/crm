<?php
/**
 * 技术人员 - 我的项目提成页面
 */
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/auth.php';

auth_require();
$user = current_user();

$pageTitle = '我的项目提成';
layout_header($pageTitle);
?>

<div class="container-fluid py-4">
    <!-- 页面标题 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-currency-yen me-2"></i>我的项目提成</h4>
    </div>

    <!-- 汇总卡片 -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-subtitle mb-2 opacity-75">总提成金额</h6>
                            <h3 class="card-title mb-0" id="totalCommission">¥0.00</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack fs-1 opacity-50"></i>
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
                            <h6 class="card-subtitle mb-2 opacity-75">项目数量</h6>
                            <h3 class="card-title mb-0" id="projectCount">0</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-folder-check fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-subtitle mb-2 opacity-75">平均提成</h6>
                            <h3 class="card-title mb-0" id="avgCommission">¥0.00</h3>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up-arrow fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 项目列表 -->
    <div class="card">
        <div class="card-header">
            <span class="fw-bold">项目列表</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>项目名称</th>
                            <th>客户名称</th>
                            <th>项目状态</th>
                            <th class="text-end">提成金额</th>
                            <th>备注</th>
                            <th>分配时间</th>
                        </tr>
                    </thead>
                    <tbody id="projectList">
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">加载中...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = '/api/tech_commission.php';

// 项目状态映射
const statusMap = {
    'pending': { text: '待开始', class: 'bg-secondary' },
    'in_progress': { text: '进行中', class: 'bg-primary' },
    'review': { text: '审核中', class: 'bg-warning' },
    'completed': { text: '已完成', class: 'bg-success' },
    'cancelled': { text: '已取消', class: 'bg-danger' }
};

document.addEventListener('DOMContentLoaded', function() {
    loadMyProjects();
});

function loadMyProjects() {
    fetch(`${API_URL}?action=my_projects`)
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                renderProjects(result.data.projects);
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

function renderProjects(projects) {
    const tbody = document.getElementById('projectList');
    
    if (!projects || projects.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">暂无分配的项目</td></tr>';
        return;
    }
    
    tbody.innerHTML = projects.map(p => {
        const status = statusMap[p.project_status] || { text: p.project_status, class: 'bg-secondary' };
        const commission = p.commission_amount ? `¥${parseFloat(p.commission_amount).toFixed(2)}` : '<span class="text-muted">待设置</span>';
        const assignedDate = p.assigned_at ? new Date(p.assigned_at * 1000).toLocaleDateString('zh-CN') : '-';
        
        return `
            <tr>
                <td>
                    <a href="/public/project_detail.php?id=${p.project_id}" class="text-decoration-none">
                        ${escapeHtml(p.project_name)}
                    </a>
                </td>
                <td>${escapeHtml(p.customer_name || '-')}</td>
                <td><span class="badge ${status.class}">${status.text}</span></td>
                <td class="text-end fw-bold ${p.commission_amount ? 'text-success' : ''}">${commission}</td>
                <td>${escapeHtml(p.commission_note || '-')}</td>
                <td>${assignedDate}</td>
            </tr>
        `;
    }).join('');
}

function updateSummary(summary) {
    const total = summary.total_commission || 0;
    const count = summary.project_count || 0;
    const avg = count > 0 ? total / count : 0;
    
    document.getElementById('totalCommission').textContent = `¥${total.toFixed(2)}`;
    document.getElementById('projectCount').textContent = count;
    document.getElementById('avgCommission').textContent = `¥${avg.toFixed(2)}`;
}

function showError(message) {
    const tbody = document.getElementById('projectList');
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${escapeHtml(message)}</td></tr>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php layout_footer(); ?>
