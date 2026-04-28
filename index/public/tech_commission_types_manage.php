<?php
/**
 * 技术提成类型管理页面（管理员后台）
 */
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    header('Location: /index.php?page=dashboard');
    exit;
}

$pageTitle = '提成类型管理';
layout_header($pageTitle);
?>

<style>
.types-card { border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.row-disabled { opacity: 0.5; }
.row-disabled td { background: #fafbfc; }
.usage-pill { display: inline-block; padding: 1px 8px; font-size: 12px; border-radius: 10px; background: #eef2ff; color: #4338ca; }
.usage-pill.zero { background: #f1f5f9; color: #64748b; }
.col-sort { width: 80px; }
.col-status { width: 110px; }
.col-usage { width: 100px; text-align: center; }
.col-actions { width: 220px; text-align: right; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-tags me-2 text-primary"></i>提成类型管理</h4>
            <p class="text-muted mb-0 small">维护设计师提成的分类（管理员可增删改查）。已被引用的类型只能停用，不能真正删除以保留历史数据。</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openTypeModal()"><i class="bi bi-plus-lg me-1"></i>新增类型</button>
        </div>
    </div>

    <div class="card border-0 types-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="col-sort">排序</th>
                            <th>类型名称</th>
                            <th>备注</th>
                            <th class="col-usage">使用次数</th>
                            <th class="col-status">状态</th>
                            <th class="col-actions">操作</th>
                        </tr>
                    </thead>
                    <tbody id="typeListBody">
                        <tr><td colspan="6" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 新增/编辑弹窗 -->
<div class="modal fade" id="typeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="typeModalTitle">新增类型</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editTypeId">
                <div class="mb-3">
                    <label class="form-label">类型名称 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editTypeName" maxlength="64" placeholder="例如：设计提成">
                </div>
                <div class="mb-3">
                    <label class="form-label">排序</label>
                    <input type="number" class="form-control" id="editTypeSort" value="0" placeholder="数字越小越靠前">
                </div>
                <div class="mb-3">
                    <label class="form-label">备注</label>
                    <textarea class="form-control" id="editTypeRemark" rows="2" maxlength="255" placeholder="可选"></textarea>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="editTypeStatus" checked>
                    <label class="form-check-label" for="editTypeStatus">启用（停用后不在下拉中显示）</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveType()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const TYPE_API = (window.API_URL || '/api') + '/tech_commission_types.php';
let typeModal;

document.addEventListener('DOMContentLoaded', function() {
    typeModal = new bootstrap.Modal(document.getElementById('typeModal'));
    loadTypes();
});

function escHtml(s) {
    if (s === null || s === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(s);
    return div.innerHTML;
}

function loadTypes() {
    fetch(TYPE_API + '?action=list')
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('typeListBody');
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${escHtml(res.error || '加载失败')}</td></tr>`;
                return;
            }
            const rows = res.data || [];
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">暂无数据，点击右上角「新增类型」</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const enabled = parseInt(r.status) === 1;
                const used = parseInt(r.used_count || 0);
                return `
                    <tr class="${enabled ? '' : 'row-disabled'}">
                        <td>${r.sort_order}</td>
                        <td><strong>${escHtml(r.name)}</strong></td>
                        <td><span class="text-muted small">${escHtml(r.remark || '-')}</span></td>
                        <td class="text-center">
                            <span class="usage-pill ${used === 0 ? 'zero' : ''}">${used} 条</span>
                        </td>
                        <td>
                            <span class="badge ${enabled ? 'bg-success' : 'bg-secondary'}">${enabled ? '启用中' : '已停用'}</span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='openTypeModal(${JSON.stringify(r)})'>编辑</button>
                            <button class="btn btn-sm btn-outline-${enabled ? 'warning' : 'success'}" onclick="toggleType(${r.id})">${enabled ? '停用' : '启用'}</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteType(${r.id}, ${used})">删除</button>
                        </td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            console.error('加载失败', err);
            document.getElementById('typeListBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">网络错误</td></tr>';
        });
}

function openTypeModal(row) {
    document.getElementById('editTypeId').value = row && row.id ? row.id : '';
    document.getElementById('editTypeName').value = row ? (row.name || '') : '';
    document.getElementById('editTypeSort').value = row ? (row.sort_order || 0) : 0;
    document.getElementById('editTypeRemark').value = row ? (row.remark || '') : '';
    document.getElementById('editTypeStatus').checked = row ? (parseInt(row.status) === 1) : true;
    document.getElementById('typeModalTitle').textContent = row && row.id ? '编辑类型' : '新增类型';
    typeModal.show();
}

function saveType() {
    const payload = {
        id: parseInt(document.getElementById('editTypeId').value || '0'),
        name: document.getElementById('editTypeName').value.trim(),
        sort_order: parseInt(document.getElementById('editTypeSort').value || '0'),
        remark: document.getElementById('editTypeRemark').value.trim(),
        status: document.getElementById('editTypeStatus').checked ? 1 : 0,
    };
    if (!payload.name) {
        alert('类型名称不能为空');
        return;
    }
    fetch(TYPE_API + '?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.error || '保存失败');
                return;
            }
            typeModal.hide();
            loadTypes();
        })
        .catch(err => { console.error(err); alert('网络错误'); });
}

function toggleType(id) {
    fetch(TYPE_API + '?action=toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.error || '操作失败'); return; }
            loadTypes();
        });
}

function deleteType(id, used) {
    const tip = used > 0
        ? `该类型已被 ${used} 条提成记录引用，无法真正删除，是否改为「停用」？`
        : '确认删除该类型？';
    if (!confirm(tip)) return;
    fetch(TYPE_API + '?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.error || '操作失败'); return; }
            if (res.data && res.data.soft_deleted) alert(res.message || '已自动停用');
            loadTypes();
        });
}
</script>

<?php layout_footer(); ?>
