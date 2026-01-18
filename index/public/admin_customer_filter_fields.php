<?php
/**
 * 客户筛选字段管理 - 后台页面
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

// 检查权限：仅管理员可访问
if (!isAdmin($user)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">您没有权限访问此页面。</div>';
    layout_footer();
    exit;
}

$pageTitle = '客户筛选字段管理';
layout_header($pageTitle);
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --primary-color: #6366f1;
    --primary-light: #818cf8;
}

.page-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 1rem;
    margin-bottom: 1.5rem;
}

.field-card {
    background: white;
    border-radius: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 1rem;
    overflow: hidden;
}

.field-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.field-title {
    font-weight: 600;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.field-name {
    font-size: 0.75rem;
    color: #64748b;
    background: #e2e8f0;
    padding: 0.125rem 0.5rem;
    border-radius: 0.25rem;
}

.field-body {
    padding: 1rem 1.25rem;
}

.option-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    background: #f8fafc;
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
    gap: 0.75rem;
}

.option-color {
    width: 1rem;
    height: 1rem;
    border-radius: 50%;
    flex-shrink: 0;
}

.option-label {
    flex-grow: 1;
    font-weight: 500;
}

.option-value {
    font-size: 0.75rem;
    color: #64748b;
}

.btn-icon {
    width: 2rem;
    height: 2rem;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.375rem;
}

.color-picker-wrapper {
    position: relative;
}

.color-picker-btn {
    width: 2rem;
    height: 2rem;
    border-radius: 0.375rem;
    border: 2px solid #e2e8f0;
    cursor: pointer;
}

.color-picker-input {
    position: absolute;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.preset-colors {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}

.preset-color {
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 0.25rem;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s;
}

.preset-color:hover {
    transform: scale(1.1);
}

.preset-color.active {
    border-color: #1e293b;
}

.status-badge {
    font-size: 0.7rem;
    padding: 0.125rem 0.5rem;
    border-radius: 1rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #64748b;
}

.drag-handle {
    cursor: grab;
    color: #94a3b8;
}

.drag-handle:hover {
    color: #64748b;
}
</style>

<div class="container py-4">
        <!-- 页面头部 -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1"><i class="bi bi-funnel me-2"></i><?= $pageTitle ?></h4>
                    <p class="mb-0 opacity-75">配置客户的自定义筛选字段和选项</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light btn-sm me-2">
                        <i class="bi bi-arrow-left"></i> 返回首页
                    </a>
                    <button class="btn btn-light" onclick="addField()">
                        <i class="bi bi-plus-lg"></i> 添加字段
                    </button>
                </div>
            </div>
        </div>
        
        <!-- 字段列表 -->
        <div id="fieldList">
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">加载中...</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加/编辑字段弹窗 -->
    <div class="modal fade" id="fieldModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fieldModalTitle">添加字段</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="fieldId">
                    <div class="mb-3">
                        <label class="form-label">字段标签 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="fieldLabel" placeholder="如：客户状态">
                    </div>
                    <div class="mb-3" id="fieldNameGroup">
                        <label class="form-label">字段名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="fieldName" placeholder="如：status（英文标识）">
                        <div class="form-text">用于系统标识，创建后不可修改</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveField()">保存</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加/编辑选项弹窗 -->
    <div class="modal fade" id="optionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="optionModalTitle">添加选项</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="optionId">
                    <input type="hidden" id="optionFieldId">
                    <div class="mb-3">
                        <label class="form-label">选项标签 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="optionLabel" placeholder="如：活跃">
                    </div>
                    <div class="mb-3" id="optionValueGroup">
                        <label class="form-label">选项值 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="optionValue" placeholder="如：active">
                        <div class="form-text">用于存储，创建后不可修改</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">颜色</label>
                        <div class="d-flex align-items-center gap-3">
                            <div class="color-picker-wrapper">
                                <input type="color" class="color-picker-input" id="optionColor" value="#6366f1">
                                <div class="color-picker-btn" id="colorPreview" style="background: #6366f1;"></div>
                            </div>
                            <input type="text" class="form-control" id="optionColorText" value="#6366f1" style="width: 100px;">
                        </div>
                        <div class="preset-colors">
                            <div class="preset-color" style="background: #10b981;" data-color="#10b981"></div>
                            <div class="preset-color" style="background: #3b82f6;" data-color="#3b82f6"></div>
                            <div class="preset-color" style="background: #6366f1;" data-color="#6366f1"></div>
                            <div class="preset-color" style="background: #8b5cf6;" data-color="#8b5cf6"></div>
                            <div class="preset-color" style="background: #ec4899;" data-color="#ec4899"></div>
                            <div class="preset-color" style="background: #f59e0b;" data-color="#f59e0b"></div>
                            <div class="preset-color" style="background: #ef4444;" data-color="#ef4444"></div>
                            <div class="preset-color" style="background: #06b6d4;" data-color="#06b6d4"></div>
                            <div class="preset-color" style="background: #94a3b8;" data-color="#94a3b8"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveOption()">保存</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 直接使用硬编码API路径（与其他页面保持一致）
        const FILTER_API = '/api/customer_filter_fields.php';
        let fieldsData = [];
        
        // 页面加载
        document.addEventListener('DOMContentLoaded', function() {
            loadFields();
            setupColorPicker();
        });
        
        // 设置颜色选择器
        function setupColorPicker() {
            const colorInput = document.getElementById('optionColor');
            const colorText = document.getElementById('optionColorText');
            const colorPreview = document.getElementById('colorPreview');
            
            colorInput.addEventListener('input', function() {
                colorText.value = this.value;
                colorPreview.style.background = this.value;
            });
            
            colorText.addEventListener('input', function() {
                if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                    colorInput.value = this.value;
                    colorPreview.style.background = this.value;
                }
            });
            
            document.querySelectorAll('.preset-color').forEach(el => {
                el.addEventListener('click', function() {
                    const color = this.dataset.color;
                    colorInput.value = color;
                    colorText.value = color;
                    colorPreview.style.background = color;
                });
            });
        }
        
        // 加载字段列表
        async function loadFields() {
            try {
                const response = await fetch(`${FILTER_API}?action=list&include_inactive=1`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const result = await response.json();
                
                if (result.success) {
                    fieldsData = result.data;
                    renderFields();
                } else {
                    showError(result.message);
                }
            } catch (error) {
                console.error('[FILTER_DEBUG] loadFields error:', error);
                showError('加载失败: ' + error.message);
            }
        }
        
        // 渲染字段列表
        function renderFields() {
            const container = document.getElementById('fieldList');
            
            if (fieldsData.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-inbox fs-1"></i>
                        <p class="mt-3">暂无筛选字段</p>
                        <button class="btn btn-primary" onclick="addField()">
                            <i class="bi bi-plus-lg"></i> 添加第一个字段
                        </button>
                    </div>
                `;
                return;
            }
            
            let html = '';
            fieldsData.forEach((field, index) => {
                html += `
                    <div class="field-card" data-field-id="${field.id}">
                        <div class="field-header">
                            <div class="field-title">
                                <i class="bi bi-grip-vertical drag-handle"></i>
                                <span>${field.field_label}</span>
                                <span class="field-name">${field.field_name}</span>
                                ${field.is_active == 0 ? '<span class="badge bg-secondary status-badge">已禁用</span>' : ''}
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary btn-icon" onclick="addOption(${field.id})" title="添加选项">
                                    <i class="bi bi-plus"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary btn-icon" onclick="editField(${field.id})" title="编辑">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-${field.is_active == 1 ? 'warning' : 'success'} btn-icon" 
                                    onclick="toggleField(${field.id}, ${field.is_active == 1 ? 0 : 1})" 
                                    title="${field.is_active == 1 ? '禁用' : '启用'}">
                                    <i class="bi bi-${field.is_active == 1 ? 'pause' : 'play'}"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-icon" onclick="deleteField(${field.id})" title="删除">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="field-body">
                            ${renderOptions(field.options, field.id)}
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // 渲染选项列表
        function renderOptions(options, fieldId) {
            if (!options || options.length === 0) {
                return '<div class="text-muted small">暂无选项，点击上方 + 添加</div>';
            }
            
            let html = '';
            options.forEach(option => {
                html += `
                    <div class="option-item">
                        <div class="option-color" style="background: ${option.color}"></div>
                        <span class="option-label">${option.option_label}</span>
                        <span class="option-value">${option.option_value}</span>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-link p-0" onclick="editOption(${option.id}, ${fieldId})">
                                <i class="bi bi-pencil text-secondary"></i>
                            </button>
                            <button class="btn btn-sm btn-link p-0" onclick="deleteOption(${option.id})">
                                <i class="bi bi-trash text-danger"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            return html;
        }
        
        // 添加字段
        function addField() {
            document.getElementById('fieldId').value = '';
            document.getElementById('fieldLabel').value = '';
            document.getElementById('fieldName').value = '';
            document.getElementById('fieldNameGroup').style.display = 'block';
            document.getElementById('fieldModalTitle').textContent = '添加字段';
            new bootstrap.Modal(document.getElementById('fieldModal')).show();
        }
        
        // 编辑字段
        function editField(fieldId) {
            const field = fieldsData.find(f => f.id == fieldId);
            if (!field) return;
            
            document.getElementById('fieldId').value = field.id;
            document.getElementById('fieldLabel').value = field.field_label;
            document.getElementById('fieldName').value = field.field_name;
            document.getElementById('fieldNameGroup').style.display = 'none';
            document.getElementById('fieldModalTitle').textContent = '编辑字段';
            new bootstrap.Modal(document.getElementById('fieldModal')).show();
        }
        
        // 保存字段
        async function saveField() {
            const fieldId = document.getElementById('fieldId').value;
            const fieldLabel = document.getElementById('fieldLabel').value.trim();
            const fieldName = document.getElementById('fieldName').value.trim();
            
            if (!fieldLabel) {
                alert('请输入字段标签');
                return;
            }
            
            if (!fieldId && !fieldName) {
                alert('请输入字段名');
                return;
            }
            
            try {
                const response = await fetch(FILTER_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: fieldId ? 'update_field' : 'create_field',
                        id: fieldId || undefined,
                        field_label: fieldLabel,
                        field_name: fieldName
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('fieldModal')).hide();
                    loadFields();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('保存失败: ' + error.message);
            }
        }
        
        // 切换字段状态
        async function toggleField(fieldId, isActive) {
            try {
                const response = await fetch(FILTER_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_field',
                        id: fieldId,
                        is_active: isActive
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    loadFields();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('操作失败: ' + error.message);
            }
        }
        
        // 删除字段
        async function deleteField(fieldId) {
            if (!confirm('确定要删除这个字段吗？关联的选项和客户数据也会被删除。')) return;
            
            try {
                const response = await fetch(FILTER_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_field',
                        id: fieldId
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    loadFields();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('删除失败: ' + error.message);
            }
        }
        
        // 添加选项
        function addOption(fieldId) {
            document.getElementById('optionId').value = '';
            document.getElementById('optionFieldId').value = fieldId;
            document.getElementById('optionLabel').value = '';
            document.getElementById('optionValue').value = '';
            document.getElementById('optionValueGroup').style.display = 'block';
            setOptionColor('#6366f1');
            document.getElementById('optionModalTitle').textContent = '添加选项';
            new bootstrap.Modal(document.getElementById('optionModal')).show();
        }
        
        // 编辑选项
        function editOption(optionId, fieldId) {
            const field = fieldsData.find(f => f.id == fieldId);
            if (!field) return;
            
            const option = field.options.find(o => o.id == optionId);
            if (!option) return;
            
            document.getElementById('optionId').value = option.id;
            document.getElementById('optionFieldId').value = fieldId;
            document.getElementById('optionLabel').value = option.option_label;
            document.getElementById('optionValue').value = option.option_value;
            document.getElementById('optionValueGroup').style.display = 'none';
            setOptionColor(option.color);
            document.getElementById('optionModalTitle').textContent = '编辑选项';
            new bootstrap.Modal(document.getElementById('optionModal')).show();
        }
        
        // 设置选项颜色
        function setOptionColor(color) {
            document.getElementById('optionColor').value = color;
            document.getElementById('optionColorText').value = color;
            document.getElementById('colorPreview').style.background = color;
        }
        
        // 保存选项
        async function saveOption() {
            const optionId = document.getElementById('optionId').value;
            const fieldId = document.getElementById('optionFieldId').value;
            const optionLabel = document.getElementById('optionLabel').value.trim();
            const optionValue = document.getElementById('optionValue').value.trim();
            const color = document.getElementById('optionColorText').value;
            
            if (!optionLabel) {
                alert('请输入选项标签');
                return;
            }
            
            if (!optionId && !optionValue) {
                alert('请输入选项值');
                return;
            }
            
            try {
                const response = await fetch(FILTER_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: optionId ? 'update_option' : 'create_option',
                        id: optionId || undefined,
                        field_id: fieldId,
                        option_label: optionLabel,
                        option_value: optionValue,
                        color: color
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    bootstrap.Modal.getInstance(document.getElementById('optionModal')).hide();
                    loadFields();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('保存失败: ' + error.message);
            }
        }
        
        // 删除选项
        async function deleteOption(optionId) {
            if (!confirm('确定要删除这个选项吗？')) return;
            
            try {
                const response = await fetch(FILTER_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_option',
                        id: optionId
                    })
                });
                
                const result = await response.json();
                if (result.success) {
                    loadFields();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('删除失败: ' + error.message);
            }
        }
        
        // 显示错误
        function showError(message) {
            document.getElementById('fieldList').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>${message}
                </div>
            `;
        }
    </script>
<?php layout_footer(); ?>
