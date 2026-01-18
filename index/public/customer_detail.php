<?php
// 客户详情页面 - 整合所有模块（首通/异议/成交/文件/自评）

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/permission.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

// 检查是否是外部访问
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$customerId = intval($_GET['id'] ?? 0);
$isNew = $customerId === 0;

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

        if (RoleCode::isAdminRole($user['role'] ?? '')) {
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

// 检查是否是AJAX请求只返回某个模块
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == 1;
$module = $_GET['module'] ?? '';

// 判断访问模式
$user = current_user();
$isReadonly = false;
$isExternalAccess = false;

// 判断是否为外部访问
if (!$user) {
    // 未登录用户
    if (isset($_GET['readonly']) && $_GET['readonly'] == 1) {
        // 明确指定只读模式
        $isReadonly = true;
        $isExternalAccess = true;
    } elseif (!$isNew && isset($_SESSION['share_verified_' . $customerId])) {
        // 通过分享链接访问
        $isExternalAccess = true;
        // 检查是否有编辑权限（输入了密码）
        $isReadonly = !isset($_SESSION['share_editable_' . $customerId]);
    }
} else {
    // 已登录用户
    // 检查是否通过分享链接访问（必须同时有share_verified和share_editable/share_readonly标记）
    if (!$isNew && isset($_SESSION['share_verified_' . $customerId]) && 
        (isset($_SESSION['share_editable_' . $customerId]) || isset($_SESSION['share_readonly_' . $customerId]))) {
        // 通过分享链接访问，检查权限
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

// 如果是编辑模式，加载客户数据
$customer = null;
$firstContact = null;
$link = null;
$internalPermission = $isNew ? 'edit' : 'none';

if (!$isNew) {
    $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
    
    if (!$customer) {
        if ($isAjax) {
            echo '<div class="alert alert-danger">客户不存在</div>';
            exit;
        }
        echo '<div class="alert alert-danger">客户不存在</div>';
        layout_footer();
        exit;
    }
    
    // 加载链接信息（用于权限检查）
    $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :id', ['id' => $customerId]);

    $internalPermission = determineInternalPermission($user, $customer, $link);

    // 登录用户拥有内部权限时，优先使用内部视图，避免误判为分享访问
    if ($user && $internalPermission !== 'none' && $isExternalAccess) {
        unset($_SESSION['share_readonly_' . $customerId], $_SESSION['share_editable_' . $customerId]);
        $isExternalAccess = false;
        $isReadonly = ($internalPermission === 'view');
    }
    
    // 权限检查（外部访问跳过权限检查）
    if (!$isExternalAccess) {
        if (!$user) {
            if ($isAjax) {
                echo '<div class="alert alert-danger">请先登录</div>';
                exit;
            }
            echo '<div class="alert alert-danger">请先登录</div>';
            layout_footer();
            exit;
        }
        
        if (!$isNew && $internalPermission === 'none') {
            if ($isAjax) {
                echo '<div class="alert alert-danger">无权限访问此客户</div>';
                exit;
            }
            echo '<div class="alert alert-danger">无权限访问此客户</div>';
            layout_footer();
            exit;
        }

        if (!$isNew) {
            $isReadonly = ($internalPermission === 'view');
        }
    }
    
    // 加载首通记录
    $firstContact = Db::queryOne('SELECT * FROM first_contact WHERE customer_id = :id', ['id' => $customerId]);
}

$storageConfig = storage_config();
$folderUploadConfig = $storageConfig['limits']['folder_upload'] ?? [];

// 如果是AJAX请求，只返回指定模块的HTML
if ($isAjax && $module === 'objection' && !$isNew) {
    include __DIR__ . '/../views/customer/objection.php';
    exit;
}

// 外部访问不需要登录
if (!$isExternalAccess) {
    layout_header('客户详情');
    // 引入html2canvas库和复制为图片功能
    require_once __DIR__ . '/../core/url.php';
    echo '<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>';
    echo '<script src="' . Url::js('copy-to-image.js') . '"></script>';
    echo '<script src="' . Url::js('attachment-upload.js') . '"></script>';
    echo '<script src="' . Url::js('recording.js') . '?v=' . time() . '"></script>';
} else {
    // 外部访问使用简化的header
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- 引入html2canvas库 -->
        <?php
        require_once __DIR__ . '/../core/url.php';
        ?>
        <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
        <script src="<?= Url::js('recording.js') ?>?v=<?= time() ?>"></script>
        <title>客户详情 - ANKOTTI</title>
        <style>
            body { font-size: 18px; line-height: 1.6; }
            .container { max-width: 1400px; }
        </style>
    </head>
    <body>
    <div class="container mt-3">
    <?php
}
?>

<!-- 样式已解耦到独立文件 -->
<link rel="stylesheet" href="css/customer-detail.css?v=<?= time() ?>">

<?php if ($isExternalAccess): ?>
<!-- 外部访问提示 -->
<div class="alert alert-<?= $isReadonly ? 'warning' : 'info' ?>">
    <?php if ($isReadonly): ?>
        <strong>🔒 只读模式</strong> - 您正在通过分享链接访问此页面，所有编辑功能已禁用。
    <?php else: ?>
        <strong>✓ 已验证</strong> - <?= $user ? '您有权限编辑此客户信息。' : '您已通过密码验证，可以编辑此客户信息。' ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" id="customerForm" <?= $isReadonly ? 'onsubmit="return false;"' : '' ?>>
    <?php if (!$isNew): ?>
    <input type="hidden" name="customer_id" value="<?= $customerId ?>">
    <?php endif; ?>
    
    <div class="main-container">
        <!-- 顶部信息栏 -->
        <div class="top-bar">
            <div>
                <label>客户姓名 *</label>
                <input type="text" name="name" class="form-control form-control-sm" style="width:120px;" 
                       value="<?= $customer ? htmlspecialchars($customer['name']) : '' ?>" required <?= $isReadonly ? 'readonly' : '' ?>>
            </div>
            <div>
                <label>联系方式</label>
                <input type="text" name="mobile" class="form-control form-control-sm" style="width:140px;"
                       value="<?= $customer ? htmlspecialchars($customer['mobile']) : '' ?>">
            </div>
            <div>
                <label>客户别名</label>
                <input type="text" name="alias" class="form-control form-control-sm" style="width:120px;"
                       value="<?= $customer ? htmlspecialchars($customer['alias'] ?? '') : '' ?>" placeholder="门户显示名">
            </div>
            <div>
                <label>客户群名称</label>
                <div class="input-group" style="width:180px;">
                    <input type="text" name="customer_group" id="customer_group_input" class="form-control form-control-sm"
                           value="<?= $customer ? htmlspecialchars($customer['customer_group'] ?? '') : '' ?>" placeholder="可选">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyCustomerGroup()" title="复制客户群名称">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>
            <div>
                <label>活动标签</label>
                <input type="text" name="activity_tag" class="form-control form-control-sm" style="width:120px;"
                       value="<?= $customer ? htmlspecialchars($customer['activity_tag'] ?? '') : '' ?>" placeholder="可选">
            </div>
            <div>
                <label>群码</label>
                <input type="text" class="form-control form-control-sm" style="width:120px; background:#f1f5f9;"
                       value="<?= $customer ? htmlspecialchars($customer['group_code'] ?? '自动生成') : '自动生成' ?>" readonly title="自动生成，不可修改">
            </div>
                        <div>
                <label>性别</label>
                <select name="gender" class="form-select form-select-sm" style="width:70px;">
                    <option value="">-</option>
                    <option value="男" <?= $customer && $customer['gender'] === '男' ? 'selected' : '' ?>>男</option>
                    <option value="女" <?= $customer && $customer['gender'] === '女' ? 'selected' : '' ?>>女</option>
                </select>
            </div>
            <div>
                <label>年龄</label>
                <input type="number" name="age" class="form-control form-control-sm" style="width:70px;" 
                       value="<?= $customer ? $customer['age'] : '' ?>" min="0" max="120">
            </div>
            <div>
                <label>ID</label>
                <input type="text" name="custom_id" class="form-control form-control-sm" style="width:100px;" 
                       value="<?= $customer ? htmlspecialchars($customer['custom_id']) : '' ?>" placeholder="手动填写">
            </div>
            <div>
                <label>自动生成ID</label>
                <input type="text" class="form-control form-control-sm" style="width:180px;" 
                       value="<?= $customer ? htmlspecialchars($customer['customer_code']) : '保存后生成' ?>" readonly>
            </div>
            <div style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
                <?php if (!$isReadonly): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>意向总结</button>
                <button type="button" class="btn btn-outline-info btn-sm" onclick="copyCurrentTabAsImage()" id="copyImageBtn">📷 复制为图片</button>
                <button type="button" class="btn btn-outline-warning btn-sm" onclick="resetForm()">重置</button>
                <button type="submit" class="btn btn-success btn-sm">保存记录</button>
                <?php if (!$isNew): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="linkShareBtn">
                    <?= $link ? '链接管理' : '生成链接' ?>
                </button>
                <?php if (canOrAdmin(PermissionCode::CUSTOMER_EDIT)): ?>
                <button type="button" class="btn btn-outline-info btn-sm" id="techAssignBtn" onclick="openTechAssignModal()">
                    👨‍💻 分配技术
                </button>
                <?php endif; ?>
                <?php if (canOrAdmin(PermissionCode::CONTRACT_CREATE)): ?>
                <a href="index.php?page=finance_contract_create&customer_id=<?= $customerId ?>" class="btn btn-outline-success btn-sm">
                    📄 新建合同
                </a>
                <?php endif; ?>
                <?php endif; ?>
                <?php else: ?>
                <span class="badge bg-warning text-dark" style="font-size: 15px; padding: 8px 16px;">只读模式</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isNew): ?>
        <!-- 自定义筛选字段 -->
        <div class="filter-fields-bar" id="filterFieldsBar">
            <div class="filter-fields-container">
                <span class="filter-fields-label"><i class="bi bi-funnel"></i> 分类标签</span>
                <div class="filter-fields-list" id="filterFieldsList">
                    <!-- 动态加载 -->
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div style="display: flex;">
            <!-- 左侧Tab -->
            <div class="sidebar">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" data-tab="first_contact">首通</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="objection">异议处理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="deal">敲定成交</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="service">正式服务</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="feedback">客户回访</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="files">文件管理</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="finance">财务</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="evaluation">沟通自评</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-tab="projects">项目</a>
                    </li>
                </ul>
            </div>

            <!-- 右侧内容 -->
            <div class="content-area" style="display: flex; flex-direction: column;">
                <!-- 首通模块 -->
                <div class="tab-content-section active" id="tab-first_contact" style="display: flex; flex-direction: column; flex: 1;">
                    <?php 
                        require_once __DIR__ . '/../core/db.php';
                        require_once __DIR__ . '/../core/auth.php';
                        require_once __DIR__ . '/../core/layout.php';
                        require_once __DIR__ . '/../core/permission.php'; 
                    ?>
                    <?php include __DIR__ . '/../views/customer/first_contact.php'; ?>
                </div>

                <!-- 异议处理模块 -->
                <div class="tab-content-section" id="tab-objection" style="display: flex; flex-direction: column; flex: 1;">
                    <?php include __DIR__ . '/../views/customer/objection.php'; ?>
                </div>

                <!-- 敲定成交模块 -->
                <div class="tab-content-section" id="tab-deal" style="display: flex; flex-direction: column; flex: 1;">
                    <?php include __DIR__ . '/../views/customer/deal.php'; ?>
                </div>

                <!-- 正式服务模块 -->
                <div class="tab-content-section" id="tab-service">
                    <div class="alert alert-info">正式服务模块（占位）</div>
                </div>

                <!-- 客户回访模块 -->
                <div class="tab-content-section" id="tab-feedback">
                    <div class="alert alert-info">客户回访模块（占位）</div>
                </div>

                <!-- 文件管理模块 -->
                <div class="tab-content-section" id="tab-files" style="display: flex; flex-direction: column; flex: 1;">
                    <?php include __DIR__ . '/../views/customer/files.php'; ?>
                </div>

                <!-- 财务模块 -->
                <div class="tab-content-section" id="tab-finance" style="display: flex; flex-direction: column; flex: 1;">
                    <?php include __DIR__ . '/../views/customer/finance.php'; ?>
                </div>

                <!-- 沟通自评模块 -->
                <div class="tab-content-section" id="tab-evaluation">
                    <div class="alert alert-info">沟通自评模块开发中...</div>
                </div>

                <!-- 项目模块 -->
                <div class="tab-content-section" id="tab-projects" style="display: flex; flex-direction: column; flex: 1;">
                    <?php include __DIR__ . '/../views/customer/projects.php'; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// 表单提交处理（全局AJAX）
document.getElementById('customerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // 根据当前激活的Tab判断提交到哪个API
    const activeTab = document.querySelector('.tab-content-section.active');
    const activeTabId = activeTab ? activeTab.id : '';
    
    // 直接使用绝对路径
    let submitUrl = '/api/customer_save.php';
    let submitterValue = '';
    
    // 根据Tab ID确定API
    if (activeTabId === 'tab-objection') {
        submitUrl = '/api/objection_save.php';
        submitterValue = 'save_objection';
    } else if (activeTabId === 'tab-deal') {
        submitUrl = '/api/deal_save.php';
        submitterValue = 'save_deal';
    }
    
    console.log('提交URL:', submitUrl);
    
    const submitter = { value: submitterValue };
    
    // 全部使用AJAX提交
    const formData = new FormData(this);
    
    // [TRACE] 调试首通备注
    console.log('[TRACE] remark value:', formData.get('remark'));
    
    // 如果在首通模块，检查是否有录音文件需要一起提交
    if (activeTabId === 'tab-first_contact' && window.recordingAudioBlob && window.recordingAudioFilename) {
        console.log('[CustomerDetail] 检测到录音数据，将在保存记录后上传:', window.recordingAudioFilename);
        // 录音文件会在表单保存成功后单独上传（见下面的success回调）
    }
    
    $.ajax({
        url: submitUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // 如果保存成功且有录音数据，上传录音文件
                if (activeTabId === 'tab-first_contact' && window.recordingAudioBlob && window.recordingAudioFilename) {
                    console.log('[CustomerDetail] 客户记录保存成功，开始上传录音文件:', window.recordingAudioFilename);
                    
                    const recordingFormData = new FormData();
                    const customerId = document.querySelector('input[name="customer_id"]')?.value || 
                                     new URLSearchParams(window.location.search).get('id') || 
                                     (response.customerId ? response.customerId.toString() : '0');
                    
                    recordingFormData.append('customer_id', customerId);
                    recordingFormData.append('category', 'client_material');
                    recordingFormData.append('upload_source', 'first_contact');
                    
                    // 将Blob转换为File对象
                    const recordingFile = new File([window.recordingAudioBlob], window.recordingAudioFilename, {
                        type: 'audio/webm',
                        lastModified: Date.now()
                    });
                    recordingFormData.append('files[]', recordingFile);
                    
                    // 上传录音文件
                    fetch('/api/customer_files.php', {
                        method: 'POST',
                        body: recordingFormData,
                        credentials: 'same-origin'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            console.log('[CustomerDetail] 录音文件上传成功');
                            // 清理暂存数据
                            window.recordingAudioBlob = null;
                            window.recordingAudioFilename = null;
                            // 如果当前在文件管理模块，触发刷新
                            if (typeof window.refreshFileList === 'function') {
                                window.refreshFileList();
                            }
                        } else {
                            console.error('[CustomerDetail] 录音文件上传失败:', data.message);
                        }
                    })
                    .catch(err => {
                        console.error('[CustomerDetail] 录音文件上传错误:', err);
                        // 尝试使用XMLHttpRequest作为降级方案
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '/api/customer_files.php', true);
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const data = JSON.parse(xhr.responseText);
                                    if (data.success) {
                                        console.log('[CustomerDetail] 录音文件上传成功（XHR）');
                                        window.recordingAudioBlob = null;
                                        window.recordingAudioFilename = null;
                                        if (typeof window.refreshFileList === 'function') {
                                            window.refreshFileList();
                                        }
                                    }
                                } catch (e) {
                                    console.error('[CustomerDetail] 解析响应失败:', e);
                                }
                            }
                        };
                        xhr.send(recordingFormData);
                    });
                }
                
                // 如果需要复制链接
                if (response.copyLink && response.shareUrl) {
                    const copyToClipboard = (text) => {
                        // 方法1: 使用现代 Clipboard API
                        if (navigator.clipboard && window.isSecureContext) {
                            return navigator.clipboard.writeText(text).then(() => true).catch(() => false);
                        }
                        
                        // 方法2: 使用 document.execCommand 作为回退
                        return new Promise((resolve) => {
                            try {
                                const textarea = document.createElement('textarea');
                                textarea.value = text;
                                textarea.style.position = 'fixed';
                                textarea.style.opacity = 0;
                                document.body.appendChild(textarea);
                                textarea.select();
                                const success = document.execCommand('copy');
                                document.body.removeChild(textarea);
                                resolve(success);
                            } catch (e) {
                                resolve(false);
                            }
                        });
                    };
                    
                    // 复制链接到剪贴板
                    copyToClipboard(response.shareUrl).then((success) => {
                        if (success) {
                            showAlertModal('✅ 客户链接已复制到剪贴板！', 'success');
                        } else {
                            // 如果复制失败，显示链接让用户手动复制
                            showAlertModal(
                                '✅ 客户创建成功！<br><br>' +
                                '请手动复制以下链接：<br>' +
                                '<div class="input-group mt-2">' +
                                `<input type="text" class="form-control" value="${response.shareUrl}" id="shareLinkInput">` +
                                '<button class="btn btn-outline-secondary" type="button" onclick="copyShareLink()">复制</button>' +
                                '</div>',
                                'info',
                                10000 // 10秒后自动关闭
                            );
                            
                            // 自动选中输入框
                            setTimeout(() => {
                                const input = document.getElementById('shareLinkInput');
                                if (input) input.select();
                            }, 100);
                        }
                    });
                }
                
                // 如果已经有复制链接的提示，就不显示默认的成功提示
                if (!(response.copyLink && response.shareUrl)) {
                    showAlertModal('✅ ' + response.message, 'success');
                }
                
                // 判断保存类型
                if (submitter && submitter.value === 'save_objection') {
                    // 异议处理：2秒后刷新异议处理数据
                    setTimeout(function() {
                        refreshObjectionData();
                        // 清空表单
                        document.querySelector('textarea[name="solution"]').value = '';
                        document.querySelector('input[name="method_custom"]').value = '';
                    }, 2000);
                } else if (submitter && submitter.value === 'save_deal') {
                    // 敲定成交：2秒后停留在当前Tab
                    setTimeout(function() {
                        showAlertModal('数据已保存', 'info');
                    }, 2000);
                } else {
                    // 其他模块：2秒后跳转
                    setTimeout(function() {
                        if (response.redirect) {
                            window.location.href = response.redirect;
                        } else {
                            window.location.reload();
                        }
                    }, 2000);
                }
            } else {
                showAlertModal(response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', xhr);
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);
            
            // 尝试解析错误信息
            let errorMsg = '提交失败，请稍后重试';
            if (xhr.responseText) {
                // 如果返回的是HTML，尝试提取错误信息
                const parser = new DOMParser();
                const doc = parser.parseFromString(xhr.responseText, 'text/html');
                const errorText = doc.body.textContent || doc.body.innerText;
                if (errorText.length < 500) {
                    errorMsg = errorText;
                }
            }
            showAlertModal(errorMsg, 'error');
        }
    });
});

// Tab切换
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        switchTab(this.getAttribute('data-tab'));
    });
});

// 复制客户群名称
window.copyCustomerGroup = function() {
    const input = document.getElementById('customer_group_input');
    if (input && input.value) {
        navigator.clipboard.writeText(input.value).then(() => {
            showAlertModal('已复制客户群名称', 'success', 1500);
        }).catch(() => {
            input.select();
            document.execCommand('copy');
            showAlertModal('已复制客户群名称', 'success', 1500);
        });
    } else {
        showAlertModal('客户群名称为空', 'warning', 1500);
    }
};

// 复制分享链接
window.copyShareLink = function() {
    const input = document.getElementById('shareLinkInput');
    if (input) {
        input.select();
        document.execCommand('copy');
        
        // 显示复制成功提示
        const button = input.nextElementSibling;
        if (button) {
            const originalText = button.textContent;
            button.textContent = '已复制！';
            button.classList.remove('btn-outline-secondary');
            button.classList.add('btn-success');
            
            // 2秒后恢复原状
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('btn-success');
                button.classList.add('btn-outline-secondary');
            }, 2000);
        }
    }
};

// Tab切换函数
window.switchTab = function(tabName) {
    // 移除所有active
    document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-content-section').forEach(t => t.classList.remove('active'));
    
    // 添加当前active
    const targetLink = document.querySelector('.sidebar .nav-link[data-tab="' + tabName + '"]');
    if (targetLink) {
        targetLink.classList.add('active');
    }
    const targetTab = document.getElementById('tab-' + tabName);
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // 更新URL hash，以便F5刷新后保持当前Tab状态
    if (window.location.hash !== '#tab-' + tabName) {
        window.location.hash = '#tab-' + tabName;
        // 使用history.replaceState避免在历史记录中创建新条目
        if (history.replaceState) {
            history.replaceState(null, null, '#tab-' + tabName);
        }
    }
    
    // 如果切换到首通Tab，初始化录音功能
    if (tabName === 'first_contact') {
        setTimeout(function() {
            console.log('[CustomerDetail] 切换到首通模块，尝试初始化录音功能');
            if (typeof window.initRecording === 'function') {
                window.initRecording();
            } else {
                console.warn('[CustomerDetail] recording.js未加载');
            }
        }, 300);
    }
    
    // 如果切换到项目Tab，加载项目列表
    if (tabName === 'projects') {
        setTimeout(function() {
            if (typeof loadProjects === 'function') {
                loadProjects();
            }
        }, 100);
    }
    
    // 如果切换到异议处理Tab，刷新数据
    <?php if (!$isNew): ?>
    if (tabName === 'objection') {
        refreshObjectionData();
    }
    
    // 如果切换到文件管理Tab，自动刷新文件列表
    if (tabName === 'files') {
        // 延迟一下确保tab已切换完成，并且customer-files.js已加载
        setTimeout(function() {
            console.log('[CustomerDetail] 切换到文件管理模块，刷新文件列表');
            if (typeof window.refreshFileList === 'function') {
                window.refreshFileList();
            } else {
                // 如果refreshFileList不存在，尝试直接调用customer-files.js的内部方法
                // 等待customer-files.js加载完成
                let attempts = 0;
                const maxAttempts = 20; // 增加到20次尝试，确保有足够时间加载
                function checkAndRefresh() {
                    attempts++;
                    if (typeof window.refreshFileList === 'function') {
                        console.log('[CustomerDetail] 文件列表刷新函数已加载，开始刷新');
                        window.refreshFileList();
                    } else if (attempts < maxAttempts) {
                        setTimeout(checkAndRefresh, 200);
                    } else {
                        console.warn('[CustomerDetail] 无法刷新文件列表：refreshFileList函数未找到（已尝试' + attempts + '次）');
                    }
                }
                checkAndRefresh();
            }
        }, 300);
    }
    <?php endif; ?>
};

// 刷新异议处理数据
window.refreshObjectionData = function() {
    const customerId = <?= $customerId ?>;
    if (!customerId) return;
    
    $.ajax({
        url: window.location.pathname + window.location.search + '&ajax=1&module=objection',
        type: 'GET',
        success: function(html) {
            const objectionTab = document.getElementById('tab-objection');
            if (objectionTab) {
                objectionTab.innerHTML = html;
            }
        },
        error: function(xhr, status, error) {
            console.error('刷新异议处理数据失败:', status, error);
        }
    });
}

// 异议处理历史区域交互
window.toggleHistory = function() {
    const records = document.getElementById('historyRecords');
    const icon = document.getElementById('toggleIcon');
    const text = document.getElementById('toggleText');
    
    if (!records || !icon || !text) return;
    
    if (records.style.display === 'none') {
        records.style.display = 'block';
        icon.textContent = '▲';
        text.textContent = '收起';
    } else {
        records.style.display = 'none';
        icon.textContent = '▼';
        text.textContent = '展开';
    }
};

window.editObjection = function(id) {
    const record = document.getElementById('record-' + id);
    if (!record) return;
    record.querySelector('.objection-content').style.display = 'none';
    record.querySelector('.objection-edit').style.display = 'block';
};

window.cancelEdit = function(id) {
    const record = document.getElementById('record-' + id);
    if (!record) return;
    record.querySelector('.objection-content').style.display = 'block';
    record.querySelector('.objection-edit').style.display = 'none';
};

window.saveEdit = function(id) {
    const script = $('#edit-script-' + id).val();
    
    $.ajax({
        url: '../api/objection_update.php',
        type: 'POST',
        data: {
            id: id,
            response_script: script
        },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                showAlertModal('✅ 修改成功！', 'success');
                setTimeout(() => {
                    if (typeof refreshObjectionData === 'function') {
                        refreshObjectionData();
                    } else {
                        window.location.reload();
                    }
                }, 2000);
            } else {
                showAlertModal('修改失败: ' + data.message, 'error');
            }
        }
    });
};

window.deleteObjection = function(id) {
    showConfirmModal('确定要删除这条异议处理记录吗？', function() {
        $.ajax({
            url: '../api/objection_delete.php',
            type: 'POST',
            data: {
                id: id
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    showAlertModal('✅ 删除成功！', 'success');
                    setTimeout(() => {
                        if (typeof refreshObjectionData === 'function') {
                            refreshObjectionData();
                        } else {
                            window.location.reload();
                        }
                    }, 2000);
                } else {
                    showAlertModal('删除失败: ' + data.message, 'error');
                }
            }
        });
    });
};

// 页面加载时检查URL hash，自动切换到对应Tab
window.addEventListener('load', function() {
    const hash = window.location.hash;
    if (hash && hash.startsWith('#tab-')) {
        const tabName = hash.substring(5); // 去掉 '#tab-'
        switchTab(tabName);
        // 如果是文件管理模块，需要额外等待一下再刷新，确保customer-files.js已完全加载
        if (tabName === 'files') {
            setTimeout(function() {
                console.log('[CustomerDetail] 页面加载时检测到文件管理模块，尝试刷新文件列表');
                if (typeof window.refreshFileList === 'function') {
                    window.refreshFileList();
                } else {
                    // 等待customer-files.js加载完成
                    let attempts = 0;
                    const maxAttempts = 20;
                    function checkAndRefresh() {
                        attempts++;
                        if (typeof window.refreshFileList === 'function') {
                            console.log('[CustomerDetail] 文件列表刷新函数已加载，开始刷新');
                            window.refreshFileList();
                        } else if (attempts < maxAttempts) {
                            setTimeout(checkAndRefresh, 200);
                        }
                    }
                    checkAndRefresh();
                }
            }, 500);
        }
    }
    
    // 初始化附件上传组件
    if (typeof AttachmentUpload !== 'undefined') {
        // 初始化首通附件上传
        AttachmentUpload.init({
            containerId: 'first-contact-attachment-upload',
            customerId: <?= ($customer && isset($customer['id']) && $customer['id'] > 0) ? $customer['id'] : 0 ?>,
            uploadSource: 'first_contact',
            isReadonly: <?= $isReadonly ? 'true' : 'false' ?>
        });
        
        // 初始化异议附件上传
        AttachmentUpload.init({
            containerId: 'objection-attachment-upload',
            customerId: <?= ($customer && isset($customer['id']) && $customer['id'] > 0) ? $customer['id'] : 0 ?>,
            uploadSource: 'objection',
            isReadonly: <?= $isReadonly ? 'true' : 'false' ?>
        });
    }
    
    // 初始化录音功能（简化版本，依赖recording.js的自动初始化）
    // recording.js会在加载后自动尝试初始化，这里只做辅助检查
    setTimeout(function() {
        console.log('[CustomerDetail] 检查录音功能初始化状态...');
        
        // 如果recording.js已加载，直接调用初始化（recording.js的自动初始化可能已经执行了）
        if (typeof window.initRecording === 'function') {
            console.log('[CustomerDetail] recording.js已加载，调用初始化');
            try {
                window.initRecording();
            } catch (err) {
                console.error('[CustomerDetail] 初始化录音功能失败:', err);
            }
        } else {
            console.warn('[CustomerDetail] recording.js未加载，等待自动初始化...');
            // 等待最多3秒
            let attempts = 0;
            const maxAttempts = 15; // 3秒
            
            function checkAndInit() {
                attempts++;
                if (typeof window.initRecording === 'function') {
                    console.log('[CustomerDetail] recording.js已加载，开始初始化');
                    try {
                        window.initRecording();
                    } catch (err) {
                        console.error('[CustomerDetail] 初始化失败:', err);
                    }
                } else if (attempts < maxAttempts) {
                    setTimeout(checkAndInit, 200);
                } else {
                    console.error('[CustomerDetail] recording.js加载超时');
                }
            }
            
            setTimeout(checkAndInit, 500);
        }
    }, 1000);
});

// 重置表单
window.resetForm = function() {
    <?php if ($isNew): ?>
    // 新增模式：清空所有字段
    showConfirmModal('确定要清空所有字段吗？', function() {
        document.getElementById('customerForm').reset();
    });
    <?php else: ?>
    // 编辑模式：刷新页面恢复到保存前的状态
    showConfirmModal('确定要恢复到保存前的状态吗？', function() {
        window.location.reload();
    });
    <?php endif; ?>
}

<?php if (!$isNew): ?>
// 链接分享功能
const customerId = <?= $customerId ?>;
const linkData = <?= json_encode($link) ?>;
const customerCode = '<?= $customer['customer_code'] ?>';

document.getElementById('linkShareBtn')?.addEventListener('click', function() {
    showLinkManageModal();
});

// 链接管理弹窗
window.showLinkManageModal = function() {
    const shareUrl = BASE_URL + '/share.php?code=' + customerCode;
    
    // 先加载用户列表
    fetch('/api/customer_link.php?action=get_users')
        .then(res => res.json())
        .then(data => {
            const users = data.users || [];
            const departments = data.departments || [];
            const allowedViewUsers = (linkData && linkData.allowed_view_users) ? JSON.parse(linkData.allowed_view_users || '[]') : [];
            const allowedEditUsers = (linkData && linkData.allowed_edit_users) ? JSON.parse(linkData.allowed_edit_users || '[]') : [];
            
            const modalHtml = `
                <div class="modal fade" id="linkManageModal" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">🔗 链接管理</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- 多区域分享链接 -->
                                <div class="mb-3">
                                    <label class="form-label"><strong>🌐 分享链接</strong></label>
                                    <div id="regionLinksContainer">
                                        <div class="text-muted small">加载区域链接中...</div>
                                    </div>
                                </div>
                                
                                <!-- 链接状态 -->
                                <div class="mb-3">
                                    <label class="form-label"><strong>链接状态</strong></label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="linkEnabledSwitch" 
                                               ${linkData && linkData.enabled ? 'checked' : ''}>
                                        <label class="form-check-label" for="linkEnabledSwitch">
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
                                            <input class="form-check-input" type="radio" name="orgPermission" id="orgNone" value="none"
                                                   ${linkData && linkData.org_permission === 'none' ? 'checked' : ''}>
                                            <label class="form-check-label" for="orgNone">禁止访问</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="orgPermission" id="orgView" value="view"
                                                   ${linkData && linkData.org_permission === 'view' ? 'checked' : ''}>
                                            <label class="form-check-label" for="orgView">只读</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="orgPermission" id="orgEdit" value="edit"
                                                   ${!linkData || linkData.org_permission === 'edit' ? 'checked' : ''}>
                                            <label class="form-check-label" for="orgEdit">可编辑</label>
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
                                        <select class="form-select form-select-sm" id="departmentFilter" onchange="filterUsersByDepartment()">
                                            <option value="">全部部门</option>
                                            ${departments.map(d => `<option value="${d.id}">${d.name}</option>`).join('')}
                                        </select>
                                    </div>
                                    ` : ''}
                                    
                                    <!-- 用户列表 -->
                                    <div id="userPermissionList" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.25rem; padding: 0.5rem;">
                                        ${users.map(u => {
                                            const viewChecked = allowedViewUsers.includes(u.id);
                                            const editChecked = allowedEditUsers.includes(u.id);
                                            return `
                                            <div class="user-permission-item mb-2 pb-2 border-bottom" data-user-id="${u.id}" data-department-id="${u.department_id || ''}">
                                                <div class="d-flex align-items-center">
                                                    <span class="flex-grow-1">${u.realname} (${u.username})</span>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <input type="radio" class="btn-check" name="user_perm_${u.id}" id="user_none_${u.id}" value="none" ${!viewChecked && !editChecked ? 'checked' : ''}>
                                                        <label class="btn btn-outline-secondary" for="user_none_${u.id}">无</label>
                                                        
                                                        <input type="radio" class="btn-check" name="user_perm_${u.id}" id="user_view_${u.id}" value="view" ${viewChecked && !editChecked ? 'checked' : ''}>
                                                        <label class="btn btn-outline-info" for="user_view_${u.id}">只读</label>
                                                        
                                                        <input type="radio" class="btn-check" name="user_perm_${u.id}" id="user_edit_${u.id}" value="edit" ${editChecked ? 'checked' : ''}>
                                                        <label class="btn btn-outline-success" for="user_edit_${u.id}">可编辑</label>
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
                                        <input type="text" class="form-control" id="linkPasswordInput" 
                                               placeholder="留空表示无密码">
                                        <button class="btn btn-outline-secondary" onclick="clearPassword()">清除</button>
                                    </div>
                                    <small class="text-muted">未登录用户需要输入密码才能访问</small>
                                </div>
                                
                                <!-- 密码权限级别 -->
                                <div class="mb-3">
                                    <label class="form-label"><strong>密码权限级别</strong></label>
                                    <div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="passwordPermission" id="pwdReadonly" value="readonly"
                                                   ${!linkData || linkData.password_permission === 'readonly' ? 'checked' : ''}>
                                            <label class="form-check-label" for="pwdReadonly">只读</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="passwordPermission" id="pwdEditable" value="editable"
                                                   ${linkData && linkData.password_permission === 'editable' ? 'checked' : ''}>
                                            <label class="form-check-label" for="pwdEditable">可编辑</label>
                                        </div>
                                    </div>
                                    <small class="text-muted">输入正确密码后的权限级别</small>
                                </div>
                                
                                ${linkData && linkData.access_count ? `
                                <hr>
                                <div class="alert alert-info mb-0">
                                    <small>
                                        <strong>📊 访问统计：</strong>共 ${linkData.access_count} 次访问<br>
                                        ${linkData.last_access_at ? '最后访问：' + new Date(linkData.last_access_at * 1000).toLocaleString() : ''}
                                    </small>
                                </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="saveLinkSettings()">保存设置</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const oldModal = document.getElementById('linkManageModal');
            if (oldModal) oldModal.remove();
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            new bootstrap.Modal(document.getElementById('linkManageModal')).show();
            
            // 加载多区域链接
            loadRegionLinks();
        })
        .catch(err => {
            showAlertModal('加载用户列表失败，请重试', 'error');
        });
}

// 加载多区域分享链接
function loadRegionLinks() {
    const container = document.getElementById('regionLinksContainer');
    if (!container) return;
    
    fetch('/api/customer_link.php?action=get_region_urls&customer_code=' + encodeURIComponent(customerCode))
        .then(res => res.json())
        .then(data => {
            console.log('[REGION_DEBUG] API返回:', data);
            if (data.success && data.regions && data.regions.length > 0) {
                container.innerHTML = data.regions.map((r, idx) => `
                    <div class="input-group mb-2">
                        <span class="input-group-text" style="min-width: 100px;">
                            ${r.is_default ? '⭐ ' : ''}${r.region_name}
                        </span>
                        <input type="text" class="form-control region-link-input" id="regionLink_${idx}" value="${r.url}" readonly>
                        <button class="btn btn-outline-primary" type="button" data-link-idx="${idx}">复制</button>
                    </div>
                `).join('');
                
                // 绑定复制按钮事件
                container.querySelectorAll('button[data-link-idx]').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const idx = this.dataset.linkIdx;
                        const input = document.getElementById('regionLink_' + idx);
                        if (input) {
                            input.select();
                            document.execCommand('copy');
                            showAlertModal('链接已复制到剪贴板！', 'success');
                        }
                    });
                });
            } else {
                // 没有配置区域，显示默认链接
                const defaultUrl = BASE_URL + '/share.php?code=' + customerCode;
                container.innerHTML = `
                    <div class="input-group">
                        <input type="text" class="form-control" id="shareLinkInput" value="${defaultUrl}" readonly>
                        <button class="btn btn-outline-primary" type="button" id="copyDefaultBtn">复制</button>
                    </div>
                    <small class="text-muted mt-1 d-block">未配置分享节点，使用默认链接</small>
                `;
                document.getElementById('copyDefaultBtn').addEventListener('click', function() {
                    const input = document.getElementById('shareLinkInput');
                    input.select();
                    document.execCommand('copy');
                    showAlertModal('链接已复制到剪贴板！', 'success');
                });
            }
        })
        .catch(err => {
            console.error('[REGION_DEBUG] 加载失败:', err);
            container.innerHTML = '<div class="text-danger small">加载区域链接失败</div>';
        });
}

// 复制指定区域链接（备用）
window.copyRegionLink = function(url) {
    const input = document.createElement('input');
    input.value = url;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    showAlertModal('链接已复制到剪贴板！', 'success');
}

// 复制分享链接（兼容旧版）
window.copyShareLink = function() {
    const input = document.getElementById('shareLinkInput');
    if (input) {
        input.select();
        document.execCommand('copy');
        showAlertModal('链接已复制到剪贴板！', 'success');
    }
}

// 清除密码
window.clearPassword = function() {
    document.getElementById('linkPasswordInput').value = '';
}

// 按部门筛选用户
window.filterUsersByDepartment = function() {
    const departmentId = document.getElementById('departmentFilter').value;
    const items = document.querySelectorAll('.user-permission-item');
    
    items.forEach(item => {
        if (!departmentId || item.dataset.departmentId === departmentId) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// 保存链接设置
window.saveLinkSettings = function() {
    console.log('saveLinkSettings 函数被调用');
    
    try {
        const enabled = document.getElementById('linkEnabledSwitch').checked ? 1 : 0;
        const password = document.getElementById('linkPasswordInput').value.trim();
        
        // 安全获取单选按钮值
        const orgPermissionEl = document.querySelector('input[name="orgPermission"]:checked');
        const passwordPermissionEl = document.querySelector('input[name="passwordPermission"]:checked');
        
        if (!orgPermissionEl) {
            console.error('组织内权限未选择');
            showAlertModal('请选择组织内权限', 'error');
            return;
        }
        
        if (!passwordPermissionEl) {
            console.error('密码权限未选择');
            showAlertModal('请选择密码权限', 'error');
            return;
        }
        
        const orgPermission = orgPermissionEl.value;
        const passwordPermission = passwordPermissionEl.value;
        
        // 如果密码为空，给出提示（但不阻止保存）
        if (password === '') {
            console.warn('密码为空，将使用无密码访问模式');
        }
        
        // 从单选按钮中收集用户权限
        const allowedViewUsers = [];
        const allowedEditUsers = [];
        
        document.querySelectorAll('.user-permission-item').forEach(item => {
            const userId = parseInt(item.dataset.userId);
            const permissionEl = document.querySelector(`input[name="user_perm_${userId}"]:checked`);
            
            if (permissionEl) {
                const permission = permissionEl.value;
                if (permission === 'view') {
                    allowedViewUsers.push(userId);
                } else if (permission === 'edit') {
                    allowedEditUsers.push(userId);
                }
            }
            // permission === 'none' 或未选择时不添加到任何列表
        });
        
        const formData = new URLSearchParams({
            action: 'update',
            customer_id: customerId,
            enabled: enabled,
            password: password,
            org_permission: orgPermission,
            password_permission: passwordPermission,
            allowed_view_users: JSON.stringify(allowedViewUsers),
            allowed_edit_users: JSON.stringify(allowedEditUsers)
        });
    
    console.log('保存链接设置:', {
        action: 'update',
        customer_id: customerId,
        enabled: enabled,
        password: password ? '***' : '',
        org_permission: orgPermission,
        password_permission: passwordPermission,
        allowed_view_users: allowedViewUsers,
        allowed_edit_users: allowedEditUsers
    });
    
        fetch('/api/customer_link.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData.toString()
        })
        .then(res => {
            console.log('API响应状态:', res.status);
            return res.json();
        })
        .then(data => {
            console.log('API响应数据:', data);
            if (data.success) {
                // 清除权限相关缓存
                if (data.version && data.cache_key) {
                    // 清除sessionStorage中的权限缓存
                    const cachePrefix = data.cache_key;
                    Object.keys(sessionStorage).forEach(key => {
                        if (key.startsWith(cachePrefix)) {
                            sessionStorage.removeItem(key);
                        }
                    });
                    // 清除localStorage中的权限缓存
                    Object.keys(localStorage).forEach(key => {
                        if (key.startsWith(cachePrefix)) {
                            localStorage.removeItem(key);
                        }
                    });
                    // 存储新的版本号
                    sessionStorage.setItem('link_permission_version_' + customerId, data.version);
                }
                showAlertModal('设置保存成功！', 'success');
                bootstrap.Modal.getInstance(document.getElementById('linkManageModal')).hide();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal('保存失败: ' + data.message, 'error');
                console.error('保存失败:', data);
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

// ========== 分配技术功能 ==========
window.openTechAssignModal = function() {
    const customerId = <?= $customerId ?>;
    if (!customerId) {
        showAlertModal('请先保存客户后再分配技术', 'warning');
        return;
    }
    
    fetch(`${API_URL}/customer_tech_assign.php?action=list&customer_id=${customerId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showAlertModal('加载失败: ' + data.message, 'error');
                return;
            }
            
            const { customer, assignments, available_techs } = data.data;
            
            // 生成已分配列表
            let assignedHtml = '';
            if (assignments && assignments.length > 0) {
                assignedHtml = assignments.map(a => `
                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                        <span>👨‍💻 ${a.tech_name || a.tech_username}</span>
                        <div>
                            <small class="text-muted me-2">由 ${a.assigned_by_name} 分配</small>
                            <button class="btn btn-sm btn-outline-danger" onclick="unassignTech(${customerId}, ${a.tech_user_id})">移除</button>
                        </div>
                    </div>
                `).join('');
            } else {
                assignedHtml = '<div class="text-muted">暂无分配的技术人员</div>';
            }
            
            // 生成可选技术列表（排除已分配的）
            const assignedIds = (assignments || []).map(a => a.tech_user_id);
            const availableTechs = (available_techs || []).filter(t => !assignedIds.includes(t.id));
            
            let availableHtml = '';
            if (availableTechs.length > 0) {
                availableHtml = `
                    <select class="form-select mb-2" id="techToAssign">
                        <option value="">-- 选择技术人员 --</option>
                        ${availableTechs.map(t => `<option value="${t.id}">${t.realname || t.username}</option>`).join('')}
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="assignTech(${customerId})">添加分配</button>
                `;
            } else {
                availableHtml = '<div class="text-muted">没有更多可分配的技术人员</div>';
            }
            
            const modalHtml = `
                <div class="modal fade" id="techAssignModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">👨‍💻 分配技术 - ${customer.name}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <h6 class="mb-3">已分配的技术人员</h6>
                                <div id="assignedTechList" class="mb-4">${assignedHtml}</div>
                                
                                <hr>
                                
                                <h6 class="mb-3">添加技术人员</h6>
                                <div id="availableTechList">${availableHtml}</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const oldModal = document.getElementById('techAssignModal');
            if (oldModal) oldModal.remove();
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            new bootstrap.Modal(document.getElementById('techAssignModal')).show();
        })
        .catch(err => {
            showAlertModal('加载技术人员列表失败: ' + err.message, 'error');
        });
}

window.assignTech = function(customerId) {
    const techUserId = document.getElementById('techToAssign').value;
    if (!techUserId) {
        showAlertModal('请选择技术人员', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'assign');
    formData.append('customer_id', customerId);
    formData.append('tech_user_id', techUserId);
    
    fetch(`${API_URL}/customer_tech_assign.php`, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlertModal('分配成功', 'success');
            bootstrap.Modal.getInstance(document.getElementById('techAssignModal')).hide();
            setTimeout(() => openTechAssignModal(), 500);
        } else {
            showAlertModal('分配失败: ' + data.message, 'error');
        }
    })
    .catch(err => {
        showAlertModal('网络错误: ' + err.message, 'error');
    });
}

window.unassignTech = function(customerId, techUserId) {
    showConfirmModal('移除技术人员', '确定要移除该技术人员的分配吗？', function() {
        const formData = new FormData();
        formData.append('action', 'unassign');
        formData.append('customer_id', customerId);
        formData.append('tech_user_id', techUserId);
        
        fetch(`${API_URL}/customer_tech_assign.php`, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal('移除成功', 'success');
                bootstrap.Modal.getInstance(document.getElementById('techAssignModal')).hide();
                setTimeout(() => openTechAssignModal(), 500);
            } else {
                showAlertModal('移除失败: ' + data.message, 'error');
            }
        })
        .catch(err => {
            showAlertModal('网络错误: ' + err.message, 'error');
        });
    });
}
<?php endif; ?>

// ========== 自定义筛选字段功能 ==========
<?php if (!$isNew): ?>
let filterFieldsData = [];
let customerFilterValues = {};

async function loadFilterFields() {
    try {
        // 加载字段定义
        const fieldsRes = await fetch('/api/customer_filter_fields.php?action=list');
        const fieldsData = await fieldsRes.json();
        if (fieldsData.success) {
            filterFieldsData = fieldsData.data;
        }
        
        // 加载客户当前值
        const valuesRes = await fetch('/api/customer_filter_fields.php?action=customer_values&customer_id=<?= $customerId ?>');
        const valuesData = await valuesRes.json();
        if (valuesData.success) {
            valuesData.data.forEach(v => {
                customerFilterValues[v.field_id] = v;
            });
        }
        
        renderFilterFields();
    } catch (error) {
        console.error('[FILTER_FIELDS] 加载失败:', error);
    }
}

// XSS转义函数
function escapeHtml(text) {
    if (text == null) return '';
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function renderFilterFields() {
    const container = document.getElementById('filterFieldsList');
    if (!container || filterFieldsData.length === 0) {
        if (container) container.innerHTML = '<span class="text-muted" style="font-size:12px;">暂无分类字段</span>';
        return;
    }
    
    let html = '';
    filterFieldsData.forEach(field => {
        const currentValue = customerFilterValues[field.id];
        
        html += `<div class="filter-field-item">
            <span class="filter-field-name">${escapeHtml(field.field_label)}:</span>`;
        
        if (currentValue) {
            // 显示已选择的标签
            html += `<span class="filter-field-tag" style="background:${escapeHtml(currentValue.color)}">
                ${escapeHtml(currentValue.option_label)}
                <?php if (!$isReadonly): ?>
                <span class="remove-tag" onclick="clearFilterValue(${field.id})">&times;</span>
                <?php endif; ?>
            </span>`;
        } else {
            // 显示选择下拉框
            html += `<select class="filter-field-select" onchange="setFilterValue(${field.id}, this.value)" <?= $isReadonly ? 'disabled' : '' ?>>
                <option value="">选择...</option>
                ${field.options.map(opt => `<option value="${opt.id}">${escapeHtml(opt.option_label)}</option>`).join('')}
            </select>`;
        }
        
        html += '</div>';
    });
    
    container.innerHTML = html;
}

async function setFilterValue(fieldId, optionId) {
    if (!optionId) return;
    
    try {
        const response = await fetch('/api/customer_filter_fields.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_customer_value',
                customer_id: <?= $customerId ?>,
                field_id: fieldId,
                option_id: optionId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            // 重新加载
            loadFilterFields();
        } else {
            showAlertModal('保存失败: ' + result.message, 'error');
        }
    } catch (error) {
        showAlertModal('网络错误', 'error');
    }
}

async function clearFilterValue(fieldId) {
    try {
        const response = await fetch('/api/customer_filter_fields.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'clear_customer_value',
                customer_id: <?= $customerId ?>,
                field_id: fieldId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            delete customerFilterValues[fieldId];
            renderFilterFields();
        } else {
            showAlertModal('清除失败: ' + result.message, 'error');
        }
    } catch (error) {
        showAlertModal('网络错误', 'error');
    }
}

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', loadFilterFields);
<?php endif; ?>
    </script>

<?php
if (!$isExternalAccess) {
    layout_footer();
    ?>
    <script>
    if (typeof window._initCustomerPrepayAdd === 'function') window._initCustomerPrepayAdd();
    
    // [TRACE:pageshow] 检测浏览器返回/前进，自动刷新页面数据
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            console.log('[TRACE:pageshow] 从 bfcache 返回，刷新页面');
            location.reload();
        }
    });
    </script>
    <?php
} else {
    // 外部访问的footer
    ?>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <?php
    require_once __DIR__ . '/../core/url.php';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= Url::js('modal.js') ?>"></script>
    <script src="<?= Url::js('ajax-config.js') ?>"></script>
    <script src="<?= Url::js('copy-to-image.js') ?>"></script>
    <script src="<?= Url::js('attachment-upload.js') ?>"></script>
    <script src="<?= Url::js('recording.js') ?>?v=<?= time() ?>"></script>
    <script>
    // 视图模式管理（外部访问时）
    (function() {
        const VIEW_MODE_KEY = 'ankotti_view_mode';
        
        function setViewMode(mode) {
            if (mode === 'mobile' || mode === 'desktop') {
                localStorage.setItem(VIEW_MODE_KEY, mode);
            }
        }
        
        setViewMode('desktop');
        
        // 处理"进入手机版"按钮（使用class选择器，因为可能有多个）
        document.querySelectorAll('.enter-mobile-link').forEach(link => {
            link.addEventListener('click', function(e) {
                setViewMode('mobile');
            });
        });
    })();
    </script>
    </div>
    </body>
    </html>
    <?php
}
