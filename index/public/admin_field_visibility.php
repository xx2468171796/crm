<?php
/**
 * 字段可见性配置页面
 * 管理员配置客户/项目字段对技术和客户的可见性
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

$pageTitle = '字段可见性配置';
layout_header($pageTitle);
?>

<style>
.config-table th { background: #f8fafc; }
.visibility-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.visibility-badge.internal { background: #fef3c7; color: #92400e; }
.visibility-badge.client { background: #d1fae5; color: #065f46; }
.visibility-badge.admin { background: #fee2e2; color: #991b1b; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>字段可见性配置</h2>
            <p class="text-muted mb-0">配置客户/项目字段对技术和客户门户的可见性</p>
        </div>
        <button class="btn btn-primary" onclick="saveAllConfigs()">
            <i class="bi bi-save"></i> 保存配置
        </button>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#customerFields">客户字段</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#projectFields">项目字段</a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="customerFields">
            <div class="card">
                <div class="card-body">
                    <table class="table config-table" id="customerTable">
                        <thead>
                            <tr>
                                <th>字段名称</th>
                                <th>可见级别</th>
                                <th>技术可见</th>
                                <th>客户可见</th>
                                <th>排序</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="projectFields">
            <div class="card">
                <div class="card-body">
                    <table class="table config-table" id="projectTable">
                        <thead>
                            <tr>
                                <th>字段名称</th>
                                <th>可见级别</th>
                                <th>技术可见</th>
                                <th>客户可见</th>
                                <th>排序</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var FIELD_API_URL = '/api';
var allConfigs = [];

function loadConfigs() {
    fetch(`${FIELD_API_URL}/field_visibility.php`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                allConfigs = data.data;
                renderTables();
            }
        });
}

function renderTables() {
    const customerConfigs = allConfigs.filter(c => c.entity_type === 'customer');
    const projectConfigs = allConfigs.filter(c => c.entity_type === 'project');
    
    renderTable('customerTable', customerConfigs);
    renderTable('projectTable', projectConfigs);
}

function renderTable(tableId, configs) {
    const tbody = document.querySelector(`#${tableId} tbody`);
    
    if (!configs || configs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">暂无配置</td></tr>';
        return;
    }
    
    let html = '';
    configs.forEach(c => {
        html += `
            <tr data-id="${c.id}">
                <td>
                    <strong>${c.field_label}</strong>
                    <br><small class="text-muted">${c.field_key}</small>
                </td>
                <td>
                    <select class="form-select form-select-sm" data-field="visibility_level" style="width: 120px;">
                        <option value="internal" ${c.visibility_level === 'internal' ? 'selected' : ''}>内部</option>
                        <option value="client" ${c.visibility_level === 'client' ? 'selected' : ''}>客户可见</option>
                        <option value="admin" ${c.visibility_level === 'admin' ? 'selected' : ''}>仅管理员</option>
                    </select>
                </td>
                <td>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" data-field="tech_visible" ${c.tech_visible == 1 ? 'checked' : ''}>
                    </div>
                </td>
                <td>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" data-field="client_visible" ${c.client_visible == 1 ? 'checked' : ''}>
                    </div>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" data-field="sort_order" value="${c.sort_order}" style="width: 70px;">
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function saveAllConfigs() {
    const configs = [];
    
    document.querySelectorAll('.config-table tbody tr[data-id]').forEach(row => {
        const id = parseInt(row.dataset.id);
        configs.push({
            id: id,
            visibility_level: row.querySelector('[data-field="visibility_level"]').value,
            tech_visible: row.querySelector('[data-field="tech_visible"]').checked ? 1 : 0,
            client_visible: row.querySelector('[data-field="client_visible"]').checked ? 1 : 0,
            sort_order: parseInt(row.querySelector('[data-field="sort_order"]').value) || 0
        });
    });
    
    fetch(`${FIELD_API_URL}/field_visibility.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ configs: configs })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal(data.message, 'success');
            loadConfigs();
        } else {
            showAlertModal('保存失败: ' + data.message, 'error');
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    loadConfigs();
});
</script>

<?php layout_footer(); ?>
