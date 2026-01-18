<?php
/**
 * 需求详情页面
 * 查看和管理表单需求
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();

$instanceId = intval($_GET['id'] ?? 0);

if ($instanceId <= 0) {
    header('Location: index.php?page=project_kanban');
    exit;
}

$pdo = Db::pdo();

// 获取表单实例信息
$stmt = $pdo->prepare("
    SELECT fi.*, ft.name as template_name, ft.form_type,
           ftv.schema_json, ftv.version_number,
           p.project_name, p.id as project_id, p.customer_id,
           c.name as customer_name
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
    echo '<div class="alert alert-danger m-4">表单实例不存在</div>';
    exit;
}

// 获取提交记录
$subStmt = $pdo->prepare("
    SELECT * FROM form_submissions 
    WHERE instance_id = ? 
    ORDER BY submitted_at DESC
");
$subStmt->execute([$instanceId]);
$submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

$schema = json_decode($instance['schema_json'] ?? '[]', true);
$latestSubmission = $submissions[0] ?? null;
$submissionData = $latestSubmission ? json_decode($latestSubmission['submission_data_json'] ?? '{}', true) : [];

// 获取状态变更记录
$logStmt = $pdo->prepare("
    SELECT te.*, u.realname as operator_name
    FROM timeline_events te
    LEFT JOIN users u ON te.operator_user_id = u.id
    WHERE te.entity_type = 'form_instance' 
    AND te.entity_id = ?
    AND te.event_type = 'requirement_status_change'
    ORDER BY te.create_time DESC
");
$logStmt->execute([$instanceId]);
$statusLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending' => '待填写',
    'communicating' => '需求沟通',
    'confirmed' => '需求确认',
    'modifying' => '需求修改'
];

$statusColors = [
    'pending' => 'secondary',
    'communicating' => 'warning',
    'confirmed' => 'success',
    'modifying' => 'danger'
];

$reqStatus = $instance['requirement_status'] ?? 'pending';
$canConfirm = $reqStatus === 'communicating' && (isAdmin($user) || RoleCode::isTechRole($user['role'] ?? ''));

$pageTitle = $instance['instance_name'] . ' - 需求详情';
layout_header($pageTitle);
?>

<style>
.requirement-header {
    background: linear-gradient(135deg, #137fec 0%, #0d5aab 100%);
    color: white;
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 24px;
}
.requirement-header h2 { margin: 0; font-weight: 600; }
.requirement-header .meta { opacity: 0.9; font-size: 14px; margin-top: 8px; }
.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}
.detail-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.detail-card h5 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
}
.field-item {
    margin-bottom: 16px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 8px;
}
.field-item label {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 4px;
    display: block;
    font-weight: 500;
}
.field-item .value {
    font-size: 14px;
    color: #1e293b;
    white-space: pre-wrap;
}
.field-item .value:empty::after {
    content: '-';
    color: #94a3b8;
}
.action-bar {
    background: white;
    border-radius: 12px;
    padding: 16px 24px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.submission-info {
    font-size: 13px;
    color: #64748b;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}
.field-item.editing .value { display: none; }
.field-item .edit-input { display: none; }
.field-item.editing .edit-input { display: block; }
.field-item .edit-input input,
.field-item .edit-input textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
}
.field-item .edit-input textarea { min-height: 80px; resize: vertical; }
.edit-actions { display: none; margin-top: 16px; gap: 12px; }
.edit-actions.show { display: flex; }
.card-header-actions { display: flex; gap: 8px; }
</style>

<div class="container-fluid mt-4">
    <!-- 返回按钮 -->
    <div class="mb-3">
        <a href="project_detail.php?id=<?= $instance['project_id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> 返回项目详情
        </a>
    </div>

    <!-- 头部 -->
    <div class="requirement-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h2><?= htmlspecialchars($instance['instance_name']) ?></h2>
                <div class="meta">
                    <span><i class="bi bi-file-text"></i> <?= htmlspecialchars($instance['template_name']) ?> v<?= $instance['version_number'] ?></span>
                    <span class="ms-3"><i class="bi bi-folder"></i> <?= htmlspecialchars($instance['project_name']) ?></span>
                    <span class="ms-3"><i class="bi bi-building"></i> <?= htmlspecialchars($instance['customer_name']) ?></span>
                </div>
            </div>
            <div>
                <span class="status-badge bg-<?= $statusColors[$reqStatus] ?>">
                    <?= $statusLabels[$reqStatus] ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 操作栏 -->
    <div class="action-bar">
        <div>
            <span class="text-muted">填写链接：</span>
            <code id="fillLink"><?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/form_fill.php?token=<?= htmlspecialchars($instance['fill_token']) ?></code>
            <button class="btn btn-sm btn-outline-primary ms-2" onclick="copyLink()">
                <i class="bi bi-clipboard"></i> 复制
            </button>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="text-muted mb-0">需求状态：</label>
            <select id="statusSelect" class="form-select form-select-sm" style="width: auto;" onchange="changeStatus(this.value)">
                <option value="pending" <?= $reqStatus === 'pending' ? 'selected' : '' ?>>待填写</option>
                <option value="communicating" <?= $reqStatus === 'communicating' ? 'selected' : '' ?>>需求沟通</option>
                <option value="confirmed" <?= $reqStatus === 'confirmed' ? 'selected' : '' ?>>需求确认</option>
                <option value="modifying" <?= $reqStatus === 'modifying' ? 'selected' : '' ?>>需求修改</option>
            </select>
        </div>
    </div>

    <div class="row">
        <!-- 左侧：需求内容 -->
        <div class="col-lg-8">
            <div class="detail-card">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid #e2e8f0;">
                    <h5 class="mb-0"><i class="bi bi-card-list"></i> 客户填写内容</h5>
                    <?php if ($latestSubmission): ?>
                    <div class="card-header-actions">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="editBtn" onclick="toggleEditMode()">
                            <i class="bi bi-pencil"></i> 编辑需求
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($latestSubmission): ?>
                    <div id="fieldsContainer">
                    <?php 
                    // 优先使用submissionData，如果为空则尝试从schema获取字段
                    $fieldsToRender = [];
                    if (!empty($submissionData) && is_array($submissionData)) {
                        foreach ($submissionData as $key => $val) {
                            $label = $key;
                            // 尝试从schema获取标签
                            if (!empty($schema) && is_array($schema)) {
                                foreach ($schema as $f) {
                                    if (($f['name'] ?? '') === $key) {
                                        $label = $f['label'] ?? $key;
                                        break;
                                    }
                                }
                            }
                            $fieldsToRender[] = ['name' => $key, 'label' => $label, 'value' => $val];
                        }
                    } elseif (!empty($schema) && is_array($schema)) {
                        foreach ($schema as $f) {
                            $name = $f['name'] ?? $f['label'] ?? '';
                            $fieldsToRender[] = ['name' => $name, 'label' => $f['label'] ?? $name, 'value' => ''];
                        }
                    }
                    ?>
                    <?php if (!empty($fieldsToRender)): ?>
                        <?php foreach ($fieldsToRender as $field): ?>
                            <?php 
                            $fieldName = $field['name'];
                            $fieldLabel = $field['label'];
                            $value = $field['value'];
                            if (is_array($value)) $value = implode(', ', $value);
                            $isMultiline = strlen($value) > 50 || strpos($value, "\n") !== false;
                            ?>
                            <div class="field-item" data-field="<?= htmlspecialchars($fieldName) ?>">
                                <label><?= htmlspecialchars($fieldLabel) ?></label>
                                <div class="value"><?= nl2br(htmlspecialchars($value)) ?></div>
                                <div class="edit-input">
                                    <?php if ($isMultiline): ?>
                                    <textarea data-field="<?= htmlspecialchars($fieldName) ?>"><?= htmlspecialchars($value) ?></textarea>
                                    <?php else: ?>
                                    <input type="text" data-field="<?= htmlspecialchars($fieldName) ?>" value="<?= htmlspecialchars($value) ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> 表单数据为空
                            <!-- DEBUG: schema=<?= json_encode($schema) ?>, submissionData=<?= json_encode($submissionData) ?> -->
                        </div>
                    <?php endif; ?>
                    </div>
                    
                    <div class="edit-actions" id="editActions">
                        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                            <i class="bi bi-x"></i> 取消
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveEdit()">
                            <i class="bi bi-check"></i> 保存修改
                        </button>
                    </div>
                    
                    <div class="submission-info">
                        <i class="bi bi-person"></i> 提交人：<?= htmlspecialchars($latestSubmission['submitted_by_name'] ?? '匿名') ?>
                        <span class="ms-3"><i class="bi bi-clock"></i> 提交时间：<?= date('Y-m-d H:i:s', $latestSubmission['submitted_at']) ?></span>
                        <span class="ms-3"><i class="bi bi-globe"></i> IP：<?= htmlspecialchars($latestSubmission['ip_address'] ?? '-') ?></span>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 客户尚未填写此表单
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 右侧：信息面板 -->
        <div class="col-lg-4">
            <div class="detail-card">
                <h5><i class="bi bi-info-circle"></i> 基本信息</h5>
                <div class="mb-3">
                    <label class="small text-muted">表单状态</label>
                    <div><span class="badge bg-<?= $instance['status'] === 'submitted' ? 'success' : 'warning' ?>"><?= $instance['status'] === 'submitted' ? '已提交' : '待填写' ?></span></div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">需求状态</label>
                    <div><span class="badge bg-<?= $statusColors[$reqStatus] ?>"><?= $statusLabels[$reqStatus] ?></span></div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">提交次数</label>
                    <div><?= count($submissions) ?> 次</div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">创建时间</label>
                    <div><?= date('Y-m-d H:i', $instance['create_time']) ?></div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">最后更新</label>
                    <div><?= date('Y-m-d H:i', $instance['update_time']) ?></div>
                </div>
            </div>

            <?php if (count($submissions) > 1): ?>
            <div class="detail-card">
                <h5><i class="bi bi-clock-history"></i> 历史提交</h5>
                <?php foreach ($submissions as $index => $sub): ?>
                    <?php if ($index === 0) continue; ?>
                    <div class="mb-2 p-2 bg-light rounded small">
                        <div class="text-muted"><?= date('Y-m-d H:i', $sub['submitted_at']) ?></div>
                        <div><?= htmlspecialchars($sub['submitted_by_name'] ?? '匿名') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- 状态变更记录 -->
            <div class="detail-card">
                <h5><i class="bi bi-arrow-repeat"></i> 状态变更记录</h5>
                <?php if (!empty($statusLogs)): ?>
                    <?php foreach ($statusLogs as $log): ?>
                        <?php 
                        $eventData = json_decode($log['event_data_json'] ?? '{}', true);
                        $fromStatus = $eventData['from_status'] ?? '';
                        $toStatus = $eventData['to_status'] ?? '';
                        $fromLabel = $statusLabels[$fromStatus] ?? $fromStatus;
                        $toLabel = $statusLabels[$toStatus] ?? $toStatus;
                        ?>
                        <div class="mb-2 p-2 bg-light rounded small">
                            <div class="d-flex justify-content-between">
                                <span>
                                    <?php if ($fromStatus): ?>
                                    <span class="badge bg-<?= $statusColors[$fromStatus] ?? 'secondary' ?> badge-sm"><?= $fromLabel ?></span>
                                    <i class="bi bi-arrow-right mx-1"></i>
                                    <?php endif; ?>
                                    <span class="badge bg-<?= $statusColors[$toStatus] ?? 'secondary' ?>"><?= $toLabel ?></span>
                                </span>
                            </div>
                            <div class="text-muted mt-1">
                                <small><?= htmlspecialchars($log['operator_name'] ?? '系统') ?> · <?= date('m-d H:i', $log['create_time']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small">暂无变更记录</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = '/api';
const INSTANCE_ID = <?= $instanceId ?>;

function copyLink() {
    const link = document.getElementById('fillLink').textContent;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(() => {
            showAlertModal('链接已复制', 'success');
        });
    } else {
        const input = document.createElement('input');
        input.value = link;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showAlertModal('链接已复制', 'success');
    }
}

function changeStatus(newStatus) {
    console.log('[FORM_DEBUG] changeStatus called:', newStatus);
    
    const statusLabels = {
        'pending': '待填写',
        'communicating': '需求沟通',
        'confirmed': '需求确认',
        'modifying': '需求修改'
    };
    
    const currentStatus = '<?= $reqStatus ?>';
    if (newStatus === currentStatus) {
        console.log('[FORM_DEBUG] Status unchanged');
        return;
    }
    
    if (typeof showConfirmModal !== 'function') {
        console.error('[FORM_DEBUG] showConfirmModal not defined!');
        alert('确认框组件未加载，请刷新页面');
        return;
    }
    
    showConfirmModal('变更需求状态', `确定要将状态改为"${statusLabels[newStatus]}"吗？`, function() {
        console.log('[FORM_DEBUG] Confirmed, sending request...');
        
        fetch(`${API_URL}/form_requirement_status.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                instance_id: INSTANCE_ID,
                status: newStatus
            })
        })
        .then(r => {
            console.log('[FORM_DEBUG] Response status:', r.status);
            return r.json();
        })
        .then(result => {
            console.log('[FORM_DEBUG] Result:', result);
            if (result.success) {
                showAlertModal('状态已更新', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlertModal('操作失败: ' + result.message, 'error');
                document.getElementById('statusSelect').value = currentStatus;
            }
        })
        .catch(err => {
            console.error('[FORM_DEBUG] Error:', err);
            showAlertModal('操作失败: ' + err.message, 'error');
            document.getElementById('statusSelect').value = currentStatus;
        });
    }, function() {
        console.log('[FORM_DEBUG] Cancelled');
        document.getElementById('statusSelect').value = currentStatus;
    });
}

// 编辑模式
let isEditing = false;

function toggleEditMode() {
    isEditing = !isEditing;
    const fields = document.querySelectorAll('.field-item');
    const editActions = document.getElementById('editActions');
    const editBtn = document.getElementById('editBtn');
    
    if (isEditing) {
        fields.forEach(f => f.classList.add('editing'));
        editActions.classList.add('show');
        editBtn.innerHTML = '<i class="bi bi-x"></i> 取消编辑';
        editBtn.classList.remove('btn-outline-primary');
        editBtn.classList.add('btn-outline-secondary');
    } else {
        fields.forEach(f => f.classList.remove('editing'));
        editActions.classList.remove('show');
        editBtn.innerHTML = '<i class="bi bi-pencil"></i> 编辑需求';
        editBtn.classList.remove('btn-outline-secondary');
        editBtn.classList.add('btn-outline-primary');
    }
}

function cancelEdit() {
    location.reload();
}

function saveEdit() {
    const data = {};
    document.querySelectorAll('.field-item[data-field]').forEach(item => {
        const field = item.dataset.field;
        const input = item.querySelector('input, textarea');
        if (input) {
            data[field] = input.value;
        }
    });
    
    fetch(`${API_URL}/form_submission_edit.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            instance_id: INSTANCE_ID,
            data: data
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            showAlertModal('需求已更新', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlertModal('保存失败: ' + result.message, 'error');
        }
    })
    .catch(err => {
        showAlertModal('保存失败: ' + err.message, 'error');
    });
}
</script>

<?php
layout_footer();
?>
