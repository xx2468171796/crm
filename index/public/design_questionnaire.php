<?php
/**
 * 设计对接资料问卷 - 外部访问页面
 * 
 * 访问方式:
 *   /design_questionnaire.php?token=xxx          - 外部访问（可编辑）
 *   /design_questionnaire.php?customer_id=xxx    - 内部访问（需登录）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_GET['token'] ?? '');
$customerId = (int)($_GET['customer_id'] ?? 0);
$readonly = isset($_GET['readonly']) && $_GET['readonly'] === '1';
$isExternal = !empty($token);
$isInternal = false;
$user = null;
$questionnaire = null;
$customerName = '';
$customerGroup = '';

if ($isExternal) {
    // 外部通过token访问
    $questionnaire = Db::queryOne('
        SELECT dq.*, c.name as customer_name, c.alias as customer_alias, c.customer_group
        FROM design_questionnaires dq
        JOIN customers c ON dq.customer_id = c.id
        WHERE dq.token = ? AND dq.status = 1
    ', [$token]);

    if (!$questionnaire) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>问卷不存在</title></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;"><h2>问卷不存在或已禁用</h2></body></html>';
        exit;
    }
    $customerId = (int)$questionnaire['customer_id'];
    $customerName = $questionnaire['customer_alias'] ?: $questionnaire['customer_name'];
    $customerGroup = $questionnaire['customer_group'] ?? '';

    // 设置 session 标志，让 customer_file_stream.php 的 resolveShareActor 能识别
    $_SESSION['share_verified_' . $customerId] = true;
    $_SESSION['share_readonly_' . $customerId] = true;
} else {
    // 内部访问需要登录
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    $isInternal = true;

    if ($customerId > 0) {
        $questionnaire = Db::queryOne('
            SELECT dq.*, c.name as customer_name, c.alias as customer_alias, c.customer_group
            FROM design_questionnaires dq
            JOIN customers c ON dq.customer_id = c.id
            WHERE dq.customer_id = ?
        ', [$customerId]);

        if (!$questionnaire) {
            $customer = Db::queryOne('SELECT id, name, alias, customer_group FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
            if (!$customer) {
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><h2>客户不存在</h2></body></html>';
                exit;
            }
            $customerName = $customer['alias'] ?: $customer['name'];
            $customerGroup = $customer['customer_group'] ?? '';
            $token = '';
        } else {
            $customerName = $questionnaire['customer_alias'] ?: $questionnaire['customer_name'];
            $customerGroup = $questionnaire['customer_group'] ?? '';
            $token = $questionnaire['token'];
        }
    }
}

// 解码JSON字段
$jsonFields = ['communication_style', 'service_items', 'rendering_type', 'life_focus', 'reference_images', 'original_files', 'contact_method'];
if ($questionnaire) {
    foreach ($jsonFields as $field) {
        if (isset($questionnaire[$field]) && is_string($questionnaire[$field])) {
            $decoded = json_decode($questionnaire[$field], true);
            $questionnaire[$field] = $decoded !== null ? $decoded : [];
        } else {
            $questionnaire[$field] = [];
        }
    }
}

$apiBase = $isExternal
    ? '/api/design_questionnaire.php?action=external_save&token=' . urlencode($token)
    : '/api/design_questionnaire.php?action=save';
$getApiBase = $isExternal
    ? '/api/design_questionnaire.php?action=external_get&token=' . urlencode($token)
    : '/api/design_questionnaire.php?action=get&customer_id=' . $customerId;
$uploadApiBase = $isExternal
    ? '/api/design_questionnaire.php?action=external_upload_file&token=' . urlencode($token)
    : '/api/design_questionnaire.php?action=upload_file';

$pageTitle = $customerName ? "设计对接资料问卷 - {$customerName}" : '设计对接资料问卷';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="/js/opencc-lite.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-bg: #eef2ff;
            --accent: #f59e0b;
            --success: #10b981;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
            --radius: 12px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            color: var(--gray-700);
            padding: 0;
            margin: 0;
        }

        .bg-decoration {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bg-decoration::before {
            content: '';
            position: absolute;
            top: -30%; right: -20%;
            width: 80%; height: 80%;
            background: radial-gradient(ellipse at center, rgba(99,102,241,0.18) 0%, rgba(139,92,246,0.10) 30%, rgba(139,92,246,0.03) 50%, transparent 70%);
            filter: blur(60px);
        }

        .bg-decoration::after {
            content: '';
            position: absolute;
            bottom: -30%; left: -20%;
            width: 70%; height: 70%;
            background: radial-gradient(ellipse at center, rgba(168,85,247,0.14) 0%, rgba(236,72,153,0.08) 30%, rgba(236,72,153,0.02) 50%, transparent 70%);
            filter: blur(80px);
        }

        .bg-decoration-extra {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            z-index: 0;
        }

        .bg-decoration-extra::before {
            content: '';
            position: fixed;
            bottom: -20%; right: -10%;
            width: 50%; height: 50%;
            background: radial-gradient(ellipse at center, rgba(251,191,36,0.10) 0%, rgba(251,146,60,0.05) 40%, transparent 70%);
            filter: blur(70px);
        }

        .bg-decoration-extra::after {
            content: '';
            position: fixed;
            top: 20%; left: 30%;
            width: 40%; height: 40%;
            background: radial-gradient(ellipse at center, rgba(14,165,233,0.06) 0%, transparent 60%);
            filter: blur(100px);
        }

        .page-header, .main-content, .footer-bar {
            position: relative;
            z-index: 1;
        }

        .lang-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border: 1.5px solid var(--gray-200);
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            background: white;
            color: var(--gray-700);
            transition: all 0.2s;
            user-select: none;
        }

        .lang-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .lang-toggle.active {
            border-color: var(--primary);
            background: var(--primary-bg);
            color: var(--primary);
        }

        .page-header {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .brand-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: var(--gray-900);
        }

        .brand-text p {
            font-size: 13px;
            color: var(--gray-500);
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .save-status {
            font-size: 13px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .save-status.saving { color: var(--accent); }
        .save-status.saved { color: var(--success); }
        .save-status.error { color: var(--danger); }

        .main-content {
            max-width: 860px;
            margin: 0 auto;
            padding: 30px 20px 80px;
        }

        .section-card {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }

        .section-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .section-header {
            background: linear-gradient(135deg, var(--primary-bg), #f0f4ff);
            padding: 18px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--gray-200);
        }

        .section-number {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .section-title {
            font-size: 17px;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        .section-subtitle {
            font-size: 13px;
            color: var(--gray-500);
            margin: 2px 0 0;
        }

        .section-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
        }

        .form-label .required {
            color: var(--danger);
            font-size: 16px;
        }

        .form-label .badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 500;
        }

        .form-control, .form-select {
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 15px;
            transition: all 0.2s;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .checkbox-group, .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .check-card {
            position: relative;
            cursor: pointer;
        }

        .check-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .check-card .card-label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
            user-select: none;
        }

        .check-card .card-label i {
            font-size: 18px;
            color: var(--gray-500);
        }

        .check-card input:checked + .card-label {
            border-color: var(--primary);
            background: var(--primary-bg);
            color: var(--primary);
        }

        .check-card input:checked + .card-label i {
            color: var(--primary);
        }

        .check-card:hover .card-label {
            border-color: var(--primary-light);
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border: none;
            color: white;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .btn-primary-custom:disabled {
            opacity: 0.6;
            transform: none;
            cursor: not-allowed;
        }

        .btn-outline-custom {
            background: white;
            border: 1.5px solid var(--gray-300);
            color: var(--gray-700);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-outline-custom:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .yesno-group {
            display: flex;
            gap: 10px;
        }

        .yesno-group .check-card .card-label {
            min-width: 80px;
            justify-content: center;
        }

        .file-upload-area {
            border: 2px dashed var(--gray-300);
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--gray-50);
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: var(--primary-bg);
        }

        .file-upload-area i {
            font-size: 36px;
            color: var(--gray-400);
            margin-bottom: 8px;
        }

        .file-upload-area p {
            margin: 0;
            color: var(--gray-500);
            font-size: 14px;
        }

        .uploaded-files {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }

        .uploaded-file-thumb {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--gray-200);
        }

        .footer-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255,255,255,0.3);
            padding: 14px 0;
            z-index: 100;
        }

        .footer-bar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 860px;
        }

        .progress-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--gray-500);
        }

        .progress-bar-custom {
            width: 120px;
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar-custom .fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 3px;
            transition: width 0.3s;
        }

        .toast-notification {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            z-index: 9999;
            transform: translateX(120%);
            transition: transform 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toast-notification.show {
            transform: translateX(0);
        }

        .toast-notification.success { background: var(--success); }
        .toast-notification.error { background: var(--danger); }

        @media (max-width: 768px) {
            .main-content { padding: 16px 12px 80px; }
            .section-body { padding: 16px; }
            .checkbox-group, .radio-group { gap: 8px; }
            .check-card .card-label { padding: 8px 12px; font-size: 13px; }
            .page-header .container { flex-direction: column; gap: 10px; }
        }

        .readonly-notice {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>

<!-- 背景装饰 -->
<div class="bg-decoration"></div>
<div class="bg-decoration-extra"></div>

<!-- Header -->
<div class="page-header">
    <div class="container">
        <div class="brand">
            <div class="brand-icon">
                <i class="bi bi-palette"></i>
            </div>
            <div class="brand-text">
                <h1><?= htmlspecialchars($customerName ?: '设计对接资料问卷') ?></h1>
                <p>Design Project Intake Form</p>
            </div>
        </div>
        <div class="header-actions">
            <button class="lang-toggle" id="langToggle" onclick="toggleLang()" title="简体/繁体切换">
                <i class="bi bi-translate"></i>
                <span id="langLabel">繁</span>
            </button>
            <div class="save-status" id="saveStatus">
                <i class="bi bi-cloud-check"></i>
                <span>就绪</span>
            </div>
            <?php if (!$readonly): ?>
            <button class="btn-primary-custom" onclick="saveQuestionnaire()" id="btnSave">
                <i class="bi bi-check2-circle"></i> 保存
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="main-content">
    <?php if ($readonly): ?>
    <div class="readonly-notice">
        <i class="bi bi-eye"></i> 当前为只读模式，无法编辑
    </div>
    <?php endif; ?>

    <form id="questionnaireForm" autocomplete="off">
        <!-- 一、基本资讯 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">1</div>
                <div>
                    <div class="section-title">基本资讯</div>
                    <div class="section-subtitle">Basic Information</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">
                        客户姓名 <span class="required">*</span>
                    </label>
                    <input type="text" class="form-control" name="client_name" placeholder="请输入您的姓名"
                           value="<?= htmlspecialchars($questionnaire['client_name'] ?? $customerName ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <?php if (!empty($customerGroup)): ?>
                <div class="form-group">
                    <label class="form-label">客户群名称</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($customerGroup) ?>" readonly style="background:#f8f9fa;">
                </div>
                <?php endif; ?>
                <?php if ($isInternal): ?>
                <div class="form-group">
                    <label class="form-label">常用联系方式</label>
                    <div class="checkbox-group">
                        <?php
                        $contactMethods = $questionnaire['contact_method'] ?? [];
                        if (!is_array($contactMethods)) $contactMethods = [];
                        $methodOptions = [
                            'line' => ['LINE', 'bi-chat-dots'],
                            'wechat' => ['微信', 'bi-wechat'],
                            'phone' => ['电话', 'bi-telephone'],
                            'email' => ['邮件', 'bi-envelope'],
                        ];
                        foreach ($methodOptions as $val => $opt): ?>
                        <label class="check-card">
                            <input type="checkbox" name="contact_method[]" value="<?= $val ?>"
                                   <?= in_array($val, $contactMethods) ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">联系电话/ID</label>
                    <input type="text" class="form-control" name="contact_phone" placeholder="请输入电话号码或社交账号ID"
                           value="<?= htmlspecialchars($questionnaire['contact_phone'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">方便联系的时间</label>
                    <input type="text" class="form-control" name="contact_time" placeholder="例如：工作日 10:00-18:00"
                           value="<?= htmlspecialchars($questionnaire['contact_time'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">您习惯的沟通方式</label>
                    <div class="checkbox-group">
                        <?php
                        $commStyles = $questionnaire['communication_style'] ?? [];
                        if (!is_array($commStyles)) $commStyles = [];
                        $styleOptions = [
                            'text' => ['文字讯息', 'bi-chat-left-text'],
                            'voice' => ['语音讯息', 'bi-mic'],
                            'call' => ['电话沟通', 'bi-telephone-outbound'],
                            'image' => ['图片/截图说明', 'bi-image'],
                        ];
                        foreach ($styleOptions as $val => $opt): ?>
                        <label class="check-card">
                            <input type="checkbox" name="communication_style[]" value="<?= $val ?>"
                                   <?= in_array($val, $commStyles) ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 二、设计服务内容 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">2</div>
                <div>
                    <div class="section-title">设计服务内容</div>
                    <div class="section-subtitle">Design Services <span class="badge bg-danger bg-opacity-10 text-danger">必填</span></div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">请勾选您本次需要的服务项目 <span class="required">*</span></label>
                    <div class="checkbox-group">
                        <?php
                        $serviceItems = $questionnaire['service_items'] ?? [];
                        if (!is_array($serviceItems)) $serviceItems = [];
                        $services = [
                            'floor_plan' => ['平面图方案设计', 'bi-grid-3x3'],
                            'rendering' => ['效果图设计', 'bi-card-image'],
                            'construction' => ['施工图设计', 'bi-rulers'],
                            'exterior' => ['外立面造型设计', 'bi-building'],
                        ];
                        foreach ($services as $val => $opt): ?>
                        <label class="check-card">
                            <input type="checkbox" name="service_items[]" value="<?= $val ?>"
                                   <?= in_array($val, $serviceItems) ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group" id="renderingTypeGroup" style="display:<?= in_array('rendering', $serviceItems) ? 'block' : 'none' ?>">
                    <label class="form-label">效果图类型</label>
                    <div class="checkbox-group">
                        <?php
                        $renderingTypes = $questionnaire['rendering_type'] ?? [];
                        if (!is_array($renderingTypes)) $renderingTypes = [];
                        $rtOptions = [
                            'single_3d' => ['单张 3D 效果图', 'bi-box'],
                            '720_panorama' => ['720° 全景环景图', 'bi-globe2'],
                        ];
                        foreach ($rtOptions as $val => $opt): ?>
                        <label class="check-card">
                            <input type="checkbox" name="rendering_type[]" value="<?= $val ?>"
                                   <?= in_array($val, $renderingTypes) ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 三、空间细节与改造程度 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">3</div>
                <div>
                    <div class="section-title">空间细节与改造程度</div>
                    <div class="section-subtitle">Space Details & Renovation Scope</div>
                </div>
            </div>
            <div class="section-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label class="form-label">设计总面积</label>
                            <input type="text" class="form-control" name="total_area" placeholder="请输入面积数字"
                                   value="<?= htmlspecialchars($questionnaire['total_area'] ?? '') ?>"
                                   <?= $readonly ? 'readonly' : '' ?>>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">面积单位</label>
                            <select class="form-select" name="area_unit" <?= $readonly ? 'disabled' : '' ?>>
                                <option value="sqm" <?= ($questionnaire['area_unit'] ?? 'sqm') === 'sqm' ? 'selected' : '' ?>>平方米</option>
                                <option value="ping" <?= ($questionnaire['area_unit'] ?? '') === 'ping' ? 'selected' : '' ?>>坪</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">房屋现况 <span class="required">*</span></label>
                    <div class="radio-group">
                        <?php
                        $houseStatus = $questionnaire['house_status'] ?? '';
                        $statusOptions = [
                            'rough' => ['毛坯房', 'bi-bricks'],
                            'decorated' => ['精装房', 'bi-house-check'],
                            'renovation' => ['旧屋翻新', 'bi-hammer'],
                            'commercial' => ['商业空间', 'bi-shop'],
                        ];
                        foreach ($statusOptions as $val => $opt): ?>
                        <label class="check-card">
                            <input type="radio" name="house_status" value="<?= $val ?>"
                                   <?= $houseStatus === $val ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">阳台/厨卫是否包含在设计范围内？</label>
                    <div class="yesno-group">
                        <?php $balcony = $questionnaire['include_balcony_kitchen'] ?? null; ?>
                        <label class="check-card">
                            <input type="radio" name="include_balcony_kitchen" value="1"
                                   <?= $balcony === '1' || $balcony === 1 ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-check-lg"></i> 是</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="include_balcony_kitchen" value="0"
                                   <?= $balcony === '0' || $balcony === 0 ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-x-lg"></i> 否</div>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">天花板/墙体结构是否拆改？</label>
                    <div class="radio-group">
                        <?php $ceiling = $questionnaire['ceiling_wall_modify'] ?? ''; ?>
                        <label class="check-card">
                            <input type="radio" name="ceiling_wall_modify" value="yes" <?= $ceiling === 'yes' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-check-lg"></i> 是</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="ceiling_wall_modify" value="no" <?= $ceiling === 'no' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-x-lg"></i> 否</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="ceiling_wall_modify" value="designer_suggest" <?= $ceiling === 'designer_suggest' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-lightbulb"></i> 听从设计师建议</div>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">水电管线是否全室重新配管？</label>
                    <div class="yesno-group">
                        <?php $rewire = $questionnaire['rewire_plumbing'] ?? ''; ?>
                        <label class="check-card">
                            <input type="radio" name="rewire_plumbing" value="yes" <?= $rewire === 'yes' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-check-lg"></i> 是</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="rewire_plumbing" value="no" <?= $rewire === 'no' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-x-lg"></i> 否</div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 四、风格倾向与审美偏好 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">4</div>
                <div>
                    <div class="section-title">风格倾向与审美偏好</div>
                    <div class="section-subtitle">Style Preferences & Aesthetics</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">风格成熟度</label>
                    <div class="radio-group">
                        <?php $styleMat = $questionnaire['style_maturity'] ?? ''; ?>
                        <label class="check-card">
                            <input type="radio" name="style_maturity" value="has_reference" <?= $styleMat === 'has_reference' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-images"></i> 已有明确参考图</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="style_maturity" value="rough_idea" <?= $styleMat === 'rough_idea' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-lightbulb"></i> 有大致意向</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="style_maturity" value="no_idea" <?= $styleMat === 'no_idea' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-magic"></i> 请设计师建议</div>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">风格描述</label>
                    <input type="text" class="form-control" name="style_description"
                           placeholder="例如：现代风、法式奶油风、侘寂风、北欧风..."
                           value="<?= htmlspecialchars($questionnaire['style_description'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">色系偏好</label>
                    <input type="text" class="form-control" name="color_preference"
                           placeholder="例如：暖白、木质色、工业灰、莫兰迪色系..."
                           value="<?= htmlspecialchars($questionnaire['color_preference'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">设计禁忌</label>
                    <textarea class="form-control" name="design_taboo" rows="3"
                              placeholder="例如：不接受开放式厨房、不喜欢深色系、需避开风水禁忌等..."
                              <?= $readonly ? 'readonly' : '' ?>><?= htmlspecialchars($questionnaire['design_taboo'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">参考图片上传</label>
                    <?php if (!$readonly): ?>
                    <div class="file-upload-area" onclick="document.getElementById('refImageInput').click()">
                        <i class="bi bi-cloud-arrow-up d-block"></i>
                        <p>点击上传参考图片或风格截图</p>
                        <p class="text-muted" style="font-size:12px;">支持 JPG / PNG / WEBP，单张最大 20MB</p>
                    </div>
                    <input type="file" id="refImageInput" accept="image/*" multiple style="display:none" onchange="handleImageUpload(this.files)">
                    <?php endif; ?>
                    <div class="uploaded-files" id="referenceImages"></div>
                </div>
            </div>
        </div>

        <!-- 五、生活功能与使用习惯 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">5</div>
                <div>
                    <div class="section-title">生活功能与使用习惯</div>
                    <div class="section-subtitle">Lifestyle & Usage Habits</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">常住成员</label>
                    <input type="text" class="form-control" name="household_members"
                           placeholder="例如：2大1小、有宠物（1猫）"
                           value="<?= htmlspecialchars($questionnaire['household_members'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">特殊功能需求</label>
                    <textarea class="form-control" name="special_function_needs" rows="3"
                              placeholder="例如：需要独立电竞房、大量鞋柜需求、开放式大厨房、独立洗衣房..."
                              <?= $readonly ? 'readonly' : '' ?>><?= htmlspecialchars($questionnaire['special_function_needs'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">生活重心</label>
                    <div class="checkbox-group">
                        <?php
                        $lifeFocus = $questionnaire['life_focus'] ?? [];
                        if (!is_array($lifeFocus)) $lifeFocus = [];
                        $focusOptions = [
                            'entertainment' => ['影音娱乐', 'bi-tv'],
                            'cooking' => ['烹饪美食', 'bi-cup-hot'],
                            'work_home' => ['居家办公', 'bi-laptop'],
                            'reading' => ['阅读放松', 'bi-book'],
                            'fitness' => ['健身运动', 'bi-heart-pulse'],
                            'kids' => ['亲子活动', 'bi-people'],
                        ];
                        foreach ($focusOptions as $val => $opt): ?>
                        <label class="check-card">
                            <input type="checkbox" name="life_focus[]" value="<?= $val ?>"
                                   <?= in_array($val, $lifeFocus) ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi <?= $opt[1] ?>"></i> <?= $opt[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 六、项目执行与预算 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">6</div>
                <div>
                    <div class="section-title">项目执行与预算</div>
                    <div class="section-subtitle">Budget & Timeline <span class="badge bg-danger bg-opacity-10 text-danger">必填</span></div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">客户预算区间 <span class="required">*</span></label>
                    <div class="radio-group">
                        <?php $budgetType = $questionnaire['budget_type'] ?? ''; ?>
                        <label class="check-card">
                            <input type="radio" name="budget_type" value="economy" <?= $budgetType === 'economy' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-piggy-bank"></i> 经济型</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="budget_type" value="standard" <?= $budgetType === 'standard' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-star"></i> 标准型</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="budget_type" value="premium" <?= $budgetType === 'premium' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-gem"></i> 高端订制</div>
                        </label>
                        <label class="check-card">
                            <input type="radio" name="budget_type" value="custom" <?= $budgetType === 'custom' ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label"><i class="bi bi-pencil-square"></i> 自定义</div>
                        </label>
                    </div>
                </div>
                <div class="form-group" id="budgetRangeGroup" style="display:<?= $budgetType === 'custom' ? 'block' : 'none' ?>">
                    <label class="form-label">具体预算范围</label>
                    <input type="text" class="form-control" name="budget_range"
                           placeholder="请输入具体预算范围"
                           value="<?= htmlspecialchars($questionnaire['budget_range'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">预计交付或完工节点</label>
                    <input type="text" class="form-control" name="delivery_deadline"
                           placeholder="例如：需在 3 月 15 日前完成设计稿"
                           value="<?= htmlspecialchars($questionnaire['delivery_deadline'] ?? '') ?>"
                           <?= $readonly ? 'readonly' : '' ?>>
                </div>
            </div>
        </div>

        <!-- 七、原始资料提供 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">7</div>
                <div>
                    <div class="section-title">原始资料提供</div>
                    <div class="section-subtitle">Source Materials <span class="badge bg-danger bg-opacity-10 text-danger">必填</span></div>
                </div>
            </div>
            <div class="section-body">
                <p style="color:var(--gray-500); font-size:14px; margin-bottom:16px;">为了开始设计，请确认您已备妥以下资料：</p>
                <div class="form-group">
                    <div class="checkbox-group" style="flex-direction: column;">
                        <?php $hasFloorPlan = (int)($questionnaire['has_floor_plan'] ?? 0); ?>
                        <label class="check-card">
                            <input type="checkbox" name="has_floor_plan" value="1"
                                   <?= $hasFloorPlan ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi bi-map"></i> 原始平面图 / 量房图
                            </div>
                        </label>
                        <?php $hasSitePhotos = (int)($questionnaire['has_site_photos'] ?? 0); ?>
                        <label class="check-card">
                            <input type="checkbox" name="has_site_photos" value="1"
                                   <?= $hasSitePhotos ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi bi-camera"></i> 现场实勘照片或影片
                            </div>
                        </label>
                        <?php $hasKeyDimensions = (int)($questionnaire['has_key_dimensions'] ?? 0); ?>
                        <label class="check-card">
                            <input type="checkbox" name="has_key_dimensions" value="1"
                                   <?= $hasKeyDimensions ? 'checked' : '' ?>
                                   <?= $readonly ? 'disabled' : '' ?>>
                            <div class="card-label">
                                <i class="bi bi-rulers"></i> 关键尺寸数据（天花板高度、梁位高度）
                            </div>
                        </label>
                    </div>
                </div>
                <div class="form-group mt-3">
                    <label class="form-label">上传原始资料文件</label>
                    <?php if (!$readonly): ?>
                    <div class="file-upload-area" onclick="document.getElementById('originalFileInput').click()">
                        <i class="bi bi-cloud-arrow-up d-block"></i>
                        <p>点击上传平面图、现场照片、尺寸图等文件</p>
                        <p class="text-muted" style="font-size:12px;">支持所有常见文件格式，单个文件最大 1GB</p>
                    </div>
                    <input type="file" id="originalFileInput" multiple style="display:none" onchange="handleFileUpload(this.files)">
                    <?php endif; ?>
                    <div id="uploadedFilesList" class="mt-2"></div>
                </div>
            </div>
        </div>

        <!-- 八、其他备注 -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-number">8</div>
                <div>
                    <div class="section-title">其他备注</div>
                    <div class="section-subtitle">Additional Notes</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label">其他想补充的细节</label>
                    <textarea class="form-control" name="extra_notes" rows="5"
                              placeholder="如果您有任何其他想补充的细节，请写在这里..."
                              <?= $readonly ? 'readonly' : '' ?>><?= htmlspecialchars($questionnaire['extra_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Footer -->
<?php if (!$readonly): ?>
<div class="footer-bar">
    <div class="container">
        <div class="progress-indicator">
            <i class="bi bi-clipboard-check"></i>
            <span id="progressText">填写进度</span>
            <div class="progress-bar-custom">
                <div class="fill" id="progressFill" style="width:0%"></div>
            </div>
        </div>
        <button class="btn-primary-custom" onclick="saveQuestionnaire()" id="btnSaveBottom">
            <i class="bi bi-check2-circle"></i> 保存问卷
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Toast -->
<div class="toast-notification" id="toastNotification">
    <i class="bi bi-check-circle"></i>
    <span id="toastMessage"></span>
</div>

<script>
const IS_EXTERNAL = <?= $isExternal ? 'true' : 'false' ?>;
const IS_READONLY = <?= $readonly ? 'true' : 'false' ?>;
const CUSTOMER_ID = <?= $customerId ?>;
const TOKEN = '<?= htmlspecialchars($token ?? '') ?>';
const API_SAVE = '<?= $apiBase ?>';
const API_UPLOAD = '<?= $uploadApiBase ?>';
const API_LIST_FILES = IS_EXTERNAL
    ? '/api/design_questionnaire.php?action=external_list_files&token=' + encodeURIComponent(TOKEN)
    : '/api/design_questionnaire.php?action=list_files&customer_id=' + CUSTOMER_ID;
const MAX_IMAGE_SIZE = 20 * 1024 * 1024; // 20MB
const MAX_FILE_SIZE = 1 * 1024 * 1024 * 1024; // 1GB

// 显示/隐藏效果图类型
document.querySelectorAll('input[name="service_items[]"]').forEach(cb => {
    cb.addEventListener('change', () => {
        const checked = document.querySelector('input[name="service_items[]"][value="rendering"]')?.checked;
        document.getElementById('renderingTypeGroup').style.display = checked ? 'block' : 'none';
    });
});

// 显示/隐藏自定义预算
document.querySelectorAll('input[name="budget_type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.getElementById('budgetRangeGroup').style.display = radio.value === 'custom' && radio.checked ? 'block' : 'none';
    });
});

// 计算填写进度
function updateProgress() {
    const fields = [
        'client_name', 'contact_phone', 'total_area', 'household_members',
        'delivery_deadline'
    ];
    const checkboxGroups = ['contact_method[]', 'service_items[]', 'life_focus[]'];
    const radioGroups = ['house_status', 'budget_type', 'style_maturity'];

    let filled = 0;
    let total = fields.length + checkboxGroups.length + radioGroups.length;

    fields.forEach(name => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el && el.value.trim()) filled++;
    });

    checkboxGroups.forEach(name => {
        const checked = document.querySelectorAll(`[name="${name}"]:checked`);
        if (checked.length > 0) filled++;
    });

    radioGroups.forEach(name => {
        const checked = document.querySelector(`[name="${name}"]:checked`);
        if (checked) filled++;
    });

    const pct = Math.round(filled / total * 100);
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressText').textContent = `填写进度 ${pct}%`;
}

// 收集表单数据
function collectFormData() {
    const form = document.getElementById('questionnaireForm');
    const data = {};

    // 文本输入和textarea
    form.querySelectorAll('input[type="text"], textarea, select').forEach(el => {
        if (el.name) data[el.name] = el.value;
    });

    // 复选框组
    ['contact_method', 'communication_style', 'service_items', 'rendering_type', 'life_focus'].forEach(name => {
        const checked = form.querySelectorAll(`input[name="${name}[]"]:checked`);
        data[name] = Array.from(checked).map(cb => cb.value);
    });

    // 单选框
    ['house_status', 'include_balcony_kitchen', 'ceiling_wall_modify', 'rewire_plumbing',
     'style_maturity', 'budget_type'].forEach(name => {
        const checked = form.querySelector(`input[name="${name}"]:checked`);
        data[name] = checked ? checked.value : null;
    });

    // 独立复选框
    ['has_floor_plan', 'has_site_photos', 'has_key_dimensions'].forEach(name => {
        const cb = form.querySelector(`input[name="${name}"]`);
        data[name] = cb && cb.checked ? 1 : 0;
    });

    // 参考图片
    data.reference_images = referenceImages;

    if (!IS_EXTERNAL) {
        data.customer_id = CUSTOMER_ID;
    }
    if (IS_EXTERNAL) {
        data.token = TOKEN;
    }

    return data;
}

let referenceImages = <?= json_encode($questionnaire['reference_images'] ?? []) ?>;

// ==================== 工具 ====================
function escAttr(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escHtml(s) {
    const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML;
}

// ==================== 文件渲染 ====================
function renderFileItem(file) {
    const el = document.createElement('div');
    el.className = 'q-file-item';
    const fn = escAttr(file.filename);
    const meta = escHtml(formatFileSize(file.filesize) + (file.uploaded_at ? ' · ' + file.uploaded_at : ''));
    if (file.is_image && file.preview_url) {
        const pu = escAttr(file.preview_url);
        const du = escAttr(file.download_url);
        el.innerHTML = `
            <div class="q-file-thumb" onclick="openLightbox('${pu}')">
                <img src="${pu}" alt="${fn}" onerror="this.parentElement.innerHTML='<i class=\'bi bi-image\' style=\'font-size:24px;color:#ccc\'></i>'">
                <div class="q-file-zoom"><i class="bi bi-zoom-in"></i></div>
            </div>
            <div class="q-file-info">
                <span class="q-file-name" title="${fn}">${escHtml(file.filename)}</span>
                <span class="q-file-meta">${meta}</span>
            </div>
            <a href="${du}" class="q-file-action" title="下载" download><i class="bi bi-download"></i></a>`;
    } else {
        const icon = getFileIcon(file.file_ext);
        const du = escAttr(file.download_url);
        el.innerHTML = `
            <div class="q-file-icon"><i class="bi ${icon}"></i></div>
            <div class="q-file-info">
                <span class="q-file-name" title="${fn}">${escHtml(file.filename)}</span>
                <span class="q-file-meta">${meta}</span>
            </div>
            <a href="${du}" class="q-file-action" title="下载" download><i class="bi bi-download"></i></a>`;
    }
    return el;
}

function getFileIcon(ext) {
    const map = {
        'pdf': 'bi-file-earmark-pdf', 'doc': 'bi-file-earmark-word', 'docx': 'bi-file-earmark-word',
        'xls': 'bi-file-earmark-excel', 'xlsx': 'bi-file-earmark-excel',
        'ppt': 'bi-file-earmark-ppt', 'pptx': 'bi-file-earmark-ppt',
        'zip': 'bi-file-earmark-zip', 'rar': 'bi-file-earmark-zip', '7z': 'bi-file-earmark-zip',
        'mp4': 'bi-file-earmark-play', 'mov': 'bi-file-earmark-play', 'avi': 'bi-file-earmark-play',
        'dwg': 'bi-file-earmark-ruled', 'dxf': 'bi-file-earmark-ruled',
        'skp': 'bi-file-earmark-ruled', 'max': 'bi-file-earmark-ruled',
    };
    return map[(ext || '').toLowerCase()] || 'bi-file-earmark';
}

// ==================== Lightbox ====================
function openLightbox(url) {
    let lb = document.getElementById('qLightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'qLightbox';
        lb.className = 'q-lightbox';
        lb.innerHTML = '<div class="q-lightbox-backdrop" onclick="closeLightbox()"></div><div class="q-lightbox-body"><img id="qLightboxImg" src=""><button class="q-lightbox-close" onclick="closeLightbox()"><i class="bi bi-x-lg"></i></button></div>';
        document.body.appendChild(lb);
    }
    document.getElementById('qLightboxImg').src = url;
    lb.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    const lb = document.getElementById('qLightbox');
    if (lb) { lb.classList.remove('active'); document.body.style.overflow = ''; }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

// ==================== 加载已有文件 ====================
async function loadExistingFiles() {
    try {
        const resp = await fetch(API_LIST_FILES);
        const result = await resp.json();
        if (!result.success || !result.data) return;

        const imgContainer = document.getElementById('referenceImages');
        const fileContainer = document.getElementById('uploadedFilesList');

        result.data.forEach(file => {
            const el = renderFileItem(file);
            if (file.is_image) {
                imgContainer.appendChild(el);
            } else {
                fileContainer.appendChild(el);
            }
        });
    } catch (e) {
        console.error('加载文件列表失败', e);
    }
}

// 页面加载时获取已上传文件
loadExistingFiles();

// ==================== 通用 XHR 上传（带进度条） ====================
function xhrUpload(formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', API_UPLOAD, true);
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable && onProgress) {
                onProgress(Math.round(e.loaded / e.total * 100), e.loaded, e.total);
            }
        });
        xhr.addEventListener('load', () => {
            try {
                resolve(JSON.parse(xhr.responseText));
            } catch (e) {
                reject(new Error('解析响应失败'));
            }
        });
        xhr.addEventListener('error', () => reject(new Error('网络错误')));
        xhr.addEventListener('abort', () => reject(new Error('上传已取消')));
        xhr.send(formData);
    });
}

function createProgressItem(name, size, container) {
    const itemId = 'upload-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
    const itemEl = document.createElement('div');
    itemEl.id = itemId;
    itemEl.className = 'q-file-item q-file-uploading';
    itemEl.innerHTML =
        '<div class="q-file-icon"><i class="bi bi-arrow-repeat spin"></i></div>' +
        '<div class="q-file-info" style="flex:1">' +
            '<span class="q-file-name">' + escHtml(name) + '</span>' +
            '<div class="q-upload-progress-wrap">' +
                '<div class="q-upload-progress-bar"><div class="q-upload-progress-fill" style="width:0%"></div></div>' +
                '<span class="q-file-meta q-upload-pct">0%</span>' +
            '</div>' +
        '</div>';
    container.appendChild(itemEl);
    return itemId;
}

function updateProgress(itemId, pct, loaded, total) {
    const el = document.getElementById(itemId);
    if (!el) return;
    const fill = el.querySelector('.q-upload-progress-fill');
    const pctEl = el.querySelector('.q-upload-pct');
    if (fill) fill.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '% · ' + formatFileSize(loaded) + ' / ' + formatFileSize(total);
}

function markUploadError(itemId, name) {
    const el = document.getElementById(itemId);
    if (!el) return;
    el.className = 'q-file-item q-file-error';
    el.innerHTML = '<div class="q-file-icon"><i class="bi bi-exclamation-circle"></i></div><div class="q-file-info"><span class="q-file-name">' + escHtml(name) + '</span><span class="q-file-meta" style="color:var(--danger)">上传失败</span></div>';
}

// ==================== 图片上传 ====================
async function handleImageUpload(files) {
    const container = document.getElementById('referenceImages');
    for (const file of files) {
        if (file.size > MAX_IMAGE_SIZE) {
            showToast('图片 ' + file.name + ' 超过20MB限制，请压缩后重试', 'error');
            continue;
        }

        const itemId = createProgressItem(file.name, file.size, container);

        const formData = new FormData();
        formData.append('image', file);
        if (!IS_EXTERNAL) formData.append('customer_id', CUSTOMER_ID);

        try {
            const result = await xhrUpload(formData, (pct, loaded, total) => updateProgress(itemId, pct, loaded, total));
            if (result.success) {
                const d = result.data;
                const fileObj = {
                    id: d.file_id, filename: d.filename, filesize: d.size,
                    mime_type: d.mime_type, file_ext: (d.filename || '').split('.').pop(),
                    is_image: true,
                    preview_url: '/api/customer_file_stream.php?id=' + d.file_id + '&mode=preview',
                    download_url: '/api/customer_file_stream.php?id=' + d.file_id + '&mode=download',
                    uploaded_at: null
                };
                const el = document.getElementById(itemId);
                if (el) el.replaceWith(renderFileItem(fileObj));
                showToast('图片上传成功', 'success');
            } else {
                markUploadError(itemId, file.name);
                showToast('上传失败: ' + (result.message || result.error), 'error');
            }
        } catch (e) {
            markUploadError(itemId, file.name);
            showToast('上传失败', 'error');
        }
    }
}

// ==================== 通用文件上传 ====================
async function handleFileUpload(files) {
    const listEl = document.getElementById('uploadedFilesList');
    for (const file of files) {
        if (file.size > MAX_FILE_SIZE) {
            showToast('文件 ' + file.name + ' 超过1GB限制', 'error');
            continue;
        }

        const itemId = createProgressItem(file.name, file.size, listEl);

        const formData = new FormData();
        formData.append('file', file);
        if (!IS_EXTERNAL) formData.append('customer_id', CUSTOMER_ID);

        try {
            const result = await xhrUpload(formData, (pct, loaded, total) => updateProgress(itemId, pct, loaded, total));
            if (result.success) {
                const d = result.data;
                const isImage = d.mime_type && d.mime_type.startsWith('image/');
                const fileObj = {
                    id: d.file_id, filename: d.filename, filesize: d.size,
                    mime_type: d.mime_type, file_ext: (d.filename || '').split('.').pop(),
                    is_image: isImage,
                    preview_url: isImage ? '/api/customer_file_stream.php?id=' + d.file_id + '&mode=preview' : null,
                    download_url: '/api/customer_file_stream.php?id=' + d.file_id + '&mode=download',
                    uploaded_at: null
                };
                const el = document.getElementById(itemId);
                if (el) el.replaceWith(renderFileItem(fileObj));
                showToast('文件上传成功: ' + file.name, 'success');
            } else {
                markUploadError(itemId, file.name);
                showToast('上传失败: ' + (result.message || result.error), 'error');
            }
        } catch (e) {
            markUploadError(itemId, file.name);
            showToast('上传失败: ' + file.name, 'error');
        }
    }
    document.getElementById('originalFileInput').value = '';
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// 保存问卷
async function saveQuestionnaire() {
    if (IS_READONLY) return;

    const btnSave = document.getElementById('btnSave');
    const btnSaveBottom = document.getElementById('btnSaveBottom');
    const statusEl = document.getElementById('saveStatus');

    if (btnSave) { btnSave.disabled = true; btnSave.innerHTML = '<i class="bi bi-hourglass-split"></i> 保存中...'; }
    if (btnSaveBottom) { btnSaveBottom.disabled = true; btnSaveBottom.innerHTML = '<i class="bi bi-hourglass-split"></i> 保存中...'; }
    statusEl.className = 'save-status saving';
    statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> <span>保存中...</span>';

    try {
        const data = collectFormData();
        const resp = await fetch(API_SAVE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();

        if (result.success) {
            statusEl.className = 'save-status saved';
            statusEl.innerHTML = '<i class="bi bi-cloud-check"></i> <span>已保存</span>';
            showToast('保存成功！', 'success');
        } else {
            throw new Error(result.message || '保存失败');
        }
    } catch (e) {
        statusEl.className = 'save-status error';
        statusEl.innerHTML = '<i class="bi bi-exclamation-circle"></i> <span>保存失败</span>';
        showToast(e.message || '保存失败，请重试', 'error');
    } finally {
        if (btnSave) { btnSave.disabled = false; btnSave.innerHTML = '<i class="bi bi-check2-circle"></i> 保存'; }
        if (btnSaveBottom) { btnSaveBottom.disabled = false; btnSaveBottom.innerHTML = '<i class="bi bi-check2-circle"></i> 保存问卷'; }
    }
}

function showToast(message, type) {
    const el = document.getElementById('toastNotification');
    el.querySelector('#toastMessage').textContent = message;
    el.className = 'toast-notification ' + type + ' show';
    setTimeout(() => el.classList.remove('show'), 3000);
}

// 监听变化更新进度
document.querySelectorAll('input, textarea, select').forEach(el => {
    el.addEventListener('change', updateProgress);
    el.addEventListener('input', updateProgress);
});

// 初始化进度
updateProgress();

// ==================== 简繁转换 ====================
let isTraditional = false;

function toggleLang() {
    const cc = window.OpenCCLite;
    if (!cc) {
        showToast('简繁转换模块未加载', 'error');
        return;
    }

    isTraditional = !isTraditional;
    const btn = document.getElementById('langToggle');
    const label = document.getElementById('langLabel');

    if (isTraditional) {
        // 简体 → 繁体
        cc.convertElement(document.body);
        btn.classList.add('active');
        label.textContent = '简';
        btn.title = '切換為簡體';
    } else {
        // 繁体 → 简体：重新加载页面恢复原始简体文本
        // OpenCCLite 的 convertElement 会修改 DOM 文本节点
        // 反向转换可能不完美，所以用 toSimplified 处理
        convertToSimplified(document.body);
        btn.classList.remove('active');
        label.textContent = '繁';
        btn.title = '简体/繁体切换';
    }
}

function convertToSimplified(element) {
    const cc = window.OpenCCLite;
    if (!cc) return;
    const excludeTags = ['INPUT', 'TEXTAREA', 'SCRIPT', 'STYLE', 'SELECT'];
    const walker = document.createTreeWalker(
        element,
        NodeFilter.SHOW_TEXT,
        {
            acceptNode: function(node) {
                const parent = node.parentElement;
                if (parent && excludeTags.includes(parent.tagName)) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        }
    );
    const textNodes = [];
    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }
    textNodes.forEach(function(node) {
        if (node.textContent.trim()) {
            node.textContent = cc.toSimplified(node.textContent);
        }
    });
}
</script>

<style>
.spin { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* 文件列表项 */
.q-file-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; background: #fff; border: 1px solid #e8ecf0;
    border-radius: 10px; margin-bottom: 8px; transition: box-shadow .2s;
}
.q-file-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.q-file-uploading { opacity: .85; }
.q-upload-progress-wrap { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.q-upload-progress-bar { flex: 1; height: 6px; background: #e8ecf0; border-radius: 3px; overflow: hidden; }
.q-upload-progress-fill { height: 100%; background: linear-gradient(90deg, #6366f1, #818cf8); border-radius: 3px; transition: width .2s ease; }
.q-upload-pct { font-size: 11px; color: #6366f1; white-space: nowrap; min-width: 60px; }
.q-file-error { border-color: #f5c6cb; background: #fff5f5; }

/* 图片缩略图 */
.q-file-thumb {
    width: 56px; height: 56px; border-radius: 8px; overflow: hidden;
    flex-shrink: 0; cursor: pointer; position: relative; background: #f0f2f5;
    display: flex; align-items: center; justify-content: center;
}
.q-file-thumb img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.q-file-zoom {
    position: absolute; inset: 0; background: rgba(0,0,0,.35);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s; color: #fff; font-size: 18px;
}
.q-file-thumb:hover .q-file-zoom { opacity: 1; }

/* 文件图标 */
.q-file-icon {
    width: 42px; height: 42px; border-radius: 8px; background: #f0f4ff;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 20px; color: var(--primary-color, #007bff);
}
.q-file-error .q-file-icon { background: #fff0f0; color: var(--danger-color, #dc3545); }

/* 文件信息 */
.q-file-info { flex: 1; min-width: 0; }
.q-file-name {
    display: block; font-size: 13px; font-weight: 500; color: #333;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.q-file-meta { display: block; font-size: 11px; color: #999; margin-top: 2px; }

/* 下载按钮 */
.q-file-action {
    width: 34px; height: 34px; border-radius: 8px; background: #f0f4ff;
    display: flex; align-items: center; justify-content: center;
    color: var(--primary-color, #007bff); text-decoration: none;
    flex-shrink: 0; transition: background .2s;
}
.q-file-action:hover { background: #dce8ff; }

/* Lightbox */
.q-lightbox {
    display: none; position: fixed; inset: 0; z-index: 10000;
    align-items: center; justify-content: center;
}
.q-lightbox.active { display: flex; }
.q-lightbox-backdrop {
    position: absolute; inset: 0; background: rgba(0,0,0,.7);
    cursor: pointer;
}
.q-lightbox-body {
    position: relative; z-index: 1; max-width: 90vw; max-height: 90vh;
}
.q-lightbox-body img {
    max-width: 90vw; max-height: 85vh; border-radius: 8px;
    box-shadow: 0 8px 40px rgba(0,0,0,.4); display: block;
}
.q-lightbox-close {
    position: absolute; top: -12px; right: -12px;
    width: 36px; height: 36px; border-radius: 50%; border: none;
    background: #fff; color: #333; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.q-lightbox-close:hover { background: #f0f0f0; }
</style>

</body>
</html>
