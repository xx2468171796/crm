<?php
/**
 * 管理员查看已删除交付物页面
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
if (!RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

layout_header('已删除交付物管理');
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
.text-muted-small {
    font-size: 12px;
    color: #6c757d;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="bi bi-trash text-danger"></i> 已删除交付物管理
            </h2>
            <p class="text-muted mb-0">查看和管理已删除的项目交付物（回收站）</p>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-2">
                    <input type="text" class="form-control" id="filterProjectId" placeholder="项目ID" value="">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control" id="filterKeyword" placeholder="文件名关键词" value="">
                </div>
                <div class="col-md-2">
                    <select class="form-select" id="filterCategory">
                        <option value="">全部类型</option>
                        <option value="artwork_file">作品文件</option>
                        <option value="model_file">模型文件</option>
                    </select>
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
                        <i class="bi bi-trash"></i> 永久删除
                    </button>
                    <button type="button" class="btn btn-warning ms-2" onclick="clearExpired()">
                        <i class="bi bi-calendar-x"></i> 清空过期文件
                    </button>
                    <span class="ms-3 text-muted" id="selectedCount">已选择 0 个文件</span>
                </div>
                <div>
                    <span class="text-muted me-3" id="totalInfo">共 0 个文件</span>
                    <button type="button" class="btn btn-secondary" onclick="loadFiles()">
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
                            <th>ID</th>
                            <th>项目</th>
                            <th>文件名</th>
                            <th>类型</th>
                            <th>分类</th>
                            <th>上传时间</th>
                            <th>上传人</th>
                            <th>删除时间</th>
                            <th>剩余天数</th>
                            <th style="width: 150px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fileListBody">
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let selectedFileIds = new Set();
let allFiles = [];

// 加载文件列表
function loadFiles() {
    const projectId = document.getElementById('filterProjectId').value.trim();
    const keyword = document.getElementById('filterKeyword').value.trim();
    const category = document.getElementById('filterCategory').value;
    
    let url = `${API_URL}/deliverables.php?action=trash`;
    if (projectId) url += `&project_id=${projectId}`;
    if (category) url += `&file_category=${category}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allFiles = data.data || [];
                // 客户端过滤关键词
                if (keyword) {
                    allFiles = allFiles.filter(f => 
                        f.deliverable_name && f.deliverable_name.toLowerCase().includes(keyword.toLowerCase())
                    );
                }
                renderFiles(allFiles);
                document.getElementById('totalInfo').textContent = `共 ${allFiles.length} 个文件`;
            } else {
                showAlertModal('加载失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('加载失败，请稍后重试', 'error');
        });
}

// 渲染文件列表
function renderFiles(files) {
    const tbody = document.getElementById('fileListBody');
    
    if (files.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-5 text-muted">暂无已删除的交付物</td></tr>';
        return;
    }
    
    tbody.innerHTML = files.map(file => {
        const deletedAt = file.deleted_at ? new Date(file.deleted_at * 1000).toLocaleString('zh-CN') : '-';
        const createTime = file.create_time ? new Date(file.create_time * 1000).toLocaleString('zh-CN') : '-';
        const isSelected = selectedFileIds.has(file.id);
        const categoryText = file.file_category === 'artwork_file' ? '作品文件' : (file.file_category === 'customer_file' ? '客户文件' : '模型文件');
        const categoryClass = file.file_category === 'artwork_file' ? 'bg-primary' : (file.file_category === 'customer_file' ? 'bg-success' : 'bg-info');
        
        // 计算剩余天数（30天保留期）
        let daysRemaining = 30;
        let daysClass = 'text-success';
        if (file.deleted_at) {
            const deletedTime = file.deleted_at * 1000;
            const expireTime = deletedTime + (30 * 24 * 60 * 60 * 1000);
            daysRemaining = Math.max(0, Math.ceil((expireTime - Date.now()) / (24 * 60 * 60 * 1000)));
            if (daysRemaining <= 7) daysClass = 'text-danger fw-bold';
            else if (daysRemaining <= 14) daysClass = 'text-warning';
        }
        
        return `
            <tr>
                <td>
                    <input type="checkbox" class="file-checkbox" value="${file.id}" 
                           ${isSelected ? 'checked' : ''} onchange="toggleFileSelect(${file.id})">
                </td>
                <td>${file.id}</td>
                <td>
                    <div>${escapeHtml(file.project_name || '未知项目')}</div>
                    <div class="text-muted-small">${escapeHtml(file.project_code || '')}</div>
                </td>
                <td>
                    <div>${escapeHtml(file.deliverable_name || '')}</div>
                    ${file.is_folder ? '<span class="badge bg-secondary">文件夹</span>' : ''}
                </td>
                <td>${escapeHtml(file.deliverable_type || '-')}</td>
                <td><span class="badge ${categoryClass}">${categoryText}</span></td>
                <td>${createTime}</td>
                <td>${escapeHtml(file.submitted_by_name || '-')}</td>
                <td>${deletedAt}</td>
                <td><span class="${daysClass}">${daysRemaining} 天</span></td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="restoreFile(${file.id}, '${escapeHtml(file.deliverable_name)}')" title="恢复">
                        <i class="bi bi-arrow-counterclockwise"></i> 恢复
                    </button>
                    <button class="btn btn-sm btn-danger ms-1" onclick="deleteFilePermanent(${file.id}, '${escapeHtml(file.deliverable_name)}')" title="永久删除">
                        <i class="bi bi-trash"></i> 删除
                    </button>
                </td>
            </tr>
        `;
    }).join('');
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
}

// 恢复单个文件
function restoreFile(fileId, filename) {
    showConfirmModal('恢复文件', `确定要恢复 "${filename}" 吗？`, function() {
        fetch(`${API_URL}/deliverables.php?action=restore`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: fileId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlertModal('恢复成功', 'success');
                loadFiles();
            } else {
                showAlertModal('恢复失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('恢复失败', 'error');
        });
    });
}

// 永久删除单个文件
function deleteFilePermanent(fileId, filename) {
    showConfirmModal('⚠️ 永久删除', `确定要永久删除 "${filename}" 吗？<br><br><strong class="text-danger">此操作将同时删除 S3 存储中的文件，无法恢复！</strong>`, function() {
        fetch(`${API_URL}/deliverables.php?id=${fileId}&permanent=true`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlertModal('已永久删除', 'success');
                loadFiles();
            } else {
                showAlertModal('删除失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('删除失败', 'error');
        });
    });
}

// 批量恢复
function batchRestore() {
    if (selectedFileIds.size === 0) return;
    
    showConfirmModal('批量恢复', `确定要恢复选中的 ${selectedFileIds.size} 个文件吗？`, function() {
        const promises = Array.from(selectedFileIds).map(id => 
            fetch(`${API_URL}/deliverables.php?action=restore`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).then(r => r.json())
        );
        
        Promise.all(promises).then(results => {
            const successCount = results.filter(r => r.success).length;
            showAlertModal(`成功恢复 ${successCount} 个文件`, 'success');
            selectedFileIds.clear();
            loadFiles();
        });
    });
}

// 批量永久删除
function batchDelete() {
    if (selectedFileIds.size === 0) return;
    
    showConfirmModal('⚠️ 批量永久删除', `确定要永久删除选中的 ${selectedFileIds.size} 个文件吗？<br><br><strong class="text-danger">此操作将同时删除 S3 存储中的文件，无法恢复！</strong>`, function() {
        const promises = Array.from(selectedFileIds).map(id => 
            fetch(`${API_URL}/deliverables.php?id=${id}&permanent=true`, {
                method: 'DELETE'
            }).then(r => r.json())
        );
        
        Promise.all(promises).then(results => {
            const successCount = results.filter(r => r.success).length;
            showAlertModal(`成功删除 ${successCount} 个文件`, 'success');
            selectedFileIds.clear();
            loadFiles();
        });
    });
}

// 清空过期文件（30天前删除的）
function clearExpired() {
    showConfirmModal('⚠️ 清空过期文件', '确定要清空所有已过期（超过30天）的文件吗？<br><br><strong class="text-danger">此操作将同时删除 S3 存储中的文件，无法恢复！</strong>', function() {
        fetch(`${API_URL}/admin_recycle_bin.php?action=empty`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlertModal(`${data.message}`, 'success');
                loadFiles();
            } else {
                showAlertModal('清空失败: ' + (data.error || data.message), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('清空失败', 'error');
        });
    });
}

// HTML转义
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', function() {
    loadFiles();
});
</script>

<?php
layout_footer();
?>
