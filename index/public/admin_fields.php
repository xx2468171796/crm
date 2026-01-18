<?php
// 字段管理页面
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('field_manage') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取所有自定义字段
$fields = Db::query('SELECT * FROM custom_fields ORDER BY sort_order, id');

layout_header('字段管理');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>📝 自定义字段管理</h3>
        <button class="btn btn-primary" onclick="showAddFieldModal()">
            <i class="bi bi-plus-circle"></i> 添加字段
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th width="50">排序</th>
                        <th>字段名称</th>
                        <th>字段代码</th>
                        <th>字段类型</th>
                        <th>是否必填</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fields)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">暂无自定义字段</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($fields as $field): ?>
                    <tr>
                        <td><?= $field['sort_order'] ?></td>
                        <td><?= htmlspecialchars($field['field_name']) ?></td>
                        <td><code><?= htmlspecialchars($field['field_code']) ?></code></td>
                        <td>
                            <?php
                            $typeMap = [
                                'text' => '单行文本',
                                'textarea' => '多行文本',
                                'select' => '下拉选择',
                                'radio' => '单选',
                                'checkbox' => '多选',
                                'date' => '日期',
                            ];
                            echo $typeMap[$field['field_type']] ?? $field['field_type'];
                            ?>
                        </td>
                        <td>
                            <?php if ($field['is_required']): ?>
                                <span class="badge bg-danger">必填</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">选填</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($field['status']): ?>
                                <span class="badge bg-success">启用</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('Y-m-d H:i', $field['create_time']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="editField(<?= $field['id'] ?>)">
                                编辑
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteField(<?= $field['id'] ?>)">
                                删除
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 添加/编辑字段弹窗 -->
<div class="modal fade" id="fieldModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fieldModalTitle">添加字段</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="fieldForm">
                    <input type="hidden" id="fieldId" name="id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">字段名称 *</label>
                            <input type="text" class="form-control" id="fieldName" name="field_name" required>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">字段代码 *</label>
                            <input type="text" class="form-control" id="fieldCode" name="field_code" required>
                            <small class="text-muted">英文小写+下划线，如：custom_field_1</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">字段类型 *</label>
                            <select class="form-select" id="fieldType" name="field_type" required onchange="toggleOptions()">
                                <option value="text">单行文本</option>
                                <option value="textarea">多行文本</option>
                                <option value="select">下拉选择</option>
                                <option value="radio">单选</option>
                                <option value="checkbox">多选</option>
                                <option value="date">日期</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">是否必填</label>
                            <select class="form-select" id="isRequired" name="is_required">
                                <option value="0">选填</option>
                                <option value="1">必填</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" class="form-control" id="sortOrder" name="sort_order" value="0">
                        </div>
                    </div>
                    
                    <div class="mb-3" id="optionsContainer" style="display:none;">
                        <label class="form-label">选项设置</label>
                        <textarea class="form-control" id="fieldOptions" name="field_options" rows="3" placeholder="每行一个选项，例如：&#10;选项1&#10;选项2&#10;选项3"></textarea>
                        <small class="text-muted">每行一个选项</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">状态</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1">启用</option>
                            <option value="0">禁用</option>
                        </select>
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

<script>
let fieldModal;

document.addEventListener('DOMContentLoaded', function() {
    fieldModal = new bootstrap.Modal(document.getElementById('fieldModal'));
});

// 根据字段类型显示/隐藏选项设置
function toggleOptions() {
    const fieldType = document.getElementById('fieldType').value;
    const optionsContainer = document.getElementById('optionsContainer');
    
    if (['select', 'radio', 'checkbox'].includes(fieldType)) {
        optionsContainer.style.display = 'block';
    } else {
        optionsContainer.style.display = 'none';
    }
}

// 显示添加字段弹窗
function showAddFieldModal() {
    document.getElementById('fieldModalTitle').textContent = '添加字段';
    document.getElementById('fieldForm').reset();
    document.getElementById('fieldId').value = '';
    document.getElementById('fieldCode').readOnly = false;
    toggleOptions();
    fieldModal.show();
}

// 编辑字段
function editField(id) {
    fetch(apiUrl('field_get.php?id=' + id))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const field = data.data;
                document.getElementById('fieldModalTitle').textContent = '编辑字段';
                document.getElementById('fieldId').value = field.id;
                document.getElementById('fieldName').value = field.field_name;
                document.getElementById('fieldCode').value = field.field_code;
                document.getElementById('fieldCode').readOnly = true;
                document.getElementById('fieldType').value = field.field_type;
                document.getElementById('isRequired').value = field.is_required;
                document.getElementById('sortOrder').value = field.sort_order;
                document.getElementById('status').value = field.status;
                
                // 设置选项
                if (field.field_options) {
                    const options = JSON.parse(field.field_options);
                    document.getElementById('fieldOptions').value = options.join('\n');
                }
                
                toggleOptions();
                fieldModal.show();
            } else {
                showAlertModal(data.message, 'error');
            }
        });
}

// 保存字段
function saveField() {
    const formData = new FormData(document.getElementById('fieldForm'));
    
    fetch(apiUrl('field_save.php'), {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlertModal(data.message, 'success');
            fieldModal.hide();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlertModal(data.message, 'error');
        }
    });
}

// 删除字段
function deleteField(id) {
    showConfirmModal('删除字段', '确定要删除这个字段吗？<strong>删除后相关数据将无法恢复！</strong>', function() {
        fetch(apiUrl('field_delete.php'), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal(data.message, 'error');
            }
        });
    });
}
</script>

<?php layout_footer(); ?>
