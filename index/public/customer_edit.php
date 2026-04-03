<?php
// 客户编辑页面（含链接分享功能）

layout_header('编辑客户');

$user = current_user();
$customerId = intval($_GET['id'] ?? 0);

if ($customerId <= 0) {
    echo '<div class="alert alert-danger">客户ID无效</div>';
    layout_footer();
    exit;
}

// 查询客户信息
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);

if (!$customer) {
    echo '<div class="alert alert-danger">客户不存在</div>';
    layout_footer();
    exit;
}

// 权限检查 - 使用RBAC动态检查
$hasPermission = false;
if (RoleCode::isAdminRole($user['role'] ?? '')) {
    $hasPermission = true;
} elseif (RoleCode::isDeptManagerRole($user['role'] ?? '') && $customer['department_id'] == $user['department_id']) {
    $hasPermission = true;
} elseif ($customer['owner_user_id'] == $user['id']) {
    $hasPermission = true;
}

if (!$hasPermission) {
    echo '<div class="alert alert-danger">无权限访问此客户</div>';
    layout_footer();
    exit;
}

// 查询首通记录
$firstContact = Db::queryOne('SELECT * FROM first_contact WHERE customer_id = :id', ['id' => $customerId]);

// 查询链接信息
$link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :id', ['id' => $customerId]);
?>

<style>
.customer-header {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.customer-info {
    display: flex;
    gap: 20px;
    align-items: center;
}
.info-item {
    display: flex;
    flex-direction: column;
}
.info-item label {
    font-size: 12px;
    color: #666;
    margin-bottom: 2px;
}
.info-item .value {
    font-weight: 600;
    font-size: 14px;
}
</style>

<!-- 客户基本信息 -->
<div class="customer-header">
    <div class="customer-info">
        <div class="info-item">
            <label>客户姓名</label>
            <div class="value"><?= htmlspecialchars($customer['name']) ?></div>
        </div>
        <div class="info-item">
            <label>系统ID</label>
            <div class="value"><code><?= htmlspecialchars($customer['customer_code']) ?></code></div>
        </div>
        <?php if ($customer['custom_id']): ?>
        <div class="info-item">
            <label>手动ID</label>
            <div class="value"><?= htmlspecialchars($customer['custom_id']) ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <label>意向等级</label>
            <div class="value">
                <?php
                $levelMap = ['high' => '高', 'medium' => '中', 'low' => '低'];
                echo $customer['intent_level'] ? $levelMap[$customer['intent_level']] : '-';
                ?>
            </div>
        </div>
    </div>
    <div>
        <button type="button" class="btn btn-primary" id="linkShareBtn">
            <?= $link ? '链接管理' : '生成客户链接' ?>
        </button>
    </div>
</div>

<!-- 客户详细信息展示（暂时简单展示） -->
<div class="card">
    <div class="card-header">
        <h5>客户详细信息</h5>
    </div>
    <div class="card-body">
        <p>联系方式: <?= htmlspecialchars($customer['mobile'] ?? '-') ?></p>
        <p>性别: <?= htmlspecialchars($customer['gender'] ?? '-') ?></p>
        <p>年龄: <?= $customer['age'] ?? '-' ?></p>
        <p>身份: <?= htmlspecialchars($customer['identity'] ?? '-') ?></p>
        <p>需求时间: <?= htmlspecialchars($customer['demand_time_type'] ?? '-') ?></p>
        <?php if ($customer['intent_summary']): ?>
        <p>意向总结: <?= htmlspecialchars($customer['intent_summary']) ?></p>
        <?php endif; ?>
        <?php if ($firstContact): ?>
        <hr>
        <h6>首通备注:</h6>
        <p><?= nl2br(htmlspecialchars($firstContact['remark'] ?? '无')) ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- 链接管理弹窗 -->
<div class="modal fade" id="linkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">客户链接管理</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="linkContent">
                    <!-- 动态加载链接信息 -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const customerId = <?= $customerId ?>;

// 清除链接权限缓存的辅助函数
function clearLinkPermissionCache(cacheKey) {
    // 清除sessionStorage中的权限缓存
    Object.keys(sessionStorage).forEach(key => {
        if (key.startsWith(cacheKey)) {
            sessionStorage.removeItem(key);
        }
    });
    // 清除localStorage中的权限缓存
    Object.keys(localStorage).forEach(key => {
        if (key.startsWith(cacheKey)) {
            localStorage.removeItem(key);
        }
    });
}
const baseUrl = window.location.origin + window.location.pathname.replace('/public/index.php', '');

// 打开链接管理弹窗
document.getElementById('linkShareBtn').addEventListener('click', function() {
    loadLinkInfo();
    new bootstrap.Modal(document.getElementById('linkModal')).show();
});

// 加载链接信息
function loadLinkInfo() {
    fetch('/api/customer_link.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get&customer_id=' + customerId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.data) {
            showLinkManagement(data.data);
        } else {
            showGenerateButton();
        }
    });
}

// 显示生成链接按钮
function showGenerateButton() {
    document.getElementById('linkContent').innerHTML = `
        <div class="text-center py-4">
            <p class="text-muted">该客户还未生成分享链接</p>
            <button class="btn btn-primary" onclick="generateLink()">生成客户链接</button>
        </div>
    `;
}

// 显示链接管理界面
function showLinkManagement(link) {
    const shareUrl = baseUrl + '/public/share.php?token=' + link.token;
    const enabledText = link.enabled ? '已启用' : '已停用';
    const enabledClass = link.enabled ? 'success' : 'danger';
    const toggleText = link.enabled ? '停用链接' : '启用链接';
    // 使用 has_password 字段判断是否设置了密码
    const hasPassword = link.has_password !== undefined ? link.has_password : (link.password ? true : false);
    
    document.getElementById('linkContent').innerHTML = `
        <div class="mb-3">
            <label class="form-label fw-bold">分享链接</label>
            <div class="input-group">
                <input type="text" class="form-control" id="shareUrl" value="${shareUrl}" readonly>
                <button class="btn btn-outline-secondary" onclick="copyLink()">复制链接</button>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">链接状态</label>
            <div>
                <span class="badge bg-${enabledClass}">${enabledText}</span>
                <button class="btn btn-sm btn-outline-${link.enabled ? 'danger' : 'success'} ms-2" onclick="toggleLink()">${toggleText}</button>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">访问密码</label>
            <div class="input-group">
                <input type="text" class="form-control" id="linkPassword" placeholder="留空表示无密码访问" value="${link.password || ''}">
                <button class="btn btn-outline-primary" onclick="setPassword()">保存密码</button>
                <button class="btn btn-outline-secondary" onclick="clearPassword()">清除密码</button>
            </div>
            <small class="text-muted">当前: ${hasPassword ? (link.password ? '密码: ' + link.password : '已设置密码') : '无密码'}</small>
        </div>
        
        <div class="mb-3">
            <label class="form-label fw-bold">链接信息</label>
            <p class="mb-1"><small>Token: <code>${link.token}</code></small></p>
            <p class="mb-1"><small>创建时间: ${new Date(link.created_at * 1000).toLocaleString('zh-CN')}</small></p>
            <p class="mb-1"><small>更新时间: ${new Date(link.updated_at * 1000).toLocaleString('zh-CN')}</small></p>
            ${link.access_count > 0 ? `<p class="mb-1"><small>访问次数: ${link.access_count}</small></p>` : ''}
        </div>
        
        <div class="alert alert-warning">
            <strong>⚠️ 重置链接</strong>
            <p class="mb-2 small">重置后原链接将失效，新链接立即生效</p>
            <button class="btn btn-sm btn-warning" onclick="resetLink()">重置链接</button>
        </div>
    `;
}

// 生成链接
function generateLink() {
    showConfirmModal('生成链接', '确定要生成客户分享链接吗？', function() {
        fetch('/api/customer_link.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=generate&customer_id=' + customerId
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // 清除权限相关缓存
                if (data.version && data.cache_key) {
                    clearLinkPermissionCache(data.cache_key);
                    sessionStorage.setItem('link_permission_version_' + customerId, data.version);
                }
                showAlertModal(data.message, 'success');
                loadLinkInfo();
                document.getElementById('linkShareBtn').textContent = '链接管理';
            } else {
                showAlertModal(data.message, 'error');
            }
        });
    });
}

// 复制链接
function copyLink() {
    const input = document.getElementById('shareUrl');
    input.select();
    document.execCommand('copy');
    alert('链接已复制到剪贴板');
}

// 启用/停用链接
function toggleLink() {
    fetch('/api/customer_link.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle&customer_id=' + customerId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // 清除权限相关缓存
            if (data.version && data.cache_key) {
                clearLinkPermissionCache(data.cache_key);
                sessionStorage.setItem('link_permission_version_' + customerId, data.version);
            }
            alert(data.message);
            loadLinkInfo();
        } else {
            alert(data.message);
        }
    });
}

// 设置密码
function setPassword() {
    const password = document.getElementById('linkPassword').value.trim();
    
    fetch('/api/customer_link.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=set_password&customer_id=' + customerId + '&password=' + encodeURIComponent(password)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // 清除权限相关缓存
            if (data.version && data.cache_key) {
                clearLinkPermissionCache(data.cache_key);
                sessionStorage.setItem('link_permission_version_' + customerId, data.version);
            }
            alert(data.message);
            loadLinkInfo();
        } else {
            alert(data.message);
        }
    });
}

// 清除密码
function clearPassword() {
    showConfirmModal('清除密码', '确定要清除访问密码吗？清除后任何人都可以通过链接访问（只读）。', function() {
        fetch('/api/customer_link.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=set_password&customer_id=' + customerId + '&password='
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // 清除权限相关缓存
                if (data.version && data.cache_key) {
                    clearLinkPermissionCache(data.cache_key);
                    sessionStorage.setItem('link_permission_version_' + customerId, data.version);
                }
                showAlertModal(data.message, 'success');
                loadLinkInfo();
            } else {
                showAlertModal(data.message, 'error');
            }
        });
    });
}

// 重置链接
function resetLink() {
    showConfirmModal('重置链接', '确定要重置链接吗？<strong>原链接将失效，新链接立即生效！</strong>', function() {
        doResetLink();
    });
}

function doResetLink() {
    
    fetch('/api/customer_link.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=reset&customer_id=' + customerId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // 清除权限相关缓存
            if (data.version && data.cache_key) {
                clearLinkPermissionCache(data.cache_key);
                sessionStorage.setItem('link_permission_version_' + customerId, data.version);
            }
            alert(data.message);
            loadLinkInfo();
        } else {
            alert(data.message);
        }
    });
}
</script>

<?php
layout_footer();
