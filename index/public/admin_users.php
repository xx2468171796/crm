<?php
// 员工管理页面
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('user_manage') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取所有角色
$roles = Db::query('SELECT id, name, code FROM roles WHERE status = 1 ORDER BY id');

// 获取所有部门
$departments = Db::query('SELECT id, name FROM departments WHERE status = 1 ORDER BY sort ASC');

// 获取筛选条件
$departmentFilter = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;

// 获取所有员工（含部门信息）
$sql = '
    SELECT 
        u.id, u.username, u.realname, u.role, u.mobile, u.email, u.status, u.create_time, u.department_id,
        d.name as department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
';

if ($departmentFilter > 0) {
    $sql .= ' WHERE u.department_id = ' . $departmentFilter;
}

$sql .= ' ORDER BY u.create_time DESC';

$users = Db::query($sql);

layout_header('员工管理');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>👥 员工管理</h3>
        <button class="btn btn-primary" onclick="showAddUserModal()">
            <i class="bi bi-plus-circle"></i> 添加员工
        </button>
    </div>
    
    <!-- 部门筛选 -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="admin_users">
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="bi bi-funnel me-1"></i>按部门筛选
                    </label>
                    <select class="form-select" name="department_id" onchange="this.form.submit()">
                        <option value="0">全部部门</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $departmentFilter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($departmentFilter > 0): ?>
                <div class="col-md-2 d-flex align-items-end">
                    <a href="index.php?page=admin_users" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-1"></i>清除筛选
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>姓名</th>
                        <th>角色</th>
                        <th>部门</th>
                        <th>手机</th>
                        <th>邮箱</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['realname']) ?></td>
                        <td>
                            <?php
                            $roleColors = [
                                'super_admin' => 'bg-dark',
                                'admin' => 'bg-danger',
                                'dept_leader' => 'bg-warning text-dark',
                                'dept_admin' => 'bg-orange',
                                'sales' => 'bg-primary',
                                'service' => 'bg-info',
                                'tech' => 'bg-success',
                                'tech_manager' => 'bg-success',
                                'design_manager' => 'bg-indigo',
                                'finance' => 'bg-purple',
                                'viewer' => 'bg-secondary',
                            ];
                            $userRoles = Permission::getUserRoles($user['id']);
                            if (empty($userRoles)) {
                                $roleColor = $roleColors[$user['role']] ?? 'bg-secondary';
                                $roleName = $user['role'];
                                foreach ($roles as $r) {
                                    if ($r['code'] === $user['role']) {
                                        $roleName = $r['name'];
                                        break;
                                    }
                                }
                                echo '<span class="badge ' . $roleColor . '">' . htmlspecialchars($roleName) . '</span>';
                            } else {
                                foreach ($userRoles as $ur) {
                                    $roleColor = $roleColors[$ur['code']] ?? 'bg-secondary';
                                    echo '<span class="badge ' . $roleColor . ' me-1">' . htmlspecialchars($ur['name']) . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($user['department_name']): ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($user['department_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">未分配</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($user['mobile'] ?? '') ?></td>
                        <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                        <td>
                            <?php if ($user['status'] == 1): ?>
                                <span class="badge bg-success">正常</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $user['create_time'] ? date('Y-m-d H:i', $user['create_time']) : '-' ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $user['id'] ?>)">
                                编辑
                            </button>
                            <?php if ($user['id'] != $currentUser['id']): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                                删除
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 添加/编辑员工弹窗 -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">添加员工</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id">
                    
                    <div class="mb-3">
                        <label class="form-label">用户名 *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">姓名 *</label>
                        <input type="text" class="form-control" id="realname" name="realname" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">密码 <span class="text-muted" id="passwordHint">*</span></label>
                        <input type="password" class="form-control" id="password" name="password">
                        <small class="text-muted">编辑时留空表示不修改密码</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">角色 *</label>
                        <div id="roleCheckboxes" class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($roles as $role): ?>
                            <div class="form-check">
                                <input class="form-check-input role-checkbox" type="checkbox" 
                                       value="<?= $role['id'] ?>" id="role_<?= $role['id'] ?>" name="role_ids[]">
                                <label class="form-check-label" for="role_<?= $role['id'] ?>">
                                    <?= htmlspecialchars($role['name']) ?>
                                    <small class="text-muted">(<?= $role['code'] ?>)</small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">可选择多个角色</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">所属部门</label>
                        <select class="form-select" id="department_id" name="department_id">
                            <option value="">未分配</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">手机</label>
                        <input type="text" class="form-control" id="mobile" name="mobile">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">邮箱</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">状态</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1">正常</option>
                            <option value="0">禁用</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveUser()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
let userModal;

// API URL辅助函数
function apiUrl(path) {
    return API_URL + '/' + path;
}

document.addEventListener('DOMContentLoaded', function() {
    userModal = new bootstrap.Modal(document.getElementById('userModal'));
});

// 清除所有角色复选框
function clearRoleCheckboxes() {
    document.querySelectorAll('.role-checkbox').forEach(cb => cb.checked = false);
}

// 设置角色复选框
function setRoleCheckboxes(roleIds) {
    clearRoleCheckboxes();
    if (roleIds && roleIds.length) {
        roleIds.forEach(id => {
            const cb = document.getElementById('role_' + id);
            if (cb) cb.checked = true;
        });
    }
}

// 获取选中的角色ID
function getSelectedRoleIds() {
    const ids = [];
    document.querySelectorAll('.role-checkbox:checked').forEach(cb => {
        ids.push(parseInt(cb.value));
    });
    return ids;
}

// 显示添加员工弹窗
function showAddUserModal() {
    document.getElementById('userModalTitle').textContent = '添加员工';
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('username').disabled = false;
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('passwordHint').textContent = '*';
    clearRoleCheckboxes();
    // 默认选中销售角色
    const salesCb = document.querySelector('.role-checkbox[value="5"]');
    if (salesCb) salesCb.checked = true;
    userModal.show();
}

// 编辑员工
function editUser(id) {
    fetch(apiUrl('admin_users.php?action=get&id=' + id))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const user = data.data;
                document.getElementById('userModalTitle').textContent = '编辑员工';
                document.getElementById('userId').value = user.id;
                document.getElementById('username').value = user.username;
                document.getElementById('username').disabled = true;
                document.getElementById('realname').value = user.realname;
                document.getElementById('mobile').value = user.mobile || '';
                document.getElementById('email').value = user.email || '';
                document.getElementById('status').value = user.status;
                document.getElementById('department_id').value = user.department_id || '';
                document.getElementById('password').value = '';
                document.getElementById('password').required = false;
                document.getElementById('passwordHint').textContent = '';
                // 设置角色
                setRoleCheckboxes(user.role_ids || []);
                userModal.show();
            } else {
                showAlertModal(data.error?.message || '获取用户信息失败', 'error');
            }
        })
        .catch(err => {
            console.error('Get user error:', err);
            showAlertModal('获取用户信息失败', 'error');
        });
}

// 保存员工
function saveUser() {
    const userId = document.getElementById('userId').value;
    const roleIds = getSelectedRoleIds();
    
    if (roleIds.length === 0) {
        showAlertModal('请至少选择一个角色', 'error');
        return;
    }
    
    const data = {
        action: userId ? 'update' : 'create',
        id: userId || undefined,
        username: document.getElementById('username').value,
        realname: document.getElementById('realname').value,
        password: document.getElementById('password').value || undefined,
        mobile: document.getElementById('mobile').value,
        email: document.getElementById('email').value,
        department_id: document.getElementById('department_id').value || 0,
        status: document.getElementById('status').value,
        role_ids: roleIds
    };
    
    fetch(apiUrl('admin_users.php'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            showAlertModal(result.message || '保存成功', 'success');
            userModal.hide();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlertModal(result.error?.message || result.message || '保存失败', 'error');
        }
    })
    .catch(error => {
        console.error('Save user error:', error);
        showAlertModal('保存失败，请查看控制台错误信息', 'error');
    });
}

// 删除员工
function deleteUser(id) {
    showConfirmModal('禁用员工', '确定要禁用这个员工吗？', function() {
        fetch(apiUrl('admin_users.php'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete', id: id})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message || '操作成功', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal(data.error?.message || '操作失败', 'error');
            }
        });
    });
}
</script>

<?php layout_footer(); ?>
