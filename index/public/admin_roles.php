<?php
// 角色管理页面
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/RoleService.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('role_manage') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取所有角色
$roles = RoleService::getAll(true);
foreach ($roles as &$role) {
    $role['user_count'] = RoleService::getUserCount($role['id']);
}
unset($role);

// 获取权限定义（按模块分组）
$permissionGroups = RoleService::getPermissionsByModule();
$moduleNames = [
    'customer' => '客户管理',
    'finance' => '财务管理',
    'portal' => '客户门户',
    'system' => '系统管理',
    'data_scope' => '数据范围'
];

layout_header('角色管理');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>🎭 角色管理</h3>
        <button class="btn btn-primary" onclick="showAddRoleModal()">
            <i class="bi bi-plus-circle"></i> 添加角色
        </button>
    </div>

    <div class="row">
        <?php foreach ($roles as $role): 
            $permissions = json_decode($role['permissions'], true) ?? [];
            $roleColors = [
                'super_admin' => 'bg-dark',
                'admin' => 'bg-danger',
                'dept_leader' => 'bg-warning',
                'dept_admin' => 'bg-orange',
                'sales' => 'bg-primary',
                'service' => 'bg-info',
                'tech' => 'bg-success',
                'finance' => 'bg-purple',
                'viewer' => 'bg-secondary',
            ];
            $cardColor = $roleColors[$role['code']] ?? 'bg-primary';
        ?>
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-header <?= $cardColor ?> text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><?= htmlspecialchars($role['name']) ?></h5>
                        <small class="opacity-75"><?= $role['code'] ?></small>
                    </div>
                    <div class="text-end">
                        <?php if ($role['is_system']): ?>
                        <span class="badge bg-light text-dark">系统</span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark"><?= $role['user_count'] ?> 人</span>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?= htmlspecialchars($role['description'] ?? '') ?></p>
                    
                    <h6 class="mb-2">权限 (<?= count($permissions) ?>)</h6>
                    <div class="permissions-list" style="max-height: 120px; overflow-y: auto;">
                        <?php
                        if (in_array('*', $permissions)) {
                            echo '<span class="badge bg-danger">所有权限</span>';
                        } elseif (empty($permissions)) {
                            echo '<span class="text-muted small">无权限</span>';
                        } else {
                            foreach ($permissions as $perm) {
                                echo '<span class="badge bg-success me-1 mb-1">' . htmlspecialchars($perm) . '</span>';
                            }
                        }
                        ?>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <button class="btn btn-sm btn-outline-primary" onclick="editRole(<?= $role['id'] ?>)">
                        <i class="bi bi-pencil"></i> 编辑
                    </button>
                    <?php if (!$role['is_system']): ?>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRole(<?= $role['id'] ?>)" <?= $role['user_count'] > 0 ? 'disabled title="该角色下有用户"' : '' ?>>
                        <i class="bi bi-trash"></i> 删除
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 添加/编辑角色弹窗 -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalTitle">添加角色</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="roleId" name="id">
                    
                    <div class="mb-3">
                        <label class="form-label">角色名称 *</label>
                        <input type="text" class="form-control" id="roleName" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">角色代码 *</label>
                        <input type="text" class="form-control" id="roleCode" name="code" required>
                        <small class="text-muted">英文小写，如：manager</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">描述</label>
                        <textarea class="form-control" id="roleDescription" name="description" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">权限设置</label>
                        <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($permissionGroups as $module => $perms): ?>
                            <div class="mb-3">
                                <h6 class="border-bottom pb-1 mb-2">
                                    <i class="bi bi-folder me-1"></i>
                                    <?= htmlspecialchars($moduleNames[$module] ?? $module) ?>
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="toggleModulePerms('<?= $module ?>')">全选</button>
                                </h6>
                                <div class="row">
                                    <?php foreach ($perms as $perm): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input perm-checkbox perm-<?= $module ?>" type="checkbox" 
                                                   name="permissions[]" value="<?= $perm['code'] ?>" id="perm_<?= $perm['code'] ?>">
                                            <label class="form-check-label" for="perm_<?= $perm['code'] ?>" title="<?= htmlspecialchars($perm['description'] ?? '') ?>">
                                                <?= htmlspecialchars($perm['name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">数据权限范围</label>
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label small">客户模块</label>
                                <select class="form-select form-select-sm" id="dataScope_customer" name="data_scopes[customer]">
                                    <option value="self">仅自己</option>
                                    <option value="dept">本部门</option>
                                    <option value="dept_tree">本部门及下级</option>
                                    <option value="all">全部</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">财务模块</label>
                                <select class="form-select form-select-sm" id="dataScope_finance" name="data_scopes[finance]">
                                    <option value="self">仅自己</option>
                                    <option value="dept">本部门</option>
                                    <option value="dept_tree">本部门及下级</option>
                                    <option value="all">全部</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveRole()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
let roleModal;

document.addEventListener('DOMContentLoaded', function() {
    roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
});

// 切换模块权限全选
function toggleModulePerms(module) {
    const checkboxes = document.querySelectorAll('.perm-' + module);
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
}

// 清除所有权限复选框
function clearPermCheckboxes() {
    document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = false);
}

// 设置权限复选框
function setPermCheckboxes(permissions) {
    clearPermCheckboxes();
    if (permissions && permissions.length) {
        permissions.forEach(perm => {
            const cb = document.getElementById('perm_' + perm);
            if (cb) cb.checked = true;
        });
    }
}

// 获取选中的权限
function getSelectedPermissions() {
    const perms = [];
    document.querySelectorAll('.perm-checkbox:checked').forEach(cb => {
        perms.push(cb.value);
    });
    return perms;
}

// 设置数据权限范围
function setDataScopes(dataPermissions) {
    // 重置为默认值
    document.getElementById('dataScope_customer').value = 'self';
    document.getElementById('dataScope_finance').value = 'self';
    
    if (dataPermissions && dataPermissions.length) {
        dataPermissions.forEach(dp => {
            const select = document.getElementById('dataScope_' + dp.module);
            if (select) select.value = dp.scope;
        });
    }
}

// 获取数据权限范围
function getDataScopes() {
    return {
        customer: document.getElementById('dataScope_customer').value,
        finance: document.getElementById('dataScope_finance').value
    };
}

function showAddRoleModal() {
    document.getElementById('roleModalTitle').textContent = '添加角色';
    document.getElementById('roleForm').reset();
    document.getElementById('roleId').value = '';
    document.getElementById('roleCode').readOnly = false;
    clearPermCheckboxes();
    setDataScopes([]);
    roleModal.show();
}

function editRole(id) {
    fetch(apiUrl('admin_roles.php?action=get&id=' + id))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const role = data.data;
                document.getElementById('roleModalTitle').textContent = '编辑角色';
                document.getElementById('roleId').value = role.id;
                document.getElementById('roleName').value = role.name;
                document.getElementById('roleCode').value = role.code;
                document.getElementById('roleCode').readOnly = role.is_system == 1;
                document.getElementById('roleDescription').value = role.description || '';
                
                // 设置权限
                const permissions = role.permissions_array || JSON.parse(role.permissions || '[]');
                setPermCheckboxes(permissions);
                
                // 设置数据权限范围
                setDataScopes(role.data_permissions || []);
                
                roleModal.show();
            } else {
                showAlertModal(data.error?.message || '获取角色信息失败', 'error');
            }
        })
        .catch(err => {
            console.error('Get role error:', err);
            showAlertModal('获取角色信息失败', 'error');
        });
}

function saveRole() {
    const roleId = document.getElementById('roleId').value;
    const permissions = getSelectedPermissions();
    const dataScopes = getDataScopes();
    
    const data = {
        action: roleId ? 'update' : 'create',
        id: roleId || undefined,
        name: document.getElementById('roleName').value,
        code: document.getElementById('roleCode').value,
        description: document.getElementById('roleDescription').value,
        permissions: permissions,
        data_permissions: dataScopes
    };
    
    fetch(apiUrl('admin_roles.php'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showAlertModal(result.message || '保存成功', 'success');
            roleModal.hide();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlertModal(result.error?.message || result.message || '保存失败', 'error');
        }
    })
    .catch(error => {
        console.error('Save role error:', error);
        showAlertModal('保存失败', 'error');
    });
}

function deleteRole(id) {
    showConfirmModal('删除角色', '确定要删除这个角色吗？', function() {
        fetch(apiUrl('admin_roles.php'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete', id: id})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message || '删除成功', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal(data.error?.message || '删除失败', 'error');
            }
        });
    });
}
</script>

<?php layout_footer(); ?>
