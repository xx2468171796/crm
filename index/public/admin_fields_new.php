<?php
/**
 * 维度管理页面（带左侧模块导航）
 * 管理各模块下的维度（字段）
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

layout_header('维度管理');
?>

<style>
.sidebar {
    background: #f8f9fa;
    border-right: 1px solid #dee2e6;
    height: calc(100vh - 56px);
    overflow-y: auto;
    position: sticky;
    top: 56px;
}

.module-item {
    padding: 12px 16px;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.module-item:hover {
    background: #e9ecef;
}

.module-item.active {
    background: #007bff;
    color: white;
    border-left-color: #0056b3;
}

.module-count {
    font-size: 12px;
    opacity: 0.8;
}

.field-type-badge {
    font-size: 11px;
    padding: 2px 6px;
}
</style>

<div class="container-fluid">
    <div class="row">
        <!-- 左侧模块导航 -->
        <div class="col-md-2 px-0">
            <div class="sidebar">
                <div class="p-3 border-bottom">
                    <h6 class="mb-0">模块列表</h6>
                </div>
                <div id="moduleNav">
                    <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <div class="small text-muted mt-2">加载中...</div>
                    </div>
                </div>
                <div class="p-2 border-top">
                    <button class="btn btn-sm btn-outline-primary w-100" onclick="manageModules()">
                        <i class="bi bi-gear"></i> 模块管理
                    </button>
                </div>
            </div>
        </div>

        <!-- 右侧内容区 -->
        <div class="col-md-10">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 id="pageTitle">维度管理</h4>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="/admin_modules.php">模块管理</a></li>
                                <li class="breadcrumb-item active" id="breadcrumbModule">维度管理</li>
                            </ol>
                        </nav>
                    </div>
                    <button class="btn btn-primary" onclick="showAddModal()">
                        <i class="bi bi-plus-circle"></i> 添加维度
                    </button>
                </div>

                <div class="card">
                    <div class="card-body">
                        <table class="table table-hover" id="fieldTable">
                            <thead>
                                <tr>
                                    <th width="80">排序</th>
                                    <th>维度名称</th>
                                    <th>维度代码</th>
                                    <th width="100">字段类型</th>
                                    <th width="80">宽度</th>
                                    <th width="80">选项数</th>
                                    <th width="80">状态</th>
                                    <th width="180">操作</th>
                                </tr>
                            </thead>
                            <tbody id="fieldList">
                                <tr>
                                    <td colspan="8" class="text-center">请选择左侧模块</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑维度对话框 -->
<div class="modal fade" id="fieldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">添加维度</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="fieldForm">
                    <input type="hidden" id="fieldId" name="id">
                    <input type="hidden" id="moduleId" name="module_id">
                    
                    <!-- 层级说明 -->
                    <div class="alert alert-info mb-3">
                        <strong>📊 三层结构：</strong> 菜单 → <strong>维度</strong> → 字段
                        <br><small>维度是数据分类，如"身份"、"客户需求"等</small>
                    </div>
                    
                    <!-- 所属菜单 -->
                    <div class="mb-3">
                        <label class="form-label">所属菜单</label>
                        <input type="text" class="form-control" id="currentModuleName" readonly disabled>
                        <small class="text-muted">维度将添加到此菜单下</small>
                    </div>
                    
                    <!-- 维度信息 -->
                    <div class="mb-3">
                        <label for="fieldName" class="form-label">维度名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="fieldName" name="field_name" required placeholder="如：身份、客户需求">
                    </div>
                    
                    <div class="mb-3">
                        <label for="fieldCode" class="form-label">维度代码 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="fieldCode" name="field_code" required 
                               pattern="[a-z_]+" title="只能包含小写字母和下划线" placeholder="如：identity">
                        <small class="text-muted">只能包含小写字母和下划线</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="fieldDescription" class="form-label">维度描述</label>
                        <textarea class="form-control" id="fieldDescription" name="description" rows="2" placeholder="简要说明此维度的用途"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="sortOrder" class="form-label">排序</label>
                        <input type="number" class="form-control" id="sortOrder" name="sort_order" value="0" min="0">
                        <small class="text-muted">数字越小越靠前</small>
                    </div>
                    
                    <!-- 字段管理提示 -->
                    <div class="alert alert-secondary">
                        <i class="bi bi-info-circle"></i> 
                        保存维度后，点击<strong>"选项"</strong>按钮可以管理此维度下的字段（如：业主、设计师等）
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveField()">保存</button>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>

<script>
// 立即执行，确认脚本加载
console.log('=== 维度管理页面脚本开始加载 ===');

let modules = [];
let fields = [];
let currentModuleId = null;
let currentModal = null;

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
    
    // 使用简单的alert样式提示，避免存储权限问题
    const alertDiv = $(`
        <div class="alert ${bgClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 250px;">
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `);
    
    $('body').append(alertDiv);
    
    // 3秒后自动关闭
    setTimeout(function() {
        alertDiv.alert('close');
    }, 3000);
}

// 版本号，用于清除缓存
const VERSION = '20251120_v2';

// 页面加载时初始化
$(document).ready(function() {
    console.log('维度管理页面加载完成 - 版本:', VERSION);
    console.log('jQuery版本:', $.fn.jquery);
    
    // 从URL获取module_id
    const urlParams = new URLSearchParams(window.location.search);
    const moduleId = urlParams.get('module_id');
    if (moduleId) {
        currentModuleId = parseInt(moduleId);
        console.log('URL中的模块ID:', currentModuleId);
    }
    
    // 加载模块列表
    loadModules();
});

/**
 * 加载模块列表
 */
function loadModules() {
    console.log('开始加载模块列表...');
    
    $.ajax({
        url: '../api/module_manage.php?action=list&_v=' + VERSION,
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            console.log('模块API响应:', response);
            if (response.success) {
                modules = response.data;
                console.log('加载到的模块数量:', modules.length);
                renderModuleNav();
                
                // 如果有当前模块ID，加载该模块的字段
                if (currentModuleId) {
                    console.log('加载指定模块的字段:', currentModuleId);
                    loadFields(currentModuleId);
                } else if (modules.length > 0) {
                    console.log('默认选择第一个模块');
                    // 默认选择第一个模块
                    loadFields(modules[0].id);
                }
            } else {
                console.error('加载模块失败:', response.message);
                showToast(response.message || '加载模块失败', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX请求失败');
            console.error('状态:', status);
            console.error('错误:', error);
            console.error('响应状态码:', xhr.status);
            console.error('响应文本:', xhr.responseText);
            
            $('#moduleNav').html('<div class="p-3 text-center text-danger">加载失败，请刷新重试</div>');
            showToast('加载模块失败: ' + xhr.status, 'error');
        }
    });
}

/**
 * 渲染模块导航
 */
function renderModuleNav() {
    const nav = $('#moduleNav');
    
    if (modules.length === 0) {
        nav.html('<div class="p-3 text-center text-muted">暂无模块</div>');
        return;
    }
    
    let html = '';
    modules.forEach(function(module) {
        const activeClass = module.id == currentModuleId ? 'active' : '';
        html += `
            <div class="module-item ${activeClass}" onclick="selectModule(${module.id})">
                <span>${escapeHtml(module.module_name)}</span>
                <span class="module-count">(${module.field_count || 0})</span>
            </div>
        `;
    });
    
    nav.html(html);
}

/**
 * 选择模块
 */
function selectModule(moduleId) {
    currentModuleId = moduleId;
    renderModuleNav();
    loadFields(moduleId);
    
    // 更新URL
    const url = new URL(window.location);
    url.searchParams.set('module_id', moduleId);
    window.history.pushState({}, '', url);
}

/**
 * 加载字段列表
 */
function loadFields(moduleId) {
    const module = modules.find(m => m.id == moduleId);
    if (module) {
        $('#pageTitle').text(`维度管理 - ${module.module_name}`);
        $('#breadcrumbModule').text(module.module_name);
    }
    
    $.ajax({
        url: `../api/field_manage.php?action=list&module_id=${moduleId}&_v=${VERSION}`,
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success) {
                fields = response.data;
                renderFields();
            }
        },
        error: function(xhr) {
            console.error('加载字段失败:', xhr.responseText);
            showToast('加载字段失败', 'error');
        }
    });
}

/**
 * 渲染字段列表
 */
function renderFields() {
    const tbody = $('#fieldList');
    
    if (fields.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center text-muted">暂无维度</td></tr>');
        return;
    }
    
    let html = '';
    fields.forEach(function(field) {
        const statusBadge = field.status == 1 
            ? '<span class="badge bg-success">启用</span>' 
            : '<span class="badge bg-secondary">禁用</span>';
        
        const typeName = getFieldTypeName(field.field_type);
        const sortLabel = (field.sort_order !== undefined && field.sort_order !== null && field.sort_order !== '')
            ? field.sort_order
            : '-';
        
        html += `
            <tr>
                <td>${sortLabel}</td>
                <td>${escapeHtml(field.field_name)}</td>
                <td><code>${escapeHtml(field.field_code)}</code></td>
                <td><span class="badge field-type-badge bg-info">${typeName}</span></td>
                <td>${field.width || 'auto'}</td>
                <td class="text-center">${field.option_count || 0}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="manageOptions(${field.id})">
                        选项
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="editField(${field.id})">
                        编辑
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteField(${field.id})">
                        删除
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
}

/**
 * 获取字段类型名称
 */
function getFieldTypeName(type) {
    const types = {
        'text': '文本',
        'textarea': '多行',
        'select': '下拉',
        'radio': '单选',
        'checkbox': '多选',
        'date': '日期',
        'cascading_select': '级联'
    };
    return types[type] || type;
}

/**
 * 显示添加对话框
 */
function showAddModal() {
    if (!currentModuleId) {
        showToast('请先选择模块', 'error');
        return;
    }
    
    // 获取当前模块名称
    const currentModule = modules.find(m => m.id == currentModuleId);
    const moduleName = currentModule ? currentModule.module_name : '未知模块';
    
    $('#modalTitle').text('添加字段');
    $('#fieldForm')[0].reset();
    $('#fieldId').val('');
    $('#moduleId').val(currentModuleId);
    $('#currentModuleName').val(moduleName);
    $('#fieldCode').prop('readonly', false);
    
    currentModal = new bootstrap.Modal(document.getElementById('fieldModal'));
    currentModal.show();
}

/**
 * 编辑字段
 */
function editField(id) {
    const field = fields.find(f => f.id == id);
    if (!field) {
        showToast('维度不存在', 'error');
        return;
    }
    
    // 获取当前模块名称
    const currentModule = modules.find(m => m.id == field.module_id);
    const moduleName = currentModule ? currentModule.module_name : '未知模块';
    
    $('#modalTitle').text('编辑维度');
    $('#fieldId').val(field.id);
    $('#moduleId').val(field.module_id);
    $('#currentModuleName').val(moduleName);
    $('#fieldName').val(field.field_name);
    $('#fieldCode').val(field.field_code).prop('readonly', true);
    $('#fieldDescription').val(field.description || '');
    $('#sortOrder').val(field.sort_order || 0);
    
    currentModal = new bootstrap.Modal(document.getElementById('fieldModal'));
    currentModal.show();
}

/**
 * 保存字段
 */
function saveField() {
    const form = $('#fieldForm')[0];
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const id = $('#fieldId').val();
    const data = {
        module_id: parseInt($('#moduleId').val()),
        field_name: $('#fieldName').val(),
        field_code: $('#fieldCode').val(),
        description: $('#fieldDescription').val(),
        sort_order: parseInt($('#sortOrder').val()) || 0
    };
    
    if (id) {
        data.id = id;
    }
    
    const action = id ? 'edit' : 'add';
    
    $.ajax({
        url: '../api/field_manage.php?action=' + action,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(data),
        success: function(response) {
            if (response.success) {
                showToast(response.message || '保存成功', 'success');
                currentModal.hide();
                loadFields(currentModuleId);
                loadModules(); // 刷新模块列表（更新字段数量）
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
 * 删除字段
 */
function deleteField(id) {
    const field = fields.find(f => f.id == id);
    if (!field) {
        showToast('维度不存在', 'error');
        return;
    }
    
    // 提示用户会级联删除选项
    let confirmMsg = `确定要删除维度"${field.field_name}"吗？`;
    if (field.option_count > 0) {
        confirmMsg += `<br><br>⚠️ 该维度下有 ${field.option_count} 个选项，删除后将一并删除这些选项！`;
    }
    
    showConfirmModal('删除维度', confirmMsg, function() {
        $.ajax({
            url: '../api/field_manage.php?action=delete',
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '删除成功', 'success');
                    loadFields(currentModuleId);
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
 * 管理选项
 */
function manageOptions(fieldId) {
    window.location.href = `index.php?page=admin_field_options&field_id=${fieldId}`;
}

/**
 * 管理模块
 */
function manageModules() {
    window.location.href = 'index.php?page=admin_modules';
}
</script>
