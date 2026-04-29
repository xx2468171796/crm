<?php
// 手机版客户详情页面 - iOS风格

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permission.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../core/field_renderer.php';

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
        echo '<!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>客户不存在</title>
        </head>
        <body>
            <div style="padding: 20px; text-align: center;">
                <h3>客户不存在</h3>
                <a href="mobile_my_customers.php">返回客户列表</a>
            </div>
        </body>
        </html>';
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
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>请先登录</title>
            </head>
            <body>
                <div style="padding: 20px; text-align: center;">
                    <h3>请先登录</h3>
                    <a href="login.php">前往登录</a>
                </div>
            </body>
            </html>';
            exit;
        }
        
        if (!$isNew && $internalPermission === 'none') {
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>无权限访问</title>
            </head>
            <body>
                <div style="padding: 20px; text-align: center;">
                    <h3>无权限访问此客户</h3>
                    <a href="mobile_my_customers.php">返回客户列表</a>
                </div>
            </body>
            </html>';
            exit;
        }

        if (!$isNew) {
            $isReadonly = ($internalPermission === 'view');
        }
    }
    
    // 加载首通记录
    $firstContact = Db::queryOne('SELECT * FROM first_contact WHERE customer_id = :id', ['id' => $customerId]);
    
    // 加载异议处理记录
    $objections = [];
    $latestObjection = null;
    if ($customer) {
        $objections = Db::query('SELECT * FROM objection WHERE customer_id = :id ORDER BY create_time DESC', ['id' => $customer['id']]);
        if (!empty($objections)) {
            $latestObjection = $objections[0];
        }
    }
    
    // 加载敲定成交记录
    $dealRecord = null;
    if ($customer) {
        $dealRecord = Db::queryOne('SELECT * FROM deal_record WHERE customer_id = :id', ['id' => $customer['id']]);
    }
}

// 定义任务清单结构（与电脑版一致）
$taskCategories = [
    '收款确认' => [
        'payment_confirmed' => '确认款项入账',
        'payment_invoice' => '更新内部记录',
        'payment_stored' => '截图留存',
        'payment_reply' => '向内部回复【客户已付款】',
    ],
    '客户通知' => [
        'notify_receipt' => '发送付款成功通知',
        'notify_schedule' => '明确后续流程说明',
        'notify_timeline' => '告知预计启动时间',
        'notify_group' => '创建 Line / WhatsApp 客户服务群',
    ],
    '建立群组' => [
        'group_invite' => '邀请设计师 / 负责人加入',
        'group_intro' => '发送自动话术',
    ],
    '资料收集' => [
        'collect_materials' => '发送资料准备清单',
        'collect_timeline' => '询问客户资料供应的时间',
        'collect_photos' => '汇整客户户型',
    ],
    '项目交接' => [
        'handover_designer' => '提供给主要或签约设计团队',
        'handover_confirm' => '确认设计团队已接收任务',
    ],
    '内部回报' => [
        'report_progress' => '回报今日进度',
        'report_new' => '更新项目进度（已建群 / 周付费 / 等待材）',
        'report_care' => '当日晚间发送关怀性信息',
    ],
    '关怀性跟进' => [
        'care_message' => '建立客户作业与服务延续感',
    ],
];

if (!$isNew && !isset($dealRecord)) {
    $dealRecord = null;
}

$storageConfig = storage_config();
$folderUploadConfig = $storageConfig['limits']['folder_upload'] ?? [];

// 准备字段值数组（用于回显）
$fieldValues = [];
if ($firstContact) {
    // 从 first_contact 表加载所有字段值（兼容旧字段）
    foreach ($firstContact as $key => $value) {
        $fieldValues[$key] = $value;
    }
    
    // 从新三层结构字段值表加载动态字段值
    $firstContactId = $firstContact['id'] ?? 0;
    if ($firstContactId > 0) {
        $dimensionValues = loadDimensionFieldValues('first_contact', $firstContactId);
        // 合并维度字段值（维度字段值优先，覆盖旧字段值）
        $fieldValues = array_merge($fieldValues, $dimensionValues);
    }
}

// 计算默认时间（明天）
$defaultTime = $firstContact && $firstContact['next_follow_time'] 
    ? date('Y-m-d\TH:i', $firstContact['next_follow_time']) 
    : date('Y-m-d\TH:i', strtotime('+1 day'));

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= $isNew ? '新增客户' : ($customer['name'] ?? '客户详情') ?> - ANKOTTI Mobile</title>
    <link rel="stylesheet" href="css/mobile-customer.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="#" id="backLink" class="back-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
        </a>
        <div class="logo"><?= $isNew ? '新增客户' : ($customer['name'] ?? '客户详情') ?></div>
        <div style="display: flex; gap: 8px;">
            <a href="https://okr.ankotti.com/" target="_blank" class="back-btn" style="cursor: pointer;" title="OKR">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
            </a>
            <button type="button" id="desktopModeBtn" class="back-btn" style="cursor: pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </button>
        </div>
    </header>
    
    <!-- Navigation -->
    <div class="nav-tabs-container">
        <nav class="nav-tabs">
            <button class="nav-item active" data-module="first_contact">首通</button>
            <button class="nav-item" data-module="objection">异议处理</button>
            <button class="nav-item" data-module="deal">敲定成交</button>
            <button class="nav-item" data-module="service">正式服务</button>
            <button class="nav-item" data-module="visit">客户回访</button>
            <button class="nav-item" data-module="file">文件管理</button>
        </nav>
    </div>
    
    <!-- Content -->
    <div class="container">
        <!-- 首通模块 -->
        <div class="module-content active" id="module-first_contact">
            <!-- Basic Info Card -->
            <div class="card">
                <div class="form-row">
                    <div class="col">
                        <div class="form-group">
                            <label class="form-label required">客户姓名</label>
                            <input type="text" name="name" class="form-input" placeholder="请输入" 
                                   value="<?= htmlspecialchars($customer['name'] ?? '') ?>" 
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label class="form-label">联系方式</label>
                            <input type="tel" name="mobile" class="form-input" placeholder="请输入" 
                                   value="<?= htmlspecialchars($customer['mobile'] ?? '') ?>" 
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label class="form-label">客户群</label>
                            <input type="text" name="customer_group" class="form-input" placeholder="可选" 
                                   value="<?= htmlspecialchars($customer['customer_group'] ?? '') ?>" 
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="col" style="flex: 0 0 100px;">
                        <div class="form-group">
                            <label class="form-label">性别</label>
                            <div class="select-wrapper">
                                <select name="gender" class="form-select" <?= $isReadonly ? 'disabled' : '' ?>>
                                    <option value="">-</option>
                                    <option value="男" <?= ($customer['gender'] ?? '') === '男' ? 'selected' : '' ?>>男</option>
                                    <option value="女" <?= ($customer['gender'] ?? '') === '女' ? 'selected' : '' ?>>女</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col" style="flex: 0 0 100px;">
                        <div class="form-group">
                            <label class="form-label">年龄</label>
                            <input type="number" name="age" class="form-input" placeholder="年龄" 
                                   value="<?= $customer['age'] ?? '' ?>" 
                                   min="0" max="120"
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label class="form-label">ID</label>
                            <input type="text" name="custom_id" class="form-input" placeholder="手动填写" 
                                   value="<?= htmlspecialchars($customer['custom_id'] ?? '') ?>"
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="col">
                        <div class="form-group">
                            <label class="form-label">自动生成ID</label>
                            <input type="text" class="form-input" placeholder="保存后生成" disabled
                                   value="<?= htmlspecialchars($customer['customer_code'] ?? '') ?>"
                                   style="background: #F9F9F9; color: #AEAEB2;">
                        </div>
                    </div>
                </div>
            </div>
            
            <?php
            // 渲染动态字段
            echo renderModuleFields('first_contact', $fieldValues, $isReadonly);
            ?>
            
            <!-- 下次跟进时间 -->
            <div class="card">
                <div class="section-title">下次跟进时间</div>
                <div class="form-group">
                    <input type="datetime-local" name="next_follow_time" class="form-input" 
                           value="<?= $defaultTime ?>" 
                           <?= $isReadonly ? 'disabled' : '' ?>>
                    <div class="text-muted">默认为明天</div>
                </div>
            </div>
            
            <!-- 首通备注 -->
            <div class="card">
                <div class="section-title">首通备注 <span style="font-weight: 400; font-size: 13px; color: var(--text-secondary); margin-left: 4px;">(支持Markdown)</span></div>
                <textarea name="remark" class="form-input" placeholder="记录沟通要点..." 
                          <?= $isReadonly ? 'disabled' : '' ?>><?= htmlspecialchars($firstContact['remark'] ?? '') ?></textarea>
            </div>
            
        </div>
        
        <!-- 异议处理模块 -->
        <div class="module-content" id="module-objection">
            <?php if (!$isNew && $customer): ?>
            <!-- 客户信息卡片 -->
            <div class="card">
                <div class="section-title">客户信息</div>
                <div class="form-group">
                    <div class="text-muted" style="font-size: 14px; line-height: 1.6;">
                        <div><strong>姓名：</strong><?= htmlspecialchars($customer['name'] ?? '') ?></div>
                        <div><strong>手机：</strong><?= htmlspecialchars($customer['mobile'] ?? '') ?></div>
                        <?php if ($firstContact): ?>
                        <div><strong>关键疑问：</strong><?= htmlspecialchars($firstContact['key_questions'] ?? '无') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 处理方法 -->
            <div class="card">
                <div class="section-title">处理方法</div>
                <div class="options-group">
                    <?php
                    $methods = ['五步法', '一步法', '镜像法', '房子法', '转化法', '拆分法'];
                    $selectedMethods = [];
                    if ($latestObjection && $latestObjection['method']) {
                        $selectedMethods = explode('、', $latestObjection['method']);
                    }
                    foreach ($methods as $method):
                        $checked = in_array($method, $selectedMethods) ? 'checked' : '';
                    ?>
                    <div class="option-chip">
                        <input type="checkbox" name="handling_methods[]" value="<?= $method ?>" 
                               id="method-<?= $method ?>" <?= $checked ?> <?= $isReadonly ? 'disabled' : '' ?>>
                        <label for="method-<?= $method ?>"><?= $method ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-group" style="margin-top: 12px;">
                    <input type="text" name="method_custom" class="form-input" 
                           placeholder="输入其他方法" <?= $isReadonly ? 'disabled' : '' ?>
                           value="<?= htmlspecialchars($latestObjection && $latestObjection['method'] && !in_array($latestObjection['method'], $methods) ? $latestObjection['method'] : '') ?>">
                </div>
            </div>
            
            <!-- 话术方案 -->
            <div class="card">
                <div class="section-title">我的话术方案 <span style="font-weight: 400; font-size: 13px; color: var(--text-secondary); margin-left: 4px;">(支持Markdown)</span></div>
                <textarea name="solution" class="form-input" placeholder="详细记录处理话术和方法..." 
                          style="min-height: 200px;" <?= $isReadonly ? 'disabled' : '' ?>><?= htmlspecialchars($latestObjection['response_script'] ?? '') ?></textarea>
            </div>
            
            <!-- 历史记录 -->
            <?php if (!empty($objections)): ?>
            <div class="card">
                <div class="section-title">历史记录 (<?= count($objections) ?>条)</div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($objections as $obj): ?>
                    <div style="padding: 12px; background: #F2F2F7; border-radius: var(--radius-md);">
                        <div style="font-weight: 600; margin-bottom: 8px; font-size: 14px;">
                            <?= htmlspecialchars($obj['method']) ?>
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">
                            <?= date('Y-m-d H:i', $obj['create_time']) ?>
                        </div>
                        <div style="font-size: 14px; line-height: 1.6; white-space: pre-wrap;">
                            <?= htmlspecialchars($obj['response_script']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="card">
                <div class="loading">请先保存客户信息</div>
            </div>
            <?php endif; ?>
        </div>
        <!-- 敲定成交模块 -->
        <div class="module-content" id="module-deal">
            <?php if ($customer && $customerId > 0): ?>
            <!-- 任务清单 -->
            <?php foreach ($taskCategories as $category => $tasks): ?>
            <div class="card">
                <div class="section-title"><?= htmlspecialchars($category) ?></div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($tasks as $field => $label): ?>
                    <div class="task-item" data-field="<?= $field ?>">
                        <div style="display: flex; align-items: flex-start; gap: 12px;">
                            <div style="flex-shrink: 0; padding-top: 4px;">
                                <input type="checkbox" 
                                       name="<?= $field ?>" 
                                       value="1"
                                       id="deal_<?= $field ?>"
                                       class="task-checkbox"
                                       <?= ($dealRecord && isset($dealRecord[$field]) && $dealRecord[$field]) ? 'checked' : '' ?>
                                       <?= $isReadonly ? 'disabled' : '' ?>>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <label for="deal_<?= $field ?>" style="display: block; font-size: 15px; font-weight: 500; margin-bottom: 8px; cursor: pointer; user-select: none; -webkit-user-select: none;">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                                <input type="text" 
                                       name="note_<?= $field ?>" 
                                       class="form-input" 
                                       placeholder="备注"
                                       value="<?= $dealRecord && isset($dealRecord['note_' . $field]) ? htmlspecialchars($dealRecord['note_' . $field]) : '' ?>"
                                       <?= $isReadonly ? 'disabled' : '' ?>
                                       style="width: 100%; font-size: 14px; padding: 8px 12px;">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- 其他待办事项 -->
            <div class="card">
                <div class="section-title">📝 其他待办事项</div>
                <textarea name="other_notes" 
                          class="form-input" 
                          placeholder="记录其他需要跟进的事项..."
                          style="min-height: 100px;"
                          <?= $isReadonly ? 'disabled' : '' ?>><?= $dealRecord ? htmlspecialchars($dealRecord['other_notes'] ?? '') : '' ?></textarea>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="loading">请先保存客户信息</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="module-content" id="module-service">
            <div class="card">
                <div class="loading">正式服务模块开发中...</div>
            </div>
        </div>
        <div class="module-content" id="module-visit">
            <div class="card">
                <div class="loading">客户回访模块开发中...</div>
            </div>
        </div>
        <!-- 文件管理模块 -->
        <div class="module-content" id="module-file">
            <?php if ($customer && $customerId > 0): ?>
                <?php
                $canManageFiles = !$isReadonly;
                
                // 加载存储配置
                require_once __DIR__ . '/../core/storage/storage_provider.php';
                $storageConfig = storage_config();
                $folderUploadConfig = $storageConfig['limits']['folder_upload'] ?? [];
                $folderLimits = [
                    'max_files' => (int)($folderUploadConfig['max_files'] ?? 500),
                    'max_total_bytes' => (int)($folderUploadConfig['max_total_bytes'] ?? (2 * 1024 * 1024 * 1024)),
                    'max_depth' => (int)($folderUploadConfig['max_depth'] ?? 5),
                    'max_segment_length' => (int)($folderUploadConfig['max_segment_length'] ?? 40),
                ];
                $maxSingleSize = (int)($storageConfig['limits']['max_single_size'] ?? (2 * 1024 * 1024 * 1024));
                $maxTotalHintValue = $folderLimits['max_total_bytes'] >= 1073741824
                    ? round($folderLimits['max_total_bytes'] / 1073741824, 1) . ' GB'
                    : round($folderLimits['max_total_bytes'] / 1048576, 1) . ' MB';
                $folderLimitHint = sprintf('%d 个文件 / %s', $folderLimits['max_files'], $maxTotalHintValue);
                ?>
                
                <div id="mobileFileManagementApp"
                     data-customer-id="<?= (int)$customerId ?>"
                     data-can-manage="<?= $canManageFiles ? '1' : '0' ?>"
                     data-max-files="<?= $folderLimits['max_files'] ?>"
                     data-max-bytes="<?= $folderLimits['max_total_bytes'] ?>"
                     data-max-single-size="<?= $maxSingleSize ?>"
                     data-max-depth="<?= $folderLimits['max_depth'] ?>"
                     data-max-segment="<?= $folderLimits['max_segment_length'] ?>"
                     data-folder-limit-hint="<?= htmlspecialchars($folderLimitHint) ?>">
                    
                    <!-- 分类切换（Segmented Control） -->
                    <div class="segmented-control">
                        <button class="segment active" data-type="customer">客户发送的资料</button>
                        <button class="segment" data-type="company">我们提供的资料</button>
                    </div>
                    
                    <!-- 上传区域 -->
                    <?php if ($canManageFiles): ?>
                    <div class="upload-card">
                        <button type="button" class="btn btn-primary upload-btn" id="mobileFileUploadBtn">
                            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            <span>上传文件</span>
                        </button>
                        <p class="upload-tip">支持单文件、多文件或文件夹上传</p>
                        <input type="file" id="mobileFileInput" multiple hidden>
                        <input type="file" id="mobileFolderInput" webkitdirectory hidden>
                        <input type="file" id="mobileCameraInput" accept="image/*" capture="environment" hidden>
                    </div>
                    <div class="upload-progress-container" id="mobileUploadProgress" style="display: none;"></div>
                    <?php else: ?>
                    <div class="card">
                        <div class="loading">当前仅支持查看，无法上传文件</div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 搜索栏 -->
                    <div class="search-bar">
                        <input type="search" class="search-input" id="fileSearchInput" placeholder="搜索文件名...">
                        <button type="button" class="search-btn view-toggle-btn" id="viewModeBtn" title="切换视图">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="viewModeIcon">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                        </button>
                        <button type="button" class="search-btn" id="folderTreeBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- 文件夹面包屑导航 -->
                    <div class="folder-breadcrumb" id="folderBreadcrumb">
                        <button class="crumb active" data-path="">全部</button>
                    </div>
                    
                    <!-- 文件列表 -->
                    <div class="file-list" id="fileList">
                        <div class="file-empty-tip">正在加载...</div>
                    </div>
                    
                    <!-- 分页 -->
                    <div class="file-pagination" id="filePagination" style="display: none;">
                        <button type="button" class="btn btn-outline" id="prevPage">上一页</button>
                        <span class="page-info" id="pageInfo"></span>
                        <button type="button" class="btn btn-outline" id="nextPage">下一页</button>
                    </div>
                    
                    <!-- 多选模式底部操作栏 -->
                    <div class="multi-select-bar" id="multiSelectBar" style="display: none;">
                        <button type="button" class="btn btn-outline" id="selectAllBtn">全选</button>
                        <span class="selected-count" id="selectedCount">已选择 0 项</span>
                        <button type="button" class="btn btn-primary" id="batchDownloadBtn">下载</button>
                        <?php if ($canManageFiles): ?>
                        <button type="button" class="btn btn-danger" id="batchDeleteBtn">删除</button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 文件夹树模态框 -->
                <div class="modal" id="folderTreeModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>文件夹结构</h3>
                            <button type="button" class="modal-close" id="folderTreeClose">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="folder-tree" id="folderTree">
                                <div class="loading">正在加载...</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="loading">请先保存客户信息</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer Actions -->
    <?php if (!$isReadonly): ?>
    <div class="footer-actions">
        <button type="button" class="btn btn-outline" id="copyImageBtn">
            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            <span>保存图片</span>
        </button>
        <button type="button" class="btn btn-primary" id="saveBtn">
            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
            </svg>
            <span>保存记录</span>
        </button>
        <div class="file-input-wrapper">
            <input type="file" id="fileInput" multiple accept="*/*" style="position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none;">
            <label for="fileInput" class="btn btn-outline" id="uploadBtn" style="cursor: pointer; margin: 0;">
                <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                <span>上传附件</span>
            </label>
        </div>
        <?php if (!$isNew && $customer): ?>
        <button type="button" class="btn btn-outline" id="linkManageBtn">
            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
            </svg>
            <span>链接</span>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Toast -->
    <div class="toast" id="toast"></div>
    
    <!-- 链接管理模态框 -->
    <?php if (!$isNew && $customer): ?>
    <div class="link-manage-modal" id="linkManageModal" style="display: none;">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">链接管理</h3>
                <button class="modal-close" id="linkManageClose">✕</button>
            </div>
            <div class="modal-body" id="linkManageBody">
                <div class="loading-state">加载中...</div>
            </div>
            <div class="modal-footer" id="linkManageFooter" style="display: none;">
                <button class="btn btn-outline" id="linkManageCancel">取消</button>
                <button class="btn btn-primary" id="linkManageSave">保存设置</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
        // ========== 视图模式管理 ==========
        const VIEW_MODE_KEY = 'ankotti_view_mode';
        
        /**
         * 设置视图模式
         * @param {string} mode - 'mobile' | 'desktop'
         */
        function setViewMode(mode) {
            if (mode === 'mobile' || mode === 'desktop') {
                localStorage.setItem(VIEW_MODE_KEY, mode);
            }
        }
        
        /**
         * 获取当前视图模式
         * @returns {string} 'mobile' | 'desktop'
         */
        function getViewMode() {
            return localStorage.getItem(VIEW_MODE_KEY) || 'desktop';
        }
        
        /**
         * 根据视图模式生成URL
         * @param {string} desktopUrl - 电脑版URL
         * @param {string} mobileUrl - 手机版URL（可选）
         * @returns {string} 根据当前模式返回对应的URL
         */
        function getViewModeUrl(desktopUrl, mobileUrl) {
            const mode = getViewMode();
            return (mode === 'mobile' && mobileUrl) ? mobileUrl : desktopUrl;
        }
        
        // 页面加载时自动设置视图模式
        (function() {
            const currentPath = window.location.pathname;
            if (currentPath.includes('mobile_customer_detail.php')) {
                setViewMode('mobile');
            }
            
            // 初始化返回链接
            const customerId = <?= $customerId ?>;
            const isNew = <?= $isNew ? 'true' : 'false' ?>;
            const backLink = document.getElementById('backLink');
            
            if (backLink) {
                const desktopUrl = isNew 
                    ? 'index.php?page=my_customers' 
                    : 'index.php?page=customer_detail&id=' + customerId;
                const mobileUrl = 'mobile_home.php'; // 手机版主页
                backLink.href = getViewModeUrl(desktopUrl, mobileUrl);
            }
            
            // 初始化"进入电脑版"按钮
            const desktopModeBtn = document.getElementById('desktopModeBtn');
            if (desktopModeBtn) {
                desktopModeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    setViewMode('desktop');
                    const desktopUrl = isNew 
                        ? 'index.php?page=my_customers' 
                        : 'index.php?page=customer_detail&id=' + customerId;
                    window.location.href = desktopUrl;
                });
            }
        })();
        
        // ========== 动态增强字段样式（iOS风格）==========
        function enhanceDynamicFields() {
            // 1. 处理容器：将 .field-row 转换为 .card
            document.querySelectorAll('.field-row').forEach(row => {
                row.classList.add('card');
                row.classList.remove('field-row');
            });

            // 2. 处理标题：将 .field-label 转换为 .section-title
            document.querySelectorAll('.field-label').forEach(label => {
                label.classList.add('section-title');
                label.classList.remove('field-label');
            });

            // 3. 处理选项组和输入框
            document.querySelectorAll('.field-options').forEach(optionsDiv => {
                // 检查是否包含 input[type=radio] 或 input[type=checkbox]
                if (optionsDiv.querySelector('input[type="radio"], input[type="checkbox"]')) {
                    optionsDiv.classList.add('options-group');
                    optionsDiv.classList.remove('field-options');
                    
                    // 处理每个选项
                    optionsDiv.querySelectorAll('label').forEach(label => {
                        const input = label.querySelector('input[type="radio"], input[type="checkbox"]');
                        if (input) {
                            // 创建新的 option-chip 结构
                            const chip = document.createElement('div');
                            chip.className = 'option-chip';
                            
                            // 复制 input（保留所有属性和事件）
                            const newInput = input.cloneNode(true);
                            // 确保 input 有 id
                            if (!newInput.id) {
                                newInput.id = 'opt-' + Math.random().toString(36).substr(2, 9);
                            }
                            chip.appendChild(newInput);
                            
                            // 创建新的 label
                            const newLabel = document.createElement('label');
                            newLabel.setAttribute('for', newInput.id);
                            // 提取文本内容（移除 input 后剩下的文本）
                            // 临时移除 input 以获取纯文本
                            const tempInput = label.querySelector('input');
                            if (tempInput) label.removeChild(tempInput);
                            newLabel.textContent = label.textContent.trim();
                            
                            chip.appendChild(newLabel);
                            
                            // 替换原有的 label
                            label.replaceWith(chip);
                        } else if (label.querySelector('input[type="text"]')) {
                            // 处理"其他"输入框
                            const customInput = label.querySelector('input[type="text"]');
                            const div = document.createElement('div');
                            div.className = 'form-group';
                            div.style.width = '100%';
                            div.style.marginTop = '8px';
                            
                            customInput.className = 'form-input';
                            customInput.style.width = '100%';
                            customInput.style.margin = '0';
                            // 移除内联 style
                            customInput.style.display = 'block';
                            
                            div.appendChild(customInput);
                            label.replaceWith(div);
                        }
                    });
                } else {
                    // 处理 text/select/textarea/date 等其他类型
                    optionsDiv.classList.remove('field-options');
                    
                    // 输入框样式
                    optionsDiv.querySelectorAll('input[type="text"], input[type="date"], textarea').forEach(input => {
                        input.classList.add('form-input');
                        input.classList.remove('form-control', 'form-control-sm');
                        input.style.width = '100%';
                    });
                    
                    // 下拉框样式
                    optionsDiv.querySelectorAll('select').forEach(select => {
                        select.classList.add('form-select');
                        select.classList.remove('form-control', 'form-control-sm');
                        select.style.width = '100%';
                        select.style.minWidth = ''; // 移除最小宽度限制
                        
                        // 包装 select 以显示自定义箭头
                        if (!select.parentElement.classList.contains('select-wrapper')) {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'select-wrapper';
                            wrapper.style.width = '100%';
                            select.parentNode.insertBefore(wrapper, select);
                            wrapper.appendChild(select);
                        }
                    });
                    
                    // 调整行布局 (.field-options-row)
                    optionsDiv.querySelectorAll('.field-options-row').forEach(row => {
                        row.className = 'form-row'; // 使用我们定义的 .form-row
                        row.style = ''; // 清除内联样式
                        
                        row.querySelectorAll('div[data-col]').forEach(col => {
                            col.className = 'col'; // 使用我们定义的 .col
                            col.style = ''; // 清除内联样式
                            
                            // 调整 label
                            const label = col.querySelector('label');
                            if (label) {
                                label.className = 'form-label';
                                label.style = ''; // 清除内联样式
                            }
                        });
                    });
                }
            });
        }

        // 页面加载完成后执行
        document.addEventListener('DOMContentLoaded', function() {
            enhanceDynamicFields();
        });

        // 模块切换函数
        function switchToModule(module) {
            // 更新导航状态
            document.querySelectorAll('.nav-item').forEach(nav => {
                if (nav.dataset.module === module) {
                    nav.classList.add('active');
                } else {
                    nav.classList.remove('active');
                }
            });
            
            // 更新内容显示
            document.querySelectorAll('.module-content').forEach(content => {
                content.classList.remove('active');
            });
            const targetModule = document.getElementById('module-' + module);
            if (targetModule) {
                targetModule.classList.add('active');
                // 滚动到模块位置
                setTimeout(() => {
                    targetModule.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
            
            // 在文件管理模块中隐藏底部操作栏的上传按钮（文件管理模块有自己的上传功能）
            const fileInputWrapper = document.querySelector('.file-input-wrapper');
            if (fileInputWrapper) {
                if (module === 'file') {
                    fileInputWrapper.style.display = 'none';
                } else {
                    fileInputWrapper.style.display = '';
                }
            }
        }
        
        // 模块切换事件监听
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                const module = this.dataset.module;
                switchToModule(module);
                // 更新URL锚点（不刷新页面）
                if (history.replaceState) {
                    history.replaceState(null, '', '#module-' + module);
                }
            });
        });
        
        // 锚点导航：页面加载时检查URL锚点
        function handleAnchorNavigation() {
            const hash = window.location.hash;
            if (hash) {
                // 移除 # 号，并处理 module- 前缀
                let module = hash.substring(1);
                // 如果已经是 module-file 格式，提取 file 部分
                if (module.startsWith('module-')) {
                    module = module.substring(7); // 移除 'module-' 前缀
                }
                // 检查模块是否存在
                const moduleElement = document.getElementById('module-' + module);
                if (module && moduleElement) {
                    // 延迟执行，确保DOM已完全加载
                    setTimeout(() => {
                        switchToModule(module);
                        // 更新URL锚点格式为 #module-xxx
                        if (history.replaceState) {
                            history.replaceState(null, '', '#module-' + module);
                        }
                    }, 200);
                }
            }
        }
        
        // 页面加载完成后执行锚点导航
        document.addEventListener('DOMContentLoaded', function() {
            handleAnchorNavigation();
        });
        
        // 如果DOM已经加载完成，立即执行
        if (document.readyState === 'loading') {
            // DOM还未加载完成，等待DOMContentLoaded事件
        } else {
            // DOM已经加载完成，立即执行
            handleAnchorNavigation();
        }
        
        // Toast提示（iOS风格）
        function showToast(message, type = 'info', duration = null) {
            const toast = document.getElementById('toast');
            if (!toast) return;
            
            // 移除之前的类型类
            toast.className = 'toast';
            
            // 计算显示时间（根据内容长度）
            let displayDuration = duration;
            if (displayDuration === null) {
                const messageLength = (message || '').length;
                if (messageLength < 20) {
                    displayDuration = 2000; // 短消息：2秒
                } else if (messageLength < 40) {
                    displayDuration = 3000; // 中等消息：3秒
                } else {
                    displayDuration = 5000; // 长消息：5秒
                }
            }
            
            // 设置类型
            if (type && type !== 'info') {
                toast.classList.add(type);
            }
            
            // 图标映射
            const iconMap = {
                'success': '✓',
                'error': '✕',
                'warning': '⚠',
                'info': 'ℹ'
            };
            
            const icon = iconMap[type] || '';
            
            // 设置内容
            if (icon) {
                toast.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-text">${escapeHtml(message)}</span>`;
                toast.classList.add('with-icon');
            } else {
                toast.textContent = message;
                toast.classList.remove('with-icon');
            }
            
            // 触发动画（使用requestAnimationFrame确保DOM更新）
            requestAnimationFrame(() => {
                toast.classList.add('show');
            });
            
            // 自动隐藏
            setTimeout(() => {
                toast.classList.remove('show');
                // 等待动画完成后重置内容
                setTimeout(() => {
                    toast.className = 'toast';
                    toast.textContent = '';
                }, 350);
            }, displayDuration);
        }
        
        // HTML转义辅助函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 检测是否为iOS设备
        function isIOS() {
            return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        }
        
        // 检测是否为Android设备
        function isAndroid() {
            return /Android/.test(navigator.userAgent);
        }
        
        // 保存图片到相册功能（适配iOS和Android）
        document.getElementById('copyImageBtn')?.addEventListener('click', async function(e) {
            e.preventDefault();
            const btn = this;
            const originalText = btn.querySelector('span').textContent;
            
            // 显示加载状态
            btn.disabled = true;
            btn.querySelector('span').textContent = '生成中...';
            
            try {
                // 优先查找当前激活的模块内容
                let container = document.querySelector('.module-content.active');
                
                // 如果找不到激活模块，尝试查找.container作为降级方案
                if (!container) {
                    container = document.querySelector('.container');
                }
                
                if (!container) {
                    showToast('未找到内容区域');
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                    return;
                }
                
                // 获取客户名称用于生成文件名
                const customerName = document.querySelector('.logo')?.textContent || '客户记录';
                
                // 根据当前模块确定文件名前缀
                let moduleName = '记录';
                if (container.id === 'module-first_contact') {
                    moduleName = '首通记录';
                } else if (container.id === 'module-objection') {
                    moduleName = '异议处理';
                } else if (container.id === 'module-deal') {
                    moduleName = '敲定成交';
                } else if (container.id === 'module-service') {
                    moduleName = '正式服务';
                } else if (container.id === 'module-visit') {
                    moduleName = '客户回访';
                } else if (container.id === 'module-file') {
                    moduleName = '文件管理';
                }
                
                const fileName = `${customerName}_${moduleName}_${(() => { const d = new Date(); const p = n => String(n).padStart(2,'0'); return d.getFullYear()+p(d.getMonth()+1)+p(d.getDate()); })()}_${Date.now()}.png`;
                
                const canvas = await html2canvas(container, {
                    backgroundColor: '#F2F2F7',
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    allowTaint: true
                });
                
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        showToast('生成图片失败');
                        btn.disabled = false;
                        btn.querySelector('span').textContent = originalText;
                        return;
                    }
                    
                    const url = URL.createObjectURL(blob);
                    const isIOSDevice = isIOS();
                    const isAndroidDevice = isAndroid();
                    
                    if (isIOSDevice) {
                        // iOS Safari 方案：先尝试使用 Clipboard API（iOS 14+支持）
                        if (navigator.clipboard && navigator.clipboard.write) {
                            navigator.clipboard.write([
                                new ClipboardItem({ 'image/png': blob })
                            ]).then(() => {
                                showToast('图片已复制到剪贴板\n可粘贴到相册保存');
                                URL.revokeObjectURL(url);
                                btn.disabled = false;
                                btn.querySelector('span').textContent = originalText;
                            }).catch(() => {
                                // 降级：显示图片让用户长按保存
                                showImageForSave(url, fileName, btn, originalText);
                            });
                        } else {
                            // 降级：显示图片让用户长按保存
                            showImageForSave(url, fileName, btn, originalText);
                        }
                    } else if (isAndroidDevice) {
                        // Android 方案：直接触发下载
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = fileName;
                        a.style.display = 'none';
                        document.body.appendChild(a);
                        a.click();
                        
                        // 延迟移除，确保下载触发
                        setTimeout(() => {
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        }, 100);
                        
                        showToast('图片已保存到相册');
                        btn.disabled = false;
                        btn.querySelector('span').textContent = originalText;
                    } else {
                        // 其他设备：使用标准下载
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = fileName;
                        a.style.display = 'none';
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(() => {
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        }, 100);
                        
                        showToast('图片已保存');
                        btn.disabled = false;
                        btn.querySelector('span').textContent = originalText;
                    }
                }, 'image/png', 1.0); // 最高质量
            } catch (err) {
                console.error('保存图片失败:', err);
                console.error('错误详情:', err.message, err.stack);
                // 显示更详细的错误信息（仅在开发环境）
                const errorMsg = err.message ? `保存失败: ${err.message}` : '保存失败，请重试';
                showToast(errorMsg);
                btn.disabled = false;
                btn.querySelector('span').textContent = originalText;
            }
        });
        
        // iOS 降级方案：显示图片让用户长按保存
        function showImageForSave(url, fileName, btn, originalText) {
            // 创建全屏图片预览模态框
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.9);
                z-index: 10000;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 20px;
            `;
            
            const img = document.createElement('img');
            img.src = url;
            img.style.cssText = `
                max-width: 100%;
                max-height: 80vh;
                object-fit: contain;
                border-radius: 8px;
            `;
            
            const hint = document.createElement('div');
            hint.textContent = '长按图片保存到相册';
            hint.style.cssText = `
                color: white;
                margin-top: 20px;
                font-size: 16px;
                text-align: center;
            `;
            
            const closeBtn = document.createElement('button');
            closeBtn.textContent = '关闭';
            closeBtn.style.cssText = `
                margin-top: 20px;
                padding: 10px 20px;
                background: #007AFF;
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
            `;
            
            modal.appendChild(img);
            modal.appendChild(hint);
            modal.appendChild(closeBtn);
            document.body.appendChild(modal);
            
            closeBtn.onclick = function() {
                document.body.removeChild(modal);
                URL.revokeObjectURL(url);
                btn.disabled = false;
                btn.querySelector('span').textContent = originalText;
            };
            
            // 点击背景也关闭
            modal.onclick = function(e) {
                if (e.target === modal) {
                    document.body.removeChild(modal);
                    URL.revokeObjectURL(url);
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            };
        }
        
        // 上传附件功能
        document.addEventListener('DOMContentLoaded', function() {
            const uploadBtn = document.getElementById('uploadBtn');
            const fileInput = document.getElementById('fileInput');
            
            if (!uploadBtn || !fileInput) return;
            
            // iOS Safari需要直接的用户交互，使用label包裹确保兼容性
            // 在change事件中检查客户ID并处理上传
            uploadBtn.addEventListener('click', function(e) {
                const customerId = <?= $customerId ?>;
                if (customerId <= 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    showToast('请先保存客户信息');
                    // 阻止label触发文件选择
                    if (fileInput) {
                        fileInput.disabled = true;
                        setTimeout(() => {
                            fileInput.disabled = false;
                        }, 50);
                    }
                    return false;
                }
            });
            
            // 文件选择变化时上传
            fileInput.addEventListener('change', function(e) {
                const files = Array.from(e.target.files || []);
                const customerId = <?= $customerId ?>;
                
                // 先检查客户ID
                if (customerId <= 0) {
                    showToast('请先保存客户信息');
                    e.target.value = '';
                    return;
                }
                
                // 如果没有选择文件，直接返回
                if (files.length === 0) {
                    e.target.value = '';
                    return;
                }
                
                // 检测当前激活的模块
                const activeModule = document.querySelector('.module-content.active');
                
                // 如果在文件管理模块，应该使用文件管理模块自己的上传功能，不在这里处理
                if (activeModule && activeModule.id === 'module-file') {
                    showToast('请使用文件管理模块的上传功能');
                    e.target.value = '';
                    return;
                }
                
                let uploadSource = 'first_contact'; // 默认首通
                
                if (activeModule) {
                    const moduleId = activeModule.id.replace('module-', '');
                    // 根据激活的模块设置upload_source
                    if (moduleId === 'objection') {
                        uploadSource = 'objection';
                    } else if (moduleId === 'first_contact') {
                        uploadSource = 'first_contact';
                    }
                    // 如果不在首通或异议处理模块，默认使用首通
                }
                
                const btn = document.getElementById('uploadBtn');
                const originalText = btn.querySelector('span').textContent;
                btn.disabled = true;
                btn.querySelector('span').textContent = '上传中...';
                
                const formData = new FormData();
                formData.append('customer_id', customerId);
                formData.append('category', 'client_material');
                formData.append('upload_source', uploadSource);
                files.forEach(file => {
                    formData.append('files[]', file);
                });
                
                // 使用相对路径（更兼容HTTP和HTTPS）
                fetch('/api/customer_files.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(`成功上传 ${data.files?.length || files.length} 个文件`);
                        e.target.value = '';
                        
                        // 如果当前在文件管理模块，刷新文件列表
                        const activeModule = document.querySelector('.module-content.active');
                        if (activeModule && activeModule.id === 'module-file') {
                            const mobileFileApp = activeModule.querySelector('#mobileFileManagementApp');
                            if (mobileFileApp && window.__MOBILE_FILE_MANAGEMENT_INITED) {
                                const event = new CustomEvent('refreshFiles');
                                mobileFileApp.dispatchEvent(event);
                            }
                        }
                    } else {
                        showToast(data.message || '上传失败');
                    }
                })
                .catch(error => {
                    console.error('Upload error:', error);
                    showToast('上传失败，请重试');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                });
            });
        });
        
        // 保存记录功能
        document.getElementById('saveBtn')?.addEventListener('click', function() {
            const btn = this;
            const originalText = btn.querySelector('span').textContent;
            btn.disabled = true;
            btn.querySelector('span').textContent = '保存中...';
            
            const activeModule = document.querySelector('.module-content.active');
            const moduleId = activeModule?.id.replace('module-', '') || 'first_contact';
            
            // 使用相对路径（更兼容HTTP和HTTPS）
            // 如果当前页面是HTTP，使用相对路径；如果是HTTPS，也使用相对路径，避免混合内容问题
            let apiUrl = '/api/customer_save.php';
            const formData = new FormData();
            formData.append('customer_id', '<?= $customerId ?>');
            
            if (moduleId === 'first_contact') {
                // 首通模块
                formData.append('name', document.querySelector('input[name="name"]')?.value || '');
                formData.append('mobile', document.querySelector('input[name="mobile"]')?.value || '');
                formData.append('gender', document.querySelector('select[name="gender"]')?.value || '');
                formData.append('next_follow_time', document.querySelector('input[name="next_follow_time"]')?.value || '');
                formData.append('remark', document.querySelector('textarea[name="remark"]')?.value || '');
                
                // 收集动态字段
                const inputs = activeModule.querySelectorAll('input, select, textarea');
                // 用于收集checkbox的值（因为checkbox可能是数组形式）
                const checkboxValues = {};
                
                inputs.forEach(input => {
                    if (!input.name) return;
                    
                    // 排除基础字段
                    if (input.name === 'name' || input.name === 'mobile' || 
                        input.name === 'gender' || input.name === 'next_follow_time' || input.name === 'remark') {
                        return;
                    }
                    
                    // 处理自定义字段的输入框（格式为 dimension_code_custom）
                    if (input.name.endsWith('_custom')) {
                        formData.append(input.name, input.value || '');
                        return;
                    }
                    
                    if (input.type === 'checkbox') {
                        if (input.checked) {
                            // checkbox的name可能是 dimension_code[] 格式
                            // 如果是数组格式，保持原样；如果不是，添加[]
                            let checkboxName = input.name;
                            if (!checkboxName.endsWith('[]')) {
                                checkboxName = checkboxName + '[]';
                            }
                            // 直接使用数组格式提交（FormData会自动处理数组）
                            formData.append(checkboxName, input.value);
                        }
                    } else if (input.type === 'radio') {
                        if (input.checked) {
                            formData.set(input.name, input.value);
                        }
                    } else {
                        // text, textarea, select, date 等类型
                        // 确保所有字段都被收集，包括自定义字段
                        if (input.value !== undefined && input.value !== null) {
                            formData.append(input.name, input.value);
                        }
                    }
                });
            } else if (moduleId === 'objection') {
                // 异议处理模块
                apiUrl = '/api/objection_save.php';
                const methods = [];
                activeModule.querySelectorAll('input[name="handling_methods[]"]:checked').forEach(cb => {
                    methods.push(cb.value);
                });
                const customMethod = document.querySelector('input[name="method_custom"]')?.value.trim() || '';
                if (customMethod) {
                    methods.push(customMethod);
                }
                // 将每个方法作为单独的数组项添加
                methods.forEach(method => {
                    formData.append('handling_methods[]', method);
                });
                formData.append('solution', document.querySelector('textarea[name="solution"]')?.value || '');
            } else if (moduleId === 'deal') {
                // 敲定成交模块
                apiUrl = '/api/deal_save.php';
                
                // 任务字段列表（与电脑版一致）
                const taskFields = [
                    'payment_confirmed', 'payment_invoice', 'payment_stored', 'payment_reply',
                    'notify_receipt', 'notify_schedule', 'notify_timeline', 'notify_group',
                    'group_invite', 'group_intro',
                    'collect_materials', 'collect_timeline', 'collect_photos',
                    'handover_designer', 'handover_confirm',
                    'report_progress', 'report_new', 'report_care',
                    'care_message'
                ];
                
                // 添加任务复选框和备注
                taskFields.forEach(field => {
                    const checkbox = activeModule.querySelector(`input[name="${field}"]`);
                    if (checkbox && checkbox.checked) {
                        formData.append(field, '1');
                    }
                    const noteInput = activeModule.querySelector(`input[name="note_${field}"]`);
                    if (noteInput) {
                        formData.append(`note_${field}`, noteInput.value || '');
                    }
                });
                
                // 其他待办事项
                const otherNotes = activeModule.querySelector('textarea[name="other_notes"]');
                if (otherNotes) {
                    formData.append('other_notes', otherNotes.value || '');
                }
            } else if (moduleId === 'file') {
                // 文件管理模块：刷新文件列表（新UI会自动处理）
                // 触发文件列表重新加载
                const mobileFileApp = activeModule.querySelector('#mobileFileManagementApp');
                if (mobileFileApp && window.__MOBILE_FILE_MANAGEMENT_INITED) {
                    // 如果文件管理模块已加载，触发刷新
                    const event = new CustomEvent('refreshFiles');
                    mobileFileApp.dispatchEvent(event);
                }
                showToast('文件已自动保存');
                btn.disabled = false;
                btn.querySelector('span').textContent = originalText;
                return;
            } else {
                showToast('该模块暂未实现保存功能');
                btn.disabled = false;
                btn.querySelector('span').textContent = originalText;
                return;
            }
            
            // 提交数据（使用fetch，如果失败则降级为XMLHttpRequest）
            const submitRequest = () => {
                return fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin' // 确保携带cookie和session
                });
            };
            
            // 先尝试fetch
            submitRequest()
            .then(response => {
                // 检查响应状态
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showToast(data.message || '保存成功');
                    if (data.redirect) {
                        // 如果是手机版，将电脑版URL转换为手机版URL
                        let redirectUrl = data.redirect;
                        // 将 /index.php?page=customer_detail&id=xxx 转换为 mobile_customer_detail.php?id=xxx
                        if (redirectUrl.includes('index.php?page=customer_detail')) {
                            const url = new URL(redirectUrl, window.location.origin);
                            const customerId = url.searchParams.get('id');
                            const hash = url.hash || '';
                            // 转换锚点格式：从 #tab-first_contact 转换为 #module-first_contact
                            let moduleHash = hash.replace('#tab-', '#module-');
                            if (customerId) {
                                redirectUrl = `mobile_customer_detail.php?id=${customerId}${moduleHash}`;
                            }
                        }
                        // 如果是手机版页面，在当前页面更新URL而不是跳转
                        if (redirectUrl.includes('mobile_customer_detail.php')) {
                            const url = new URL(redirectUrl, window.location.origin);
                            // 更新URL中的id参数和锚点
                            if (url.searchParams.get('id')) {
                                window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                                // 如果有锚点，切换到对应模块
                                if (url.hash) {
                                    const module = url.hash.substring(1).replace('module-', '');
                                    if (module && document.getElementById('module-' + module)) {
                                        setTimeout(() => {
                                            switchToModule(module);
                                        }, 100);
                                    }
                                }
                                // 刷新页面以加载新数据
                                setTimeout(() => window.location.reload(), 500);
                            } else {
                                window.location.href = redirectUrl;
                            }
                        } else {
                            // 如果是电脑版URL，不应该在手机版中跳转
                            // 只刷新当前页面
                            setTimeout(() => window.location.reload(), 500);
                        }
                    } else if (data.customer_id) {
                        // 如果是新建客户，更新URL
                        const url = new URL(window.location);
                        url.searchParams.set('id', data.customer_id);
                        window.history.replaceState({}, '', url);
                        // 刷新页面以加载新数据
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        // 刷新页面以加载新数据
                        setTimeout(() => window.location.reload(), 1000);
                    }
                } else {
                    showToast(data.message || '保存失败');
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                // 如果fetch失败（特别是"The operation is insecure"错误），使用XMLHttpRequest作为降级方案
                if (error.message && (error.message.includes('insecure') || error.message.includes('Failed to fetch'))) {
                    console.log('Fetch failed, trying XMLHttpRequest as fallback');
                    // 使用XMLHttpRequest作为降级方案
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', apiUrl, true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    
                    xhr.onload = function() {
                        btn.disabled = false;
                        btn.querySelector('span').textContent = originalText;
                        
                        if (xhr.status === 200) {
                            try {
                                const data = JSON.parse(xhr.responseText);
                                if (data.success) {
                                    showToast(data.message || '保存成功');
                                    if (data.redirect) {
                                        // 处理重定向逻辑（与fetch相同）
                                        let redirectUrl = data.redirect;
                                        if (redirectUrl.includes('index.php?page=customer_detail')) {
                                            const url = new URL(redirectUrl, window.location.origin);
                                            const customerId = url.searchParams.get('id');
                                            const hash = url.hash || '';
                                            let moduleHash = hash.replace('#tab-', '#module-');
                                            if (customerId) {
                                                redirectUrl = `mobile_customer_detail.php?id=${customerId}${moduleHash}`;
                                            }
                                        }
                                        if (redirectUrl.includes('mobile_customer_detail.php')) {
                                            const url = new URL(redirectUrl, window.location.origin);
                                            if (url.searchParams.get('id')) {
                                                window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                                                if (url.hash) {
                                                    const module = url.hash.substring(1).replace('module-', '');
                                                    if (module && document.getElementById('module-' + module)) {
                                                        setTimeout(() => {
                                                            switchToModule(module);
                                                        }, 100);
                                                    }
                                                }
                                                setTimeout(() => window.location.reload(), 1000);
                                            } else {
                                                window.location.href = redirectUrl;
                                            }
                                        } else {
                                            window.location.href = redirectUrl;
                                        }
                                    } else if (data.customer_id) {
                                        // 如果是新建客户，更新URL
                                        const url = new URL(window.location);
                                        url.searchParams.set('id', data.customer_id);
                                        window.history.replaceState({}, '', url);
                                        setTimeout(() => window.location.reload(), 1000);
                                    } else {
                                        setTimeout(() => window.location.reload(), 1000);
                                    }
                                } else {
                                    showToast(data.message || '保存失败');
                                }
                            } catch (parseError) {
                                console.error('Parse error:', parseError);
                                showToast('保存失败: 服务器响应格式错误');
                            }
                        } else {
                            showToast('保存失败: HTTP ' + xhr.status);
                        }
                    };
                    
                    xhr.onerror = function() {
                        console.error('XHR error');
                        showToast('保存失败，请检查网络连接');
                        btn.disabled = false;
                        btn.querySelector('span').textContent = originalText;
                    };
                    
                    xhr.send(formData);
                    return; // 使用XHR，不执行下面的finally
                } else {
                    // 其他错误
                    let errorMsg = '保存失败';
                    if (error.message) {
                        errorMsg = '保存失败: ' + error.message;
                    }
                    showToast(errorMsg);
                }
            })
            .finally(() => {
                // 只有fetch成功或非insecure错误时才执行finally
                // 如果是insecure错误，已经在catch中处理了XHR逻辑
                if (btn.disabled) {
                    btn.disabled = false;
                    btn.querySelector('span').textContent = originalText;
                }
            });
        });
        
        // 敲定成交模块：点击整行切换复选框
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#module-deal .task-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    // 如果点击的是备注输入框，不切换复选框
                    if (e.target.tagName === 'INPUT' && e.target.type === 'text') {
                        e.stopPropagation();
                        return;
                    }
                    
                    // 如果点击的是checkbox本身，让默认行为处理
                    if (e.target.type === 'checkbox') {
                        return;
                    }
                    
                    // 如果点击的是label，阻止冒泡，让label的for属性触发checkbox
                    if (e.target.tagName === 'LABEL') {
                        // label的for属性会自动触发checkbox，不需要手动处理
                        return;
                    }
                    
                    // 点击整行其他区域，切换复选框
                    e.preventDefault();
                    e.stopPropagation();
                    const checkbox = this.querySelector('.task-checkbox');
                    if (checkbox && !checkbox.disabled) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        });
        
        // ========== 链接管理功能 ==========
        <?php if (!$isNew && $customer): ?>
        const customerId = <?= $customerId ?>;
        const customerCode = '<?= $customer['customer_code'] ?? '' ?>';
        let linkData = <?= json_encode($link ?: null) ?>;
        const BASE_URL = window.location.origin;
        
        // 等待DOM加载完成后再绑定事件
        document.addEventListener('DOMContentLoaded', function() {
            // 链接管理按钮点击事件
            const linkManageBtn = document.getElementById('linkManageBtn');
            if (linkManageBtn) {
                linkManageBtn.addEventListener('click', function() {
                    showLinkManageModal();
                });
            }
            
            // 关闭按钮事件
            const linkManageClose = document.getElementById('linkManageClose');
            if (linkManageClose) {
                linkManageClose.addEventListener('click', hideLinkManageModal);
            }
            
            const linkManageCancel = document.getElementById('linkManageCancel');
            if (linkManageCancel) {
                linkManageCancel.addEventListener('click', hideLinkManageModal);
            }
            
            const modalOverlay = document.querySelector('.link-manage-modal .modal-overlay');
            if (modalOverlay) {
                modalOverlay.addEventListener('click', hideLinkManageModal);
            }
        });
        
        // 显示链接管理模态框
        function showLinkManageModal() {
            const modal = document.getElementById('linkManageModal');
            if (!modal) return;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // 加载链接信息
            loadLinkInfo();
        }
        
        // 隐藏链接管理模态框
        function hideLinkManageModal() {
            const modal = document.getElementById('linkManageModal');
            if (!modal) return;
            
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // 加载链接信息
        function loadLinkInfo() {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body) return;
            
            body.innerHTML = '<div class="loading-state">加载中...</div>';
            footer.style.display = 'none';
            
            const formData = new URLSearchParams({
                action: 'get',
                customer_id: customerId
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    linkData = data.data;
                    renderLinkManagement(linkData);
                } else {
                    renderGenerateLink();
                }
            })
            .catch(err => {
                console.error('加载链接信息失败:', err);
                body.innerHTML = '<div class="error-state">加载失败，请重试</div>';
            });
        }
        
        // 渲染链接管理界面
        function renderLinkManagement(link) {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body) return;
            
            const shareUrl = BASE_URL + '/share.php?code=' + customerCode;
            const hasPassword = link.has_password || false;
            const orgPermission = link.org_permission || 'edit';
            const passwordPermission = link.password_permission || 'editable';
            
            body.innerHTML = `
                <div class="link-manage-section">
                    <label class="form-label">🌐 分享链接</label>
                    <div id="regionLinksContainer">
                        <div style="color:#999;font-size:12px;">加载区域链接中...</div>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <div class="option-row">
                        <label>启用分享</label>
                        <label class="switch">
                            <input type="checkbox" id="linkEnabledSwitch" ${link.enabled ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">密码保护</label>
                    <input type="text" class="form-input" id="linkPasswordInput" placeholder="留空表示无密码" ${hasPassword ? 'value="********"' : ''}>
                    <small class="form-hint">未登录用户需要输入密码才能访问</small>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">密码权限级别</label>
                    <div class="options-group">
                        <div class="option-chip">
                            <input type="radio" name="passwordPermission" id="pwdReadonly" value="readonly" ${passwordPermission === 'readonly' ? 'checked' : ''}>
                            <label for="pwdReadonly">只读</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="passwordPermission" id="pwdEditable" value="editable" ${passwordPermission === 'editable' ? 'checked' : ''}>
                            <label for="pwdEditable">可编辑</label>
                        </div>
                    </div>
                </div>
                
                <div class="link-manage-section">
                    <label class="form-label">组织内权限</label>
                    <div class="options-group">
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgNone" value="none" ${orgPermission === 'none' ? 'checked' : ''}>
                            <label for="orgNone">禁止访问</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgView" value="view" ${orgPermission === 'view' ? 'checked' : ''}>
                            <label for="orgView">只读</label>
                        </div>
                        <div class="option-chip">
                            <input type="radio" name="orgPermission" id="orgEdit" value="edit" ${orgPermission === 'edit' ? 'checked' : ''}>
                            <label for="orgEdit">可编辑</label>
                        </div>
                    </div>
                    <small class="form-hint">登录用户的默认权限</small>
                </div>
                
                ${link.access_count ? `
                <div class="link-manage-section">
                    <div class="info-card">
                        <strong>访问统计</strong>
                        <p>访问次数：${link.access_count}</p>
                        ${link.last_access_at ? `<p>最后访问：${new Date(link.last_access_at * 1000).toLocaleString('zh-CN')}</p>` : ''}
                    </div>
                </div>
                ` : ''}
            `;
            
            footer.style.display = 'flex';
            
            // 加载多区域链接
            loadRegionLinks();
            
            // 绑定保存按钮事件
            document.getElementById('linkManageSave')?.addEventListener('click', updateLinkSettings);
        }
        
        // 加载多区域分享链接
        function loadRegionLinks() {
            const container = document.getElementById('regionLinksContainer');
            if (!container) return;
            
            fetch('/api/customer_link.php?action=get_region_urls&customer_code=' + encodeURIComponent(customerCode))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.regions && data.regions.length > 0) {
                        container.innerHTML = data.regions.map((r, idx) => `
                            <div class="share-link-display" style="margin-bottom:8px;">
                                <span style="min-width:60px;font-size:12px;color:#666;">${r.is_default ? '⭐' : ''} ${r.region_name}</span>
                                <input type="text" class="form-input" id="regionLink_${idx}" value="${r.url}" readonly style="flex:1;font-size:12px;">
                                <button class="btn btn-primary" data-link-idx="${idx}" style="font-size:12px;padding:6px 10px;">复制</button>
                            </div>
                        `).join('');
                        
                        container.querySelectorAll('button[data-link-idx]').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const idx = this.dataset.linkIdx;
                                const input = document.getElementById('regionLink_' + idx);
                                if (input) {
                                    input.select();
                                    document.execCommand('copy');
                                    showToast('链接已复制');
                                }
                            });
                        });
                    } else {
                        const defaultUrl = BASE_URL + '/share.php?code=' + customerCode;
                        container.innerHTML = `
                            <div class="share-link-display">
                                <input type="text" class="form-input" id="shareLinkInput" value="${defaultUrl}" readonly>
                                <button class="btn btn-primary" id="copyDefaultBtn">复制</button>
                            </div>
                        `;
                        document.getElementById('copyDefaultBtn')?.addEventListener('click', copyShareLink);
                    }
                })
                .catch(err => {
                    container.innerHTML = '<div style="color:#f00;font-size:12px;">加载失败</div>';
                });
        }
        
        // 渲染生成链接界面
        function renderGenerateLink() {
            const body = document.getElementById('linkManageBody');
            const footer = document.getElementById('linkManageFooter');
            
            if (!body) return;
            
            body.innerHTML = `
                <div class="empty-state">
                    <p>该客户还未生成分享链接</p>
                    <button class="btn btn-primary" id="generateLinkBtn">生成分享链接</button>
                </div>
            `;
            
            footer.style.display = 'none';
            
            // 绑定生成按钮事件
            document.getElementById('generateLinkBtn')?.addEventListener('click', generateLink);
        }
        
        // 生成分享链接
        function generateLink() {
            const formData = new URLSearchParams({
                action: 'generate',
                customer_id: customerId
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('分享链接生成成功');
                    loadLinkInfo();
                } else {
                    showToast(data.message || '生成失败');
                }
            })
            .catch(err => {
                console.error('生成链接失败:', err);
                showToast('生成失败，请重试');
            });
        }
        
        // 复制分享链接
        function copyShareLink() {
            const input = document.getElementById('shareLinkInput');
            if (!input) return;
            
            input.select();
            input.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                document.execCommand('copy');
                // 使用现代的 Clipboard API（如果可用）
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        showToast('链接已复制');
                    });
                } else {
                    showToast('链接已复制');
                }
            } catch (err) {
                console.error('复制失败:', err);
                showToast('复制失败，请手动复制');
            }
        }
        
        // 更新链接设置
        function updateLinkSettings() {
            const enabled = document.getElementById('linkEnabledSwitch')?.checked ? 1 : 0;
            const password = document.getElementById('linkPasswordInput')?.value.trim() || '';
            const orgPermission = document.querySelector('input[name="orgPermission"]:checked')?.value || 'edit';
            const passwordPermission = document.querySelector('input[name="passwordPermission"]:checked')?.value || 'editable';
            
            const formData = new URLSearchParams({
                action: 'update',
                customer_id: customerId,
                enabled: enabled,
                password: password,
                org_permission: orgPermission,
                password_permission: passwordPermission
            });
            
            fetch('/api/customer_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('设置保存成功');
                    hideLinkManageModal();
                    // 重新加载链接信息
                    if (data.data) {
                        linkData = data.data;
                    }
                } else {
                    showToast(data.message || '保存失败');
                }
            })
            .catch(err => {
                console.error('保存设置失败:', err);
                showToast('保存失败，请重试');
            });
        }
        <?php endif; ?>
        
        // 加载手机版文件管理模块的 JavaScript
        const mobileFileModule = document.getElementById('mobileFileManagementApp');
        if (mobileFileModule) {
            const script = document.createElement('script');
            script.src = 'js/mobile-file-management.js?v=' + Date.now();
            script.onload = function() {
                console.log('Mobile file management module loaded');
            };
            document.body.appendChild(script);
        }
    </script>
</body>
</html>
