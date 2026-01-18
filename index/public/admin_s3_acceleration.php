<?php
require_once __DIR__ . '/../core/layout.php';

renderHeader('S3 加速节点管理');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-speedometer2 me-2"></i>S3 加速节点管理
        </h4>
        <button class="btn btn-primary" onclick="showModal()">
            <i class="bi bi-plus-lg me-1"></i>添加节点
        </button>
    </div>

    <div class="card">
        <div class="card-header bg-light">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                加速节点用于桌面端文件上传/下载加速，通过反向代理实现多区域加速访问
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="60">排序</th>
                            <th>节点名称</th>
                            <th>加速端点URL</th>
                            <th width="100">区域代码</th>
                            <th width="80">默认</th>
                            <th width="80">状态</th>
                            <th width="150">操作</th>
                        </tr>
                    </thead>
                    <tbody id="nodeList">
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-hourglass-split me-1"></i>加载中...
                            </td>
                        </tr>
                    </tbody>
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
                <h5 class="modal-title" id="modalTitle">添加节点</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="nodeForm">
                    <input type="hidden" id="nodeId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">节点名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nodeName" placeholder="如: 中国大陆加速、台湾加速" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">加速端点URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="endpointUrl" placeholder="https://proxy.example.com" required>
                        <div class="form-text">反向代理地址，用于替换原始S3端点</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">区域代码</label>
                        <input type="text" class="form-control" id="regionCode" placeholder="如: cn, tw, us">
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">排序</label>
                                <input type="number" class="form-control" id="sortOrder" value="0" min="0">
                                <div class="form-text">数字越小越靠前</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">状态</label>
                                <select class="form-select" id="status">
                                    <option value="1">启用</option>
                                    <option value="0">禁用</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="isDefault">
                            <label class="form-check-label" for="isDefault">设为默认节点</label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">备注说明</label>
                        <textarea class="form-control" id="description" rows="2" placeholder="可选"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveNode()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const modal = new bootstrap.Modal(document.getElementById('editModal'));

async function loadNodes() {
    try {
        const res = await fetch('/api/s3_acceleration_nodes.php?action=list_all');
        const data = await res.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        const tbody = document.getElementById('nodeList');
        
        if (!data.data || data.data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox me-1"></i>暂无加速节点，点击右上角添加
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = data.data.map(node => `
            <tr>
                <td>${node.sort_order}</td>
                <td>
                    <strong>${escapeHtml(node.node_name)}</strong>
                    ${node.description ? `<br><small class="text-muted">${escapeHtml(node.description)}</small>` : ''}
                </td>
                <td>
                    <code class="small">${escapeHtml(node.endpoint_url)}</code>
                </td>
                <td>${node.region_code || '-'}</td>
                <td>
                    ${node.is_default == 1 ? '<span class="badge bg-warning">⭐ 默认</span>' : '-'}
                </td>
                <td>
                    <span class="badge ${node.status == 1 ? 'bg-success' : 'bg-secondary'}" 
                          style="cursor:pointer" onclick="toggleStatus(${node.id})">
                        ${node.status == 1 ? '启用' : '禁用'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editNode(${node.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteNode(${node.id}, '${escapeHtml(node.node_name)}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        document.getElementById('nodeList').innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>加载失败: ${err.message}
                </td>
            </tr>
        `;
    }
}

function showModal(id = 0) {
    document.getElementById('modalTitle').textContent = id ? '编辑节点' : '添加节点';
    document.getElementById('nodeId').value = id || '';
    document.getElementById('nodeName').value = '';
    document.getElementById('endpointUrl').value = '';
    document.getElementById('regionCode').value = '';
    document.getElementById('sortOrder').value = '0';
    document.getElementById('status').value = '1';
    document.getElementById('isDefault').checked = false;
    document.getElementById('description').value = '';
    modal.show();
}

async function editNode(id) {
    try {
        const res = await fetch(`/api/s3_acceleration_nodes.php?action=get&id=${id}`);
        const data = await res.json();
        
        if (!data.success) {
            throw new Error(data.message);
        }
        
        const node = data.data;
        document.getElementById('modalTitle').textContent = '编辑节点';
        document.getElementById('nodeId').value = node.id;
        document.getElementById('nodeName').value = node.node_name;
        document.getElementById('endpointUrl').value = node.endpoint_url;
        document.getElementById('regionCode').value = node.region_code || '';
        document.getElementById('sortOrder').value = node.sort_order;
        document.getElementById('status').value = node.status;
        document.getElementById('isDefault').checked = node.is_default == 1;
        document.getElementById('description').value = node.description || '';
        modal.show();
    } catch (err) {
        alert('获取节点信息失败: ' + err.message);
    }
}

async function saveNode() {
    const data = {
        id: document.getElementById('nodeId').value || 0,
        node_name: document.getElementById('nodeName').value.trim(),
        endpoint_url: document.getElementById('endpointUrl').value.trim(),
        region_code: document.getElementById('regionCode').value.trim(),
        sort_order: parseInt(document.getElementById('sortOrder').value) || 0,
        status: parseInt(document.getElementById('status').value),
        is_default: document.getElementById('isDefault').checked ? 1 : 0,
        description: document.getElementById('description').value.trim()
    };
    
    if (!data.node_name) {
        alert('请输入节点名称');
        return;
    }
    
    if (!data.endpoint_url) {
        alert('请输入加速端点URL');
        return;
    }
    
    try {
        const res = await fetch('/api/s3_acceleration_nodes.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        
        if (!result.success) {
            throw new Error(result.message);
        }
        
        modal.hide();
        loadNodes();
    } catch (err) {
        alert('保存失败: ' + err.message);
    }
}

async function deleteNode(id, name) {
    if (!confirm(`确定要删除节点 "${name}" 吗？`)) {
        return;
    }
    
    try {
        const res = await fetch('/api/s3_acceleration_nodes.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await res.json();
        
        if (!result.success) {
            throw new Error(result.message);
        }
        
        loadNodes();
    } catch (err) {
        alert('删除失败: ' + err.message);
    }
}

async function toggleStatus(id) {
    try {
        const res = await fetch('/api/s3_acceleration_nodes.php?action=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await res.json();
        
        if (!result.success) {
            throw new Error(result.message);
        }
        
        loadNodes();
    } catch (err) {
        alert('切换状态失败: ' + err.message);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
}

// 页面加载时获取数据
loadNodes();
</script>

<?php renderFooter(); ?>
