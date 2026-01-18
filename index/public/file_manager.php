<?php
// 独立文件管理页面 - 全屏布局

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/permission.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../services/CustomerFilePolicy.php';

if (!function_exists('determineInternalPermission')) {
    /**
     * 根据登录用户、客户和链接配置推导内部权限
     *
     * @return string edit|view|none
     */
    function determineInternalPermission(?array $user, ?array $customer, ?array $link): string
    {
        if (!$user || !$customer) {
            return 'none';
        }

        if (($user['role'] ?? '') === 'admin') {
            return 'edit';
        }

        if ($link) {
            $allowedViewUsers = json_decode($link['allowed_view_users'] ?? '[]', true) ?: [];
            $allowedEditUsers = json_decode($link['allowed_edit_users'] ?? '[]', true) ?: [];

            if (in_array($user['id'], $allowedEditUsers, true)) {
                return 'edit';
            }

            if (in_array($user['id'], $allowedViewUsers, true)) {
                return 'view';
            }

            $orgPermission = $link['org_permission'] ?? 'edit';

            if ($orgPermission === 'edit') {
                return 'edit';
            }

            if ($orgPermission === 'view') {
                return 'view';
            }

            return 'none';
        }

        $isCreator = isset($customer['create_user_id']) && $customer['create_user_id'] == $user['id'];
        $isOwner = isset($customer['owner_user_id']) && $customer['owner_user_id'] == $user['id'];

        return ($isCreator || $isOwner) ? 'edit' : 'none';
    }
}

if (!function_exists('formatFileManagerLinkForClient')) {
    /**
     * 将文件管理链接数据转换为前端可用格式（包含明文密码）
     */
    function formatFileManagerLinkForClient(?array $link): ?array
    {
        if (!$link) {
            return null;
        }

        $payload = $link;
        $payload['has_password'] = !empty($link['password']);
        if (!empty($link['password'])) {
            $payload['password'] = decryptLinkPassword($link['password']) ?? '';
        } else {
            $payload['password'] = '';
        }

        return $payload;
    }
}

// 检查是否是外部访问
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerId = intval($_GET['customer_id'] ?? 0);

if ($customerId === 0) {
    layout_header('文件管理');
    echo '<div class="container-fluid mt-3"><div class="alert alert-danger">请指定客户ID</div></div>';
    layout_footer();
    exit;
}

// 判断访问模式
$user = current_user();
$isReadonly = false;
$isExternalAccess = false;

// 判断是否为外部访问（通过分享链接）
if (!$user) {
    // 未登录用户
    if (isset($_SESSION['share_verified_' . $customerId]) || isset($_SESSION['file_manager_share_verified_' . $customerId])) {
        // 通过分享链接访问（客户分享链接或文件管理分享链接）
        $isExternalAccess = true;
        // 检查是否有编辑权限（优先检查文件管理分享链接）
        if (isset($_SESSION['file_manager_share_readonly_' . $customerId])) {
            $isReadonly = true;
        } elseif (isset($_SESSION['file_manager_share_editable_' . $customerId])) {
            $isReadonly = false;
        } elseif (isset($_SESSION['share_readonly_' . $customerId])) {
            $isReadonly = true;
        } else {
            $isReadonly = !isset($_SESSION['share_editable_' . $customerId]);
        }
    } else {
        // 未登录且未通过分享链接，拒绝访问
        layout_header('文件管理');
        echo '<div class="container-fluid mt-3"><div class="alert alert-danger">请先登录或通过分享链接访问</div></div>';
        layout_footer();
        exit;
    }
} else {
    // 已登录用户
    // 检查是否通过文件管理分享链接访问
    if (isset($_SESSION['file_manager_share_verified_' . $customerId]) && 
        (isset($_SESSION['file_manager_share_editable_' . $customerId]) || isset($_SESSION['file_manager_share_readonly_' . $customerId]))) {
        // 通过文件管理分享链接访问，检查权限
        $isExternalAccess = true;
        // 检查是否为只读权限
        if (isset($_SESSION['file_manager_share_readonly_' . $customerId])) {
            $isReadonly = true;
        } else {
            $isReadonly = false;
        }
    } 
    // 检查是否通过客户分享链接访问
    elseif (isset($_SESSION['share_verified_' . $customerId]) && 
        (isset($_SESSION['share_editable_' . $customerId]) || isset($_SESSION['share_readonly_' . $customerId]))) {
        // 通过客户分享链接访问，检查权限
        $isExternalAccess = true;
        // 检查是否为只读权限
        if (isset($_SESSION['share_readonly_' . $customerId])) {
            $isReadonly = true;
        } else {
            $isReadonly = false;
        }
    } else {
        // 直接访问（非分享链接），使用基础权限判断
        $isReadonly = false;
        $isExternalAccess = false;
    }
}

// 加载客户数据
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);

if (!$customer) {
    layout_header('文件管理');
    echo '<div class="container-fluid mt-3"><div class="alert alert-danger">客户不存在</div></div>';
    layout_footer();
    exit;
}

// 加载链接信息（用于权限检查）
$link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :id', ['id' => $customerId]);
$fileManagerLink = Db::queryOne('SELECT * FROM file_manager_links WHERE customer_id = :id', ['id' => $customerId]);
$fileManagerLinkForClient = formatFileManagerLinkForClient($fileManagerLink);
$internalPermission = determineInternalPermission($user, $customer, $link);
if ($internalPermission === 'none' && $fileManagerLink) {
    $internalPermission = determineInternalPermission($user, $customer, $fileManagerLink);
}

if ($user && $internalPermission !== 'none' && $isExternalAccess) {
    unset(
        $_SESSION['share_readonly_' . $customerId],
        $_SESSION['share_editable_' . $customerId],
        $_SESSION['file_manager_share_readonly_' . $customerId],
        $_SESSION['file_manager_share_editable_' . $customerId]
    );
    $isExternalAccess = false;
    $isReadonly = ($internalPermission === 'view');
}

// 权限检查（外部访问跳过权限检查）
if (!$isExternalAccess) {
    if (!$user) {
        layout_header('文件管理');
        echo '<div class="container-fluid mt-3"><div class="alert alert-danger">请先登录</div></div>';
        layout_footer();
        exit;
    }

    $hasPermission = false;
    $linkCandidates = [];

    if ($fileManagerLink) {
        $linkCandidates[] = $fileManagerLink;
    }
    if ($link) {
        $linkCandidates[] = $link;
    }

    foreach ($linkCandidates as $linkCandidate) {
        if (CustomerFilePolicy::canView($user, $customer, $linkCandidate)) {
            $hasPermission = true;
            break;
        }
    }

    if (!$hasPermission) {
        // 未通过任何链接授权，回退到基础权限判断
        $hasPermission = CustomerFilePolicy::canView($user, $customer, null);
    }

    if (!$hasPermission) {
        layout_header('文件管理');
        echo '<div class="container-fluid mt-3"><div class="alert alert-danger">无权限访问此客户的文件</div></div>';
        layout_footer();
        exit;
    }
}

// 使用 CustomerFilePolicy 检查文件管理权限
// 优先使用文件管理分享链接，如果没有则使用客户分享链接
$linkForPolicy = $fileManagerLink ?: $link;
$canManageFiles = CustomerFilePolicy::canEdit($user ?: [], $customer, $linkForPolicy);
$canViewFiles = CustomerFilePolicy::canView($user ?: [], $customer, $linkForPolicy);

// 如果通过文件管理分享链接访问，需要特殊处理密码
if ($fileManagerLink && $fileManagerLink['enabled']) {
    $customerId = (int)$customer['id'];
    // 优先使用文件管理分享链接的密码
    $password = $_SESSION['file_manager_share_password_' . $customerId] ?? $_SESSION['share_password_' . $customerId] ?? null;
    $linkPermission = checkLinkPermission($fileManagerLink, $user, $password);
    
    if ($linkPermission === 'edit') {
        $canManageFiles = true;
        $canViewFiles = true;
    } elseif ($linkPermission === 'view') {
        $canManageFiles = false;
        $canViewFiles = true;
    } elseif ($linkPermission === 'none') {
        $canManageFiles = false;
        $canViewFiles = false;
    }
}

if (!$canViewFiles) {
    layout_header('文件管理');
    echo '<div class="container-fluid mt-3"><div class="alert alert-danger">无权限查看此客户的文件</div></div>';
    layout_footer();
    exit;
}

// 设置只读模式
if (!$canManageFiles) {
    $isReadonly = true;
}

$storageConfig = storage_config();
$folderUploadConfig = $storageConfig['limits']['folder_upload'] ?? [];

// 外部访问使用简化的header
if ($isExternalAccess) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
        <title>文件管理 - <?= htmlspecialchars($customer['name']) ?> - ANKOTTI</title>
        <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
        <style>
            body { font-size: 16px; line-height: 1.6; }
        </style>
    </head>
    <body>
    <div class="container-fluid" style="padding: 20px;">
        <h4 style="color: #dc2626; margin-bottom: 15px;">独立文件管理页面</h4>
        <div class="d-flex gap-2 mb-3">
            <?php if (!$isReadonly): ?>
            <a href="share.php?code=<?= htmlspecialchars($customer['customer_code']) ?>" class="btn btn-outline-secondary btn-sm">意向总结</a>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="copyCurrentPageAsImage()" id="copyImageBtn">📷 复制为图片</button>
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="resetFileManager()">重置</button>
            <button type="button" class="btn btn-success btn-sm" onclick="saveFileManager()">保存记录</button>
            <?php endif; ?>
            <a href="share.php?code=<?= htmlspecialchars($customer['customer_code']) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> 返回客户详情
            </a>
        </div>
    <?php
} else {
    layout_header('文件管理 - ' . htmlspecialchars($customer['name']));
    // 引入html2canvas库和复制为图片功能
    echo '<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>';
    echo '<script src="js/copy-to-image.js"></script>';
    ?>
    <div class="container-fluid" style="padding: 20px;">
        <h4 style="color: #dc2626; margin-bottom: 15px;">独立文件管理页面</h4>
        <div class="d-flex gap-2 mb-3">
            <?php if (!$isReadonly): ?>
            <a href="index.php?page=customer_detail&id=<?= $customerId ?>" class="btn btn-outline-secondary btn-sm">意向总结</a>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="copyCurrentPageAsImage()" id="copyImageBtn">📷 复制为图片</button>
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="resetFileManager()">重置</button>
            <button type="button" class="btn btn-success btn-sm" onclick="saveFileManager()">保存记录</button>
            <?php endif; ?>
            <?php if (!$isExternalAccess || !$isReadonly): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" id="fileManagerLinkBtn">
                <?= $fileManagerLink ? '链接管理' : '生成链接' ?>
            </button>
            <?php endif; ?>
            <a href="index.php?page=customer_detail&id=<?= $customerId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> 返回客户详情
            </a>
        </div>
    <?php
}

// 包含文件管理视图组件
$customerId = $customer['id'];
include __DIR__ . '/../views/customer/files.php';

if ($isExternalAccess) {
    ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/customer-files.js"></script>
    <script>
    // 复制当前页面为图片
    function copyCurrentPageAsImage() {
        const element = document.querySelector('.customer-files-layout') || document.body;
        html2canvas(element, {
            backgroundColor: '#ffffff',
            scale: 2,
            useCORS: true
        }).then(canvas => {
            canvas.toBlob(blob => {
                const item = new ClipboardItem({ 'image/png': blob });
                navigator.clipboard.write([item]).then(() => {
                    alert('图片已复制到剪贴板！');
                }).catch(err => {
                    console.error('复制失败:', err);
                    alert('复制失败，请重试');
                });
            });
        }).catch(err => {
            console.error('生成图片失败:', err);
            alert('生成图片失败，请重试');
        });
    }
    
    // 重置文件管理页面
    function resetFileManager() {
        showConfirmModal('重置页面', '确定要重置文件管理页面吗？这将清除所有未保存的更改。', function() {
            window.location.reload();
        });
    }
    
    // 保存文件管理记录
    function saveFileManager() {
        const saveBtn = document.querySelector('[data-action="refresh-files"]');
        if (saveBtn) {
            saveBtn.click();
            alert('文件保存成功！');
        } else {
            alert('当前没有需要保存的文件');
        }
    }
    </script>
    </body>
    </html>
    <?php
} else {
    ?>
    </div>
    <?php
    // 添加文件管理链接管理的JavaScript代码
    if (!$isExternalAccess || !$isReadonly):
    ?>
    <script>
    // 文件管理链接管理功能
    const fileManagerCustomerId = <?= $customerId ?>;
    const fileManagerLinkData = <?= json_encode($fileManagerLinkForClient) ?>;
    
    document.getElementById('fileManagerLinkBtn')?.addEventListener('click', function() {
        if (!fileManagerLinkData) {
            // 生成链接
            generateFileManagerLink();
        } else {
            // 显示链接管理弹窗
            showFileManagerLinkModal();
        }
    });
    
    // 生成文件管理链接
    function generateFileManagerLink() {
        const formData = new URLSearchParams({
            action: 'generate',
            customer_id: fileManagerCustomerId
        });
        
        fetch('/api/file_manager_link.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal('链接生成成功！', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal('生成失败: ' + data.message, 'error');
            }
        })
        .catch(err => {
            console.error('网络错误:', err);
            showAlertModal('网络错误，请稍后重试', 'error');
        });
    }
    
    // 显示文件管理链接管理弹窗
    function showFileManagerLinkModal() {
        const shareUrl = BASE_URL + '/file_manager_share.php?token=' + fileManagerLinkData.token;
        
        // 先加载用户列表
        fetch('/api/file_manager_link.php?action=get_users')
            .then(res => res.json())
            .then(data => {
                const users = data.users || [];
                const departments = data.departments || [];
                const allowedViewUsers = (fileManagerLinkData && fileManagerLinkData.allowed_view_users) ? JSON.parse(fileManagerLinkData.allowed_view_users || '[]') : [];
                const allowedEditUsers = (fileManagerLinkData && fileManagerLinkData.allowed_edit_users) ? JSON.parse(fileManagerLinkData.allowed_edit_users || '[]') : [];
                
                const modalHtml = `
                    <div class="modal fade" id="fileManagerLinkModal" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title">🔗 文件管理链接管理</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <!-- 分享链接 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>分享链接</strong></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="fileManagerShareLinkInput" value="${shareUrl}" readonly>
                                            <button class="btn btn-outline-primary" onclick="copyFileManagerShareLink()">复制</button>
                                        </div>
                                    </div>
                                    
                                    <!-- 链接状态 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>链接状态</strong></label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="fileManagerLinkEnabledSwitch" 
                                                   ${fileManagerLinkData && fileManagerLinkData.enabled ? 'checked' : ''}>
                                            <label class="form-check-label" for="fileManagerLinkEnabledSwitch">
                                                启用分享链接
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <!-- 权限设置 -->
                                    <h6 class="mb-3">🔐 权限设置</h6>
                                    
                                    <!-- 组织内权限 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>组织内权限</strong></label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="fileManagerOrgPermission" id="fileManagerOrgNone" value="none"
                                                       ${fileManagerLinkData && fileManagerLinkData.org_permission === 'none' ? 'checked' : ''}>
                                                <label class="form-check-label" for="fileManagerOrgNone">禁止访问</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="fileManagerOrgPermission" id="fileManagerOrgView" value="view"
                                                       ${fileManagerLinkData && fileManagerLinkData.org_permission === 'view' ? 'checked' : ''}>
                                                <label class="form-check-label" for="fileManagerOrgView">只读</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="fileManagerOrgPermission" id="fileManagerOrgEdit" value="edit"
                                                       ${!fileManagerLinkData || fileManagerLinkData.org_permission === 'edit' ? 'checked' : ''}>
                                                <label class="form-check-label" for="fileManagerOrgEdit">可编辑</label>
                                            </div>
                                        </div>
                                        <small class="text-muted">登录用户的默认权限</small>
                                    </div>
                                    
                                    <!-- 指定用户权限 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>指定用户权限</strong></label>
                                        
                                        <!-- 部门筛选 -->
                                        ${departments.length > 0 ? `
                                        <div class="mb-2">
                                            <select class="form-select form-select-sm" id="fileManagerDepartmentFilter" onchange="filterFileManagerUsersByDepartment()">
                                                <option value="">全部部门</option>
                                                ${departments.map(d => `<option value="${d.id}">${d.name}</option>`).join('')}
                                            </select>
                                        </div>
                                        ` : ''}
                                        
                                        <!-- 用户列表 -->
                                        <div id="fileManagerUserPermissionList" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.25rem; padding: 0.5rem;">
                                            ${users.map(u => {
                                                const viewChecked = allowedViewUsers.includes(u.id);
                                                const editChecked = allowedEditUsers.includes(u.id);
                                                return `
                                                <div class="file-manager-user-permission-item mb-2 pb-2 border-bottom" data-user-id="${u.id}" data-department-id="${u.department_id || ''}">
                                                    <div class="d-flex align-items-center">
                                                        <span class="flex-grow-1">${u.realname} (${u.username})</span>
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <input type="radio" class="btn-check" name="file_manager_user_perm_${u.id}" id="file_manager_user_none_${u.id}" value="none" ${!viewChecked && !editChecked ? 'checked' : ''}>
                                                            <label class="btn btn-outline-secondary" for="file_manager_user_none_${u.id}">无</label>
                                                            
                                                            <input type="radio" class="btn-check" name="file_manager_user_perm_${u.id}" id="file_manager_user_view_${u.id}" value="view" ${viewChecked && !editChecked ? 'checked' : ''}>
                                                            <label class="btn btn-outline-info" for="file_manager_user_view_${u.id}">只读</label>
                                                            
                                                            <input type="radio" class="btn-check" name="file_manager_user_perm_${u.id}" id="file_manager_user_edit_${u.id}" value="edit" ${editChecked ? 'checked' : ''}>
                                                            <label class="btn btn-outline-success" for="file_manager_user_edit_${u.id}">可编辑</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                `;
                                            }).join('')}
                                        </div>
                                        <small class="text-muted">为每个用户选择权限级别：无/只读/可编辑</small>
                                    </div>
                                    
                                    <hr>
                                    
                                    <!-- 访问密码 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>访问密码</strong></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="fileManagerLinkPasswordInput" 
                                                   placeholder="留空表示无密码" value="${fileManagerLinkData && fileManagerLinkData.password ? fileManagerLinkData.password : ''}">
                                            <button class="btn btn-outline-secondary" onclick="clearFileManagerPassword()">清除</button>
                                        </div>
                                        <small class="text-muted">${fileManagerLinkData && fileManagerLinkData.has_password ? (fileManagerLinkData.password ? '当前密码: ' + fileManagerLinkData.password : '已设置密码') : '未登录用户需要输入密码才能访问'}</small>
                                    </div>
                                    
                                    <!-- 密码权限级别 -->
                                    <div class="mb-3">
                                        <label class="form-label"><strong>密码权限级别</strong></label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="fileManagerPasswordPermission" id="fileManagerPwdReadonly" value="readonly"
                                                       ${!fileManagerLinkData || fileManagerLinkData.password_permission === 'readonly' ? 'checked' : ''}>
                                                <label class="form-check-label" for="fileManagerPwdReadonly">只读</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="fileManagerPasswordPermission" id="fileManagerPwdEditable" value="editable"
                                                       ${fileManagerLinkData && fileManagerLinkData.password_permission === 'editable' ? 'checked' : ''}>
                                                <label class="form-check-label" for="fileManagerPwdEditable">可编辑</label>
                                            </div>
                                        </div>
                                        <small class="text-muted">输入正确密码后的权限级别</small>
                                    </div>
                                    
                                    ${fileManagerLinkData && fileManagerLinkData.access_count ? `
                                    <hr>
                                    <div class="alert alert-info mb-0">
                                        <small>
                                            <strong>📊 访问统计：</strong>共 ${fileManagerLinkData.access_count} 次访问<br>
                                            ${fileManagerLinkData.last_access_at ? '最后访问：' + new Date(fileManagerLinkData.last_access_at * 1000).toLocaleString() : ''}
                                        </small>
                                    </div>
                                    ` : ''}
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                    <button type="button" class="btn btn-primary" onclick="saveFileManagerLinkSettings()">保存设置</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                const oldModal = document.getElementById('fileManagerLinkModal');
                if (oldModal) oldModal.remove();
                
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                new bootstrap.Modal(document.getElementById('fileManagerLinkModal')).show();
            })
            .catch(err => {
                showAlertModal('加载用户列表失败，请重试', 'error');
            });
    }
    
    // 复制文件管理分享链接
    window.copyFileManagerShareLink = function() {
        const input = document.getElementById('fileManagerShareLinkInput');
        input.select();
        document.execCommand('copy');
        showAlertModal('链接已复制到剪贴板！', 'success');
    }
    
    // 清除密码
    window.clearFileManagerPassword = function() {
        document.getElementById('fileManagerLinkPasswordInput').value = '';
    }
    
    // 按部门筛选用户
    window.filterFileManagerUsersByDepartment = function() {
        const departmentId = document.getElementById('fileManagerDepartmentFilter').value;
        const items = document.querySelectorAll('.file-manager-user-permission-item');
        
        items.forEach(item => {
            if (!departmentId || item.dataset.departmentId === departmentId) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    // 保存文件管理链接设置
    window.saveFileManagerLinkSettings = function() {
        try {
            const enabled = document.getElementById('fileManagerLinkEnabledSwitch').checked ? 1 : 0;
            const password = document.getElementById('fileManagerLinkPasswordInput').value.trim();
            
            // 安全获取单选按钮值
            const orgPermissionEl = document.querySelector('input[name="fileManagerOrgPermission"]:checked');
            const passwordPermissionEl = document.querySelector('input[name="fileManagerPasswordPermission"]:checked');
            
            if (!orgPermissionEl) {
                showAlertModal('请选择组织内权限', 'error');
                return;
            }
            
            if (!passwordPermissionEl) {
                showAlertModal('请选择密码权限', 'error');
                return;
            }
            
            const orgPermission = orgPermissionEl.value;
            const passwordPermission = passwordPermissionEl.value;
            
            // 从单选按钮中收集用户权限
            const allowedViewUsers = [];
            const allowedEditUsers = [];
            
            document.querySelectorAll('.file-manager-user-permission-item').forEach(item => {
                const userId = parseInt(item.dataset.userId);
                const permissionEl = document.querySelector(`input[name="file_manager_user_perm_${userId}"]:checked`);
                
                if (permissionEl) {
                    const permission = permissionEl.value;
                    if (permission === 'view') {
                        allowedViewUsers.push(userId);
                    } else if (permission === 'edit') {
                        allowedEditUsers.push(userId);
                    }
                }
            });
            
            const formData = new URLSearchParams({
                action: 'update',
                customer_id: fileManagerCustomerId,
                enabled: enabled,
                password: password,
                org_permission: orgPermission,
                password_permission: passwordPermission,
                allowed_view_users: JSON.stringify(allowedViewUsers),
                allowed_edit_users: JSON.stringify(allowedEditUsers)
            });
        
            fetch('/api/file_manager_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlertModal('设置保存成功！', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('fileManagerLinkModal')).hide();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showAlertModal('保存失败: ' + data.message, 'error');
                }
            })
            .catch(err => {
                console.error('网络错误:', err);
                showAlertModal('网络错误，请稍后重试: ' + err.message, 'error');
            });
        } catch (error) {
            console.error('保存设置时出错:', error);
            showAlertModal('保存失败: ' + error.message, 'error');
        }
    }
    </script>
    <?php
    endif;
    ?>
    <script>
    // 复制当前页面为图片
    function copyCurrentPageAsImage() {
        if (typeof copyCurrentTabAsImage === 'function') {
            // 如果存在全局的复制为图片函数，使用它
            copyCurrentTabAsImage();
        } else {
            // 否则使用html2canvas直接实现
            const element = document.querySelector('.customer-files-layout') || document.body;
            html2canvas(element, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true
            }).then(canvas => {
                canvas.toBlob(blob => {
                    const item = new ClipboardItem({ 'image/png': blob });
                    navigator.clipboard.write([item]).then(() => {
                        alert('图片已复制到剪贴板！');
                    }).catch(err => {
                        console.error('复制失败:', err);
                        alert('复制失败，请重试');
                    });
                });
            }).catch(err => {
                console.error('生成图片失败:', err);
                alert('生成图片失败，请重试');
            });
        }
    }
    
    // 重置文件管理页面
    function resetFileManager() {
        showConfirmModal('重置页面', '确定要重置文件管理页面吗？这将清除所有未保存的更改。', function() {
            window.location.reload();
        });
    }
    
    // 保存文件管理记录
    function saveFileManager() {
        // 触发文件上传的保存（如果存在）
        const saveBtn = document.querySelector('[data-action="refresh-files"]');
        if (saveBtn) {
            saveBtn.click();
            alert('文件保存成功！');
        } else {
            alert('当前没有需要保存的文件');
        }
    }
    </script>
    <?php
    layout_footer();
}
?>

