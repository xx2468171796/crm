<?php
/**
 * 后台表单详情页面
 * 管理员查看表单提交内容和附件
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$instanceId = intval($_GET['instance_id'] ?? 0);
if ($instanceId <= 0) {
    die('无效的表单实例ID');
}

$pdo = Db::pdo();

// 查询表单实例
$stmt = $pdo->prepare("
    SELECT fi.*, ft.name as template_name, ftv.schema_json,
           p.project_name, p.id as project_id, c.name as customer_name
    FROM form_instances fi
    JOIN form_templates ft ON fi.template_id = ft.id
    JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
    LEFT JOIN projects p ON fi.project_id = p.id
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE fi.id = ?
");
$stmt->execute([$instanceId]);
$instance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instance) {
    die('表单实例不存在');
}

// 获取最新提交记录
$subStmt = $pdo->prepare("
    SELECT * FROM form_submissions 
    WHERE instance_id = ? 
    ORDER BY submitted_at DESC 
    LIMIT 1
");
$subStmt->execute([$instanceId]);
$submission = $subStmt->fetch(PDO::FETCH_ASSOC);

$schemaJson = $instance['schema_json'] ?? '[]';
$submissionData = $submission ? json_decode($submission['submission_data_json'] ?? '{}', true) : [];
$reqStatus = $instance['requirement_status'] ?? 'pending';

// 状态标签
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

// 获取附件
$attStmt = $pdo->prepare("
    SELECT id, deliverable_name as filename, file_size, file_path, create_time
    FROM deliverables 
    WHERE form_instance_id = ? AND approval_status = 'approved'
    ORDER BY create_time DESC
");
$attStmt->execute([$instanceId]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

layout_header('表单详情 - ' . $instance['instance_name']);
?>

<style>
.form-detail-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}
.detail-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 20px;
}
.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.detail-title h1 {
    font-size: 20px;
    font-weight: 600;
    margin: 0 0 8px;
}
.detail-meta {
    font-size: 13px;
    color: #64748b;
}
.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}
.field-group {
    margin-bottom: 16px;
}
.field-label {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 4px;
}
.field-value {
    font-size: 15px;
    color: #1e293b;
    padding: 8px 0;
}
.field-value.empty {
    color: #94a3b8;
    font-style: italic;
}
.section-title {
    font-size: 16px;
    font-weight: 600;
    margin: 24px 0 16px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}
.attachment-item {
    display: flex;
    align-items: center;
    padding: 12px;
    background: #f8fafc;
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
}
.attachment-info {
    flex: 1;
}
.attachment-name {
    font-weight: 500;
}
.attachment-meta {
    font-size: 12px;
    color: #64748b;
}
.attachment-download {
    padding: 8px 16px;
    background: #6366f1;
    color: white;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
}
.attachment-download:hover {
    background: #4f46e5;
    color: white;
}
.submitter-info {
    display: flex;
    align-items: center;
    padding: 12px;
    background: #f0fdf4;
    border-radius: 8px;
    margin-bottom: 20px;
}
.submitter-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #10b981;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-right: 12px;
}
.action-buttons {
    display: flex;
    gap: 12px;
    margin-top: 20px;
}
</style>

<div class="form-detail-container">
    <div class="detail-card">
        <div class="detail-header">
            <div class="detail-title">
                <h1><?= htmlspecialchars($instance['instance_name']) ?></h1>
                <div class="detail-meta">
                    <?= htmlspecialchars($instance['template_name']) ?> · 
                    <?= htmlspecialchars($instance['project_name'] ?? '') ?>
                    <?php if ($instance['customer_name']): ?>
                    · <?= htmlspecialchars($instance['customer_name']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="status-badge" style="background: <?= $statusColors[$reqStatus] ?>20; color: <?= $statusColors[$reqStatus] ?>;">
                <?= $statusLabels[$reqStatus] ?? '未知' ?>
            </span>
        </div>
        
        <?php if ($submission): ?>
        <div class="submitter-info">
            <div class="submitter-avatar">
                <i class="bi bi-person"></i>
            </div>
            <div>
                <div style="font-weight: 500;"><?= htmlspecialchars($submission['submitted_by_name'] ?: '匿名') ?></div>
                <div style="font-size: 12px; color: #64748b;">提交于 <?= date('Y-m-d H:i', $submission['submitted_at']) ?></div>
            </div>
        </div>
        
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
        
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #64748b;">
            <i class="bi bi-file-earmark-text" style="font-size: 48px;"></i>
            <p>暂无提交记录</p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($attachments)): ?>
        <div class="section-title"><i class="bi bi-paperclip me-2"></i>参考文件</div>
        <?php 
        require_once __DIR__ . '/../core/storage/storage_provider.php';
        $storage = storage_provider();
        foreach ($attachments as $att): 
            $downloadUrl = $storage->getTemporaryUrl($att['file_path'], 3600);
            $fileSize = $att['file_size'] < 1024*1024 
                ? round($att['file_size']/1024, 1) . ' KB' 
                : round($att['file_size']/1024/1024, 1) . ' MB';
        ?>
        <div class="attachment-item">
            <div class="attachment-icon">
                <i class="bi bi-file-earmark"></i>
            </div>
            <div class="attachment-info">
                <div class="attachment-name"><?= htmlspecialchars($att['filename']) ?></div>
                <div class="attachment-meta"><?= $fileSize ?> · <?= date('Y-m-d H:i', $att['create_time']) ?></div>
            </div>
            <a href="<?= htmlspecialchars($downloadUrl) ?>" class="attachment-download" target="_blank">
                <i class="bi bi-download me-1"></i>下载
            </a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="action-buttons">
            <a href="project_detail.php?id=<?= $instance['project_id'] ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i>返回项目
            </a>
            <a href="form_fill.php?token=<?= htmlspecialchars($instance['fill_token']) ?>" class="btn btn-primary" target="_blank">
                <i class="bi bi-pencil me-1"></i>查看填写页
            </a>
        </div>
    </div>
</div>

<?php
layout_footer();
?>
