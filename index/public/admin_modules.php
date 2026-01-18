<?php
/**
 * 菜单管理页面
 * 管理系统菜单（首通、异议、成交、自评等）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 检查登录
if (!is_logged_in()) {
    redirect('/login.php');
}

// 检查管理员权限
$user = current_user();
if (!isAdmin($user)) {
    layout_header('无权限');
    echo '<div class="container mt-5"><div class="alert alert-danger">无权限访问此页面</div></div>';
    layout_footer();
    exit;
}

layout_header('菜单管理');
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2>菜单管理</h2>
                <button class="btn btn-primary" onclick="showAddModal()">
                    <i class="bi bi-plus-circle"></i> 添加菜单
                </button>
            </div>

            <div class="card">
                <div class="card-body">
                    <table class="table table-hover" id="moduleTable">
                        <thead>
                            <tr>
                                <th width="80">排序</th>
                                <th>菜单名称</th>
                                <th>菜单代码</th>
                                <th width="100">维度数量</th>
                                <th width="100">状态</th>
                                <th width="200">操作</th>
                            </tr>
                        </thead>
                        <tbody id="moduleList">
                            <tr id="loadingRow">
                                <td colspan="6" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">加载中...</span>
                                    </div>
                                    <div class="mt-2">正在加载模块数据...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑菜单对话框 -->
<div class="modal fade" id="moduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">添加菜单</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="moduleForm">
                    <input type="hidden" id="moduleId" name="id">
                    
                    <div class="mb-3">
                        <label for="moduleName" class="form-label">菜单名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="moduleName" name="module_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="moduleCode" class="form-label">菜单代码 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="moduleCode" name="module_code" required 
                               pattern="[a-z_]+" title="只能包含小写字母和下划线">
                        <small class="text-muted">只能包含小写字母和下划线，如：first_contact</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="moduleDescription" class="form-label">模块描述</label>
                        <textarea class="form-control" id="moduleDescription" name="description" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveModule()">保存</button>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>

<script>
let modules = [];
let currentModal = null;

// 页面加载时获取模块列表
$(document).ready(function() {
    console.log('页面加载完成，开始加载模块...');
    console.log('jQuery版本:', $.fn.jquery);
    loadModules();
});

/**
 * 加载模块列表
 */
function loadModules() {
    console.log('开始请求API:', '../api/module_manage.php?action=list');
    
    $.ajax({
        url: '../api/module_manage.php?action=list',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            console.log('API响应:', response);
            if (response.success) {
                modules = response.data;
                console.log('加载到的模块数量:', modules.length);
                renderModules();
            } else {
                console.error('API返回失败:', response.message);
                showToast(response.message || '加载失败', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX请求失败');
            console.error('状态:', status);
            console.error('错误:', error);
            console.error('响应状态码:', xhr.status);
            console.error('响应文本:', xhr.responseText);
            showToast('加载失败，请重试。错误: ' + xhr.status, 'error');
        }
    });
}

/**
 * 渲染模块列表
 */
function renderModules() {
    const tbody = $('#moduleList');
    
    if (modules.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted">暂无数据</td></tr>');
        return;
    }
    
    let html = '';
    modules.forEach(function(module) {
        const statusBadge = module.status == 1 
            ? '<span class="badge bg-success">启用</span>' 
            : '<span class="badge bg-secondary">禁用</span>';
        
        html += `
            <tr>
                <td>${module.sort_order}</td>
                <td>${escapeHtml(module.module_name)}</td>
                <td><code>${escapeHtml(module.module_code)}</code></td>
                <td class="text-center">${module.field_count || 0}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="manageFields(${module.id})">
                        管理维度
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="editModule(${module.id})">
                        编辑
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteModule(${module.id})">
                        删除
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
}

/**
 * 显示添加菜单对话框
 */
function showAddModal() {
    $('#modalTitle').text('添加菜单');
    $('#moduleForm')[0].reset();
    $('#moduleId').val('');
    $('#moduleCode').prop('readonly', false);
    
    currentModal = new bootstrap.Modal(document.getElementById('moduleModal'));
    currentModal.show();
}

/**
 * 编辑菜单
 */
function editModule(id) {
    const module = modules.find(m => m.id == id);
    if (!module) {
        showToast('菜单不存在', 'error');
        return;
    }
    
    $('#modalTitle').text('编辑菜单');
    $('#moduleId').val(module.id);
    $('#moduleName').val(module.module_name);
    $('#moduleCode').val(module.module_code).prop('readonly', true);
    $('#moduleDescription').val(module.description || '');
    
    currentModal = new bootstrap.Modal(document.getElementById('moduleModal'));
    currentModal.show();
}

/**
 * 保存模块
 */
function saveModule() {
    const form = $('#moduleForm')[0];
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const id = $('#moduleId').val();
    const data = {
        module_name: $('#moduleName').val(),
        module_code: $('#moduleCode').val(),
        description: $('#moduleDescription').val()
    };
    
    if (id) {
        data.id = id;
    }
    
    const action = id ? 'edit' : 'add';
    
    $.ajax({
        url: '../api/module_manage.php?action=' + action,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(data),
        success: function(response) {
            if (response.success) {
                showToast(response.message || '保存成功', 'success');
                currentModal.hide();
                loadModules();
            } else {
                showToast(response.message || '保存失败', 'error');
            }
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            showToast(response?.message || '保存失败，请重试', 'error');
        }
    });
}

/**
 * 删除模块
 */
function deleteModule(id) {
    const module = modules.find(m => m.id == id);
    if (!module) {
        showToast('模块不存在', 'error');
        return;
    }
    
    if (module.field_count > 0) {
        showToast(`该模块下还有 ${module.field_count} 个维度，无法删除`, 'error');
        return;
    }
    
    showConfirmModal('删除模块', `确定要删除模块"${module.module_name}"吗？`, function() {
        $.ajax({
            url: '../api/module_manage.php?action=delete',
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '删除成功', 'success');
                    loadModules();
                } else {
                    showToast(response.message || '删除失败', 'error');
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showToast(response?.message || '删除失败，请重试', 'error');
            }
        });
    });
}

/**
 * 管理维度
 */
function manageFields(moduleId) {
    window.location.href = 'index.php?page=admin_fields_new&module_id=' + moduleId;
}

/**
 * HTML转义
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * 显示提示消息
 */
function showToast(message, type = 'info') {
    const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
    const toast = $(`
        <div class="toast align-items-center text-white ${bgClass} border-0" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
            <div class="d-flex">
                <div class="toast-body">${escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `);
    
    $('body').append(toast);
    const bsToast = new bootstrap.Toast(toast[0], { delay: 3000 });
    bsToast.show();
    
    toast.on('hidden.bs.toast', function() {
        toast.remove();
    });
}
</script>
