<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

auth_require();
$user = current_user();
if (!isAdmin($user)) {
    echo '<div class="alert alert-danger">无权限访问</div>';
    exit;
}

layout_header('字典管理');

ensureDictTableExists();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">字典管理</h3>
    <button type="button" class="btn btn-primary" onclick="openDictModal()">新增字典项</button>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">字典类型</label>
                <select class="form-select" id="filterType">
                    <option value="">全部</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-primary" onclick="loadDictList()">查询</button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>字典类型</th>
                    <th>代码</th>
                    <th>显示名称</th>
                    <th>排序</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody id="dictTableBody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="dictModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dictModalTitle">新增字典项</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dictId" value="0">
                <div class="mb-3">
                    <label class="form-label">字典类型 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="dictType" placeholder="如：payment_method">
                    <div class="form-text">已有类型：<span id="existingTypes"></span></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">代码 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="dictCode" placeholder="如：cash">
                </div>
                <div class="mb-3">
                    <label class="form-label">显示名称 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="dictLabel" placeholder="如：现金">
                </div>
                <div class="mb-3">
                    <label class="form-label">排序</label>
                    <input type="number" class="form-control" id="dictSortOrder" value="0">
                </div>
                <div class="mb-3">
                    <label class="form-label">状态</label>
                    <select class="form-select" id="dictIsEnabled">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveDict()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
let dictModal = null;
let allTypes = [];

function apiUrl(path) {
    return API_URL + '/' + path;
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function loadDictList() {
    const type = document.getElementById('filterType').value;
    const url = apiUrl('system_dict_list.php') + (type ? '?type=' + encodeURIComponent(type) : '');
    
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '加载失败', 'error');
                return;
            }
            
            // 更新类型下拉
            allTypes = res.types || [];
            const filterType = document.getElementById('filterType');
            const currentVal = filterType.value;
            filterType.innerHTML = '<option value="">全部</option>' + 
                allTypes.map(t => '<option value="' + esc(t) + '"' + (t === currentVal ? ' selected' : '') + '>' + esc(t) + '</option>').join('');
            document.getElementById('existingTypes').textContent = allTypes.join('、') || '无';
            
            // 渲染表格
            const tbody = document.getElementById('dictTableBody');
            if (!res.data || res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无数据</td></tr>';
                return;
            }
            
            tbody.innerHTML = res.data.map(r => `
                <tr>
                    <td>${r.id}</td>
                    <td><span class="badge bg-secondary">${esc(r.dict_type)}</span></td>
                    <td><code>${esc(r.dict_code)}</code></td>
                    <td>${esc(r.dict_label)}</td>
                    <td>${r.sort_order}</td>
                    <td>${r.is_enabled == 1 ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">禁用</span>'}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editDict(${r.id}, '${esc(r.dict_type)}', '${esc(r.dict_code)}', '${esc(r.dict_label)}', ${r.sort_order}, ${r.is_enabled})">编辑</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDict(${r.id})">删除</button>
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => showAlertModal('加载失败', 'error'));
}

function openDictModal() {
    document.getElementById('dictId').value = '0';
    document.getElementById('dictType').value = '';
    document.getElementById('dictCode').value = '';
    document.getElementById('dictLabel').value = '';
    document.getElementById('dictSortOrder').value = '0';
    document.getElementById('dictIsEnabled').value = '1';
    document.getElementById('dictModalTitle').textContent = '新增字典项';
    
    if (!dictModal) {
        dictModal = new bootstrap.Modal(document.getElementById('dictModal'));
    }
    dictModal.show();
}

function editDict(id, type, code, label, sortOrder, isEnabled) {
    document.getElementById('dictId').value = String(id);
    document.getElementById('dictType').value = type;
    document.getElementById('dictCode').value = code;
    document.getElementById('dictLabel').value = label;
    document.getElementById('dictSortOrder').value = String(sortOrder);
    document.getElementById('dictIsEnabled').value = String(isEnabled);
    document.getElementById('dictModalTitle').textContent = '编辑字典项';
    
    if (!dictModal) {
        dictModal = new bootstrap.Modal(document.getElementById('dictModal'));
    }
    dictModal.show();
}

function saveDict() {
    const id = document.getElementById('dictId').value;
    const dictType = document.getElementById('dictType').value.trim();
    const dictCode = document.getElementById('dictCode').value.trim();
    const dictLabel = document.getElementById('dictLabel').value.trim();
    const sortOrder = document.getElementById('dictSortOrder').value;
    const isEnabled = document.getElementById('dictIsEnabled').value;
    
    if (!dictType || !dictCode || !dictLabel) {
        showAlertModal('类型、代码和名称不能为空', 'warning');
        return;
    }
    
    const fd = new FormData();
    fd.append('id', id);
    fd.append('dict_type', dictType);
    fd.append('dict_code', dictCode);
    fd.append('dict_label', dictLabel);
    fd.append('sort_order', sortOrder);
    fd.append('is_enabled', isEnabled);
    
    fetch(apiUrl('system_dict_save.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '保存失败', 'error');
                return;
            }
            if (dictModal) dictModal.hide();
            showAlertModal('保存成功', 'success', loadDictList);
        })
        .catch(() => showAlertModal('保存失败', 'error'));
}

function deleteDict(id) {
    showConfirmModal('删除字典项', '确定要删除这个字典项吗？', function() {
        const fd = new FormData();
        fd.append('id', String(id));
        
        fetch(apiUrl('system_dict_delete.php'), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showAlertModal(res.message || '删除失败', 'error');
                    return;
                }
                showAlertModal('删除成功', 'success', loadDictList);
            })
            .catch(() => showAlertModal('删除失败', 'error'));
    });
}

// 初始加载
loadDictList();
</script>

<?php layout_footer(); ?>
