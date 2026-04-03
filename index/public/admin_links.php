<?php
/**
 * 管理员链接管理页面
 * 集中管理所有客户的分享链接
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('customer_view') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取筛选条件
$search = trim($_GET['search'] ?? '');
$enabled = $_GET['enabled'] ?? '';
$hasPassword = $_GET['has_password'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$pageSize = 50;
$offset = ($page - 1) * $pageSize;

// 构建SQL
$sql = 'SELECT cl.*, c.name as customer_name, c.customer_code, u.realname as creator_name
        FROM customer_links cl
        LEFT JOIN customers c ON cl.customer_id = c.id
        LEFT JOIN users u ON c.create_user_id = u.id
        WHERE 1=1';

$params = [];

if (!empty($search)) {
    $sql .= ' AND (c.name LIKE :search OR c.customer_code LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($enabled !== '') {
    $sql .= ' AND cl.enabled = :enabled';
    $params['enabled'] = intval($enabled);
}

if ($hasPassword !== '') {
    if ($hasPassword === '1') {
        $sql .= ' AND cl.password IS NOT NULL';
    } else {
        $sql .= ' AND cl.password IS NULL';
    }
}

// 获取总数
$countSql = str_replace('SELECT cl.*, c.name as customer_name, c.customer_code, u.realname as creator_name', 'SELECT COUNT(*)', $sql);
$total = Db::queryOne($countSql, $params)['COUNT(*)'] ?? 0;
$totalPages = ceil($total / $pageSize);

// 获取分页数据
$sql .= ' ORDER BY cl.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset;
$links = Db::query($sql, $params);

// 获取基础URL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
         . '://' . $_SERVER['HTTP_HOST'];

layout_header('链接管理');
?>

<style>
.link-url {
    font-family: monospace;
    font-size: 0.85em;
    color: #666;
}
.badge-enabled {
    background-color: #28a745;
}
.badge-disabled {
    background-color: #dc3545;
}
.table-actions {
    white-space: nowrap;
}
.table-actions .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>🔗 链接管理</h3>
        <div>
            <button class="btn btn-danger" id="batchDeleteBtn" style="display:none;">
                <i class="bi bi-trash"></i> 批量删除
            </button>
            <button class="btn btn-warning" id="batchDisableBtn" style="display:none;">
                <i class="bi bi-x-circle"></i> 批量禁用
            </button>
            <button class="btn btn-success" id="batchEnableBtn" style="display:none;">
                <i class="bi bi-check-circle"></i> 批量启用
            </button>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="搜索客户姓名/编号" value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-2">
                    <select class="form-select" name="enabled">
                        <option value="">全部状态</option>
                        <option value="1" <?= $enabled === '1' ? 'selected' : '' ?>>已启用</option>
                        <option value="0" <?= $enabled === '0' ? 'selected' : '' ?>>已禁用</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select class="form-select" name="has_password">
                        <option value="">全部密码状态</option>
                        <option value="1" <?= $hasPassword === '1' ? 'selected' : '' ?>>有密码</option>
                        <option value="0" <?= $hasPassword === '0' ? 'selected' : '' ?>>无密码</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="admin_links.php" class="btn btn-secondary">重置</a>
                </div>
            </form>
        </div>
    </div>

    <!-- 统计信息 -->
    <div class="alert alert-info">
        共找到 <strong><?= $total ?></strong> 个链接
    </div>

    <!-- 链接列表 -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th>客户信息</th>
                        <th>链接地址</th>
                        <th>状态</th>
                        <th>密码</th>
                        <th>访问统计</th>
                        <th>创建时间</th>
                        <th width="200">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">暂无链接数据</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($links as $link): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="link-checkbox" value="<?= $link['id'] ?>">
                        </td>
                        <td>
                            <div><strong><?= htmlspecialchars($link['customer_name'] ?? '未知') ?></strong></div>
                            <small class="text-muted"><?= htmlspecialchars($link['customer_code'] ?? '') ?></small>
                        </td>
                        <td>
                            <div class="link-url">
                                <?= $baseUrl ?>/share.php?token=<?= htmlspecialchars($link['token']) ?>
                            </div>
                            <button class="btn btn-sm btn-link p-0" onclick="copyLink('<?= $link['token'] ?>')">
                                <i class="bi bi-clipboard"></i> 复制
                            </button>
                        </td>
                        <td>
                            <?php if ($link['enabled']): ?>
                            <span class="badge badge-enabled">已启用</span>
                            <?php else: ?>
                            <span class="badge badge-disabled">已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($link['password']): ?>
                            <span class="badge bg-warning">🔒 有密码</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">无密码</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div>访问: <strong><?= $link['access_count'] ?></strong> 次</div>
                            <?php if ($link['last_access_at']): ?>
                            <small class="text-muted">
                                最后: <?= date('Y-m-d H:i', $link['last_access_at']) ?><br>
                                IP: <?= htmlspecialchars($link['last_access_ip'] ?? '') ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">从未访问</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small><?= date('Y-m-d H:i', $link['created_at']) ?></small>
                        </td>
                        <td class="table-actions">
                            <?php if ($link['enabled']): ?>
                            <button class="btn btn-sm btn-warning" onclick="toggleEnabled(<?= $link['id'] ?>, 0)">
                                禁用
                            </button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-success" onclick="toggleEnabled(<?= $link['id'] ?>, 1)">
                                启用
                            </button>
                            <?php endif; ?>
                            
                            <button class="btn btn-sm btn-info" onclick="showPasswordModal(<?= $link['id'] ?>, <?= $link['password'] ? 'true' : 'false' ?>)">
                                密码
                            </button>
                            
                            <button class="btn btn-sm btn-secondary" onclick="regenerateToken(<?= $link['id'] ?>)">
                                重生成
                            </button>
                            
                            <button class="btn btn-sm btn-danger" onclick="deleteLink(<?= $link['id'] ?>, '<?= htmlspecialchars($link['customer_name'] ?? '') ?>')">
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

    <!-- 分页 -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?p=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&enabled=<?= $enabled ?>&has_password=<?= $hasPassword ?>">上一页</a>
            </li>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="?p=<?= $i ?>&search=<?= urlencode($search) ?>&enabled=<?= $enabled ?>&has_password=<?= $hasPassword ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?p=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&enabled=<?= $enabled ?>&has_password=<?= $hasPassword ?>">下一页</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- 设置密码弹窗 -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">设置链接密码</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="passwordLinkId">
                <div class="mb-3">
                    <label class="form-label">新密码</label>
                    <input type="text" class="form-control" id="newPassword" placeholder="留空则删除密码">
                </div>
                <div id="currentPasswordInfo" class="alert alert-info" style="display:none;">
                    当前已设置密码
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="removePasswordBtn" style="display:none;">删除密码</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="savePassword()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const baseUrl = '<?= $baseUrl ?>';

// 全选/反选
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.link-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateBatchButtons();
});

// 监听单个复选框变化
document.querySelectorAll('.link-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBatchButtons);
});

// 更新批量操作按钮显示
function updateBatchButtons() {
    const checked = document.querySelectorAll('.link-checkbox:checked');
    const show = checked.length > 0;
    document.getElementById('batchEnableBtn').style.display = show ? 'inline-block' : 'none';
    document.getElementById('batchDisableBtn').style.display = show ? 'inline-block' : 'none';
    document.getElementById('batchDeleteBtn').style.display = show ? 'inline-block' : 'none';
}

// 复制链接
function copyLink(token) {
    const url = baseUrl + '/share.php?token=' + token;
    navigator.clipboard.writeText(url).then(() => {
        alert('链接已复制到剪贴板');
    }).catch(() => {
        prompt('请手动复制链接:', url);
    });
}

// 启用/禁用链接
function toggleEnabled(linkId, enabled) {
    showConfirmModal(enabled ? '启用链接' : '禁用链接', enabled ? '确定要启用此链接吗？' : '确定要禁用此链接吗？', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'toggle_enabled',
            link_id: linkId,
            enabled: enabled
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
}

// 显示密码设置弹窗
function showPasswordModal(linkId, hasPassword) {
    document.getElementById('passwordLinkId').value = linkId;
    document.getElementById('newPassword').value = '';
    
    if (hasPassword) {
        document.getElementById('currentPasswordInfo').style.display = 'block';
        document.getElementById('removePasswordBtn').style.display = 'inline-block';
    } else {
        document.getElementById('currentPasswordInfo').style.display = 'none';
        document.getElementById('removePasswordBtn').style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
}

// 保存密码
function savePassword() {
    const linkId = document.getElementById('passwordLinkId').value;
    const password = document.getElementById('newPassword').value.trim();
    
    if (!password) {
        alert('请输入密码');
        return;
    }
    
    $.post('/api/admin_link_operations.php', {
        action: 'set_password',
        link_id: linkId,
        password: password
    }, function(response) {
        if (response.success) {
            alert(response.message);
            bootstrap.Modal.getInstance(document.getElementById('passwordModal')).hide();
            location.reload();
        } else {
            alert('操作失败: ' + response.message);
        }
    }, 'json').fail(function() {
        alert('操作失败，请重试');
    });
}

// 删除密码
document.getElementById('removePasswordBtn').addEventListener('click', function() {
    const linkId = document.getElementById('passwordLinkId').value;
    
    showConfirmModal('删除密码', '确定要删除此链接的密码吗？', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'remove_password',
            link_id: linkId
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('passwordModal')).hide();
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
});

// 重新生成token
function regenerateToken(linkId) {
    showConfirmModal('重新生成Token', '重新生成Token后，旧链接将立即失效，确定继续吗？', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'regenerate_token',
            link_id: linkId
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message + '<br><br>新链接: ' + response.new_url, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
}

// 删除链接
function deleteLink(linkId, customerName) {
    showConfirmModal('删除链接', '确定要删除客户 "' + customerName + '" 的分享链接吗？<br><br><strong>此操作不可恢复！</strong>', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'delete_link',
            link_id: linkId
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
}

// 批量启用
document.getElementById('batchEnableBtn').addEventListener('click', function() {
    const linkIds = Array.from(document.querySelectorAll('.link-checkbox:checked')).map(cb => cb.value);
    
    showConfirmModal('批量启用', '确定要启用选中的 ' + linkIds.length + ' 个链接吗？', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'batch_toggle',
            link_ids: linkIds,
            enabled: 1
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
});

// 批量禁用
document.getElementById('batchDisableBtn').addEventListener('click', function() {
    const linkIds = Array.from(document.querySelectorAll('.link-checkbox:checked')).map(cb => cb.value);
    
    showConfirmModal('批量禁用', '确定要禁用选中的 ' + linkIds.length + ' 个链接吗？', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'batch_toggle',
            link_ids: linkIds,
            enabled: 0
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
});

// 批量删除
document.getElementById('batchDeleteBtn').addEventListener('click', function() {
    const linkIds = Array.from(document.querySelectorAll('.link-checkbox:checked')).map(cb => cb.value);
    
    showConfirmModal('批量删除', '确定要删除选中的 ' + linkIds.length + ' 个链接吗？<br><br><strong>此操作不可恢复！</strong>', function() {
        $.post('/api/admin_link_operations.php', {
            action: 'batch_delete',
            link_ids: linkIds
        }, function(response) {
            if (response.success) {
                showAlertModal(response.message, 'success');
                location.reload();
            } else {
                showAlertModal('操作失败: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showAlertModal('操作失败，请重试', 'error');
        });
    });
});
</script>

<?php layout_footer(); ?>
