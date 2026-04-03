<?php
/**
 * 选项管理页面
 * 管理字段的选项
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

// 获取字段ID
$fieldId = intval($_GET['field_id'] ?? 0);
if ($fieldId <= 0) {
    layout_header('错误');
    echo '<div class="container mt-5"><div class="alert alert-danger">字段ID无效</div></div>';
    layout_footer();
    exit;
}

// 获取维度信息（新三层结构）
$field = Db::queryOne('SELECT d.*, d.dimension_name as field_name, d.dimension_code as field_code, d.menu_id as module_id, m.menu_name as module_name 
                       FROM dimensions d 
                       LEFT JOIN menus m ON d.menu_id = m.id 
                       WHERE d.id = ?', [$fieldId]);
if (!$field) {
    layout_header('错误');
    echo '<div class="container mt-5"><div class="alert alert-danger">维度不存在</div></div>';
    layout_footer();
    exit;
}

layout_header('选项管理 - ' . $field['field_name']);
?>


<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/admin_modules.php">模块管理</a></li>
                    <li class="breadcrumb-item"><a href="/admin_fields_new.php?module_id=<?= $field['module_id'] ?>">
                        <?= htmlspecialchars($field['module_name']) ?>
                    </a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($field['field_name']) ?></li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4>选项管理 - <?= htmlspecialchars($field['field_name']) ?></h4>
                    <p class="text-muted mb-0">字段代码：<code><?= htmlspecialchars($field['field_code']) ?></code></p>
                </div>
                <div>
                    <button class="btn btn-outline-secondary" onclick="batchEnable()">
                        <i class="bi bi-check-circle"></i> 批量启用
                    </button>
                    <button class="btn btn-outline-secondary" onclick="batchDisable()">
                        <i class="bi bi-x-circle"></i> 批量禁用
                    </button>
                    <button class="btn btn-outline-danger" onclick="batchDelete()">
                        <i class="bi bi-trash"></i> 批量删除
                    </button>
                    <button class="btn btn-primary" onclick="showAddModal()">
                        <i class="bi bi-plus-circle"></i> 添加选项
                    </button>
                </div>
            </div>

            <!-- 表格布局 -->
            <div class="card" id="tableLayoutCard">
                <div class="card-body">
                    <table class="table table-hover" id="optionTable">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <th width="60">排序</th>
                                <th>选项名称</th>
                                <th>选项值</th>
                                <th width="100">父选项</th>
                                <th width="80">类型</th>
                                <th width="60">行</th>
                                <th width="60">列</th>
                                <th width="80">宽度</th>
                                <th width="80">状态</th>
                                <th width="150">操作</th>
                            </tr>
                        </thead>
                        <tbody id="optionList">
                            <tr>
                                <td colspan="10" class="text-center">加载中...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑选项对话框 -->
<div class="modal fade" id="optionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">添加选项</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="optionForm">
                    <input type="hidden" id="optionId" name="id">
                    <input type="hidden" id="fieldId" name="field_id" value="<?= $fieldId ?>">
                    
                    <!-- 层级说明 -->
                    <div class="alert alert-info mb-3">
                        <strong>📊 三层结构：</strong> 菜单（维度） → 字段（维度下的字段） → <strong>选项（字段的可选值）</strong>
                    </div>
                    
                    <!-- 第1层：所属模块 -->
                    <h6 class="border-bottom pb-2 mb-3">
                        <span class="badge bg-primary">第1层</span> 所属模块（维度）
                    </h6>
                    <div class="mb-3">
                        <label class="form-label">当前模块</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($field['module_name'] ?? '') ?>" readonly disabled>
                        <small class="text-muted">字段将添加到此模块下</small>
                    </div>
                    
                    <!-- 第2层：字段信息 -->
                    <h6 class="border-bottom pb-2 mb-3">
                        <span class="badge bg-success">第2层</span> 字段信息（维度下的字段）
                    </h6>
                    <div class="mb-3">
                        <label class="form-label">当前维度</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($field['field_name']) ?>" readonly disabled>
                        <small class="text-muted">选项将添加到此维度下</small>
                    </div>
                    
                    <!-- 第3层：选项信息 -->
                    <h6 class="border-bottom pb-2 mb-3">
                        <span class="badge bg-warning">第3层</span> 选项信息（字段的可选值）
                    </h6>
                    
                    <!-- 批量添加选项（仅在下拉框类型时显示） -->
                    <div class="mb-3" id="batchOptionsContainer" style="display:none;">
                        <label for="batchOptions" class="form-label">批量添加选项 <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="batchOptions" name="batch_options" rows="5" 
                                  placeholder="每行一个选项，例如：&#10;选项1&#10;选项2&#10;选项3&#10;&#10;或者使用格式：选项名称=选项值（每行一个）&#10;例如：&#10;选项1=value1&#10;选项2=value2"></textarea>
                        <small class="text-muted">
                            <strong>批量模式：</strong>每行一个选项。如果使用"选项名称=选项值"格式，则选项值会使用指定的值；否则选项值等于选项名称。
                            <br><strong>单条模式：</strong>留空则使用下方的"选项名称"和"选项值"字段添加单个选项。
                        </small>
                    </div>
                    
                    <div class="row" id="singleOptionContainer">
                        <div class="col-md-6 mb-3">
                            <label for="optionLabel" class="form-label">选项名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="optionLabel" name="option_label" placeholder="如：业主、设计师">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="optionValue" class="form-label">选项值</label>
                            <input type="text" class="form-control" id="optionValue" name="option_value" placeholder="留空则使用选项名称">
                            <small class="text-muted">留空则使用选项名称作为值</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="fieldCode" class="form-label">字段代码</label>
                        <input type="text" class="form-control" id="fieldCode" name="field_code" 
                               pattern="[a-zA-Z0-9_]+" title="只能包含大小写字母、数字和下划线" placeholder="如：question_quality">
                        <small class="text-muted">只能包含大小写字母、数字和下划线，留空自动生成</small>
                    </div>
                    
                    <!-- 字段类型与属性 -->
                    <h6 class="border-bottom pb-2 mb-3">字段类型与属性</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="fieldType" class="form-label">字段类型 <span class="text-danger">*</span></label>
                            <select class="form-select" id="fieldType" name="field_type" required onchange="toggleBatchOptions();">
                                <option value="">请选择</option>
                                <option value="radio">单选按钮（如：身份选择）</option>
                                <option value="checkbox">多选框（如：需要发送的资料）</option>
                                <option value="select">下拉选择</option>
                                <option value="text">文本框</option>
                                <option value="textarea">多行文本</option>
                                <option value="date">日期选择</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">字段属性</label>
                            <div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="isRequired" name="is_required">
                                    <label class="form-check-label" for="isRequired">
                                        <strong>必填字段</strong> - 用户必须填写此字段
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="allowCustom" name="allow_custom">
                                    <label class="form-check-label" for="allowCustom">
                                        <strong>允许自定义</strong> - 显示"其他"输入框
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 布局与显示 -->
                    <h6 class="border-bottom pb-2 mb-3">布局与显示</h6>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="rowOrder" class="form-label">行序号</label>
                            <input type="number" class="form-control" id="rowOrder" name="row_order" value="0" min="0">
                            <small class="text-muted">控制字段在第几行显示</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="colOrder" class="form-label">列序号</label>
                            <input type="number" class="form-control" id="colOrder" name="col_order" value="0" min="0">
                            <small class="text-muted">控制字段在行内的位置</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="fieldWidth" class="form-label">字段宽度</label>
                            <select class="form-select" id="fieldWidthSelect" onchange="handleWidthChange()">
                                <option value="auto">auto - 自适应内容（推荐）</option>
                                <option value="100%">100% - 整行</option>
                                <option value="50%">50% - 半行</option>
                                <option value="33%">33% - 三分之一</option>
                                <option value="25%">25% - 四分之一</option>
                                <option value="20%">20% - 五分之一</option>
                                <option value="16%">16% - 六分之一</option>
                                <option value="custom">自定义...</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="fieldWidth" name="width" 
                                   placeholder="如：150px 或 30%" style="display:none;">
                            <small class="text-muted">auto让字段根据内容自动调整宽度</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveOption()">保存</button>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>

<script>
const fieldId = <?= $fieldId ?>;
const fieldType = '<?= htmlspecialchars($field['field_type'] ?? '', ENT_QUOTES) ?>';
let options = [];
let currentModal = null;

// 页面加载时获取选项列表
$(document).ready(function() {
    loadOptions();
});

/**
 * 加载选项列表
 */
function loadOptions() {
    $.ajax({
        url: `/api/option_manage.php?action=list&field_id=${fieldId}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                options = response.data;
                renderOptions();
            } else {
                showToast(response.message || '加载失败', 'error');
            }
        },
        error: function() {
            showToast('加载失败，请重试', 'error');
        }
    });
}

/**
 * 渲染选项列表
 */
function renderOptions() {
    const tbody = $('#optionList');
    
    if (options.length === 0) {
        tbody.html('<tr><td colspan="11" class="text-center text-muted">暂无选项</td></tr>');
        return;
    }
    
    // 构建选项映射，用于查找父选项
    const optionMap = {};
    options.forEach(opt => {
        optionMap[opt.id] = opt;
    });
    
    // 构建父子关系树
    function buildTree(items) {
        const tree = [];
        const map = {};
        
        // 先创建所有节点的映射
        items.forEach(item => {
            map[item.id] = { ...item, children: [] };
        });
        
        // 构建树结构
        items.forEach(item => {
            const parentId = item.parent_field_id || item.parent_option_id;
            if (parentId && map[parentId]) {
                map[parentId].children.push(map[item.id]);
            } else {
                tree.push(map[item.id]);
            }
        });
        
        return tree;
    }
    
    // 递归渲染树节点
    function renderNode(option, level = 0) {
        const statusBadge = option.status == 1 
            ? '<span class="badge bg-success">启用</span>' 
            : '<span class="badge bg-secondary">禁用</span>';
        
        const fieldTypeMap = {
            'radio': '单选',
            'checkbox': '复选',
            'text': '文本',
            'textarea': '多行',
            'select': '下拉',
            'date': '日期'
        };
        const fieldTypeLabel = fieldTypeMap[option.field_type] || option.field_type;
        
        // 计算缩进
        const indent = level * 20;
        const indentClass = level > 0 ? 'text-muted' : '';
        const prefix = level > 0 ? '<span class="text-muted">└─</span> ' : '';
        
        // 获取父选项名称
        const parentId = option.parent_field_id || option.parent_option_id;
        const parentName = parentId && optionMap[parentId] 
            ? escapeHtml(optionMap[parentId].option_label || optionMap[parentId].field_name)
            : '-';
        
        let html = `
            <tr>
                <td><input type="checkbox" class="option-checkbox" value="${option.id}"></td>
                <td>${option.sort_order}</td>
                <td style="padding-left: ${indent}px;" class="${indentClass}">
                    ${prefix}${escapeHtml(option.option_label || option.field_name)}
                </td>
                <td><code>${escapeHtml(option.option_value || option.field_value)}</code></td>
                <td>${parentName}</td>
                <td><span class="badge bg-info">${fieldTypeLabel}</span></td>
                <td>${option.row_order || 0}</td>
                <td>${option.col_order || 0}</td>
                <td>${option.width || 'auto'}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="editOption(${option.id})">
                        编辑
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteOption(${option.id})">
                        删除
                    </button>
                </td>
            </tr>
        `;
        
        // 递归渲染子节点
        if (option.children && option.children.length > 0) {
            option.children.forEach(child => {
                html += renderNode(child, level + 1);
            });
        }
        
        return html;
    }
    
    // 构建树并渲染
    const tree = buildTree(options);
    let html = '';
    tree.forEach(root => {
        html += renderNode(root, 0);
    });
    
    tbody.html(html);
}

/**
 * 全选/取消全选
 */
function toggleSelectAll() {
    const checked = $('#selectAll').is(':checked');
    $('.option-checkbox').prop('checked', checked);
}

/**
 * 生成字段代码（简化版）
 */
function generateFieldCode(name) {
    if (!name) return '';
    
    // 提取英文、数字，转小写
    let code = name.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
    
    // 移除连续的下划线和首尾下划线
    code = code.replace(/_+/g, '_').replace(/^_|_$/g, '');
    
    // 限制长度
    code = code.substring(0, 50);
    
    // 如果为空，使用时间戳
    if (!code) {
        code = 'field_' + Date.now();
    }
    
    return code;
}


/**
 * 根据输入的父选项名称查找或创建父选项
 */
function findOrCreateParentOption(parentName) {
    if (!parentName || parentName.trim() === '' || parentName === '无（顶级选项）') {
        return null;
    }
    
    parentName = parentName.trim();
    
    // 首先尝试通过名称精确匹配
    const matchedOption = options.find(opt => {
        const label = (opt.option_label || opt.field_name || '').trim();
        return label === parentName && opt.status == 1;
    });
    
    if (matchedOption) {
        return matchedOption.id;
    }
    
    // 如果没有匹配到，返回特殊标记，表示需要创建新选项
    return 'NEW:' + parentName;
}

/**
 * 显示添加对话框
 */
function showAddModal() {
    $('#modalTitle').text('添加选项');
    $('#optionForm')[0].reset();
    $('#optionId').val('');
    $('#fieldId').val(fieldId);
    
    // 设置默认宽度为 auto
    $('#fieldWidthSelect').val('auto');
    $('#fieldWidth').val('auto').hide();
    
    // 重置批量选项显示状态
    toggleBatchOptions();
    
    currentModal = new bootstrap.Modal(document.getElementById('optionModal'));
    currentModal.show();
}

/**
 * 根据字段类型显示/隐藏批量选项输入框
 */
function toggleBatchOptions() {
    const fieldType = $('#fieldType').val();
    const batchContainer = $('#batchOptionsContainer');
    const singleContainer = $('#singleOptionContainer');
    const optionLabel = $('#optionLabel');
    
    if (fieldType === 'select') {
        // 下拉框类型：显示批量输入，单条输入变为可选
        batchContainer.show();
        optionLabel.removeAttr('required');
        // 清空批量输入框（如果是编辑模式，保留原值）
        if (!$('#optionId').val()) {
            $('#batchOptions').val('');
        }
    } else {
        // 其他类型：隐藏批量输入，单条输入必填
        batchContainer.hide();
        optionLabel.attr('required', 'required');
        $('#batchOptions').val('');
    }
}

// 监听选项名称输入，自动生成字段代码
$(document).on('input', '#optionLabel', function() {
    const label = $(this).val();
    const codeInput = $('#fieldCode');
    
    // 只在字段代码为空时自动生成
    if (!codeInput.val() || codeInput.data('auto-generated')) {
        const code = generateFieldCode(label);
        codeInput.val(code).data('auto-generated', true);
    }
});

// 手动修改字段代码时，取消自动生成标记
$(document).on('input', '#fieldCode', function() {
    $(this).data('auto-generated', false);
});


/**
 * 处理宽度选择变化
 */
function handleWidthChange() {
    const select = $('#fieldWidthSelect');
    const input = $('#fieldWidth');
    const value = select.val();
    
    if (value === 'custom') {
        input.show().focus();
        input.val('');
    } else {
        input.hide();
        input.val(value);
    }
}

/**
 * 编辑选项
 */
function editOption(id) {
    const option = options.find(o => o.id == id);
    if (!option) {
        showToast('选项不存在', 'error');
        return;
    }
    
    $('#modalTitle').text('编辑选项');
    $('#optionId').val(option.id);
    $('#fieldId').val(option.field_id || option.dimension_id);
    $('#optionLabel').val(option.option_label || option.field_name);
    $('#optionValue').val(option.option_value || option.field_value);
    $('#fieldCode').val(option.field_code || '');
    $('#fieldType').val(option.field_type || 'radio');
    $('#rowOrder').val(option.row_order || 0);
    $('#colOrder').val(option.col_order || 0);
    
    // 处理宽度值
    const width = option.width || 'auto';
    const predefinedWidths = ['auto', '100%', '50%', '33%', '25%', '20%', '16%'];
    if (predefinedWidths.includes(width)) {
        $('#fieldWidthSelect').val(width);
        $('#fieldWidth').val(width).hide();
    } else {
        $('#fieldWidthSelect').val('custom');
        $('#fieldWidth').val(width).show();
    }
    
    $('#isRequired').prop('checked', option.is_required == 1);
    $('#allowCustom').prop('checked', option.allow_custom == 1);
    
    // 重置批量选项显示状态
    toggleBatchOptions();
    
    // 编辑模式下隐藏批量输入
    $('#batchOptionsContainer').hide();
    $('#singleOptionContainer').show();
    $('#optionLabel').attr('required', 'required');
    
    currentModal = new bootstrap.Modal(document.getElementById('optionModal'));
    currentModal.show();
}

/**
 * 解析批量选项文本
 * 支持格式：
 * 1. 每行一个选项名称
 * 2. 每行一个"选项名称=选项值"
 */
function parseBatchOptions(text) {
    if (!text || !text.trim()) {
        return [];
    }
    
    const lines = text.split('\n');
    const options = [];
    
    lines.forEach(function(line, index) {
        line = line.trim();
        if (!line) return; // 跳过空行
        
        // 检查是否包含等号（选项名称=选项值格式）
        if (line.includes('=')) {
            const parts = line.split('=');
            const label = parts[0].trim();
            const value = parts.slice(1).join('=').trim(); // 支持值中包含等号
            if (label) {
                options.push({
                    option_label: label,
                    option_value: value || label
                });
            }
        } else {
            // 只有选项名称，值等于名称
            options.push({
                option_label: line,
                option_value: line
            });
        }
    });
    
    return options;
}

/**
 * 保存选项
 */
function saveOption() {
    const form = $('#optionForm')[0];
    const fieldType = $('#fieldType').val();
    const batchOptionsText = $('#batchOptions').val().trim();
    const id = $('#optionId').val();
    
    // 如果是编辑模式，使用单条保存
    if (id) {
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        const data = {
            id: id,
            field_id: parseInt($('#fieldId').val()),
            option_label: $('#optionLabel').val(),
            option_value: $('#optionValue').val() || $('#optionLabel').val(),
            field_code: $('#fieldCode').val(),
            field_type: fieldType,
            row_order: parseInt($('#rowOrder').val()) || 0,
            col_order: parseInt($('#colOrder').val()) || 0,
            width: $('#fieldWidth').val(),
            is_required: $('#isRequired').is(':checked') ? 1 : 0,
            allow_custom: $('#allowCustom').is(':checked') ? 1 : 0
        };
        
        $.ajax({
            url: '/api/option_manage.php?action=edit',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '保存成功', 'success');
                    currentModal.hide();
                    loadOptions();
                } else {
                    showToast(response.message || '保存失败', 'error');
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showToast(response?.message || '保存失败，请重试', 'error');
            }
        });
        return;
    }
    
    // 添加模式：检查是否是批量添加
    if (fieldType === 'select' && batchOptionsText) {
        // 批量添加模式
        const options = parseBatchOptions(batchOptionsText);
        if (options.length === 0) {
            showToast('请输入至少一个选项', 'error');
            return;
        }
        
        // 批量添加
        const commonData = {
            field_id: parseInt($('#fieldId').val()),
            field_type: fieldType,
            row_order: parseInt($('#rowOrder').val()) || 0,
            col_order: parseInt($('#colOrder').val()) || 0,
            width: $('#fieldWidth').val(),
            is_required: $('#isRequired').is(':checked') ? 1 : 0,
            allow_custom: $('#allowCustom').is(':checked') ? 1 : 0
        };
        
        $.ajax({
            url: '/api/option_manage.php?action=batch_add',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                options: options,
                common: commonData
            }),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || `成功添加 ${response.data?.count || options.length} 个选项`, 'success');
                    currentModal.hide();
                    loadOptions();
                } else {
                    showToast(response.message || '保存失败', 'error');
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showToast(response?.message || '保存失败，请重试', 'error');
            }
        });
    } else {
        // 单条添加模式
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        if (! $('#optionLabel').val().trim()) {
            showToast('请输入选项名称', 'error');
            return;
        }
        
        const data = {
            field_id: parseInt($('#fieldId').val()),
            option_label: $('#optionLabel').val(),
            option_value: $('#optionValue').val() || $('#optionLabel').val(),
            field_code: $('#fieldCode').val(),
            field_type: fieldType,
            row_order: parseInt($('#rowOrder').val()) || 0,
            col_order: parseInt($('#colOrder').val()) || 0,
            width: $('#fieldWidth').val(),
            is_required: $('#isRequired').is(':checked') ? 1 : 0,
            allow_custom: $('#allowCustom').is(':checked') ? 1 : 0
        };
        
        $.ajax({
            url: '/api/option_manage.php?action=add',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '保存成功', 'success');
                    currentModal.hide();
                    loadOptions();
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
}

/**
 * 删除选项
 */
function deleteOption(id) {
    const option = options.find(o => o.id == id);
    if (!option) {
        showToast('选项不存在', 'error');
        return;
    }
    
    showConfirmModal('删除选项', `确定要删除选项"${option.option_label}"吗？`, function() {
        $.ajax({
            url: '/api/option_manage.php?action=delete',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id }),
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '删除成功', 'success');
                    loadOptions();
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
 * 获取选中的选项ID
 */
function getSelectedIds() {
    const ids = [];
    $('.option-checkbox:checked').each(function() {
        ids.push(parseInt($(this).val()));
    });
    return ids;
}

/**
 * 批量启用
 */
function batchEnable() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('请先选择要操作的选项', 'error');
        return;
    }
    
    batchOperation(ids, 'enable');
}

/**
 * 批量禁用
 */
function batchDisable() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('请先选择要操作的选项', 'error');
        return;
    }
    
    batchOperation(ids, 'disable');
}

/**
 * 批量删除
 */
function batchDelete() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        showToast('请先选择要操作的选项', 'error');
        return;
    }
    
    showConfirmModal('批量删除', `确定要删除选中的 ${ids.length} 个选项吗？`, function() {
        batchOperation(ids, 'delete');
    });
}

/**
 * 批量操作
 */
function batchOperation(ids, operation) {
    $.ajax({
        url: '/api/option_manage.php?action=batch',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ ids: ids, operation: operation }),
        success: function(response) {
            if (response.success) {
                showToast(response.message || '操作成功', 'success');
                $('#selectAll').prop('checked', false);
                loadOptions();
            } else {
                showToast(response.message || '操作失败', 'error');
            }
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            showToast(response?.message || '操作失败，请重试', 'error');
        }
    });
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
