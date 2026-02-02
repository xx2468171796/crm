<?php
/**
 * 支付方式管理页面
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

if (!is_logged_in()) {
    redirect('/login.php');
}

$user = current_user();
if (!isAdmin($user)) {
    layout_header('无权限');
    echo '<div class="container mt-5"><div class="alert alert-danger">无权限访问此页面</div></div>';
    layout_footer();
    exit;
}

layout_header('支付方式管理');
finance_sidebar_start('admin_payment_methods');
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/index.php">首页</a></li>
                    <li class="breadcrumb-item active">支付方式管理</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4>支付方式管理</h4>
                    <p class="text-muted mb-0">配置收款时可选的支付方式</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="showAddModal()">
                        <i class="bi bi-plus-circle"></i> 添加支付方式
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <table class="table table-hover" id="methodTable">
                        <thead>
                            <tr>
                                <th width="60">排序</th>
                                <th>代码</th>
                                <th>显示名称</th>
                                <th>手续费加成</th>
                                <th width="100">状态</th>
                                <th width="180">操作</th>
                            </tr>
                        </thead>
                        <tbody id="methodList">
                            <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑对话框 -->
<div class="modal fade" id="methodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">添加支付方式</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="methodForm">
                    <input type="hidden" id="methodId" name="id" value="0">
                    <div class="mb-3">
                        <label class="form-label">代码 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="dictCode" name="dict_code" required placeholder="如 cash、wechat">
                        <small class="text-muted">英文标识，保存后建议不要修改</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">显示名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="dictLabel" name="dict_label" required placeholder="如 现金、微信">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">排序</label>
                        <input type="number" class="form-control" id="sortOrder" name="sort_order" value="0">
                        <small class="text-muted">数字越小越靠前</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="isEnabled" name="is_enabled" checked>
                            <label class="form-check-label" for="isEnabled">启用</label>
                        </div>
                    </div>
                    <hr>
                    <h6 class="text-muted">手续费加成配置</h6>
                    <div class="mb-3">
                        <label class="form-label">手续费类型</label>
                        <select class="form-select" id="feeType" name="fee_type">
                            <option value="">无手续费</option>
                            <option value="fixed">固定金额</option>
                            <option value="percent">百分比</option>
                        </select>
                    </div>
                    <div class="mb-3" id="feeValueDiv" style="display:none;">
                        <label class="form-label">手续费值 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" class="form-control" id="feeValue" name="fee_value" placeholder="输入手续费值">
                            <span class="input-group-text" id="feeValueUnit">元</span>
                        </div>
                        <small class="text-muted" id="feeValueHint">每笔收款加收的固定金额</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveMethod()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_BASE = '/api/system_dict.php';
let methodModal = null;

document.addEventListener('DOMContentLoaded', function() {
    methodModal = new bootstrap.Modal(document.getElementById('methodModal'));
    loadList();
});

function loadList() {
    fetch(API_BASE + '?action=list&dict_type=payment_method')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                document.getElementById('methodList').innerHTML = '<tr><td colspan="5" class="text-center text-danger">加载失败</td></tr>';
                return;
            }
            renderList(res.data || []);
        })
        .catch(() => {
            document.getElementById('methodList').innerHTML = '<tr><td colspan="5" class="text-center text-danger">加载失败</td></tr>';
        });
}

function renderList(rows) {
    const tbody = document.getElementById('methodList');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无数据</td></tr>';
        return;
    }
    
    tbody.innerHTML = rows.map(r => {
        let feeText = '无';
        if (r.fee_type === 'fixed' && r.fee_value) {
            feeText = '+' + parseFloat(r.fee_value).toFixed(2) + ' 元';
        } else if (r.fee_type === 'percent' && r.fee_value) {
            feeText = '+' + (parseFloat(r.fee_value) * 100).toFixed(2) + '%';
        }
        return `
        <tr data-id="${r.id}">
            <td>${r.sort_order}</td>
            <td><code>${esc(r.dict_code)}</code></td>
            <td>${esc(r.dict_label)}</td>
            <td><span class="badge bg-info">${feeText}</span></td>
            <td>
                <span class="badge ${r.is_enabled == 1 ? 'bg-success' : 'bg-secondary'}">${r.is_enabled == 1 ? '启用' : '禁用'}</span>
            </td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editMethod(${r.id})">编辑</button>
                <button class="btn btn-sm btn-outline-${r.is_enabled == 1 ? 'warning' : 'success'}" onclick="toggleMethod(${r.id})">${r.is_enabled == 1 ? '禁用' : '启用'}</button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteMethod(${r.id})">删除</button>
            </td>
        </tr>
    `}).join('');
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showAddModal() {
    document.getElementById('modalTitle').textContent = '添加支付方式';
    document.getElementById('methodId').value = '0';
    document.getElementById('dictCode').value = '';
    document.getElementById('dictLabel').value = '';
    document.getElementById('sortOrder').value = '0';
    document.getElementById('isEnabled').checked = true;
    document.getElementById('feeType').value = '';
    document.getElementById('feeValue').value = '';
    updateFeeValueVisibility();
    methodModal.show();
}

function updateFeeValueVisibility() {
    const feeType = document.getElementById('feeType').value;
    const feeValueDiv = document.getElementById('feeValueDiv');
    const feeValueUnit = document.getElementById('feeValueUnit');
    const feeValueHint = document.getElementById('feeValueHint');
    
    if (feeType === 'fixed') {
        feeValueDiv.style.display = '';
        feeValueUnit.textContent = '元';
        feeValueHint.textContent = '每笔收款加收的固定金额';
    } else if (feeType === 'percent') {
        feeValueDiv.style.display = '';
        feeValueUnit.textContent = '%';
        feeValueHint.textContent = '输入百分比数字，如 3 表示 3%';
    } else {
        feeValueDiv.style.display = 'none';
    }
}

document.getElementById('feeType').addEventListener('change', updateFeeValueVisibility);

function editMethod(id) {
    fetch(API_BASE + '?action=list&dict_type=payment_method')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const row = (res.data || []).find(r => r.id == id);
            if (!row) return;
            
            document.getElementById('modalTitle').textContent = '编辑支付方式';
            document.getElementById('methodId').value = row.id;
            document.getElementById('dictCode').value = row.dict_code;
            document.getElementById('dictLabel').value = row.dict_label;
            document.getElementById('sortOrder').value = row.sort_order;
            document.getElementById('isEnabled').checked = row.is_enabled == 1;
            
            // 手续费配置
            document.getElementById('feeType').value = row.fee_type || '';
            if (row.fee_type === 'percent' && row.fee_value) {
                // 百分比转换为显示值（0.03 -> 3）
                document.getElementById('feeValue').value = (parseFloat(row.fee_value) * 100).toFixed(2);
            } else {
                document.getElementById('feeValue').value = row.fee_value || '';
            }
            updateFeeValueVisibility();
            
            methodModal.show();
        });
}

function saveMethod() {
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('dict_type', 'payment_method');
    fd.append('id', document.getElementById('methodId').value);
    fd.append('dict_code', document.getElementById('dictCode').value.trim());
    fd.append('dict_label', document.getElementById('dictLabel').value.trim());
    fd.append('sort_order', document.getElementById('sortOrder').value);
    fd.append('is_enabled', document.getElementById('isEnabled').checked ? '1' : '0');
    
    // 手续费配置
    const feeType = document.getElementById('feeType').value;
    fd.append('fee_type', feeType);
    if (feeType) {
        let feeValue = parseFloat(document.getElementById('feeValue').value) || 0;
        if (feeType === 'percent') {
            // 百分比转换为小数（3 -> 0.03）
            feeValue = feeValue / 100;
        }
        fd.append('fee_value', feeValue);
    }
    
    fetch(API_BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || '保存失败');
                return;
            }
            methodModal.hide();
            loadList();
        })
        .catch(() => alert('保存失败'));
}

function toggleMethod(id) {
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('id', id);
    
    fetch(API_BASE, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.message || '操作失败');
                return;
            }
            loadList();
        })
        .catch(() => alert('操作失败'));
}

function deleteMethod(id) {
    showConfirmModal('删除支付方式', '确定删除此支付方式？', function() {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        
        fetch(API_BASE, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showAlertModal(res.message || '删除失败', 'error');
                    return;
                }
                showAlertModal('删除成功', 'success');
                loadList();
            })
            .catch(() => showAlertModal('删除失败', 'error'));
    });
}
</script>

<?php
finance_sidebar_end();
layout_footer();
?>
