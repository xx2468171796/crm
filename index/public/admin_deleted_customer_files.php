<?php
/**
 * 管理员查看已删除客户文件页面
 * 权限：仅系统管理员可访问
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('customer_delete') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

layout_header('已删除客户文件管理');
?>

<style>
.file-table {
    background: #fff;
    border: 1px solid #dee2e6;
}
.file-table th {
    background: #f8f9fa;
    font-weight: 600;
    padding: 12px 8px;
    border-bottom: 2px solid #dee2e6;
    font-size: 14px;
    white-space: nowrap;
}
.file-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 13px;
}
.file-table tr:hover {
    background: #f8f9fa;
}
.badge-deleted {
    background-color: #dc3545;
    color: white;
}
.text-muted-small {
    font-size: 12px;
    color: #6c757d;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="bi bi-trash text-danger"></i> 已删除客户文件管理
            </h2>
            <p class="text-muted mb-0">查看和管理已删除客户的文件（15天保留期）</p>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-2">
                    <input type="text" class="form-control" id="filterCustomerId" placeholder="客户ID" value="">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control" id="filterKeyword" placeholder="文件名关键词" value="">
                </div>
                <div class="col-md-2">
                    <select class="form-select" id="filterCategory">
                        <option value="">全部类型</option>
                        <option value="client_material">客户文件</option>
                        <option value="internal_solution">公司文件</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" id="filterDeletedStart" placeholder="删除开始日期">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" id="filterDeletedEnd" placeholder="删除结束日期">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-primary w-100" onclick="loadFiles()">
                        <i class="bi bi-search"></i> 搜索
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 操作栏 -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <button type="button" class="btn btn-success" onclick="batchRestore()" id="batchRestoreBtn" disabled>
                        <i class="bi bi-arrow-counterclockwise"></i> 批量恢复
                    </button>
                    <button type="button" class="btn btn-danger ms-2" onclick="batchDelete()" id="batchDeleteBtn" disabled>
                        <i class="bi bi-trash"></i> 批量删除
                    </button>
                    <span class="ms-3 text-muted" id="selectedCount">已选择 0 个文件</span>
                </div>
                <div>
                    <span class="text-muted me-3" id="totalInfo">共 0 个文件</span>
                    <button type="button" class="btn btn-secondary" onclick="refreshFiles()">
                        <i class="bi bi-arrow-clockwise"></i> 刷新
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 文件列表 -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover file-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>文件ID</th>
                            <th>客户信息</th>
                            <th>文件名</th>
                            <th>类型</th>
                            <th>大小</th>
                            <th>上传时间</th>
                            <th>上传人</th>
                            <th>删除时间</th>
                            <th>删除人</th>
                            <th style="width: 120px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fileListBody">
                        <tr>
                            <td colspan="11" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            <nav>
                <ul class="pagination justify-content-center mb-0" id="pagination">
                </ul>
            </nav>
        </div>
    </div>
</div>

<!-- 删除确认模态框 -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> <strong>警告：</strong>此操作将永久删除文件，无法恢复！
                </div>
                <p id="deleteConfirmMessage">确定要删除选中的文件吗？</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">确认删除</button>
            </div>
        </div>
    </div>
</div>

<script>
// API_URL 已在 layout.php 中全局声明，直接使用即可
let currentPage = 1;
let pageSize = 20;
let totalFiles = 0;
let selectedFileIds = new Set();
let pendingDeleteIds = [];

// 加载文件列表
function loadFiles(page = 1) {
    currentPage = page;
    
    const filters = {
        page: currentPage,
        page_size: pageSize
    };
    
    const customerId = document.getElementById('filterCustomerId').value.trim();
    if (customerId) {
        filters.customer_id = parseInt(customerId);
    }
    
    const keyword = document.getElementById('filterKeyword').value.trim();
    if (keyword) {
        filters.keyword = keyword;
    }
    
    const category = document.getElementById('filterCategory').value;
    if (category) {
        filters.category = category;
    }
    
    const deletedStart = document.getElementById('filterDeletedStart').value;
    if (deletedStart) {
        filters.deleted_start_at = deletedStart;
    }
    
    const deletedEnd = document.getElementById('filterDeletedEnd').value;
    if (deletedEnd) {
        filters.deleted_end_at = deletedEnd;
    }
    
    const queryString = new URLSearchParams(filters).toString();
    
    fetch(`${API_URL}/admin_deleted_customer_files.php?${queryString}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderFiles(data.data.items);
                renderPagination(data.data.pagination);
                totalFiles = data.data.pagination.total;
                updateTotalInfo();
            } else {
                alert('加载失败: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('加载失败，请稍后重试');
        });
}

// 渲染文件列表
function renderFiles(files) {
    const tbody = document.getElementById('fileListBody');
    
    if (files.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5 text-muted">暂无数据</td></tr>';
        return;
    }
    
    tbody.innerHTML = files.map(file => {
        const deletedAt = file.deleted_at ? new Date(file.deleted_at * 1000).toLocaleString('zh-CN') : '-';
        const uploadedAt = file.uploaded_at ? new Date(file.uploaded_at * 1000).toLocaleString('zh-CN') : '-';
        const fileSize = formatBytes(file.filesize);
        const isSelected = selectedFileIds.has(file.id);
        
        return `
            <tr>
                <td>
                    <input type="checkbox" class="file-checkbox" value="${file.id}" 
                           ${isSelected ? 'checked' : ''} onchange="toggleFileSelect(${file.id})">
                </td>
                <td>${file.id}</td>
                <td>
                    <div>ID: ${file.customer_id}</div>
                    ${file.customer_name ? `<div class="text-muted-small">${escapeHtml(file.customer_name)}</div>` : ''}
                    ${file.customer_code ? `<div class="text-muted-small">${escapeHtml(file.customer_code)}</div>` : ''}
                </td>
                <td>
                    <div>${escapeHtml(file.filename)}</div>
                    ${file.folder_path ? `<div class="text-muted-small">${escapeHtml(file.folder_path)}</div>` : ''}
                </td>
                <td>
                    <span class="badge ${file.category === 'client_material' ? 'bg-primary' : 'bg-info'}">
                        ${file.category === 'client_material' ? '客户文件' : '公司文件'}
                    </span>
                </td>
                <td>${fileSize}</td>
                <td>
                    <div>${uploadedAt}</div>
                    ${file.uploaded_by_name ? `<div class="text-muted-small">${escapeHtml(file.uploaded_by_name)}</div>` : ''}
                </td>
                <td>${file.uploaded_by_name || '-'}</td>
                <td>
                    <div>${deletedAt}</div>
                    ${file.deleted_by_name ? `<div class="text-muted-small">${escapeHtml(file.deleted_by_name)}</div>` : ''}
                </td>
                <td>${file.deleted_by_name || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="restoreFile(${file.id}, '${escapeHtml(file.filename)}')" title="恢复文件">
                        <i class="bi bi-arrow-counterclockwise"></i> 恢复
                    </button>
                    <button class="btn btn-sm btn-danger ms-1" onclick="deleteFile(${file.id}, '${escapeHtml(file.filename)}')" title="永久删除">
                        <i class="bi bi-trash"></i> 删除
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

// 渲染分页
function renderPagination(pagination) {
    const paginationEl = document.getElementById('pagination');
    const totalPages = Math.ceil(pagination.total / pagination.page_size);
    
    if (totalPages <= 1) {
        paginationEl.innerHTML = '';
        return;
    }
    
    let html = '';
    
    // 上一页
    html += `<li class="page-item ${pagination.page <= 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadFiles(${pagination.page - 1}); return false;">上一页</a>
    </li>`;
    
    // 页码
    const startPage = Math.max(1, pagination.page - 2);
    const endPage = Math.min(totalPages, pagination.page + 2);
    
    if (startPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadFiles(1); return false;">1</a></li>`;
        if (startPage > 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i === pagination.page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadFiles(${i}); return false;">${i}</a>
        </li>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        html += `<li class="page-item"><a class="page-link" href="#" onclick="loadFiles(${totalPages}); return false;">${totalPages}</a></li>`;
    }
    
    // 下一页
    html += `<li class="page-item ${pagination.page >= totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="loadFiles(${pagination.page + 1}); return false;">下一页</a>
    </li>`;
    
    paginationEl.innerHTML = html;
}

// 切换全选
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll').checked;
    const checkboxes = document.querySelectorAll('.file-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll;
        const fileId = parseInt(checkbox.value);
        if (selectAll) {
            selectedFileIds.add(fileId);
        } else {
            selectedFileIds.delete(fileId);
        }
    });
    
    updateSelectionUI();
}

// 切换单个文件选择
function toggleFileSelect(fileId) {
    const checkbox = document.querySelector(`.file-checkbox[value="${fileId}"]`);
    if (checkbox.checked) {
        selectedFileIds.add(fileId);
    } else {
        selectedFileIds.delete(fileId);
    }
    updateSelectionUI();
}

// 更新选择UI
function updateSelectionUI() {
    const count = selectedFileIds.size;
    document.getElementById('selectedCount').textContent = `已选择 ${count} 个文件`;
    document.getElementById('batchRestoreBtn').disabled = count === 0;
    document.getElementById('batchDeleteBtn').disabled = count === 0;
    
    // 更新全选状态
    const checkboxes = document.querySelectorAll('.file-checkbox');
    const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
    document.getElementById('selectAll').checked = allChecked;
}

// 恢复单个文件
function restoreFile(fileId, filename) {
    showConfirmModal('恢复文件', `确定要恢复文件 "${filename}" 吗？`, function() {
        const formData = new FormData();
        formData.append('file_ids', JSON.stringify([fileId]));
        
        fetch(`${API_URL}/customer_file_restore.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlertModal('文件恢复成功', 'success');
                loadFiles(currentPage);
            } else {
                showAlertModal('恢复失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('恢复失败，请稍后重试', 'error');
        });
    });
}

// 批量恢复
function batchRestore() {
    if (selectedFileIds.size === 0) {
        alert('请先选择要恢复的文件');
        return;
    }
    
    showConfirmModal('批量恢复', `确定要恢复选中的 ${selectedFileIds.size} 个文件吗？`, function() {
        doBatchRestore();
    });
}

function doBatchRestore() {
    
    const formData = new FormData();
    formData.append('file_ids', JSON.stringify(Array.from(selectedFileIds)));
    
    fetch(`${API_URL}/customer_file_restore.php`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`成功恢复 ${data.data.restored_count} 个文件`);
            selectedFileIds.clear();
            loadFiles(currentPage);
        } else {
            alert('恢复失败: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('恢复失败，请稍后重试');
    });
}

// 删除单个文件
function deleteFile(fileId, filename) {
    pendingDeleteIds = [fileId];
    document.getElementById('deleteConfirmMessage').textContent = 
        `确定要删除文件 "${filename}" 吗？此操作将永久删除文件，无法恢复！`;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

// 批量删除
function batchDelete() {
    if (selectedFileIds.size === 0) {
        alert('请先选择要删除的文件');
        return;
    }
    
    pendingDeleteIds = Array.from(selectedFileIds);
    document.getElementById('deleteConfirmMessage').textContent = 
        `确定要删除选中的 ${pendingDeleteIds.length} 个文件吗？此操作将永久删除文件，无法恢复！`;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

// 确认删除
function confirmDelete() {
    if (pendingDeleteIds.length === 0) {
        return;
    }
    
    const formData = new FormData();
    if (pendingDeleteIds.length === 1) {
        formData.append('file_id', pendingDeleteIds[0]);
    } else {
        formData.append('file_ids', JSON.stringify(pendingDeleteIds));
    }
    
    fetch(`${API_URL}/admin_deleted_customer_file_delete.php`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            selectedFileIds.clear();
            loadFiles(currentPage);
            bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
        } else {
            alert('删除失败: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('删除失败，请稍后重试');
    });
}

// 刷新文件列表
function refreshFiles() {
    loadFiles(currentPage);
}

// 更新总数信息
function updateTotalInfo() {
    document.getElementById('totalInfo').textContent = `共 ${totalFiles} 个文件`;
}

// 格式化文件大小
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// HTML转义
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', function() {
    loadFiles(1);
    
    // 回车搜索
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        loadFiles(1);
    });
});
</script>

<?php
layout_footer();
?>

