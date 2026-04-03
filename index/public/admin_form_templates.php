<?php
/**
 * 表单模板管理页面
 * 集成 formBuilder 拖拽设计器
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    header('Location: /public/index.php');
    exit;
}

$pageTitle = '表单模板管理';
layout_header($pageTitle);
?>

<!-- jQuery formBuilder CDN -->
<link href="https://cdn.jsdelivr.net/npm/formBuilder@3.19.7/dist/form-builder.min.css" rel="stylesheet">

<style>
.template-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.template-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.template-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.template-status.draft { background: #fef3c7; color: #92400e; }
.template-status.published { background: #d1fae5; color: #065f46; }
.form-builder-container {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    min-height: 400px;
    background: #f8fafc;
}
#formBuilderArea {
    min-height: 400px;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>表单模板管理</h2>
            <p class="text-muted mb-0">管理可拖拽设计的表单模板</p>
        </div>
        <button class="btn btn-primary" onclick="openCreateModal()">
            <i class="bi bi-plus-circle"></i> 新建模板
        </button>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#templateList">模板列表</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#templateDesigner" id="designerTab" style="display: none;">
                设计器 <span id="designerTemplateName"></span>
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="templateList">
            <div class="row mb-3">
                <div class="col-md-3">
                    <select class="form-select" id="filterType" onchange="loadTemplates()">
                        <option value="">全部类型</option>
                        <option value="requirements">需求确认书</option>
                        <option value="feedback">客户反馈</option>
                        <option value="evaluation">评价表单</option>
                        <option value="custom">自定义</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="filterStatus" onchange="loadTemplates()">
                        <option value="">全部状态</option>
                        <option value="draft">草稿</option>
                        <option value="published">已发布</option>
                    </select>
                </div>
            </div>
            <div id="templateListContainer"></div>
        </div>
        
        <div class="tab-pane fade" id="templateDesigner">
            <div class="mb-3">
                <button class="btn btn-success" onclick="saveTemplate()">
                    <i class="bi bi-save"></i> 保存
                </button>
                <button class="btn btn-primary" onclick="publishTemplate()">
                    <i class="bi bi-cloud-upload"></i> 发布新版本
                </button>
                <button class="btn btn-outline-secondary" onclick="closeDesigner()">
                    <i class="bi bi-x"></i> 关闭
                </button>
            </div>
            <div class="form-builder-container">
                <div id="formBuilderArea"></div>
            </div>
        </div>
    </div>
</div>

<!-- 新建/编辑模板弹窗 -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">新建模板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="templateId">
                <div class="mb-3">
                    <label class="form-label">模板名称 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="templateName" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">模板类型</label>
                    <select class="form-select" id="templateType">
                        <option value="custom">自定义</option>
                        <option value="requirements">需求确认书</option>
                        <option value="feedback">客户反馈</option>
                        <option value="evaluation">评价表单</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">描述</label>
                    <textarea class="form-control" id="templateDescription" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveTemplateInfo()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
var FORM_API_URL = '/api';
var currentTemplateId = null;
var formBuilder = null;
var defaultRequirementTemplateId = 0;

// 表单类型名称映射
const typeNames = {
    'custom': '自定义',
    'requirements': '需求确认书',
    'feedback': '客户反馈',
    'evaluation': '评价表单'
};

// 加载模板列表
function loadTemplates() {
    const formType = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;
    
    // 先获取默认模板ID
    fetch(`${FORM_API_URL}/form_default_template.php?type=requirement`)
        .then(r => r.json())
        .then(defaultData => {
            if (defaultData.success) {
                defaultRequirementTemplateId = defaultData.data.template_id || 0;
            }
            
            // 再加载模板列表
            let url = `${FORM_API_URL}/form_templates.php?`;
            if (formType) url += `form_type=${formType}&`;
            if (status) url += `status=${status}&`;
            
            return fetch(url);
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderTemplateList(data.data);
            } else {
                showAlertModal('加载失败: ' + data.message, 'error');
            }
        });
}

// 渲染模板列表
function renderTemplateList(templates) {
    const container = document.getElementById('templateListContainer');
    
    if (!templates || templates.length === 0) {
        container.innerHTML = '<div class="alert alert-info">暂无模板，点击右上角"新建模板"创建</div>';
        return;
    }
    
    let html = '';
    templates.forEach(t => {
        const statusClass = t.status === 'published' ? 'published' : 'draft';
        const statusText = t.status === 'published' ? '已发布' : '草稿';
        const typeName = typeNames[t.form_type] || t.form_type;
        const updateTime = new Date(t.update_time * 1000).toLocaleString('zh-CN');
        
        html += `
            <div class="template-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-1">${t.name}</h5>
                        <p class="text-muted small mb-2">${t.description || '无描述'}</p>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="template-status ${statusClass}">${statusText}</span>
                            ${t.id === defaultRequirementTemplateId ? '<span class="badge bg-warning text-dark">⭐ 默认需求表单</span>' : ''}
                            <span class="text-muted small">类型: ${typeName}</span>
                            <span class="text-muted small">版本: v${t.version_number || 1}</span>
                            <span class="text-muted small">实例: ${t.instance_count || 0}</span>
                        </div>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="openDesigner(${t.id})">
                            设计
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="editTemplateInfo(${t.id})" title="编辑信息">
                            编辑
                        </button>
                        ${(t.form_type === 'requirement' || t.form_type === 'requirements') && t.status === 'published' && t.id !== defaultRequirementTemplateId ? `<button class="btn btn-sm btn-outline-warning" onclick="setAsDefault(${t.id})" title="设为默认需求表单">设为默认</button>` : ''}
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(${t.id})" title="删除模板">
                            删除
                        </button>
                    </div>
                </div>
                <div class="text-muted small mt-2">
                    创建者: ${t.created_by_name || '-'} | 更新时间: ${updateTime}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// 打开新建弹窗
function openCreateModal() {
    document.getElementById('templateId').value = '';
    document.getElementById('templateName').value = '';
    document.getElementById('templateType').value = 'custom';
    document.getElementById('templateDescription').value = '';
    document.getElementById('templateModalTitle').textContent = '新建模板';
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}

// 编辑模板信息
function editTemplateInfo(id) {
    fetch(`${FORM_API_URL}/form_templates.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const t = data.data;
                document.getElementById('templateId').value = t.id;
                document.getElementById('templateName').value = t.name;
                document.getElementById('templateType').value = t.form_type;
                document.getElementById('templateDescription').value = t.description || '';
                document.getElementById('templateModalTitle').textContent = '编辑模板';
                new bootstrap.Modal(document.getElementById('templateModal')).show();
            }
        });
}

// 保存模板信息
function saveTemplateInfo() {
    const id = document.getElementById('templateId').value;
    const name = document.getElementById('templateName').value.trim();
    const formType = document.getElementById('templateType').value;
    const description = document.getElementById('templateDescription').value.trim();
    
    if (!name) {
        showAlertModal('请输入模板名称', 'warning');
        return;
    }
    
    const method = id ? 'PUT' : 'POST';
    const body = { name, form_type: formType, description };
    if (id) body.id = parseInt(id);
    
    fetch(`${FORM_API_URL}/form_templates.php`, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
            showAlertModal(id ? '更新成功' : '创建成功', 'success');
            loadTemplates();
            
            // 如果是新建，自动打开设计器
            if (!id && data.data?.id) {
                setTimeout(() => openDesigner(data.data.id), 500);
            }
        } else {
            showAlertModal('操作失败: ' + data.message, 'error');
        }
    });
}

// 设为默认需求表单
function setAsDefault(templateId) {
    if (!confirm('确定将此模板设为默认需求表单？新创建的项目将自动使用此表单。')) {
        return;
    }
    
    fetch(`${FORM_API_URL}/form_default_template.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'requirement', template_id: templateId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('已设为默认需求表单', 'success');
            loadTemplates();
        } else {
            showAlertModal('设置失败: ' + data.message, 'error');
        }
    });
}

// 打开设计器
function openDesigner(templateId) {
    fetch(`${FORM_API_URL}/form_templates.php?id=${templateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentTemplateId = templateId;
                const template = data.data;
                
                document.getElementById('designerTab').style.display = 'block';
                document.getElementById('designerTemplateName').textContent = `- ${template.name}`;
                
                // 切换到设计器tab
                const designerTab = new bootstrap.Tab(document.getElementById('designerTab'));
                designerTab.show();
                
                // 初始化formBuilder
                let formData = [];
                try {
                    formData = template.schema_json ? JSON.parse(template.schema_json) : [];
                } catch (e) {
                    formData = [];
                }
                
                // 销毁旧的formBuilder
                if (formBuilder) {
                    $('#formBuilderArea').empty();
                }
                
                // 创建新的formBuilder（使用内联中文翻译）
                formBuilder = $('#formBuilderArea').formBuilder({
                    formData: formData,
                    i18n: {
                        override: {
                            'zh-CN': {
                                addOption: '添加选项',
                                allFieldsRemoved: '所有字段已移除',
                                allowMultipleFiles: '允许多文件',
                                autocomplete: '自动完成',
                                button: '按钮',
                                cannotBeEmpty: '此字段不能为空',
                                checkboxGroup: '复选框组',
                                checkbox: '复选框',
                                checkboxes: '复选框',
                                className: 'CSS类名',
                                clearAllMessage: '确定要清除所有字段吗？',
                                clear: '清除',
                                close: '关闭',
                                content: '内容',
                                copy: '复制',
                                copyButton: '复制',
                                copyButtonTooltip: '复制到剪贴板',
                                dateField: '日期',
                                description: '描述',
                                descriptionField: '描述',
                                devMode: '开发模式',
                                editNames: '编辑名称',
                                editorTitle: '表单元素',
                                editXML: '编辑XML',
                                enableOther: '启用其他选项',
                                enableOtherMsg: '允许用户输入未列出的选项',
                                fieldDeleteWarning: '删除',
                                fieldVars: '字段变量',
                                fieldNonEditable: '此字段不可编辑',
                                fieldRemoveWarning: '确定要删除此字段吗？',
                                fileUpload: '文件上传',
                                formUpdated: '表单已更新',
                                getStarted: '将字段拖到此处',
                                header: '标题',
                                hide: '隐藏',
                                hidden: '隐藏字段',
                                label: '标签',
                                labelEmpty: '字段标签不能为空',
                                limitRole: '限制访问',
                                mandatory: '必填',
                                maxlength: '最大长度',
                                minOptionMessage: '此字段至少需要2个选项',
                                multipleFiles: '多文件',
                                name: '名称',
                                no: '否',
                                noFieldsToClear: '没有可清除的字段',
                                number: '数字',
                                off: '关',
                                on: '开',
                                option: '选项',
                                optional: '可选',
                                optionLabelPlaceholder: '标签',
                                optionValuePlaceholder: '值',
                                optionEmpty: '选项值必填',
                                other: '其他',
                                paragraph: '段落',
                                placeholder: '占位符',
                                placeholders: {
                                    value: '值',
                                    label: '标签',
                                    text: '',
                                    textarea: '',
                                    email: '输入邮箱',
                                    placeholder: '',
                                    className: '用空格分隔多个类名',
                                    password: '输入密码'
                                },
                                preview: '预览',
                                radioGroup: '单选框组',
                                radio: '单选框',
                                removeMessage: '删除元素',
                                removeOption: '删除选项',
                                remove: '删除',
                                required: '必填',
                                richText: '富文本编辑器',
                                roles: '访问角色',
                                rows: '行数',
                                save: '保存',
                                selectOptions: '选项',
                                select: '下拉选择',
                                selectColor: '选择颜色',
                                selectionsMessage: '允许多选',
                                show: '显示',
                                size: '尺寸',
                                sizes: {
                                    xs: '超小',
                                    sm: '小',
                                    m: '默认',
                                    lg: '大'
                                },
                                style: '样式',
                                styles: {
                                    btn: {
                                        'default': '默认',
                                        danger: '危险',
                                        info: '信息',
                                        primary: '主要',
                                        success: '成功',
                                        warning: '警告'
                                    }
                                },
                                subtype: '类型',
                                text: '文本框',
                                textArea: '文本域',
                                toggle: '切换',
                                warning: '警告！',
                                value: '值',
                                viewJSON: '查看JSON',
                                viewXML: '查看XML',
                                yes: '是'
                            }
                        },
                        locale: 'zh-CN'
                    },
                    disableFields: ['button', 'hidden', 'autocomplete'],
                    controlOrder: ['text', 'textarea', 'select', 'checkbox-group', 'radio-group', 'number', 'date', 'file', 'header', 'paragraph']
                });
            }
        });
}

// 保存模板
function saveTemplate() {
    if (!currentTemplateId || !formBuilder) {
        showAlertModal('请先打开模板', 'warning');
        return;
    }
    
    const formData = formBuilder.actions.getData('json');
    
    fetch(`${FORM_API_URL}/form_templates.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: currentTemplateId,
            schema_json: formData
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('保存成功', 'success');
        } else {
            showAlertModal('保存失败: ' + data.message, 'error');
        }
    });
}

// 发布模板
function publishTemplate() {
    if (!currentTemplateId || !formBuilder) {
        showAlertModal('请先打开模板', 'warning');
        return;
    }
    
    showConfirmModal('确认发布', '发布后将创建新版本，确定要发布吗？', function() {
        const formData = formBuilder.actions.getData('json');
        
        fetch(`${FORM_API_URL}/form_template_publish.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                template_id: currentTemplateId,
                schema_json: formData
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message, 'success');
                loadTemplates();
            } else {
                showAlertModal('发布失败: ' + data.message, 'error');
            }
        });
    });
}

// 关闭设计器
function closeDesigner() {
    document.getElementById('designerTab').style.display = 'none';
    currentTemplateId = null;
    
    // 切换回列表tab
    const listTab = document.querySelector('[href="#templateList"]');
    new bootstrap.Tab(listTab).show();
}

// 删除模板
function deleteTemplate(id) {
    showConfirmModal('确认删除', '确定要删除此模板吗？', function() {
        fetch(`${FORM_API_URL}/form_templates.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('删除成功', 'success');
                loadTemplates();
            } else {
                showAlertModal('删除失败: ' + data.message, 'error');
            }
        });
    });
}

// 页面加载
document.addEventListener('DOMContentLoaded', function() {
    loadTemplates();
});
</script>

<?php layout_footer(); ?>

<!-- formBuilder 必须在 jQuery 之后加载 -->
<script src="https://cdn.jsdelivr.net/npm/jquery-ui@1.13.2/dist/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/formBuilder@3.19.7/dist/form-builder.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/formBuilder@3.19.7/dist/form-render.min.js"></script>
<script>
// formBuilder加载后初始化
console.log('[FORM_DEBUG] formBuilder loaded:', typeof $.fn.formBuilder);
</script>
