<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();

$user = current_user();
if (!$user || !isAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

layout_header('分享节点管理');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-globe me-2"></i>分享节点管理</h4>
        <button class="btn btn-primary" id="addNodeBtn" onclick="window.showEditModal()">
            <i class="bi bi-plus-lg me-1"></i>添加节点
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="regionsTable">
                    <thead>
                        <tr>
                            <th>排序</th>
                            <th>区域名称</th>
                            <th>域名</th>
                            <th>端口</th>
                            <th>协议</th>
                            <th>状态</th>
                            <th>默认</th>
                            <th>预览链接</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="regionsList"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 编辑弹窗 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalTitle">添加节点</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label">区域名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="region_name" id="editRegionName" placeholder="如：中国大陆、台湾" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">域名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="domain" id="editDomain" placeholder="如：space.ankotti.com" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">端口</label>
                            <input type="number" class="form-control" name="port" id="editPort" placeholder="留空则不拼接">
                            <small class="text-muted">留空表示使用默认端口</small>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">协议</label>
                            <select class="form-select" name="protocol" id="editProtocol">
                                <option value="https">HTTPS</option>
                                <option value="http">HTTP</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" class="form-control" name="sort_order" id="editSortOrder" value="0">
                            <small class="text-muted">数字越小越靠前</small>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">状态</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="1">启用</option>
                                <option value="0">禁用</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_default" id="editIsDefault" value="1">
                            <label class="form-check-label" for="editIsDefault">设为默认推荐</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">链接预览</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                            <input type="text" class="form-control" id="linkPreview" readonly>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveRegion()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const SHARE_REGIONS_CSRF = '<?= csrf_token() ?>';

function updateLinkPreview() {
    const protocol = document.getElementById('editProtocol').value;
    const domain = document.getElementById('editDomain').value;
    const port = document.getElementById('editPort').value;
    
    let url = `${protocol}://${domain || 'example.com'}`;
    if (port) url += `:${port}`;
    url += '/share/{token}';
    
    document.getElementById('linkPreview').value = url;
}

async function loadRegions() {
    try {
        const res = await fetch('/api/share_regions.php?action=list');
        const data = await res.json();
        if (data.success) {
            renderRegions(data.data);
        }
    } catch (e) {
        console.error('加载失败:', e);
    }
}

function renderRegions(regions) {
    const tbody = document.getElementById('regionsList');
    if (!regions || regions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">暂无节点配置</td></tr>';
        return;
    }
    
    tbody.innerHTML = regions.map(r => {
        let previewUrl = `${r.protocol}://${r.domain}`;
        if (r.port) previewUrl += `:${r.port}`;
        previewUrl += '/share/{token}';
        
        return `
        <tr>
            <td>${r.sort_order}</td>
            <td><strong>${esc(r.region_name)}</strong></td>
            <td><code>${esc(r.domain)}</code></td>
            <td>${r.port || '<span class="text-muted">-</span>'}</td>
            <td><span class="badge bg-${r.protocol === 'https' ? 'success' : 'warning'}">${r.protocol.toUpperCase()}</span></td>
            <td>
                <span class="badge bg-${r.status == 1 ? 'success' : 'secondary'}" style="cursor:pointer" onclick="toggleStatus(${r.id})">
                    ${r.status == 1 ? '启用' : '禁用'}
                </span>
            </td>
            <td>${r.is_default == 1 ? '<i class="bi bi-star-fill text-warning"></i>' : ''}</td>
            <td><small class="text-muted">${esc(previewUrl)}</small></td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="showEditModal(${r.id})"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteRegion(${r.id})"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
        `;
    }).join('');
}

function showEditModal(id = 0) {
    document.getElementById('editModalTitle').textContent = id ? '编辑节点' : '添加节点';
    document.getElementById('editForm').reset();
    document.getElementById('editId').value = id;
    
    if (id) {
        fetch(`/api/share_regions.php?action=get&id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const r = data.data;
                    document.getElementById('editRegionName').value = r.region_name;
                    document.getElementById('editDomain').value = r.domain;
                    document.getElementById('editPort').value = r.port || '';
                    document.getElementById('editProtocol').value = r.protocol;
                    document.getElementById('editSortOrder').value = r.sort_order;
                    document.getElementById('editStatus').value = r.status;
                    document.getElementById('editIsDefault').checked = r.is_default == 1;
                    updateLinkPreview();
                }
            });
    } else {
        updateLinkPreview();
    }
    
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
}

async function saveRegion() {
    const form = document.getElementById('editForm');
    const fd = new FormData(form);
    fd.append('_csrf', SHARE_REGIONS_CSRF);
    fd.append('is_default', document.getElementById('editIsDefault').checked ? 1 : 0);
    
    try {
        const res = await fetch('/api/share_regions.php?action=save', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
            loadRegions();
        } else {
            alert(data.message);
        }
    } catch (e) {
        alert('保存失败');
    }
}

async function toggleStatus(id) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('_csrf', SHARE_REGIONS_CSRF);
    
    try {
        const res = await fetch('/api/share_regions.php?action=toggle', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();
        if (data.success) {
            loadRegions();
        }
    } catch (e) {
        console.error(e);
    }
}

async function deleteRegion(id) {
    if (!confirm('确定要删除此节点吗？')) return;
    
    const fd = new FormData();
    fd.append('id', id);
    fd.append('_csrf', SHARE_REGIONS_CSRF);
    
    try {
        const res = await fetch('/api/share_regions.php?action=delete', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();
        if (data.success) {
            loadRegions();
        } else {
            alert(data.message);
        }
    } catch (e) {
        alert('删除失败');
    }
}

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// 将函数暴露到全局作用域
window.showEditModal = showEditModal;
window.saveRegion = saveRegion;
window.toggleStatus = toggleStatus;
window.deleteRegion = deleteRegion;

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    // 绑定添加按钮点击事件
    document.getElementById('addNodeBtn').addEventListener('click', function() {
        showEditModal();
    });
    
    // 加载数据
    loadRegions();
});
</script>

<?php layout_footer(); ?>
