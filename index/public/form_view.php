<?php
/**
 * 表单查看页面（门户风格）
 * 客户查看已提交的表单详情，可切换状态
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../core/db.php';

$token = trim($_GET['token'] ?? '');
$portalToken = trim($_GET['portal_token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>访问错误</title></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;"><h3>无效的表单链接</h3></body></html>';
    exit;
}

$pdo = Db::pdo();

// 查询表单实例
$stmt = $pdo->prepare("
    SELECT fi.*, ft.name as template_name, ftv.schema_json,
           p.project_name, p.customer_id, c.name as customer_name
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

// 获取最新提交记录
$subStmt = $pdo->prepare("
    SELECT * FROM form_submissions 
    WHERE instance_id = ? 
    ORDER BY submitted_at DESC 
    LIMIT 1
");
$subStmt->execute([$instance['id']]);
$submission = $subStmt->fetch(PDO::FETCH_ASSOC);

$schemaJson = $instance['schema_json'] ?? '[]';
$submissionData = $submission ? json_decode($submission['submission_data_json'] ?? '{}', true) : [];
$reqStatus = $instance['requirement_status'] ?? 'pending';

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

// 判断是否可以申请修改
$canRequestModify = ($reqStatus === 'confirmed');

// 获取项目阶段时间数据
$stageTimeInfo = null;
if ($instance['project_id']) {
    // 检查项目是否已完工
    $projectStmt = $pdo->prepare("SELECT completed_at FROM projects WHERE id = ?");
    $projectStmt->execute([$instance['project_id']]);
    $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);
    $isProjectCompleted = !empty($projectInfo['completed_at']);
    
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
        $actualDays = 0;
        $currentStage = null;
        
        // 如果项目已完工，计算实际用时
        if ($isProjectCompleted && !empty($stages[0]['planned_start_date'])) {
            $completedAtTimestamp = is_numeric($projectInfo['completed_at']) ? $projectInfo['completed_at'] : strtotime($projectInfo['completed_at']);
            $startDate = new DateTime($stages[0]['planned_start_date']);
            $completedDate = new DateTime(date('Y-m-d', $completedAtTimestamp));
            $actualDays = max(1, $completedDate->diff($startDate)->days + 1);
        }
        
        foreach ($stages as $st) {
            $totalDays += intval($st['planned_days']);
            if ($isProjectCompleted) {
                // 项目已完工，所有阶段视为完成
                $elapsedDays += intval($st['planned_days']);
            } elseif ($st['status'] === 'completed') {
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
            'elapsed_days' => $isProjectCompleted ? $actualDays : $elapsedDays,
            'remaining_days' => $isProjectCompleted ? 0 : max(0, $totalDays - $elapsedDays),
            'progress' => $isProjectCompleted ? 100 : ($totalDays > 0 ? min(100, round($elapsedDays * 100 / $totalDays)) : 0),
            'current_stage' => $isProjectCompleted ? null : $currentStage,
            'is_completed' => $isProjectCompleted
        ];
    }
}
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
        .form-logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .form-logo img {
            height: 36px;
        }
        .field-group {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--portal-border);
        }
        .field-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .field-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--portal-text-muted);
            margin-bottom: 6px;
        }
        .field-value {
            font-size: 15px;
            color: var(--portal-text);
            line-height: 1.6;
        }
        .field-value.empty {
            color: var(--portal-text-muted);
            font-style: italic;
        }
        .submission-info {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: rgba(99, 102, 241, 0.05);
            border-radius: var(--portal-radius);
            margin-bottom: 24px;
        }
        .submission-info-icon {
            width: 40px;
            height: 40px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--portal-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .submission-info-text {
            flex: 1;
        }
        .submission-info-text .name {
            font-weight: 600;
            color: var(--portal-text);
        }
        .submission-info-text .time {
            font-size: 13px;
            color: var(--portal-text-muted);
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .action-btn {
            flex: 1;
            min-width: 140px;
            padding: 12px 20px;
            border-radius: var(--portal-radius);
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .action-btn-primary {
            background: var(--portal-gradient);
            color: white;
            border: none;
        }
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--portal-shadow-lg);
        }
        .action-btn-secondary {
            background: rgba(99, 102, 241, 0.1);
            color: var(--portal-primary);
            border: none;
        }
        .action-btn-secondary:hover {
            background: rgba(99, 102, 241, 0.15);
        }
        .action-btn-warning {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: none;
        }
        .action-btn-warning:hover {
            background: rgba(245, 158, 11, 0.15);
        }
        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .no-submission {
            text-align: center;
            padding: 40px 20px;
            color: var(--portal-text-muted);
        }
        .no-submission i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--portal-text-secondary);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .back-link:hover {
            color: var(--portal-primary);
        }
    </style>
</head>
<body class="portal-page">
    <div class="portal-bg-decoration"></div>
    <div class="portal-bg-decoration-extra"></div>
    
    <div class="form-page">
        <div class="form-container">
            <!-- 返回链接 -->
            <?php if ($portalToken): ?>
            <a href="portal.php?token=<?= htmlspecialchars($portalToken) ?>" class="back-link">
                <i class="bi bi-arrow-left"></i> 返回门户
            </a>
            <?php endif; ?>
            
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
                <span class="form-status-badge" id="statusBadge" style="background: <?= $statusColors[$reqStatus] ?>15; color: <?= $statusColors[$reqStatus] ?>;">
                    <?= $statusLabels[$reqStatus] ?? '未知' ?>
                </span>
                <?php if ($stageTimeInfo): ?>
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--portal-border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 13px; color: var(--portal-text-muted);">项目周期</span>
                        <span style="font-size: 13px; color: <?= !empty($stageTimeInfo['is_completed']) ? '#10b981' : 'var(--portal-text-muted)' ?>;">
                            <?php if (!empty($stageTimeInfo['is_completed'])): ?>
                                ✓ 已完工
                            <?php elseif ($stageTimeInfo['current_stage']): ?>
                                <?= htmlspecialchars($stageTimeInfo['current_stage']['stage_from']) ?> → <?= htmlspecialchars($stageTimeInfo['current_stage']['stage_to']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 4px; height: 6px; overflow: hidden; margin-bottom: 8px;">
                        <div style="background: <?= !empty($stageTimeInfo['is_completed']) ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)' : 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)' ?>; height: 100%; width: <?= $stageTimeInfo['progress'] ?>%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px;">
                        <span style="color: var(--portal-text-muted);">
                            <?= !empty($stageTimeInfo['is_completed']) ? '实际用时' : '已进行' ?> <?= $stageTimeInfo['elapsed_days'] ?> 天
                        </span>
                        <?php if (!empty($stageTimeInfo['is_completed'])): ?>
                        <span style="color: #10b981; font-weight: 500;">100% 完成</span>
                        <?php else: ?>
                        <span style="color: <?= $stageTimeInfo['remaining_days'] <= 3 ? '#ef4444' : 'var(--portal-text-muted)' ?>;">
                            剩余 <?= $stageTimeInfo['remaining_days'] ?> 天
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 表单内容 -->
            <div class="portal-card portal-card-solid form-body-card">
                <?php if ($submission): ?>
                <!-- 提交信息 -->
                <div class="submission-info">
                    <div class="submission-info-icon">
                        <i class="bi bi-person"></i>
                    </div>
                    <div class="submission-info-text">
                        <div class="name"><?= htmlspecialchars($submission['submitted_by_name'] ?: '匿名') ?></div>
                        <div class="time">提交于 <?= date('Y-m-d H:i', $submission['submitted_at']) ?></div>
                    </div>
                </div>
                
                <!-- 表单字段 -->
                <div id="formFields">
                    <?php
                    $schema = json_decode($schemaJson, true);
                    if ($schema && is_array($schema)):
                        foreach ($schema as $field):
                            if (empty($field['name'])) continue;
                            $fieldName = $field['name'];
                            $fieldLabel = $field['label'] ?? $fieldName;
                            $fieldValue = $submissionData[$fieldName] ?? '';
                            
                            if (is_array($fieldValue)) {
                                $fieldValue = implode(', ', $fieldValue);
                            }
                    ?>
                    <div class="field-group">
                        <div class="field-label"><?= htmlspecialchars($fieldLabel) ?></div>
                        <div class="field-value <?= empty($fieldValue) ? 'empty' : '' ?>">
                            <?= empty($fieldValue) ? '未填写' : nl2br(htmlspecialchars($fieldValue)) ?>
                        </div>
                    </div>
                    <?php
                        endforeach;
                    endif;
                    ?>
                </div>
                
                <!-- 附件列表 -->
                <div id="attachmentsSection" class="attachments-section" style="display:none;">
                    <div class="section-title"><i class="bi bi-paperclip me-2"></i>参考文件</div>
                    <div id="attachmentsList"></div>
                </div>
                <?php else: ?>
                <div class="no-submission">
                    <i class="bi bi-file-earmark-text"></i>
                    <p>暂无提交记录</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 操作按钮 -->
            <div class="portal-card portal-card-solid">
                <div class="action-buttons">
                    <?php if ($reqStatus === 'pending' || $reqStatus === 'modifying'): ?>
                    <a href="form_fill.php?token=<?= htmlspecialchars($token) ?>" class="action-btn action-btn-primary">
                        <i class="bi bi-pencil"></i> 填写表单
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($canRequestModify): ?>
                    <button type="button" class="action-btn action-btn-warning" id="requestModifyBtn" onclick="requestModify()">
                        <i class="bi bi-arrow-repeat"></i> 申请修改
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($portalToken): ?>
                    <a href="portal.php?token=<?= htmlspecialchars($portalToken) ?>" class="action-btn action-btn-secondary">
                        <i class="bi bi-house"></i> 返回门户
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    const FILL_TOKEN = '<?= htmlspecialchars($token) ?>';
    const PORTAL_TOKEN = '<?= htmlspecialchars($portalToken) ?>';
    const INSTANCE_ID = <?= intval($instance['id']) ?>;
    
    // 加载附件列表
    document.addEventListener('DOMContentLoaded', function() {
        loadAttachments();
    });
    
    function loadAttachments() {
        fetch('/api/form_attachments.php?instance_id=' + INSTANCE_ID)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data.length > 0) {
                    const section = document.getElementById('attachmentsSection');
                    const list = document.getElementById('attachmentsList');
                    section.style.display = 'block';
                    
                    list.innerHTML = data.data.map(att => `
                        <div class="attachment-item">
                            <div class="attachment-icon">
                                <i class="bi ${getFileIcon(att.filename)}"></i>
                            </div>
                            <div class="attachment-info">
                                <div class="attachment-name">${escapeHtml(att.filename)}</div>
                                <div class="attachment-meta">${att.file_size_formatted} · ${att.create_time_formatted}</div>
                            </div>
                            <a href="${att.download_url}" class="attachment-download" target="_blank" title="下载">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    `).join('');
                }
            })
            .catch(err => console.error('加载附件失败:', err));
    }
    
    function getFileIcon(filename) {
        const ext = (filename || '').split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'bi-image';
        if (['mp4', 'mov', 'avi', 'mkv'].includes(ext)) return 'bi-camera-video';
        if (['pdf'].includes(ext)) return 'bi-file-pdf';
        if (['doc', 'docx'].includes(ext)) return 'bi-file-word';
        if (['xls', 'xlsx'].includes(ext)) return 'bi-file-excel';
        if (['zip', 'rar', '7z'].includes(ext)) return 'bi-file-zip';
        return 'bi-file-earmark';
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    
    function requestModify() {
        const btn = document.getElementById('requestModifyBtn');
        if (!confirm('确定要申请修改此需求吗？')) return;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> 处理中...';
        
        fetch('/api/form_requirement_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                instance_id: INSTANCE_ID,
                status: 'modifying',
                portal_token: PORTAL_TOKEN
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('申请已提交，您现在可以重新填写表单');
                location.reload();
            } else {
                alert('操作失败: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> 申请修改';
            }
        })
        .catch(err => {
            alert('操作失败: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> 申请修改';
        });
    }
    </script>
    
    <style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { display: inline-block; animation: spin 1s linear infinite; }
    /* 附件区域样式 */
    .attachments-section {
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid var(--portal-border);
    }
    .section-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--portal-text);
        margin-bottom: 12px;
    }
    .attachment-item {
        display: flex;
        align-items: center;
        padding: 12px;
        background: rgba(99, 102, 241, 0.05);
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .attachment-icon {
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
    .attachment-info {
        flex: 1;
        min-width: 0;
    }
    .attachment-name {
        font-weight: 500;
        color: var(--portal-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .attachment-meta {
        font-size: 12px;
        color: var(--portal-text-muted);
    }
    .attachment-download {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: #6366f1;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: all 0.2s;
    }
    .attachment-download:hover {
        background: #4f46e5;
        transform: scale(1.05);
    }
    </style>
</body>
</html>
