<?php
/**
 * 表单填写页面（门户风格）
 * 通过 token 访问，无需登录
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../core/db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>访问错误</title></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;"><h3>无效的表单链接</h3></body></html>';
    exit;
}

$pdo = Db::pdo();

// 查询表单实例
$stmt = $pdo->prepare("
    SELECT fi.*, ft.name as template_name, ftv.schema_json,
           p.project_name, c.name as customer_name
    FROM form_instances fi
    JOIN form_templates ft ON fi.template_id = ft.id
    JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
    LEFT JOIN projects p ON fi.project_id = p.id
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE fi.fill_token = ?
");
$stmt->execute([$token]);
$instance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instance) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>表单不存在</title></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;"><h3>表单不存在或已失效</h3></body></html>';
    exit;
}

$schemaJson = $instance['schema_json'] ?? '[]';
$reqStatus = $instance['requirement_status'] ?? 'pending';
$canFill = ($instance['status'] !== 'submitted') || ($reqStatus === 'modifying');

// 获取最新提交记录用于预填充
$submissionData = [];
$lastSubmitterName = '';
if ($instance['status'] === 'submitted') {
    $subStmt = $pdo->prepare("
        SELECT * FROM form_submissions 
        WHERE instance_id = ? 
        ORDER BY submitted_at DESC 
        LIMIT 1
    ");
    $subStmt->execute([$instance['id']]);
    $lastSubmission = $subStmt->fetch(PDO::FETCH_ASSOC);
    if ($lastSubmission) {
        $submissionData = json_decode($lastSubmission['submission_data_json'] ?? '{}', true);
        $lastSubmitterName = $lastSubmission['submitted_by_name'] ?? '';
    }
}
$submissionDataJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE);

// 获取项目阶段时间数据
$stageTimeInfo = null;
if ($instance['project_id']) {
    $stageStmt = $pdo->prepare("
        SELECT pst.*, DATEDIFF(pst.planned_end_date, CURDATE()) as remaining_days
        FROM project_stage_times pst
        WHERE pst.project_id = ?
        ORDER BY pst.stage_order ASC
    ");
    $stageStmt->execute([$instance['project_id']]);
    $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($stages) {
        $totalDays = 0;
        $elapsedDays = 0;
        $currentStage = null;
        
        foreach ($stages as $st) {
            $totalDays += intval($st['planned_days']);
            if ($st['status'] === 'completed') {
                $elapsedDays += intval($st['planned_days']);
            } elseif ($st['status'] === 'in_progress') {
                $startDate = new DateTime($st['planned_start_date']);
                $today = new DateTime();
                $daysPassed = max(0, $today->diff($startDate)->days + 1);
                $elapsedDays += min($daysPassed, intval($st['planned_days']));
                $currentStage = $st;
            }
        }
        
        $stageTimeInfo = [
            'total_days' => $totalDays,
            'elapsed_days' => $elapsedDays,
            'remaining_days' => max(0, $totalDays - $elapsedDays),
            'progress' => $totalDays > 0 ? min(100, round($elapsedDays * 100 / $totalDays)) : 0,
            'current_stage' => $currentStage
        ];
    }
}

// 表单状态标签
$statusLabels = [
    'pending' => '待填写',
    'communicating' => '沟通中',
    'confirmed' => '已确认',
    'modifying' => '修改中'
];
$statusColors = [
    'pending' => '#94a3b8',
    'communicating' => '#f59e0b',
    'confirmed' => '#10b981',
    'modifying' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#6366f1">
    <title><?= htmlspecialchars($instance['instance_name']) ?> - 設計空間</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/portal-theme.css">
    <style>
        /* 表单页面特定样式 */
        .form-page {
            min-height: 100vh;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        .form-container {
            max-width: 680px;
            margin: 0 auto;
        }
        .form-header-card {
            text-align: center;
            margin-bottom: 24px;
        }
        .form-header-card h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 8px;
            color: var(--portal-text);
        }
        .form-header-card .form-meta {
            font-size: 14px;
            color: var(--portal-text-secondary);
            margin-bottom: 12px;
        }
        .form-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        .form-body-card {
            margin-bottom: 24px;
        }
        .submitter-section {
            margin-bottom: 24px;
        }
        .submitter-section label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--portal-text);
        }
        .submitter-section input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--portal-border);
            border-radius: var(--portal-radius);
            font-size: 15px;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(10px);
            transition: all 0.2s;
        }
        .submitter-section input:focus {
            outline: none;
            border-color: var(--portal-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        /* formBuilder 字段样式覆盖 */
        .form-render-wrap .form-group {
            margin-bottom: 20px;
        }
        .form-render-wrap .formbuilder-text label,
        .form-render-wrap .formbuilder-textarea label,
        .form-render-wrap .formbuilder-select label,
        .form-render-wrap .formbuilder-radio-group label.formbuilder-radio-group-label,
        .form-render-wrap .formbuilder-checkbox-group label.formbuilder-checkbox-group-label {
            font-weight: 500;
            margin-bottom: 8px;
            display: block;
            color: var(--portal-text);
        }
        .form-render-wrap input[type="text"],
        .form-render-wrap input[type="email"],
        .form-render-wrap input[type="tel"],
        .form-render-wrap input[type="number"],
        .form-render-wrap input[type="date"],
        .form-render-wrap textarea,
        .form-render-wrap select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--portal-border);
            border-radius: var(--portal-radius);
            font-size: 15px;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(10px);
            transition: all 0.2s;
        }
        .form-render-wrap input:focus,
        .form-render-wrap textarea:focus,
        .form-render-wrap select:focus {
            outline: none;
            border-color: var(--portal-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .form-render-wrap .formbuilder-radio-group .formbuilder-radio,
        .form-render-wrap .formbuilder-checkbox-group .formbuilder-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .form-render-wrap .formbuilder-radio input,
        .form-render-wrap .formbuilder-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: var(--portal-primary);
        }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .submit-btn {
            flex: 2;
            padding: 14px 24px;
            background: var(--portal-gradient);
            color: white;
            border: none;
            border-radius: var(--portal-radius);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--portal-shadow-lg);
        }
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .clear-btn {
            flex: 1;
            padding: 14px 20px;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: none;
            border-radius: var(--portal-radius);
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .clear-btn:hover {
            background: rgba(239, 68, 68, 0.15);
        }
        .success-message {
            text-align: center;
            padding: 60px 20px;
        }
        .success-message .icon {
            width: 80px;
            height: 80px;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
        }
        .success-message h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--portal-text);
            margin: 0 0 8px;
        }
        .success-message p {
            color: var(--portal-text-secondary);
            margin: 0;
        }
        .form-logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .form-logo img {
            height: 36px;
        }
        /* 文件上传区域 */
        .file-upload-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--portal-border);
        }
        .section-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--portal-text);
            margin-bottom: 12px;
        }
        .upload-dropzone {
            border: 2px dashed var(--portal-border);
            border-radius: var(--portal-radius);
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(99, 102, 241, 0.02);
        }
        .upload-dropzone:hover, .upload-dropzone.dragover {
            border-color: #6366f1;
            background: rgba(99, 102, 241, 0.05);
        }
        .dropzone-content i {
            font-size: 48px;
            color: #6366f1;
            margin-bottom: 12px;
        }
        .dropzone-content p {
            margin: 0 0 8px;
            color: var(--portal-text);
        }
        .dropzone-content small {
            color: var(--portal-text-muted);
        }
        .upload-link {
            color: #6366f1;
            font-weight: 600;
        }
        .uploaded-files-list {
            margin-top: 16px;
        }
        .upload-file-item {
            display: flex;
            align-items: center;
            padding: 12px;
            background: rgba(99, 102, 241, 0.05);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .upload-file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #6366f1;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .upload-file-icon.image {
            background: #10b981;
        }
        .upload-file-icon.video {
            background: #f59e0b;
        }
        .upload-file-info {
            flex: 1;
            min-width: 0;
        }
        .upload-file-name {
            font-weight: 500;
            color: var(--portal-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .upload-file-size {
            font-size: 12px;
            color: var(--portal-text-muted);
        }
        .upload-file-progress {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
        }
        .upload-file-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            transition: width 0.3s;
        }
        .upload-file-status {
            margin-left: 12px;
            font-size: 12px;
        }
        .upload-file-status.success { color: #10b981; }
        .upload-file-status.error { color: #ef4444; }
        .upload-file-status.uploading { color: #6366f1; }
        .upload-file-remove {
            margin-left: 8px;
            padding: 4px 8px;
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 16px;
        }
        /* 自定义文件字段样式 */
        .custom-file-upload-wrapper {
            margin-top: 8px;
        }
        .custom-file-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .custom-file-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(99, 102, 241, 0.08);
            border-radius: 6px;
            font-size: 13px;
        }
        .custom-file-item i {
            color: #6366f1;
        }
        .custom-file-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--portal-text);
        }
        .custom-file-size {
            color: var(--portal-text-muted);
            font-size: 12px;
        }
        .custom-file-status {
            font-size: 12px;
            color: #6366f1;
        }
        .custom-file-status.success {
            color: #10b981;
        }
        .custom-file-status.error {
            color: #ef4444;
        }
        .custom-file-progress {
            width: 60px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
        .custom-file-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            transition: width 0.2s;
            width: 0%;
        }
        .custom-file-remove {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 16px;
            padding: 0 4px;
            line-height: 1;
        }
        .custom-file-remove:hover {
            color: #ef4444;
        }
        /* 移动端适配 */
        @media (max-width: 640px) {
            .upload-dropzone {
                padding: 24px 16px;
            }
            .dropzone-content i {
                font-size: 36px;
            }
        }
    </style>
</head>
<body class="portal-page">
    <div class="portal-bg-decoration"></div>
    <div class="portal-bg-decoration-extra"></div>
    
    <div class="form-page">
        <div class="form-container">
            <!-- Logo -->
            <div class="form-logo">
                <img src="images/logo-ankotti.svg" alt="ANKOTTI">
            </div>
            
            <!-- 表单头部 -->
            <div class="portal-card portal-card-solid form-header-card">
                <h1><?= htmlspecialchars($instance['instance_name']) ?></h1>
                <div class="form-meta">
                    <span><?= htmlspecialchars($instance['project_name'] ?? '') ?></span>
                    <?php if ($instance['customer_name']): ?>
                    <span> · <?= htmlspecialchars($instance['customer_name']) ?></span>
                    <?php endif; ?>
                </div>
                <span class="form-status-badge" style="background: <?= $statusColors[$reqStatus] ?>15; color: <?= $statusColors[$reqStatus] ?>;">
                    <?= $statusLabels[$reqStatus] ?? '未知' ?>
                </span>
                <?php if ($stageTimeInfo): ?>
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--portal-border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 13px; color: var(--portal-text-muted);">项目周期</span>
                        <span style="font-size: 13px; color: var(--portal-text-muted);">
                            <?php if ($stageTimeInfo['current_stage']): ?>
                                <?= htmlspecialchars($stageTimeInfo['current_stage']['stage_from']) ?> → <?= htmlspecialchars($stageTimeInfo['current_stage']['stage_to']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 4px; height: 6px; overflow: hidden; margin-bottom: 8px;">
                        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); height: 100%; width: <?= $stageTimeInfo['progress'] ?>%; transition: width 0.5s;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px;">
                        <span style="color: var(--portal-text-muted);">已进行 <?= $stageTimeInfo['elapsed_days'] ?> 天</span>
                        <span style="color: <?= $stageTimeInfo['remaining_days'] <= 3 ? '#ef4444' : 'var(--portal-text-muted)' ?>;">
                            剩余 <?= $stageTimeInfo['remaining_days'] ?> 天
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 表单内容 -->
            <div class="portal-card portal-card-solid form-body-card">
                <div id="formBody">
                    <?php if (!$canFill): ?>
                    <div class="success-message">
                        <div class="icon"><i class="bi bi-check-lg"></i></div>
                        <h2>表单已提交</h2>
                        <p>感谢您的填写，此表单已经提交过了。</p>
                    </div>
                    <?php else: ?>
                    <div id="formRenderArea" class="form-render-wrap"></div>
                    
                    <!-- 文件上传区域 -->
                    <div class="file-upload-section">
                        <label class="section-label"><i class="bi bi-paperclip me-2"></i>参考文件（可选）</label>
                        <div class="upload-dropzone" id="uploadDropzone">
                            <div class="dropzone-content">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <p>拖拽文件/文件夹到此处</p>
                                <div class="upload-buttons" style="margin-top:10px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="document.getElementById('fileInput').click()">
                                        <i class="bi bi-file-earmark me-1"></i>选择文件
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('folderInput').click()">
                                        <i class="bi bi-folder me-1"></i>选择文件夹
                                    </button>
                                </div>
                                <small style="display:block;margin-top:8px;">单次上传总大小不超过3GB</small>
                            </div>
                            <input type="file" id="fileInput" multiple style="display:none;">
                            <input type="file" id="folderInput" webkitdirectory directory multiple style="display:none;">
                        </div>
                        <div id="uploadedFilesList" class="uploaded-files-list"></div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="clear-btn" id="clearBtn" onclick="clearForm()">
                            <i class="bi bi-trash me-2"></i>清空表单
                        </button>
                        <button type="button" class="submit-btn" id="submitBtn" onclick="submitForm()">
                            <i class="bi bi-send me-2"></i>提交表单
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/formBuilder@3.19.7/dist/form-render.min.js"></script>
    
    <script>
    const FILL_TOKEN = '<?= htmlspecialchars($token) ?>';
    const FORM_INSTANCE_ID = <?= intval($instance['id']) ?>;
    const FORM_NAME = '<?= htmlspecialchars($instance['instance_name'] ?? '表单', ENT_QUOTES) ?>';
    const PART_SIZE = 50 * 1024 * 1024; // 50MB per part
    const MAX_TOTAL_SIZE = 3 * 1024 * 1024 * 1024; // 3GB
    let formRenderInstance = null;
    let uploadedFiles = []; // 已上传文件列表
    let uploadQueue = []; // 上传队列
    
    <?php if ($canFill): ?>
    const savedData = <?= $submissionDataJson ?>;
    const originalFormData = <?= $schemaJson ?>;
    
    $(document).ready(function() {
        // 预填充数据到schema
        const formData = prefillFormData(originalFormData, savedData);
        formRenderInstance = $('#formRenderArea').formRender({
            formData: formData
        });
        
        // 初始化文件上传
        initFileUpload();
        
        // 绑定自定义文件字段
        bindCustomFileFields();
    });
    
    // 存储自定义文件字段上传结果
    let customFileUploads = {};
    
    // 绑定自定义文件字段到分片上传
    function bindCustomFileFields() {
        // 延迟执行确保 formRender 完成
        setTimeout(() => {
            $('#formRenderArea input[type="file"]').each(function() {
                const input = this;
                const fieldName = $(input).attr('name') || $(input).attr('id') || 'file_' + Date.now();
                
                // 创建文件上传容器
                const wrapper = document.createElement('div');
                wrapper.className = 'custom-file-upload-wrapper';
                wrapper.innerHTML = `
                    <div class="custom-file-list" data-field="${fieldName}"></div>
                `;
                $(input).after(wrapper);
                
                // 初始化存储
                if (!customFileUploads[fieldName]) {
                    customFileUploads[fieldName] = [];
                }
                
                // 绑定change事件
                $(input).on('change', function(e) {
                    const files = e.target.files;
                    if (files.length > 0) {
                        handleCustomFieldFiles(fieldName, files, wrapper.querySelector('.custom-file-list'));
                    }
                    // 清空input以便重复选择同一文件
                    this.value = '';
                });
            });
        }, 100);
    }
    
    // 处理自定义字段文件上传
    async function handleCustomFieldFiles(fieldName, files, listContainer) {
        for (let file of files) {
            // 过滤掉文件夹（filesize=0）
            if (file.size === 0) {
                alert('不支持上传文件夹，请选择具体文件');
                continue;
            }
            const fileId = 'cf_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // 创建文件项UI
            const itemHtml = `
                <div class="custom-file-item" id="${fileId}">
                    <i class="bi ${getFileIcon(file.name)}"></i>
                    <span class="custom-file-name">${escapeHtml(file.name)}</span>
                    <span class="custom-file-size">(${formatFileSize(file.size)})</span>
                    <span class="custom-file-status">上传中...</span>
                    <div class="custom-file-progress"><div class="custom-file-progress-bar"></div></div>
                    <button type="button" class="custom-file-remove" onclick="removeCustomFile('${fieldName}', '${fileId}')">&times;</button>
                </div>
            `;
            listContainer.insertAdjacentHTML('beforeend', itemHtml);
            
            // 开始上传
            try {
                const result = await uploadCustomFieldFile(fileId, file);
                if (result) {
                    customFileUploads[fieldName].push({
                        id: fileId,
                        name: file.name,
                        size: file.size,
                        storage_key: result.storage_key,
                        deliverable_id: result.deliverable_id
                    });
                }
            } catch (err) {
                console.error('自定义字段文件上传失败:', err);
            }
        }
    }
    
    // 上传自定义字段文件
    async function uploadCustomFieldFile(fileId, file) {
        const el = document.getElementById(fileId);
        const statusEl = el.querySelector('.custom-file-status');
        const progressBar = el.querySelector('.custom-file-progress-bar');
        
        try {
            // 1. 初始化上传
            const initRes = await fetch('/api/form_upload_init.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    form_instance_id: FORM_INSTANCE_ID,
                    filename: file.name,
                    filesize: file.size,
                    mime_type: file.type || 'application/octet-stream'
                })
            });
            const initData = await initRes.json();
            if (!initData.success) throw new Error(initData.message);
            
            const { upload_id, storage_key, deliverable_id, total_parts } = initData.data;
            
            // 2. 分片上传
            const parts = [];
            for (let partNum = 1; partNum <= total_parts; partNum++) {
                const start = (partNum - 1) * PART_SIZE;
                const end = Math.min(start + PART_SIZE, file.size);
                const chunk = file.slice(start, end);
                
                // 使用代理API上传分片（解决CORS）
                const uploadUrl = '/api/form_upload_part.php?upload_id=' + encodeURIComponent(upload_id) + 
                    '&storage_key=' + encodeURIComponent(storage_key) + '&part_number=' + partNum;
                
                const uploadRes = await fetch(uploadUrl, {
                    method: 'POST',
                    body: chunk
                });
                const uploadData = await uploadRes.json();
                if (!uploadData.success) throw new Error(uploadData.message || '分片上传失败');
                
                parts.push({ PartNumber: partNum, ETag: uploadData.data.etag });
                
                const progress = Math.round((partNum / total_parts) * 100);
                progressBar.style.width = progress + '%';
                statusEl.textContent = progress + '%';
            }
            
            // 3. 完成上传
            const completeRes = await fetch('/api/form_upload_complete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    upload_id: upload_id,
                    storage_key: storage_key,
                    deliverable_id: deliverable_id,
                    parts: parts
                })
            });
            const completeData = await completeRes.json();
            if (!completeData.success) throw new Error(completeData.message);
            
            // 成功
            statusEl.textContent = '✓';
            statusEl.className = 'custom-file-status success';
            progressBar.style.width = '100%';
            
            return { storage_key, deliverable_id };
            
        } catch (err) {
            statusEl.textContent = '失败';
            statusEl.className = 'custom-file-status error';
            throw err;
        }
    }
    
    // 移除自定义字段文件
    function removeCustomFile(fieldName, fileId) {
        const el = document.getElementById(fileId);
        if (el) el.remove();
        if (customFileUploads[fieldName]) {
            customFileUploads[fieldName] = customFileUploads[fieldName].filter(f => f.id !== fileId);
        }
    }
    
    // 初始化文件上传
    function initFileUpload() {
        const dropzone = document.getElementById('uploadDropzone');
        const fileInput = document.getElementById('fileInput');
        const folderInput = document.getElementById('folderInput');
        
        if (!dropzone || !fileInput) return;
        
        // 阻止dropzone点击事件冒泡到按钮
        dropzone.addEventListener('click', (e) => {
            if (e.target.closest('.upload-buttons')) return;
        });
        
        // 文件选择
        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files, null);
            fileInput.value = '';
        });
        
        // 文件夹选择
        if (folderInput) {
            folderInput.addEventListener('change', (e) => {
                handleFolderFiles(e.target.files);
                folderInput.value = '';
            });
        }
        
        // 拖拽事件
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            handleDroppedItems(e.dataTransfer);
        });
    }
    
    // 处理拖拽项目（支持文件夹，保留层级）
    async function handleDroppedItems(dataTransfer) {
        const items = dataTransfer.items;
        const files = [];
        let folderName = null;
        
        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
            if (entry) {
                if (entry.isDirectory) {
                    folderName = entry.name;
                    // 传入文件夹名称作为基础路径
                    const folderFiles = await readDirectory(entry, entry.name);
                    files.push(...folderFiles);
                } else {
                    const file = items[i].getAsFile();
                    files.push({ file: file, relPath: file.name });
                }
            }
        }
        
        if (files.length > 0) {
            if (folderName) {
                // 有文件夹，保留层级结构
                for (let f of files) {
                    f.file._relativePath = f.relPath;
                }
                handleFilesWithPath(files.map(f => f.file), folderName);
            } else {
                // 普通文件
                handleFiles(files.map(f => f.file), null);
            }
        }
    }
    
    // 递归读取目录
    function readDirectory(dirEntry, basePath = '') {
        return new Promise((resolve) => {
            const reader = dirEntry.createReader();
            const files = [];
            
            function readEntries() {
                reader.readEntries(async (entries) => {
                    if (entries.length === 0) {
                        resolve(files);
                        return;
                    }
                    for (let entry of entries) {
                        const path = basePath ? basePath + '/' + entry.name : entry.name;
                        if (entry.isFile) {
                            const file = await getFile(entry);
                            files.push({ file, relPath: path });
                        } else if (entry.isDirectory) {
                            const subFiles = await readDirectory(entry, path);
                            files.push(...subFiles);
                        }
                    }
                    readEntries();
                });
            }
            readEntries();
        });
    }
    
    // 获取文件
    function getFile(fileEntry) {
        return new Promise((resolve) => {
            fileEntry.file(resolve);
        });
    }
    
    // 处理文件夹选择（webkitdirectory）- 保留层级结构
    function handleFolderFiles(files) {
        if (files.length === 0) return;
        
        // 获取文件夹名称（从第一个文件的webkitRelativePath）
        const firstPath = files[0].webkitRelativePath || '';
        const folderName = firstPath.split('/')[0] || '文件夹';
        
        // 转换为数组并保留相对路径
        const fileArray = [];
        for (let f of files) {
            if (f.size > 0) {
                // 保留webkitRelativePath作为相对路径
                f._relativePath = f.webkitRelativePath || f.name;
                fileArray.push(f);
            }
        }
        handleFilesWithPath(fileArray, folderName);
    }
    
    // 处理文件（普通文件，不保留路径）
    function handleFiles(files, folderName) {
        // 计算总大小
        let totalSize = 0;
        const validFiles = [];
        
        for (let file of files) {
            if (file.size === 0) continue;
            totalSize += file.size;
            validFiles.push(file);
        }
        
        // 检查总大小限制
        if (totalSize > MAX_TOTAL_SIZE) {
            alert('上传总大小超过3GB限制，当前大小：' + formatFileSize(totalSize));
            return;
        }
        
        if (validFiles.length === 0) {
            alert('没有可上传的文件');
            return;
        }
        
        for (let file of validFiles) {
            addFileToQueue(file, null);
        }
        processUploadQueue();
    }
    
    // 处理文件（保留文件夹层级路径）
    function handleFilesWithPath(files, folderName) {
        // 计算总大小
        let totalSize = 0;
        const validFiles = [];
        
        for (let file of files) {
            if (file.size === 0) continue;
            totalSize += file.size;
            validFiles.push(file);
        }
        
        // 检查总大小限制
        if (totalSize > MAX_TOTAL_SIZE) {
            alert('上传总大小超过3GB限制，当前大小：' + formatFileSize(totalSize));
            return;
        }
        
        if (validFiles.length === 0) {
            alert('没有可上传的文件');
            return;
        }
        
        // 重命名文件夹为：表单名称_原文件夹名称
        const renamedFolderName = FORM_NAME + '_' + folderName;
        
        for (let file of validFiles) {
            // 获取相对路径（如：folder/subfolder/file.txt）
            const relativePath = file._relativePath || file.webkitRelativePath || file.name;
            // 替换原文件夹名称为重命名后的名称
            const displayPath = relativePath.replace(folderName, renamedFolderName);
            addFileToQueueWithPath(file, displayPath);
        }
        processUploadQueue();
    }
    
    // 添加文件到队列（普通文件）
    function addFileToQueue(file, folderName) {
        const fileId = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const displayName = folderName ? folderName + '/' + file.name : file.name;
        const fileItem = {
            id: fileId,
            file: file,
            displayName: displayName,
            name: file.name,
            size: file.size,
            status: 'pending',
            progress: 0,
            deliverableId: null
        };
        uploadQueue.push(fileItem);
        renderFileItem(fileItem);
    }
    
    // 添加文件到队列（保留完整路径）
    function addFileToQueueWithPath(file, displayPath) {
        const fileId = 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const fileItem = {
            id: fileId,
            file: file,
            displayName: displayPath,  // 完整路径如：表单名_文件夹/子目录/文件.txt
            name: file.name,
            size: file.size,
            status: 'pending',
            progress: 0,
            deliverableId: null
        };
        uploadQueue.push(fileItem);
        renderFileItem(fileItem);
    }
    
    // 渲染文件项
    function renderFileItem(item) {
        const container = document.getElementById('uploadedFilesList');
        const iconClass = getFileIconClass(item.name);
        const displayText = item.displayName || item.name;
        
        const html = `
            <div class="upload-file-item" id="${item.id}">
                <div class="upload-file-icon ${iconClass}">
                    <i class="bi ${getFileIcon(item.name)}"></i>
                </div>
                <div class="upload-file-info">
                    <div class="upload-file-name" title="${escapeHtml(displayText)}">${escapeHtml(displayText)}</div>
                    <div class="upload-file-size">${formatFileSize(item.size)}</div>
                    <div class="upload-file-progress">
                        <div class="upload-file-progress-bar" style="width: 0%"></div>
                    </div>
                </div>
                <span class="upload-file-status uploading">等待上传</span>
                <button class="upload-file-remove" onclick="removeFile('${item.id}')" title="移除">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    }
    
    // 处理上传队列
    async function processUploadQueue() {
        for (let item of uploadQueue) {
            if (item.status === 'pending') {
                await uploadFile(item);
            }
        }
    }
    
    // 分片上传文件
    async function uploadFile(item) {
        const el = document.getElementById(item.id);
        const statusEl = el.querySelector('.upload-file-status');
        const progressBar = el.querySelector('.upload-file-progress-bar');
        
        item.status = 'uploading';
        statusEl.textContent = '上传中...';
        statusEl.className = 'upload-file-status uploading';
        
        try {
            // 1. 初始化上传（使用displayName作为显示名称）
            const uploadFilename = item.displayName || item.name;
            const initRes = await fetch('/api/form_upload_init.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    form_instance_id: FORM_INSTANCE_ID,
                    filename: uploadFilename,
                    filesize: item.size,
                    mime_type: item.file.type || 'application/octet-stream'
                })
            });
            const initData = await initRes.json();
            if (!initData.success) throw new Error(initData.message);
            
            const { upload_id, storage_key, deliverable_id, total_parts } = initData.data;
            item.deliverableId = deliverable_id;
            
            // 2. 分片上传
            const parts = [];
            for (let partNum = 1; partNum <= total_parts; partNum++) {
                const start = (partNum - 1) * PART_SIZE;
                const end = Math.min(start + PART_SIZE, item.size);
                const chunk = item.file.slice(start, end);
                
                // 使用代理API上传分片（解决CORS）
                const uploadUrl = '/api/form_upload_part.php?upload_id=' + encodeURIComponent(upload_id) + 
                    '&storage_key=' + encodeURIComponent(storage_key) + '&part_number=' + partNum;
                
                const uploadRes = await fetch(uploadUrl, {
                    method: 'POST',
                    body: chunk
                });
                const uploadData = await uploadRes.json();
                if (!uploadData.success) throw new Error(uploadData.message || '分片上传失败');
                
                parts.push({ PartNumber: partNum, ETag: uploadData.data.etag });
                
                // 更新进度
                const progress = Math.round((partNum / total_parts) * 100);
                progressBar.style.width = progress + '%';
                statusEl.textContent = `上传中 ${progress}%`;
            }
            
            // 3. 完成上传
            const completeRes = await fetch('/api/form_upload_complete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    upload_id: upload_id,
                    storage_key: storage_key,
                    deliverable_id: deliverable_id,
                    parts: parts
                })
            });
            const completeData = await completeRes.json();
            if (!completeData.success) throw new Error(completeData.message);
            
            // 上传成功
            item.status = 'success';
            progressBar.style.width = '100%';
            statusEl.textContent = '已上传';
            statusEl.className = 'upload-file-status success';
            uploadedFiles.push(item);
            
        } catch (err) {
            item.status = 'error';
            statusEl.textContent = '失败';
            statusEl.className = 'upload-file-status error';
            console.error('上传失败:', err);
        }
    }
    
    // 移除文件
    function removeFile(fileId) {
        const el = document.getElementById(fileId);
        if (el) el.remove();
        uploadQueue = uploadQueue.filter(f => f.id !== fileId);
        uploadedFiles = uploadedFiles.filter(f => f.id !== fileId);
    }
    
    // 获取文件图标类
    function getFileIconClass(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'image';
        if (['mp4', 'mov', 'avi', 'mkv'].includes(ext)) return 'video';
        return '';
    }
    
    // 获取文件图标
    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'bi-image';
        if (['mp4', 'mov', 'avi', 'mkv'].includes(ext)) return 'bi-camera-video';
        if (['pdf'].includes(ext)) return 'bi-file-pdf';
        if (['doc', 'docx'].includes(ext)) return 'bi-file-word';
        if (['xls', 'xlsx'].includes(ext)) return 'bi-file-excel';
        if (['zip', 'rar', '7z'].includes(ext)) return 'bi-file-zip';
        return 'bi-file-earmark';
    }
    
    // 格式化文件大小
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }
    
    // HTML转义
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    
    // 预填充表单数据
    function prefillFormData(schema, data) {
        if (!data || Object.keys(data).length === 0) return schema;
        
        return schema.map(field => {
            if (field.name && data[field.name] !== undefined) {
                const value = data[field.name];
                if (field.type === 'checkbox-group' || field.type === 'select' && field.multiple) {
                    // 多选处理
                    if (field.values && Array.isArray(value)) {
                        field.values = field.values.map(v => ({
                            ...v,
                            selected: value.includes(v.value)
                        }));
                    }
                } else if (field.type === 'radio-group' || field.type === 'select') {
                    // 单选处理
                    if (field.values) {
                        field.values = field.values.map(v => ({
                            ...v,
                            selected: v.value === value
                        }));
                    }
                } else {
                    // 文本类型
                    field.value = value;
                }
            }
            return field;
        });
    }
    
    // 清空表单
    function clearForm() {
        if (!confirm('确定要清空所有填写内容吗？')) return;
        
        $('#formRenderArea').empty();
        formRenderInstance = $('#formRenderArea').formRender({
            formData: originalFormData
        });
    }
    
    function submitForm() {
        const btn = document.getElementById('submitBtn');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>提交中...';
        
        const userData = formRenderInstance.userData;
        const submitData = {};
        if (userData && userData.length > 0) {
            userData.forEach(field => {
                if (field.name) {
                    submitData[field.name] = field.userData || '';
                }
            });
        }
        
        fetch('/api/form_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fill_token: FILL_TOKEN,
                data: submitData
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('formBody').innerHTML = `
                    <div class="success-message">
                        <div class="icon"><i class="bi bi-check-lg"></i></div>
                        <h2>提交成功</h2>
                        <p>感谢您的填写！</p>
                        <button class="back-btn" onclick="history.back()" style="margin-top: 20px; padding: 12px 32px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px;">
                            <i class="bi bi-arrow-left me-2"></i>返回
                        </button>
                    </div>
                `;
            } else {
                alert('提交失败: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-2"></i>提交表单';
            }
        })
        .catch(err => {
            alert('提交失败: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-2"></i>提交表单';
        });
    }
    <?php endif; ?>
    </script>
    
    <style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { display: inline-block; animation: spin 1s linear infinite; }
    </style>
</body>
</html>
