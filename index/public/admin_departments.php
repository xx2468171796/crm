<?php
/**
 * 部门管理页面
 * 提供部门的增删改查、启用禁用、排序调整等功能
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('dept_manage') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取所有部门及其人员数量
$departments = Db::query('
    SELECT 
        d.*,
        COUNT(u.id) as user_count
    FROM departments d
    LEFT JOIN users u ON d.id = u.department_id
    GROUP BY d.id
    ORDER BY d.sort ASC, d.id ASC
');

layout_header('部门管理');
?>

<div class="container-fluid py-4">
    <!-- 页面标题 -->
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="bi bi-building text-primary"></i> 部门管理
                    </h2>
                    <p class="text-muted mb-0">管理系统部门信息，设置部门权限和人员分配</p>
                </div>
                <button class="btn btn-primary btn-lg shadow-sm" onclick="showAddDeptModal()">
                    <i class="bi bi-plus-circle me-2"></i>添加部门
                </button>
            </div>
        </div>
    </div>

    <!-- 部门列表卡片 -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php if (empty($departments)): ?>
                <div class="alert alert-info border-0 shadow-sm">
                    <i class="bi bi-info-circle me-2"></i>
                    暂无部门数据，请点击“添加部门”创建第一个部门。
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="60" class="text-center">ID</th>
                        <th>部门名称</th>
                        <th width="80" class="text-center">排序</th>
                        <th width="100" class="text-center">状态</th>
                        <th width="100" class="text-center">人员</th>
                        <th width="150">创建时间</th>
                        <th width="260" class="text-center">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $index => $dept): ?>
                    <tr>
                        <td class="text-center">
                            <span class="badge bg-light text-dark"><?= $dept['id'] ?></span>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($dept['name']) ?></div>
                            <?php if ($dept['remark']): ?>
                                <small class="text-muted"><?= htmlspecialchars($dept['remark']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $dept['sort'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($dept['status'] == 1): ?>
                                <span class="badge bg-success">启用</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($dept['user_count'] > 0): ?>
                                <a href="admin_users.php?department_id=<?= $dept['id'] ?>" 
                                   class="badge bg-info bg-opacity-10 text-info text-decoration-none px-3 py-2">
                                    <i class="bi bi-people-fill me-1"></i><?= $dept['user_count'] ?> 人
                                </a>
                            <?php else: ?>
                                <span class="badge bg-light text-muted px-3 py-2">
                                    <i class="bi bi-people me-1"></i>0 人
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= $dept['create_time'] ? date('Y-m-d H:i', $dept['create_time']) : '-' ?></td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center flex-wrap">
                                <button class="btn btn-sm btn-primary" onclick="editDept(<?= $dept['id'] ?>)">
                                    <i class="bi bi-pencil-square me-1"></i>编辑
                                </button>
                                <button class="btn btn-sm btn-<?= $dept['status'] ? 'warning' : 'success' ?>" 
                                        onclick="toggleDeptStatus(<?= $dept['id'] ?>, <?= $dept['status'] ?>)">
                                    <i class="bi bi-<?= $dept['status'] ? 'pause-circle-fill' : 'play-circle-fill' ?> me-1"></i>
                                    <?= $dept['status'] ? '禁用' : '启用' ?>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteDept(<?= $dept['id'] ?>, <?= $dept['user_count'] ?>)">
                                    <i class="bi bi-trash-fill me-1"></i>删除
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加/编辑部门弹窗 -->
<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deptModalTitle">添加部门</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="deptForm">
                    <input type="hidden" id="deptId" name="id">
                    
                    <div class="mb-3">
                        <label for="deptName" class="form-label">部门名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="deptName" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="deptSort" class="form-label">排序</label>
                        <input type="number" class="form-control" id="deptSort" name="sort" value="0">
                        <small class="text-muted">数字越小越靠前</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="deptStatus" name="status" checked>
                            <label class="form-check-label" for="deptStatus">启用</label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="deptRemark" class="form-label">备注</label>
                        <textarea class="form-control" id="deptRemark" name="remark" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveDept()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
// 显示添加部门弹窗
function showAddDeptModal() {
    document.getElementById('deptModalTitle').textContent = '添加部门';
    document.getElementById('deptForm').reset();
    document.getElementById('deptId').value = '';
    document.getElementById('deptStatus').checked = true;
    
    const modal = new bootstrap.Modal(document.getElementById('deptModal'));
    modal.show();
}

// 编辑部门
function editDept(id) {
    fetch(`/api/department_operations.php?action=get&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const dept = data.department;
                document.getElementById('deptModalTitle').textContent = '编辑部门';
                document.getElementById('deptId').value = dept.id;
                document.getElementById('deptName').value = dept.name;
                document.getElementById('deptSort').value = dept.sort;
                document.getElementById('deptStatus').checked = dept.status == 1;
                document.getElementById('deptRemark').value = dept.remark || '';
                
                const modal = new bootstrap.Modal(document.getElementById('deptModal'));
                modal.show();
            } else {
                alert(data.message || '获取部门信息失败');
            }
        })
        .catch(err => {
            console.error(err);
            alert('获取部门信息失败');
        });
}

// 保存部门
function saveDept() {
    const form = document.getElementById('deptForm');
    const formData = new FormData(form);
    
    const id = document.getElementById('deptId').value;
    formData.append('action', id ? 'update' : 'create');
    formData.append('status', document.getElementById('deptStatus').checked ? '1' : '0');
    
    fetch('/api/department_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || '操作失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('操作失败');
    });
}

// 删除部门
function deleteDept(id, userCount) {
    if (userCount > 0) {
        showConfirmModal('部门有用户', `该部门下有 ${userCount} 个用户，请先转移用户后再删除。<br><br>是否查看该部门的用户？`, function() {
            window.location.href = `admin_users.php?department_id=${id}`;
        });
        return;
    }
    
    showConfirmModal('删除部门', '确定要删除这个部门吗？<strong>此操作不可恢复。</strong>', function() {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        fetch('/api/department_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message, 'success');
                location.reload();
            } else {
                showAlertModal(data.message || '删除失败', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showAlertModal('删除失败', 'error');
        });
    });
}

// 启用/禁用部门
function toggleDeptStatus(id, currentStatus) {
    const action = currentStatus ? '禁用' : '启用';
    showConfirmModal(action + '部门', `确定要${action}这个部门吗？`, function() {
        doToggleDeptStatus(id);
    });
}

function doToggleDeptStatus(id) {
    
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    
    fetch('/api/department_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || '操作失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('操作失败');
    });
}

// 移动部门排序
function moveDept(id, direction) {
    const formData = new FormData();
    formData.append('action', 'move');
    formData.append('id', id);
    formData.append('direction', direction);
    
    fetch('/api/department_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || '操作失败');
        }
    })
    .catch(err => {
        console.error(err);
        alert('操作失败');
    });
}

// 初始化Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    // 初始化所有tooltip
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
/* 自定义样式优化 */
.card {
    border-radius: 12px;
    overflow: hidden;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    border-radius: 8px;
}

.table > :not(caption) > * > * {
    padding: 1rem 0.75rem;
}

.badge {
    font-weight: 500;
    padding: 0.35em 0.65em;
}

.btn-group-sm > .btn {
    padding: 0.375rem 0.75rem;
}

/* 图标圆形背景 */
.bg-primary.bg-opacity-10 {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* 悬停效果 */
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

/* 按钮组优化 */
.btn-group .btn {
    border-radius: 0;
}

.btn-group .btn:first-child {
    border-top-left-radius: 0.25rem;
    border-bottom-left-radius: 0.25rem;
}

.btn-group .btn:last-child {
    border-top-right-radius: 0.25rem;
    border-bottom-right-radius: 0.25rem;
}

/* 模态框优化 */
.modal-content {
    border-radius: 12px;
    border: none;
}

.modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    padding: 1.25rem 1.5rem;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    padding: 1rem 1.5rem;
}

/* 表单优化 */
.form-control:focus,
.form-select:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
}

/* 响应式优化 */
@media (max-width: 768px) {
    .btn-lg {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    
    h2 {
        font-size: 1.5rem;
    }
    
    .btn-group-sm > .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
}
</style>

<?php layout_footer(); ?>
