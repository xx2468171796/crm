<?php
// 统一入口,通过 ?page=xxx 路由

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/field_renderer.php';
require_once __DIR__ . '/../core/device.php';

/**
 * 设置用户设备偏好
 * @param string $preference 'mobile' | 'desktop'
 */
function setDevicePreference($preference) {
    setcookie('device_preference', $preference, time() + 86400 * 30, '/'); // 30天有效
    $_COOKIE['device_preference'] = $preference;
}

// 设备检测和自动重定向逻辑
$forceMobile = isset($_GET['mobile']) && $_GET['mobile'] == '1';
$forceDesktop = isset($_GET['mobile']) && $_GET['mobile'] == '0';

// 如果用户强制指定了版本，保存偏好
if ($forceMobile) {
    setDevicePreference('mobile');
} elseif ($forceDesktop) {
    setDevicePreference('desktop');
}

// 获取用户偏好（Cookie）
$userPreference = $_COOKIE['device_preference'] ?? null;

// 判断是否应该重定向到手机版
$shouldRedirectToMobile = false;
$redirectReason = '';

if ($forceMobile) {
    $shouldRedirectToMobile = true;
    $redirectReason = 'force_mobile';
} elseif ($forceDesktop) {
    $shouldRedirectToMobile = false;
    $redirectReason = 'force_desktop';
} elseif ($userPreference === 'mobile') {
    $shouldRedirectToMobile = true;
    $redirectReason = 'user_preference';
} elseif ($userPreference === 'desktop') {
    $shouldRedirectToMobile = false;
    $redirectReason = 'user_preference';
} else {
    // 自动检测设备类型
    $shouldRedirectToMobile = isMobileDevice();
    $redirectReason = 'auto_detect';
    
    // 如果是自动检测为移动设备，保存偏好
    if ($shouldRedirectToMobile) {
        setDevicePreference('mobile');
    }
}

// 执行重定向（如果已经在手机版页面，则不重定向）
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$currentScript = basename($currentPath);
$isMobilePage = strpos($currentScript, 'mobile_') !== false || strpos($currentPath, 'mobile_') !== false;

if ($shouldRedirectToMobile && !$isMobilePage) {
    // 检查目标页面
    $targetPage = $_GET['page'] ?? null;
    $redirectUrl = '';
    
    if ($targetPage === 'customer_detail') {
        $customerId = intval($_GET['id'] ?? 0);
        $redirectUrl = 'mobile_customer_detail.php';
        if ($customerId > 0) {
            $redirectUrl .= '?id=' . $customerId;
        }
        // 保持其他参数（如hash锚点等）
        $hash = $_GET['hash'] ?? '';
        if ($hash) {
            $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . 'hash=' . urlencode($hash);
        }
    } elseif ($targetPage === 'my_customers') {
        // 保持搜索和筛选参数
        $params = $_GET;
        unset($params['page'], $params['mobile']);
        $redirectUrl = 'mobile_my_customers.php';
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }
    } elseif ($targetPage === 'okr') {
        $redirectUrl = 'mobile_okr.php';
    } else {
        // 默认：手机版主页
        $redirectUrl = 'mobile_home.php';
    }
    
    // 执行重定向（在认证之前）
    header('Location: ' . $redirectUrl);
    exit;
}

// 需要登录的页面统一在这里处理
$page = $_GET['page'] ?? 'dashboard';

// 白名单路由
$routes = [
    'dashboard'       => __DIR__ . '/dashboard.php',
    'customer_new'    => __DIR__ . '/customer_new.php',
    'customer_detail' => __DIR__ . '/customer_detail.php',
    'customer_edit'   => __DIR__ . '/customer_edit.php',
    'my_customers'    => __DIR__ . '/my_customers.php',

    // 财务模块
    'my_receivables'   => __DIR__ . '/my_receivables.php',
    'finance_dashboard' => __DIR__ . '/finance_dashboard.php',
    'finance_contract_create' => __DIR__ . '/finance_contract_create.php',
    'finance_contract_detail' => __DIR__ . '/finance_contract_detail.php',
    'finance_receipts'  => __DIR__ . '/finance_receipts.php',
    'finance_prepay'    => __DIR__ . '/finance_prepay.php',
    'finance_prepay_report' => __DIR__ . '/finance_prepay_report.php',
    'finance_kpi_dashboard' => __DIR__ . '/finance_kpi_dashboard.php',
    'commission_rules' => __DIR__ . '/commission_rules.php',
    'commission_calculator' => __DIR__ . '/commission_calculator.php',
    'exchange_rate' => __DIR__ . '/exchange_rate.php',
    'admin_payment_methods' => __DIR__ . '/admin_payment_methods.php',
    
    // 数据分析
    'analytics'       => __DIR__ . '/analytics.php',
    'okr'             => __DIR__ . '/okr.php',
    
    // 后台管理
    'admin_customers'   => __DIR__ . '/admin_customers.php',
    'admin_deleted_customers' => __DIR__ . '/admin_deleted_customers.php',
    'admin_deleted_customer_files' => __DIR__ . '/admin_deleted_customer_files.php',
    'admin_deleted_deliverables' => __DIR__ . '/admin_deleted_deliverables.php',
    'admin_files'       => __DIR__ . '/admin_files.php',
    'admin_departments' => __DIR__ . '/admin_departments.php',
    'admin_users'       => __DIR__ . '/admin_users.php',
    'admin_roles'       => __DIR__ . '/admin_roles.php',
    'admin_fields'      => __DIR__ . '/admin_fields.php',
    
    // 字段配置（新版三层结构）
    'admin_modules'      => __DIR__ . '/admin_modules.php',
    'admin_fields_new'   => __DIR__ . '/admin_fields_new.php',
    'admin_field_options' => __DIR__ . '/admin_field_options.php',
    'system_dict'        => __DIR__ . '/system_dict.php',
    
    // 审批工作台
    'admin_approval'     => __DIR__ . '/admin_approval.php',
    
    // 表单模板管理
    'admin_form_templates' => __DIR__ . '/admin_form_templates.php',
    
    // 字段可见性配置
    'admin_field_visibility' => __DIR__ . '/admin_field_visibility.php',
    
    // 阶段时间模板
    'admin_stage_templates' => __DIR__ . '/../views/admin/stage_templates.php',
    'stage_templates' => __DIR__ . '/../views/admin/stage_templates.php',  // 别名
    
    // 评价模板配置
    'admin_evaluation_config' => __DIR__ . '/../views/admin/evaluation_config.php',
    
    // 常用QA
    'qa'              => __DIR__ . '/qa.php',
    
    // 项目看板
    'project_kanban'  => __DIR__ . '/project_kanban.php',
    'project_detail'  => __DIR__ . '/project_detail.php',
    
    // 技术提成
    'tech_my_projects'       => __DIR__ . '/tech_my_projects.php',
    'tech_commission_manage' => __DIR__ . '/tech_commission_manage.php',
    'admin_tech_finance'     => __DIR__ . '/admin_tech_finance.php',
    
    // 小工具
    'tools'           => __DIR__ . '/tools.php',
    'storage_health'  => __DIR__ . '/storage_health.php',
    'upload_config_check' => __DIR__ . '/upload_config_check.php',
    
    // 客户筛选字段管理
    'admin_customer_filter_fields' => __DIR__ . '/admin_customer_filter_fields.php',
    
    // 分享节点配置
    'admin_share_regions' => __DIR__ . '/admin_share_regions.php',
    
    // S3加速节点配置
    'admin_s3_acceleration' => __DIR__ . '/admin_s3_acceleration.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    layout_header('页面不存在');
    echo '<div class="alert alert-danger">页面不存在</div>';
    layout_footer();
    exit;
}

// 检查是否是外部分享链接访问（customer_detail 页面且有 session 验证）
$isExternalAccess = false;
if ($page === 'customer_detail') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $customerId = intval($_GET['id'] ?? 0);
    // 只有在未登录的情况下才检查外部访问
    if ($customerId > 0 && isset($_SESSION['share_verified_' . $customerId]) && !current_user()) {
        $isExternalAccess = true;
    }
}

// 需要登录（外部访问跳过）
if (!$isExternalAccess) {
    auth_require();
}

require $routes[$page];
