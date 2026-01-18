<?php
/**
 * 项目阶段时间模板管理页面
 * 入口：系统设置 → 阶段时间模板
 */

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/rbac.php';
require_once __DIR__ . '/../../core/layout.php';
require_once __DIR__ . '/../../core/config/statuses.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    echo '<div class="alert alert-danger m-4">无权限访问此页面</div>';
    exit;
}

$pageTitle = '阶段时间模板管理';
layout_header($pageTitle);

$projectStatuses = PROJECT_STATUSES;
?>

<style>
.template-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}
.template-table {
    width: 100%;
    border-collapse: collapse;
}
.template-table th,
.template-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.template-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #475569;
}
.template-table tr:hover {
    background: #f8fafc;
}
.days-input {
    width: 80px;
    text-align: center;
}
.stage-flow {
    display: flex;
    align-items: center;
    gap: 8px;
}
.stage-badge {
    background: #e0e7ff;
    color: #4338ca;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
}
.stage-arrow {
    color: #94a3b8;
}
.btn-save {
    background: #6366f1;
    color: #fff;
    border: none;
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
}
.btn-save:hover {
    background: #4f46e5;
}
.btn-save:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
}
.total-days {
    background: #f0fdf4;
    color: #166534;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
}
.help-text {
    color: #64748b;
    font-size: 13px;
    margin-top: 8px;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><?= $pageTitle ?></h4>
            <p class="text-muted mb-0">配置项目各阶段的默认预计天数，创建项目时将自动应用</p>
        </div>
        <button class="btn-save" id="btnSaveAll" onclick="saveAllTemplates()">
            <i class="bi bi-check-lg"></i> 保存全部
        </button>
    </div>

    <div class="template-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">阶段时间配置</h5>
            <span class="total-days" id="totalDays">总计: 0 天</span>
        </div>
        
        <table class="template-table" id="templateTable">
            <thead>
                <tr>
                    <th style="width:50px">顺序</th>
                    <th>阶段转换</th>
                    <th style="width:120px">默认天数</th>
                    <th style="width:200px">说明</th>
                </tr>
            </thead>
            <tbody id="templateBody">
                <tr><td colspan="4" class="text-center text-muted py-4">加载中...</td></tr>
            </tbody>
        </table>
        
        <p class="help-text">
            <i class="bi bi-info-circle"></i> 
            调整默认天数后点击"保存全部"。创建新项目时将自动使用这些默认值，项目创建后可单独调整各阶段时间。
        </p>
    </div>
</div>

<script>
const projectStatuses = <?= json_encode($projectStatuses) ?>;
let templates = [];

async function loadTemplates() {
    try {
        const res = await fetch('/api/stage_templates.php');
        const data = await res.json();
        if (data.success) {
            templates = data.data;
            renderTemplates();
        } else {
            showToast(data.message, 'error');
        }
    } catch (e) {
        console.error('[STAGE_TEMPLATE_DEBUG]', e);
        showToast('加载失败', 'error');
    }
}

function renderTemplates() {
    const tbody = document.getElementById('templateBody');
    
    if (templates.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">暂无模板数据</td></tr>';
        return;
    }
    
    let html = '';
    templates.forEach((t, idx) => {
        html += `
            <tr data-id="${t.id}">
                <td>${t.stage_order}</td>
                <td>
                    <div class="stage-flow">
                        <span class="stage-badge">${escapeHtml(t.stage_from)}</span>
                        <span class="stage-arrow">→</span>
                        <span class="stage-badge">${escapeHtml(t.stage_to)}</span>
                    </div>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm days-input" 
                           value="${t.default_days}" min="1" max="365"
                           onchange="updateDays(${t.id}, this.value)">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" 
                           value="${escapeHtml(t.description || '')}" 
                           placeholder="可选说明"
                           onchange="updateDescription(${t.id}, this.value)">
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
    updateTotalDays();
}

function updateDays(id, value) {
    const t = templates.find(x => x.id == id);
    if (t) {
        t.default_days = Math.max(1, parseInt(value) || 1);
        t._changed = true;
    }
    updateTotalDays();
}

function updateDescription(id, value) {
    const t = templates.find(x => x.id == id);
    if (t) {
        t.description = value;
        t._changed = true;
    }
}

function updateTotalDays() {
    const total = templates.reduce((sum, t) => sum + parseInt(t.default_days || 0), 0);
    document.getElementById('totalDays').textContent = `总计: ${total} 天`;
}

async function saveAllTemplates() {
    const btn = document.getElementById('btnSaveAll');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> 保存中...';
    
    try {
        const changedTemplates = templates.filter(t => t._changed);
        
        for (const t of changedTemplates) {
            const res = await fetch('/api/stage_templates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: t.id,
                    stage_from: t.stage_from,
                    stage_to: t.stage_to,
                    stage_order: t.stage_order,
                    default_days: t.default_days,
                    description: t.description
                })
            });
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.message);
            }
            t._changed = false;
        }
        
        showToast('保存成功', 'success');
    } catch (e) {
        console.error('[STAGE_TEMPLATE_DEBUG]', e);
        showToast('保存失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> 保存全部';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

function showToast(msg, type) {
    if (typeof showAlertModal === 'function') {
        showAlertModal(msg, type);
    } else {
        alert(msg);
    }
}

document.addEventListener('DOMContentLoaded', loadTemplates);
</script>

<?php layout_footer(); ?>
