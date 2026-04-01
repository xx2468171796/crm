<?php
/**
 * 项目详情页面（技术端）
 * 包含：概览/动态表单/交付物/沟通记录 四个Tab
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();

$projectId = intval($_GET['id'] ?? 0);

if ($projectId <= 0) {
    header('Location: index.php?page=project_kanban');
    exit;
}

$pdo = Db::pdo();

// 获取项目信息
$stmt = $pdo->prepare("
    SELECT p.*, c.name as customer_name, c.group_code as customer_group_code, c.group_name as customer_group_name, c.customer_group
    FROM projects p
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    echo '<div class="alert alert-danger m-4">项目不存在</div>';
    exit;
}

// 辅助函数：获取或创建客户门户token
function getPortalToken($pdo, $customerId) {
    $stmt = $pdo->prepare("SELECT token FROM portal_links WHERE customer_id = ? AND enabled = 1 LIMIT 1");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && !empty($row['token'])) {
        return $row['token'];
    }
    
    // 自动创建portal_links记录
    $token = bin2hex(random_bytes(32));
    $now = time();
    $passwordHash = password_hash('', PASSWORD_DEFAULT); // 空密码
    $insertStmt = $pdo->prepare("
        INSERT INTO portal_links (customer_id, token, password_hash, enabled, created_by, create_time, update_time) 
        VALUES (?, ?, ?, 1, 0, ?, ?)
    ");
    $insertStmt->execute([$customerId, $token, $passwordHash, $now, $now]);
    
    return $token;
}

$pageTitle = $project['project_name'] . ' - 项目详情';
layout_header($pageTitle);
?>

<link rel="stylesheet" href="css/resource-center.css?v=2.4">
<style>
:root {
    --primary: #6366f1;
    --primary-light: #818cf8;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-400: #94a3b8;
    --gray-600: #475569;
    --gray-800: #1e293b;
}

.project-page {
    background: var(--gray-50);
    min-height: calc(100vh - 60px);
    padding: 24px;
}

.project-header {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    padding: 32px;
    border-radius: 16px;
    margin-bottom: 24px;
    box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
}
.project-header h2 { 
    margin: 0; 
    font-weight: 700; 
    font-size: 28px;
    letter-spacing: -0.5px;
}
.project-header .meta { 
    opacity: 0.9; 
    font-size: 14px; 
    margin-top: 8px;
    display: flex;
    gap: 20px;
}
.project-header .meta i { margin-right: 6px; }

/* 状态步骤条 */
.status-stepper {
    background: white;
    border-radius: 16px;
    padding: 24px 32px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.status-stepper-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 20px;
}
.stepper {
    display: flex;
    justify-content: space-between;
    position: relative;
}
.stepper::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 40px;
    right: 40px;
    height: 3px;
    background: var(--gray-200);
    z-index: 1;
}
.stepper-progress {
    position: absolute;
    top: 20px;
    left: 40px;
    height: 3px;
    background: var(--primary);
    z-index: 2;
    transition: width 0.5s ease;
}
.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 3;
    cursor: pointer;
    transition: all 0.2s;
}
.step:hover .step-circle {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}
.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    border: 3px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-400);
    transition: all 0.3s;
}
.step.completed .step-circle {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}
.step.active .step-circle {
    background: white;
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.2);
}
.step-label {
    margin-top: 10px;
    font-size: 13px;
    font-weight: 500;
    color: var(--gray-400);
    text-align: center;
    max-width: 80px;
}
.step.completed .step-label,
.step.active .step-label {
    color: var(--gray-800);
}

/* 负责人头像样式 */
.tech-avatar-lg {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* 卡片样式 */
.info-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 20px;
    border: 1px solid var(--gray-100);
}
.info-card h5 {
    font-size: 15px;
    color: var(--gray-800);
    margin-bottom: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-card h5 i { color: var(--primary); }
.info-item { margin-bottom: 16px; }
.info-item:last-child { margin-bottom: 0; }
.info-item label { 
    font-size: 12px; 
    color: var(--gray-400); 
    display: block;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-item span { 
    font-size: 15px; 
    color: var(--gray-800);
    font-weight: 500;
}
.tab-content-area { padding: 24px 0; }

/* Tab样式优化 */
.nav-tabs {
    border-bottom: 2px solid var(--gray-200);
    gap: 8px;
}
.nav-tabs .nav-link {
    border: none;
    color: var(--gray-600);
    font-weight: 500;
    padding: 12px 20px;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
}
.nav-tabs .nav-link:hover {
    color: var(--primary);
    background: var(--gray-50);
}
.nav-tabs .nav-link.active {
    color: var(--primary);
    background: white;
    border-bottom: 2px solid var(--primary);
    margin-bottom: -2px;
}

/* 按钮样式 */
.btn-primary-gradient {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.2s;
}
.btn-primary-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
    color: white;
}
.header-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
}
.header-actions .btn {
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
}
.timeline-item {
    position: relative;
    padding-left: 24px;
    padding-bottom: 20px;
    border-left: 2px solid #e2e8f0;
}
.timeline-item:last-child { border-left-color: transparent; }
.timeline-item::before {
    content: '';
    position: absolute;
    left: -6px;
    top: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #137fec;
    border: 2px solid white;
}
.timeline-item .time { font-size: 12px; color: #94a3b8; }
.timeline-item .content { font-size: 14px; color: #1e293b; margin-top: 4px; }

/* 表单实例按钮样式优化 */
.form-instance-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.form-instance-actions .btn {
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 6px;
    white-space: nowrap;
}
.form-instance-actions .btn-outline-primary {
    border-color: #137fec;
    color: #137fec;
}
.form-instance-actions .btn-outline-primary:hover {
    background: #137fec;
    color: white;
}
.form-instance-actions .btn-outline-info {
    border-color: #0dcaf0;
    color: #0891b2;
}
.form-instance-actions .btn-outline-info:hover {
    background: #0dcaf0;
    color: white;
}
.form-instance-actions .btn-success {
    background: #10b981;
    border-color: #10b981;
}
.form-instance-actions .btn-success:hover {
    background: #059669;
    border-color: #059669;
}

/* 进度条样式 */
.form-progress {
    width: 100px;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-left: 12px;
}
.form-progress-bar {
    height: 100%;
    background: #137fec;
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* 阶段时间样式 */
.step-days {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
    text-align: center;
}
.step-days.overdue {
    color: #ef4444;
    font-weight: 600;
}
.step-days.warning {
    color: #f59e0b;
}
.stage-time-modal .stage-row {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
}
.stage-time-modal .stage-row:last-child {
    border-bottom: none;
}
.stage-time-modal .stage-name {
    flex: 1;
    font-weight: 500;
}
.stage-time-modal .stage-dates {
    color: #64748b;
    font-size: 13px;
    margin-right: 16px;
}
.stage-time-modal .days-input {
    width: 70px;
    text-align: center;
}
</style>

<div class="project-page">
    <!-- 项目头部 -->
    <div class="project-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h2><?= htmlspecialchars($project['project_name']) ?></h2>
                <div class="meta">
                    <span><i class="bi bi-building"></i> <?= htmlspecialchars($project['customer_name']) ?></span>
                    <span><i class="bi bi-hash"></i> <?= htmlspecialchars($project['project_code'] ?? '-') ?></span>
                    <span><i class="bi bi-calendar"></i> <?= date('Y-m-d', $project['create_time']) ?></span>
                </div>
            </div>
        </div>
        <div class="header-actions">
            <?php if (!empty($project['customer_id'])): ?>
            <a href="index.php?page=customer_detail&id=<?= $project['customer_id'] ?>" class="btn btn-outline-light">
                <i class="bi bi-arrow-left"></i> 返回客户
            </a>
            <?php endif; ?>
            <?php 
            // 手动完工按钮：设计中之后的阶段且未完工时显示
            $canManualComplete = empty($project['completed_at']) && 
                                 (isAdmin($user) || $user['role'] === 'tech_lead') &&
                                 in_array($project['current_status'], ['设计中', '设计核对', '客户完结', '设计评价']);
            if ($canManualComplete): ?>
            <button type="button" class="btn btn-warning" onclick="manualComplete()">
                <i class="bi bi-check-circle"></i> 手动完工
            </button>
            <?php endif; ?>
            <?php if ($project['requirements_locked']): ?>
            <span class="btn btn-success"><i class="bi bi-lock-fill"></i> 需求已锁定</span>
            <?php else: ?>
            <button type="button" class="btn btn-light" onclick="lockRequirements()">
                <i class="bi bi-lock"></i> 锁定需求
            </button>
            <?php endif; ?>
            <div class="btn-group">
                <?php if (can('portal_view') || isAdmin($user)): ?>
                <a href="portal.php?token=<?= urlencode(getPortalToken($pdo, $project['customer_id'])) ?>&project_id=<?= $projectId ?>" 
                   class="btn btn-outline-light btn-sm" target="_blank">客户门户</a>
                <?php endif; ?>
                <?php if (can('portal_copy_link') || isAdmin($user)): ?>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="copyPortalLink()">复制链接</button>
                <?php endif; ?>
                <?php if (can('portal_view_password') || isAdmin($user)): ?>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="showPortalPassword()">查看密码</button>
                <?php endif; ?>
                <?php if (can('portal_edit_password') || isAdmin($user)): ?>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="editPortalPassword()">修改密码</button>
                <?php endif; ?>
            </div>
            <?php if (isAdmin($user)): ?>
            <div class="form-check form-switch ms-3">
                <input class="form-check-input" type="checkbox" id="showModelFiles" <?= $project['show_model_files'] ? 'checked' : '' ?> onchange="toggleModelFiles(this.checked)">
                <label class="form-check-label text-white small" for="showModelFiles">显示模型文件</label>
            </div>
            <button type="button" class="btn btn-danger ms-2" onclick="confirmDeleteProject()">
                <i class="bi bi-trash"></i> 删除项目
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 状态步骤条 -->
    <?php
    $projectStatuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];
    $currentIndex = array_search($project['current_status'], $projectStatuses);
    if ($currentIndex === false) $currentIndex = 0;
    
    // 检查项目是否已完工
    $isProjectCompleted = !empty($project['completed_at']);
    
    // 完工项目进度为100%，否则按当前阶段计算
    $progressWidth = $isProjectCompleted ? 100 : ($currentIndex > 0 ? (($currentIndex) / (count($projectStatuses) - 1) * 100) : 0);
    ?>
    <div class="status-stepper">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="status-stepper-title">📊 项目进度</div>
            <div class="d-flex align-items-center gap-3">
                <span id="stageTimeInfo" class="text-muted small"></span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="showStageTimeModal()" title="调整阶段时间">
                    <i class="bi bi-clock-history"></i> 调整时间
                </button>
            </div>
        </div>
        <div class="stepper">
            <div class="stepper-progress" id="stepperProgress" style="width: calc(<?= $progressWidth ?>% - 40px);"></div>
            <?php foreach ($projectStatuses as $index => $status): 
                // 项目完工时，所有阶段都视为已完成
                $isCompleted = $isProjectCompleted ? true : ($index < $currentIndex);
                $isActive = $isProjectCompleted ? false : ($index === $currentIndex);
                $stepClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
            ?>
            <div class="step <?= $stepClass ?>" onclick="changeProjectStatus('<?= htmlspecialchars($status) ?>')" title="点击切换到此状态">
                <div class="step-circle">
                    <?php if ($isCompleted): ?>
                        <i class="bi bi-check"></i>
                    <?php else: ?>
                        <?= $index + 1 ?>
                    <?php endif; ?>
                </div>
                <div class="step-label"><?= htmlspecialchars($status) ?></div>
                <div class="step-days" id="stepDays<?= $index ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- 项目周期信息 -->
        <div id="projectTimelineCard" class="project-timeline-card" style="display: none; margin-top: 16px; padding: 16px; background: #f8fafc; border-radius: 8px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="fw-semibold"><i class="bi bi-calendar3"></i> 项目周期</div>
                <div id="projectDateRange" class="text-muted small"></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <div class="text-center p-2 bg-white rounded">
                        <div id="totalDaysNum" class="fs-4 fw-bold text-primary">-</div>
                        <div class="small text-muted">总天数</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="text-center p-2 bg-white rounded">
                        <div id="elapsedDaysNum" class="fs-4 fw-bold text-success">-</div>
                        <div class="small text-muted">已进行</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="text-center p-2 bg-white rounded">
                        <div id="remainingDaysNum" class="fs-4 fw-bold text-warning">-</div>
                        <div class="small text-muted">剩余</div>
                    </div>
                </div>
            </div>
            <div class="progress" style="height: 8px;">
                <div id="timeProgressBar" class="progress-bar bg-success" style="width: 0%; transition: width 0.5s;"></div>
            </div>
            <div class="d-flex justify-content-between mt-1">
                <small class="text-muted">时间进度</small>
                <small id="timeProgressPct" class="text-muted">0%</small>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" id="projectTabs">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#overview">概览</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#forms">动态表单</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#deliverables">交付物</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#timeline">沟通记录</a>
        </li>
    </ul>

    <div class="tab-content tab-content-area">
        <!-- 概览 Tab -->
        <div class="tab-pane fade show active" id="overview">
            <div class="row">
                <div class="col-md-4">
                    <div class="info-card">
                        <h5><i class="bi bi-person"></i> 客户信息</h5>
                        <div class="info-item">
                            <label>客户名称</label>
                            <span><?= htmlspecialchars($project['customer_name']) ?></span>
                        </div>
                        <?php if (!empty($project['customer_group_code'])): ?>
                        <div class="info-item">
                            <label>客户群名称</label>
                            <span><?= htmlspecialchars($project['customer_group_code']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <h5><i class="bi bi-folder"></i> 项目规格</h5>
                        <div class="info-item">
                            <label>项目编号</label>
                            <span><?= htmlspecialchars($project['project_code'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <label>当前状态</label>
                            <span class="status-badge <?= htmlspecialchars($project['current_status']) ?>"><?= htmlspecialchars($project['current_status']) ?></span>
                        </div>
                        <div class="info-item">
                            <label>创建时间</label>
                            <span><?= date('Y-m-d H:i', $project['create_time']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <h5><i class="bi bi-clock-history"></i> 最近交付物</h5>
                        <div id="recentDeliverables">
                            <div class="text-muted small">加载中...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 客户评价区块（仅在设计评价阶段或已完工时显示） -->
            <?php if ($project['current_status'] === '设计评价' || !empty($project['completed_at'])): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="info-card" id="evaluationCard">
                        <h5><i class="bi bi-star"></i> 客户评价</h5>
                        <div id="evaluationContent">
                            <div class="text-muted small">加载中...</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 项目负责人区块 -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="info-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="bi bi-people"></i> 项目负责人</h5>
                            <?php if (isAdmin($user) || $user['role'] === 'dept_leader'): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAssignTechModal()">
                                <i class="bi bi-plus-circle"></i> 添加负责人
                            </button>
                            <?php endif; ?>
                        </div>
                        <div id="projectAssigneesList">
                            <div class="text-muted small">加载中...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 动态表单 Tab -->
        <div class="tab-pane fade" id="forms">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">表单实例</h5>
                <button type="button" class="btn btn-primary" style="padding: 8px 16px; font-size: 14px;" onclick="openCreateFormModal()">
                    <i class="bi bi-plus-circle"></i> 创建表单
                </button>
            </div>
            <div id="formInstancesList"></div>
        </div>

        <!-- 文件管理 Tab -->
        <div class="tab-pane fade" id="deliverables">
            <!-- 统一资源管理中心 -->
            <div id="resourceCenter"></div>
        </div>

        <!-- 沟通记录 Tab -->
        <div class="tab-pane fade" id="timeline">
            <h5 class="mb-3">时间线</h5>
            <div id="timelineList"></div>
        </div>
    </div>
</div>

<script>
const PROJECT_ID = <?= $projectId ?>;

// 加载客户评价
function loadEvaluation() {
    const container = document.getElementById('evaluationContent');
    if (!container) return;
    
    fetch(`${API_URL}/project_evaluations.php?project_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                const info = data.data;
                
                // 优先显示评价表单内容
                if (info.evaluation_form && info.evaluation_form.status === 'submitted') {
                    container.innerHTML = `
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-check-circle text-success"></i>
                            <strong>客户已提交评价表单</strong>
                        </div>
                        <a href="javascript:void(0)" onclick="showFormSubmissionDetail(${info.evaluation_form.id})" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> 查看评价详情
                        </a>
                    `;
                } else if (info.evaluation) {
                    // 简单评分显示
                    const e = info.evaluation;
                    const stars = '★'.repeat(e.rating) + '☆'.repeat(5 - e.rating);
                    container.innerHTML = `
                        <div class="d-flex align-items-start gap-3">
                            <div style="font-size: 24px; color: #f59e0b;">${stars}</div>
                            <div class="flex-grow-1">
                                <div class="mb-2">
                                    <strong>${e.rating} 分</strong>
                                    <span class="text-muted ms-2">评价于 ${e.created_at}</span>
                                </div>
                                ${e.comment ? `<p class="mb-0 text-secondary">${escapeHtml(e.comment)}</p>` : '<p class="mb-0 text-muted">（未填写评价内容）</p>'}
                            </div>
                        </div>
                    `;
                } else if (info.evaluation_form && info.evaluation_form.status === 'pending') {
                    // 评价表单待填写
                    container.innerHTML = `
                        <div class="text-warning">
                            <i class="bi bi-file-earmark-text"></i> 评价表单已创建，等待客户填写
                        </div>
                    `;
                } else if (info.completed_at) {
                    const byText = info.completed_by === 'auto' ? '（超时自动完工）' : 
                                   info.completed_by === 'admin' ? '（管理员手动完工）' :
                                   info.completed_by === 'customer' ? '（客户评价完工）' : '';
                    container.innerHTML = `
                        <div class="text-muted">
                            <i class="bi bi-check-circle text-success"></i> 项目已完工 ${byText}
                            <br><small>完工时间: ${info.completed_at}</small>
                        </div>
                    `;
                } else {
                    const days = info.remaining_days;
                    const urgentClass = days <= 3 ? 'text-danger' : 'text-warning';
                    container.innerHTML = `
                        <div class="${urgentClass}">
                            <i class="bi bi-hourglass-split"></i> 等待客户评价
                            ${days !== null ? `<span class="ms-2">（剩余 ${days} 天）</span>` : ''}
                        </div>
                    `;
                }
            } else {
                container.innerHTML = '<div class="text-muted">暂无评价信息</div>';
            }
        })
        .catch(() => {
            container.innerHTML = '<div class="text-danger">加载失败</div>';
        });
}

// 显示表单提交详情
function showFormSubmissionDetail(instanceId) {
    window.open(`/form_detail.php?instance_id=${instanceId}`, '_blank');
}

// 加载最近交付物
function loadRecentDeliverables() {
    fetch(`${API_URL}/deliverables.php?project_id=${PROJECT_ID}&limit=5`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('recentDeliverables');
            if (data.success && data.data.length > 0) {
                // 过滤掉文件夹，只显示文件
                const files = data.data.filter(d => !d.is_folder);
                if (files.length === 0) {
                    container.innerHTML = '<div class="text-muted small">暂无交付物</div>';
                    return;
                }
                container.innerHTML = files.slice(0, 5).map(d => `
                    <div class="mb-2">
                        <small class="text-muted">${new Date((d.submitted_at || d.create_time) * 1000).toLocaleDateString('zh-CN')}</small>
                        <div class="text-truncate" style="max-width: 200px;" title="${d.deliverable_name || ''}">${d.deliverable_name || '未命名文件'}</div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-muted small">暂无交付物</div>';
            }
        });
}

// 加载项目负责人
function loadProjectAssignees() {
    fetch(`${API_URL}/projects.php?action=assignees&project_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('projectAssigneesList');
            if (data.success && data.data.length > 0) {
                container.innerHTML = `
                    <div class="row g-2">
                        ${data.data.map(a => `
                            <div class="col-md-6 col-lg-4">
                                <div class="assignee-card p-3 bg-light rounded">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="tech-avatar-lg me-2">
                                            ${(a.realname || a.username || '?').charAt(0)}
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div class="fw-semibold text-truncate">${a.realname || a.username}</div>
                                            <small class="text-muted">${a.department_name || ''}</small>
                                        </div>
                                        <?php if (isAdmin($user) || $user['role'] === 'dept_leader'): ?>
                                        <button class="btn btn-sm btn-link text-danger p-0" onclick="removeAssignee(${a.assignment_id})" title="移除">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top">
                                        <div>
                                            <small class="text-muted">提成金额</small>
                                            <div class="fw-bold ${a.commission_amount ? 'text-success' : 'text-muted'}">
                                                ${a.commission_amount ? '¥' + parseFloat(a.commission_amount).toFixed(2) : '未设置'}
                                            </div>
                                        </div>
                                        <?php if (isAdmin($user) || $user['role'] === 'dept_leader'): ?>
                                        <button class="btn btn-sm btn-outline-primary" onclick="openCommissionModal(${a.assignment_id}, '${(a.realname || a.username).replace(/'/g, "\\'")}', ${a.commission_amount || 0})">
                                            <i class="bi bi-currency-yen"></i> 设置提成
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    ${a.commission_note ? `<div class="mt-2"><small class="text-muted">备注: ${a.commission_note}</small></div>` : ''}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                container.innerHTML = '<div class="text-muted small">暂未分配技术人员</div>';
            }
        })
        .catch(err => {
            console.error('加载负责人失败:', err);
            document.getElementById('projectAssigneesList').innerHTML = '<div class="text-muted small">加载失败</div>';
        });
}

// 打开设置提成弹窗
function openCommissionModal(assignmentId, userName, currentAmount) {
    const modalHtml = `
        <div class="modal fade" id="commissionModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-currency-yen me-2"></i>设置提成</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <div class="tech-avatar-lg mx-auto mb-2" style="width: 48px; height: 48px; font-size: 18px;">
                                ${userName.charAt(0)}
                            </div>
                            <div class="fw-semibold">${userName}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">提成金额 (元)</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="number" class="form-control form-control-lg" id="commissionAmount" 
                                       value="${currentAmount || ''}" placeholder="0.00" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">备注（可选）</label>
                            <input type="text" class="form-control" id="commissionNote" placeholder="如：项目完成后发放">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-success" onclick="submitCommission(${assignmentId})">
                            <i class="bi bi-check-lg"></i> 确认设置
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const old = document.getElementById('commissionModal');
    if (old) old.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('commissionModal'));
    modal.show();
    
    // 聚焦输入框
    setTimeout(() => document.getElementById('commissionAmount').focus(), 300);
}

// 提交提成设置
function submitCommission(assignmentId) {
    const amount = parseFloat(document.getElementById('commissionAmount').value) || 0;
    const note = document.getElementById('commissionNote').value;
    
    fetch(`${API_URL}/tech_commission.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'set_commission',
            assignment_id: assignmentId,
            commission_amount: amount,
            commission_note: note
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('commissionModal')).hide();
            showAlertModal('提成设置成功', 'success');
            loadProjectAssignees();
        } else {
            showAlertModal(data.message || '设置失败', 'error');
        }
    })
    .catch(err => {
        showAlertModal('设置失败: ' + err.message, 'error');
    });
}

// 打开添加负责人弹窗
function openAssignTechModal() {
    // 加载技术人员列表
    fetch(`${API_URL}/users.php?role=tech`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            const users = data.data;
            const modalHtml = `
                <div class="modal fade" id="assignTechModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">添加项目负责人</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">选择技术人员</label>
                                    <select class="form-select" id="assignTechUserId">
                                        <option value="">请选择...</option>
                                        ${users.map(u => `<option value="${u.id}">${u.realname || u.username}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">备注（可选）</label>
                                    <input type="text" class="form-control" id="assignTechNotes" placeholder="分配备注">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="submitAssignTech()">确认添加</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // 移除旧弹窗
            const old = document.getElementById('assignTechModal');
            if (old) old.remove();
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('assignTechModal'));
            modal.show();
        });
}

// 提交添加负责人
function submitAssignTech() {
    const userId = document.getElementById('assignTechUserId').value;
    const notes = document.getElementById('assignTechNotes').value;
    
    if (!userId) {
        showAlertModal('请选择技术人员', 'warning');
        return;
    }
    
    fetch(`${API_URL}/project_assignments.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            project_id: PROJECT_ID,
            tech_user_id: userId,
            notes: notes
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('assignTechModal')).hide();
            showAlertModal('添加成功', 'success');
            loadProjectAssignees();
        } else {
            showAlertModal(data.message || '添加失败', 'error');
        }
    });
}

// 移除负责人
function removeAssignee(assignmentId) {
    if (!confirm('确定要移除此负责人吗？')) return;
    
    fetch(`${API_URL}/project_assignments.php?id=${assignmentId}`, {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('已移除', 'success');
            loadProjectAssignees();
        } else {
            showAlertModal(data.message || '移除失败', 'error');
        }
    });
}

// 需求状态配置
const requirementStatusConfig = {
    'pending': { label: '待填写', color: 'secondary' },
    'communicating': { label: '需求沟通', color: 'warning' },
    'confirmed': { label: '需求确认', color: 'success' },
    'modifying': { label: '需求修改', color: 'danger' }
};

// 加载表单实例
function loadFormInstances() {
    fetch(`${API_URL}/form_instances.php?project_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('formInstancesList');
            if (data.success && data.data.length > 0) {
                container.innerHTML = data.data.map(f => {
                    const reqStatus = f.requirement_status || 'pending';
                    const statusCfg = requirementStatusConfig[reqStatus] || requirementStatusConfig['pending'];
                    const canConfirm = reqStatus === 'communicating';
                    
                    return `
                    <div class="info-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div style="flex: 1;">
                                <strong style="font-size: 15px;">${f.instance_name}</strong>
                                <div class="text-muted small mt-1">${f.template_name} v${f.version_number}</div>
                                <div class="mt-2 d-flex align-items-center">
                                    <span class="badge bg-${statusCfg.color}" style="font-size: 12px; padding: 5px 10px;">${statusCfg.label}</span>
                                    ${f.submission_count > 0 ? `<span class="badge bg-info ms-2" style="font-size: 12px; padding: 5px 10px;">${f.submission_count}次提交</span>` : ''}
                                </div>
                            </div>
                            <div class="form-instance-actions">
                                <div class="dropdown d-inline-block me-2">
                                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="切换状态">
                                        <i class="bi bi-arrow-repeat"></i> 切换状态
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item ${reqStatus === 'pending' ? 'active' : ''}" href="javascript:void(0)" onclick="updateFormStatus(${f.id}, 'pending')"><i class="bi bi-clock text-secondary me-2"></i>待填写</a></li>
                                        <li><a class="dropdown-item ${reqStatus === 'communicating' ? 'active' : ''}" href="javascript:void(0)" onclick="updateFormStatus(${f.id}, 'communicating')"><i class="bi bi-chat-dots text-warning me-2"></i>沟通中</a></li>
                                        <li><a class="dropdown-item ${reqStatus === 'confirmed' ? 'active' : ''}" href="javascript:void(0)" onclick="updateFormStatus(${f.id}, 'confirmed')"><i class="bi bi-check-circle text-success me-2"></i>已确认</a></li>
                                        <li><a class="dropdown-item ${reqStatus === 'modifying' ? 'active' : ''}" href="javascript:void(0)" onclick="updateFormStatus(${f.id}, 'modifying')"><i class="bi bi-pencil text-danger me-2"></i>修改中</a></li>
                                    </ul>
                                </div>
                                <button type="button" class="btn btn-outline-primary" onclick="copyFillLink('${f.fill_token}')" title="复制填写链接">
                                    <i class="bi bi-link-45deg"></i> 复制链接
                                </button>
                                ${f.submission_count > 0 ? `
                                <a href="form_requirement_detail.php?id=${f.id}" class="btn btn-outline-info" title="查看需求详情">
                                    <i class="bi bi-eye"></i> 查看需求
                                </a>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `}).join('');
            } else {
                container.innerHTML = '<div class="alert alert-info">暂无表单实例</div>';
            }
        });
}

// 加载客户文件（只读）
function loadCustomerFiles() {
    const container = document.getElementById('customerFilesList');
    if (!container) return; // 元素不存在时跳过
    const customerId = <?= (int)$project['customer_id'] ?>;
    fetch(`${API_URL}/customer_files.php?customer_id=${customerId}&limit=20`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                container.innerHTML = data.data.map(f => `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <i class="bi bi-file-earmark text-muted me-2"></i>
                            <span>${f.filename || f.name}</span>
                            <small class="text-muted ms-2">${f.size_formatted || ''}</small>
                        </div>
                        <a href="${f.url || '#'}" class="btn btn-outline-secondary btn-sm" target="_blank">
                            <i class="bi bi-eye"></i> 查看
                        </a>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-muted small">暂无客户文件</div>';
            }
        })
        .catch(() => {
            if (container) container.innerHTML = '<div class="text-muted small">暂无客户文件</div>';
        });
}

// 加载作品文件（需审批）
function loadArtworkFiles() {
    const container = document.getElementById('artworkFilesList');
    if (!container) return; // 元素不存在时跳过
    fetch(`${API_URL}/deliverables.php?project_id=${PROJECT_ID}&file_category=artwork_file`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                container.innerHTML = data.data.map(d => `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <i class="bi bi-palette text-primary me-2"></i>
                            <strong>${d.title}</strong>
                            <small class="text-muted ms-2">${d.deliverable_type || ''}</small>
                            ${d.description ? `<div class="text-muted small">${d.description}</div>` : ''}
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge ${d.status === 'approved' ? 'bg-success' : d.status === 'rejected' ? 'bg-danger' : 'bg-warning'}">
                                ${d.status === 'approved' ? '已通过' : d.status === 'rejected' ? '已驳回' : '待审批'}
                            </span>
                            ${d.file_url ? `<a href="${d.file_url}" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-download"></i></a>` : ''}
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-muted small">暂无作品文件</div>';
            }
        });
}

// 加载模型文件（无需审批）
function loadModelFiles() {
    const container = document.getElementById('modelFilesList');
    if (!container) return; // 元素不存在时跳过
    fetch(`${API_URL}/deliverables.php?project_id=${PROJECT_ID}&file_category=model_file`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                container.innerHTML = data.data.map(d => `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <i class="bi bi-box text-success me-2"></i>
                            <strong>${d.title}</strong>
                            <small class="text-muted ms-2">${d.deliverable_type || ''}</small>
                            ${d.description ? `<div class="text-muted small">${d.description}</div>` : ''}
                        </div>
                        <div>
                            ${d.file_url ? `<a href="${d.file_url}" class="btn btn-outline-success btn-sm" target="_blank"><i class="bi bi-download"></i> 下载</a>` : ''}
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-muted small">暂无模型文件</div>';
            }
        });
}

// 加载所有文件（兼容旧代码）
function loadDeliverables() {
    loadCustomerFiles();
    loadArtworkFiles();
    loadModelFiles();
}

// 加载时间线
function loadTimeline() {
    fetch(`${API_URL}/timeline.php?entity_type=project&entity_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('timelineList');
            if (data.success && data.data.length > 0) {
                container.innerHTML = data.data.map(t => `
                    <div class="timeline-item">
                        <div class="time">${new Date(t.create_time * 1000).toLocaleString('zh-CN')}</div>
                        <div class="content">${t.description}</div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-muted">暂无记录</div>';
            }
        });
}

// 复制填写链接（兼容HTTP环境）
function copyFillLink(token) {
    const url = window.location.origin + '/form_fill.php?token=' + token;
    
    // 创建临时输入框复制
    const input = document.createElement('input');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    input.value = url;
    document.body.appendChild(input);
    input.select();
    
    try {
        document.execCommand('copy');
        showAlertModal('链接已复制: ' + url, 'success');
    } catch (e) {
        showAlertModal('复制失败，请手动复制: ' + url, 'warning');
    }
    
    document.body.removeChild(input);
}

// 手动完工
function manualComplete() {
    showConfirmModal('确认完工', '确定要手动将此项目标记为完工吗？', function() {
        fetch(`${API_URL}/project_complete.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: PROJECT_ID })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('项目已完工', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message || '操作失败', 'error');
            }
        })
        .catch(e => showToast('网络错误', 'error'));
    });
}

// 锁定需求
function lockRequirements() {
    showConfirmModal('确认锁定', '锁定需求后将无法修改，确定要锁定吗？', function() {
        fetch(`${API_URL}/projects.php`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: PROJECT_ID, requirements_locked: 1 })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                showAlertModal('锁定失败: ' + data.message, 'error');
            }
        });
    });
}

// 创建表单实例弹窗
function openCreateFormModal() {
    // 先获取可用的表单模板
    fetch(`${API_URL}/form_templates.php?status=published`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data.length) {
                showAlertModal('暂无可用的表单模板，请先在后台创建', 'warning');
                return;
            }
            
            let html = '<form id="createFormInstanceForm">';
            html += '<div class="mb-3"><label class="form-label">表单模板 *</label>';
            html += '<select class="form-select" name="template_id" required>';
            html += '<option value="">请选择</option>';
            data.data.forEach(t => {
                html += `<option value="${t.id}">${t.name}</option>`;
            });
            html += '</select></div>';
            html += '<div class="mb-3"><label class="form-label">实例名称 *</label>';
            html += `<input type="text" class="form-control" name="instance_name" value="表单-${new Date().toLocaleDateString('zh-CN')}" required>`;
            html += '</div></form>';
            
            // 使用自定义模态框，避免回调时form已被移除
            const modalId = 'createFormInstanceModal';
            let existingModal = document.getElementById(modalId);
            if (existingModal) existingModal.remove();
            
            const modalHtml = `
                <div class="modal fade" id="${modalId}" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">创建表单实例</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">${html}</div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" id="submitCreateFormBtn">确定</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modalElement = document.getElementById(modalId);
            const modal = new bootstrap.Modal(modalElement);
            
            document.getElementById('submitCreateFormBtn').onclick = function() {
                const form = document.getElementById('createFormInstanceForm');
                const templateId = form.querySelector('[name="template_id"]').value;
                const instanceName = form.querySelector('[name="instance_name"]').value;
                
                if (!templateId) {
                    showAlertModal('请选择表单模板', 'warning');
                    return;
                }
                
                fetch(`${API_URL}/form_instances.php`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        project_id: PROJECT_ID,
                        template_id: templateId,
                        instance_name: instanceName
                    })
                })
                .then(r => r.json())
                .then(result => {
                    modal.hide();
                    if (result.success) {
                        showAlertModal('表单实例创建成功', 'success');
                        loadFormInstances();
                    } else {
                        showAlertModal('创建失败: ' + result.message, 'error');
                    }
                });
            };
            
            modal.show();
        });
}

// openUploadModal 函数已移动到 layout_footer 之后的脚本块中

// 查看需求详情
function viewRequirementDetail(instanceId) {
    fetch(`${API_URL}/form_submissions.php?instance_id=${instanceId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlertModal('加载失败: ' + data.message, 'error');
                return;
            }
            
            const { instance, schema, submissions } = data.data;
            const latestSubmission = submissions[0];
            
            let html = `
                <div class="mb-3">
                    <span class="badge bg-${requirementStatusConfig[instance.requirement_status]?.color || 'secondary'}">
                        ${instance.requirement_status_label}
                    </span>
                    <small class="text-muted ms-2">最后更新: ${instance.update_time}</small>
                </div>
            `;
            
            if (latestSubmission) {
                html += '<div class="border rounded p-3 bg-light">';
                html += '<h6 class="mb-3">客户填写内容</h6>';
                
                const submissionData = latestSubmission.submission_data || {};
                
                // 尝试根据schema渲染字段
                if (schema && schema.length > 0) {
                    schema.forEach(field => {
                        const fieldName = field.name || field.label;
                        const value = submissionData[fieldName] || '-';
                        html += `
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">${field.label || fieldName}</label>
                                <div>${Array.isArray(value) ? value.join(', ') : value}</div>
                            </div>
                        `;
                    });
                } else {
                    // 直接显示提交数据
                    Object.entries(submissionData).forEach(([key, value]) => {
                        html += `
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">${key}</label>
                                <div>${Array.isArray(value) ? value.join(', ') : value}</div>
                            </div>
                        `;
                    });
                }
                
                html += `<div class="mt-3 pt-2 border-top small text-muted">`;
                html += `提交人: ${latestSubmission.submitted_by_name} | `;
                html += `提交时间: ${latestSubmission.submitted_at_formatted}`;
                html += `</div></div>`;
            } else {
                html += '<div class="alert alert-warning">暂无提交记录</div>';
            }
            
            // 添加操作按钮
            if (instance.requirement_status === 'communicating') {
                html += `
                    <div class="mt-3 text-end">
                        <button class="btn btn-success" onclick="confirmRequirement(${instanceId}); bootstrap.Modal.getInstance(document.querySelector('.modal.show')).hide();">
                            <i class="bi bi-check-lg"></i> 确认需求
                        </button>
                    </div>
                `;
            }
            
            showAlertModal(html, 'info', `需求详情 - ${instance.instance_name}`);
        })
        .catch(err => {
            showAlertModal('加载失败: ' + err.message, 'error');
        });
}

// 确认需求
function confirmRequirement(instanceId) {
    showConfirmModal('确认需求', '确定要确认此需求吗？确认后客户将无法再修改。', function() {
        fetch(`${API_URL}/form_requirement_status.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                instance_id: instanceId,
                status: 'confirmed'
            })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                showAlertModal('需求已确认', 'success');
                loadFormInstances();
            } else {
                showAlertModal('操作失败: ' + result.message, 'error');
            }
        })
        .catch(err => {
            showAlertModal('操作失败: ' + err.message, 'error');
        });
    });
}

// 页面加载
document.addEventListener('DOMContentLoaded', function() {
    loadRecentDeliverables();
    loadEvaluation();
    loadFormInstances();
    loadDeliverables();
    loadTimeline();
    loadStageTimes();
});

// 阶段时间相关
let stageTimesData = null;
const projectStatuses = ['待沟通', '需求确认', '设计中', '设计核对', '客户完结', '设计评价'];

async function loadStageTimes() {
    try {
        const res = await fetch(`${API_URL}/project_stage_times.php?project_id=${PROJECT_ID}`);
        const data = await res.json();
        if (data.success) {
            stageTimesData = data.data;
            renderStageTimes();
        }
    } catch (e) {
        console.error('[STAGE_TIME_ERROR] loadStageTimes:', e);
    }
}

function renderStageTimes() {
    if (!stageTimesData || !stageTimesData.stages) return;
    
    const stages = stageTimesData.stages;
    const summary = stageTimesData.summary;
    
    // 更新进度信息
    const infoEl = document.getElementById('stageTimeInfo');
    if (infoEl) {
        // 项目已完工
        if (summary.is_completed) {
            infoEl.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill"></i> 已完工，用时 ${summary.actual_days} 天</span>`;
        } else if (summary.current_stage) {
            const remaining = summary.current_stage.remaining_days;
            if (remaining !== null) {
                if (remaining < 0) {
                    infoEl.innerHTML = `<span class="text-danger">当前阶段已超期 ${Math.abs(remaining)} 天</span>`;
                } else if (remaining === 0) {
                    infoEl.innerHTML = `<span class="text-warning">当前阶段今日到期</span>`;
                } else {
                    infoEl.innerHTML = `当前阶段剩余 <strong>${remaining}</strong> 天`;
                }
            }
        }
    }
    
    // 更新每个阶段的天数显示
    stages.forEach((st, idx) => {
        // 找到对应的阶段索引（stage_from 对应显示位置）
        const stageIdx = projectStatuses.indexOf(st.stage_from);
        if (stageIdx >= 0 && stageIdx < projectStatuses.length) {
            const daysEl = document.getElementById(`stepDays${stageIdx}`);
            if (daysEl) {
                if (st.status === 'completed') {
                    daysEl.textContent = `${st.planned_days}天 ✓`;
                } else if (st.status === 'in_progress') {
                    const remaining = st.remaining_days;
                    if (remaining < 0) {
                        daysEl.innerHTML = `<span class="overdue">超${Math.abs(remaining)}天</span>`;
                    } else if (remaining <= 1) {
                        daysEl.innerHTML = `<span class="warning">剩${remaining}天</span>`;
                    } else {
                        daysEl.textContent = `剩${remaining}天`;
                    }
                } else {
                    daysEl.textContent = `${st.planned_days}天`;
                }
            }
        }
    });
    
    // 更新进度条（基于时间）
    if (summary.overall_progress !== undefined) {
        const progressEl = document.getElementById('stepperProgress');
        if (progressEl) {
            const pct = Math.min(100, summary.overall_progress);
            progressEl.style.width = `calc(${pct}% - 40px)`;
        }
    }
    
    // 更新项目周期卡片
    renderProjectTimelineCard(summary, stages);
}

function renderProjectTimelineCard(summary, stages) {
    const card = document.getElementById('projectTimelineCard');
    if (!card || !summary.total_days) return;
    
    card.style.display = 'block';
    
    const totalDays = summary.total_days;
    const isCompleted = summary.is_completed;
    
    // 已完工项目显示实际用时
    if (isCompleted) {
        const actualDays = summary.actual_days || summary.elapsed_days;
        document.getElementById('totalDaysNum').textContent = totalDays;
        document.getElementById('elapsedDaysNum').textContent = actualDays;
        
        const remainingEl = document.getElementById('remainingDaysNum');
        remainingEl.textContent = '已完工';
        remainingEl.className = 'fs-4 fw-bold text-success';
        
        document.getElementById('timeProgressBar').style.width = '100%';
        document.getElementById('timeProgressBar').className = 'progress-bar bg-success';
        document.getElementById('timeProgressPct').textContent = '100%';
        
        // 日期范围显示实际完成日期
        if (summary.completed_at) {
            const first = stages && stages.length > 0 ? stages[0] : null;
            if (first && first.planned_start_date) {
                document.getElementById('projectDateRange').textContent = 
                    `${first.planned_start_date} ~ ${summary.completed_at.split(' ')[0]} (已完工)`;
            }
        }
    } else {
        const elapsedDays = summary.elapsed_days;
        const remainingDays = Math.max(0, totalDays - elapsedDays);
        const pct = Math.min(100, Math.round(elapsedDays * 100 / totalDays));
        
        document.getElementById('totalDaysNum').textContent = totalDays;
        document.getElementById('elapsedDaysNum').textContent = elapsedDays;
        
        const remainingEl = document.getElementById('remainingDaysNum');
        remainingEl.textContent = remainingDays;
        remainingEl.className = 'fs-4 fw-bold ' + (remainingDays <= 3 ? 'text-danger' : 'text-warning');
        
        document.getElementById('timeProgressBar').style.width = pct + '%';
        document.getElementById('timeProgressBar').className = 'progress-bar bg-success';
        document.getElementById('timeProgressPct').textContent = pct + '%';
        
        // 日期范围
        if (stages && stages.length > 0) {
            const first = stages[0];
            const last = stages[stages.length - 1];
            if (first.planned_start_date && last.planned_end_date) {
                document.getElementById('projectDateRange').textContent = 
                    `${first.planned_start_date} ~ ${last.planned_end_date}`;
            }
        }
    }
}

// 上传文件弹窗（支持文件分类，带进度显示）
function openUploadModal(fileCategory = 'artwork_file') {
    const isArtwork = fileCategory === 'artwork_file';
    const isModel = fileCategory === 'model_file';
    const title = isArtwork ? '上传作品文件' : (isModel ? '上传模型文件' : '上传文件');
    const typeOptions = isArtwork 
        ? '<option value="效果图">效果图</option><option value="平面图">平面图</option><option value="施工图">施工图</option><option value="其他">其他</option>'
        : '<option value="3D模型">3D模型</option><option value="渲染文件">渲染文件</option><option value="源文件">源文件</option><option value="其他">其他</option>';
    
    let html = '<form id="uploadDeliverableForm" enctype="multipart/form-data">';
    html += `<input type="hidden" name="file_category" value="${fileCategory}">`;
    html += '<div class="mb-3"><label class="form-label">文件名称 *</label>';
    html += '<input type="text" class="form-control" name="title" required></div>';
    html += '<div class="mb-3"><label class="form-label">类型</label>';
    html += `<select class="form-select" name="deliverable_type">${typeOptions}</select></div>`;
    html += '<div class="mb-3"><label class="form-label">文件 *</label>';
    html += '<input type="file" class="form-control" name="file" required></div>';
    html += '<div class="mb-3"><label class="form-label">描述</label>';
    html += '<textarea class="form-control" name="description" rows="2"></textarea></div>';
    html += '<div id="uploadProgressContainer" class="mb-3" style="display:none;">';
    html += '<label class="form-label">上传进度</label>';
    html += '<div class="progress" style="height: 20px;">';
    html += '<div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>';
    html += '</div>';
    html += '<div id="uploadProgressText" class="small text-muted mt-1"></div>';
    html += '</div>';
    if (isArtwork) {
        html += '<div class="alert alert-warning small"><i class="bi bi-info-circle"></i> 作品文件需要审批后才能对客户可见</div>';
    } else if (isModel) {
        html += '<div class="alert alert-success small"><i class="bi bi-check-circle"></i> 模型文件无需审批，上传后直接可用</div>';
    }
    html += '</form>';
    
    showConfirmModal(title, html, function() {
        const form = document.getElementById('uploadDeliverableForm');
        const formData = new FormData(form);
        formData.append('project_id', PROJECT_ID);
        
        if (isModel) {
            formData.append('auto_approve', '1');
        }
        
        // 显示进度条
        const progressContainer = document.getElementById('uploadProgressContainer');
        const progressBar = document.getElementById('uploadProgressBar');
        const progressText = document.getElementById('uploadProgressText');
        progressContainer.style.display = 'block';
        
        // 禁用确认按钮
        const confirmBtn = document.querySelector('.modal.show .btn-primary');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>上传中...';
        }
        
        // 使用 XMLHttpRequest 以支持进度监听
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${API_URL}/deliverables.php`, true);
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                const loaded = formatBytes(e.loaded);
                const total = formatBytes(e.total);
                progressText.textContent = `${loaded} / ${total}`;
            }
        };
        
        xhr.onload = function() {
            try {
                const result = JSON.parse(xhr.responseText);
                if (result.success) {
                    progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    progressBar.classList.add('bg-success');
                    progressBar.textContent = '完成';
                    const msg = isArtwork ? '作品上传成功，等待审批' : '模型文件上传成功';
                    setTimeout(() => {
                        bootstrap.Modal.getInstance(document.querySelector('.modal.show'))?.hide();
                        showAlertModal(msg, 'success');
                        loadDeliverables();
                        loadRecentDeliverables();
                    }, 500);
                } else {
                    progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                    progressBar.classList.add('bg-danger');
                    progressBar.textContent = '失败';
                    showAlertModal('上传失败: ' + result.message, 'error');
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '确认';
                    }
                }
            } catch (e) {
                progressBar.classList.add('bg-danger');
                showAlertModal('上传失败: 服务器响应异常', 'error');
            }
        };
        
        xhr.onerror = function() {
            progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
            progressBar.classList.add('bg-danger');
            progressBar.textContent = '失败';
            showAlertModal('上传失败: 网络错误', 'error');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '确认';
            }
        };
        
        xhr.send(formData);
        
        return false; // 阻止默认关闭行为
    });
}

// 格式化文件大小
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// 阶段时间调整弹窗
function showStageTimeModal() {
    if (!stageTimesData || !stageTimesData.stages) {
        showAlertModal('暂无阶段时间数据', 'warning');
        return;
    }
    
    let html = '<div class="stage-time-modal">';
    stageTimesData.stages.forEach(st => {
        const statusBadge = st.status === 'completed' ? '<span class="badge bg-success ms-2">已完成</span>' 
                         : st.status === 'in_progress' ? '<span class="badge bg-primary ms-2">进行中</span>'
                         : '<span class="badge bg-secondary ms-2">待开始</span>';
        html += `
            <div class="stage-row d-flex align-items-center gap-2 mb-2 p-2 border rounded" data-stage-id="${st.id}" data-original-days="${st.planned_days}">
                <div class="flex-grow-1">
                    <div class="fw-bold">${escapeHtml(st.stage_from)} → ${escapeHtml(st.stage_to)} ${statusBadge}</div>
                    <div class="small text-muted">${st.planned_start_date || '-'} ~ ${st.planned_end_date || '-'}</div>
                </div>
                <input type="number" class="form-control form-control-sm stage-days-input" style="width:70px;" 
                       value="${st.planned_days}" min="1" max="365" data-id="${st.id}">
                <span>天</span>
            </div>
        `;
    });
    html += '</div>';
    
    const modalId = 'stageTimeModal';
    let existingModal = document.getElementById(modalId);
    if (existingModal) existingModal.remove();
    
    const modalHtml = `
        <div class="modal fade" id="${modalId}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>阶段时间调整</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">${html}</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                        <button type="button" class="btn btn-primary" onclick="batchAdjustStageTimes()">
                            <i class="bi bi-check-all me-1"></i>批量提交
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById(modalId));
    modal.show();
}

async function batchAdjustStageTimes() {
    const inputs = document.querySelectorAll('.stage-days-input');
    const changes = [];
    
    inputs.forEach(input => {
        const stageId = parseInt(input.dataset.id);
        const newDays = parseInt(input.value);
        const row = input.closest('.stage-row');
        const originalDays = parseInt(row?.dataset.originalDays || 0);
        
        if (!isNaN(newDays) && newDays >= 1 && newDays !== originalDays) {
            changes.push({ stage_id: stageId, new_days: newDays });
        }
    });
    
    if (changes.length === 0) {
        showAlertModal('没有需要调整的内容', 'info');
        return;
    }
    
    try {
        const res = await fetch(`${API_URL}/project_stage_times.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'batch_adjust',
                project_id: PROJECT_ID,
                changes: changes
            })
        });
        const data = await res.json();
        if (data.success) {
            showAlertModal(`已成功调整 ${changes.length} 个阶段的时间`, 'success');
            const modal = bootstrap.Modal.getInstance(document.getElementById('stageTimeModal'));
            if (modal) modal.hide();
            loadStageTimes();
        } else {
            showAlertModal(data.message, 'error');
        }
    } catch (e) {
        console.error('[STAGE_TIME_DEBUG]', e);
        showAlertModal('批量调整失败', 'error');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}


function changeProjectStatus(newStatus) {
    const currentStatus = '<?= $project['current_status'] ?>';
    if (newStatus === currentStatus) return;
    
    // 切换到"设计评价"时，提示客户将收到评价邀请
    const confirmMsg = newStatus === '设计评价' 
        ? '确定要将状态改为"设计评价"吗？\n\n客户将在门户端看到评价提醒，完成评价后项目自动完工。'
        : `确定要将状态改为"${newStatus}"吗？`;
    
    showConfirmModal('变更项目状态', confirmMsg, function() {
        fetch('/api/projects.php', {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: <?= $projectId ?>,
                current_status: newStatus
            })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                showAlertModal('状态已更新', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlertModal('操作失败: ' + result.message, 'error');
            }
        })
        .catch(err => {
            showAlertModal('操作失败: ' + err.message, 'error');
        });
    });
}

// 客户门户密码管理
const PORTAL_TOKEN = '<?= urlencode(getPortalToken($pdo, $project['customer_id'])) ?>';
const CUSTOMER_ID = <?= $project['customer_id'] ?>;

function copyPortalLink() {
    const url = window.location.origin + '/portal.php?token=' + PORTAL_TOKEN + '&project_id=' + PROJECT_ID;
    const input = document.createElement('input');
    input.value = url;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    showAlertModal('门户链接已复制', 'success');
}

function showPortalPassword() {
    fetch(API_URL + '/portal_password.php?customer_id=' + CUSTOMER_ID)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                const pwd = data.data.current_password || '(未设置)';
                showAlertModal('访问密码: <code style="font-size:18px;user-select:all">' + pwd + '</code>', 'info');
            } else {
                showAlertModal('未找到门户信息', 'warning');
            }
        })
        .catch(err => showAlertModal('获取失败: ' + err.message, 'error'));
}

function editPortalPassword() {
    showPromptModal('设置访问密码', '', function(newPwd) {
        if (newPwd === null) return;
        fetch(API_URL + '/portal_password.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ customer_id: CUSTOMER_ID, password: newPwd })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('密码已更新', 'success');
            } else {
                showAlertModal('更新失败: ' + data.message, 'error');
            }
        })
        .catch(err => showAlertModal('更新失败: ' + err.message, 'error'));
    });
}

function toggleModelFiles(enabled) {
    fetch(API_URL + '/projects.php', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: PROJECT_ID, show_model_files: enabled ? 1 : 0 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal(enabled ? '已允许客户查看模型文件' : '已禁止客户查看模型文件', 'success');
        } else {
            showAlertModal('设置失败: ' + data.message, 'error');
            document.getElementById('showModelFiles').checked = !enabled;
        }
    })
    .catch(err => {
        showAlertModal('设置失败: ' + err.message, 'error');
        document.getElementById('showModelFiles').checked = !enabled;
    });
}

// 删除项目确认
function confirmDeleteProject() {
    const projectName = <?= json_encode($project['project_name']) ?>;
    const projectCode = <?= json_encode($project['project_code'] ?? '') ?>;
    
    showConfirmModal(
        '确认删除项目',
        `<div class="text-start">
            <p>确定要删除项目 <strong>${escapeHtml(projectName)}</strong> 吗？</p>
            <p class="text-muted small mb-2">项目编号：${escapeHtml(projectCode)}</p>
            <div class="alert alert-warning py-2 mb-0">
                <i class="bi bi-exclamation-triangle"></i> 删除后项目及相关交付物将移至回收站，15天后自动永久删除。
            </div>
        </div>`,
        function() {
            deleteProject();
        }
    );
}

// 执行删除项目
function deleteProject() {
    fetch(API_URL + '/projects.php?id=' + PROJECT_ID, {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('项目已删除，即将返回...', 'success');
            setTimeout(() => {
                window.location.href = 'index.php?page=project_kanban';
            }, 1500);
        } else {
            showAlertModal('删除失败: ' + data.message, 'error');
        }
    })
    .catch(err => {
        showAlertModal('删除失败: ' + err.message, 'error');
    });
}

// ========== 动态表单管理 ==========

// 加载表单实例列表
function loadFormInstances() {
    const container = document.getElementById('formInstancesList');
    if (!container) return;
    
    fetch(`${API_URL}/form_instances.php?project_id=${PROJECT_ID}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const statusLabels = { pending: '待填写', communicating: '沟通中', confirmed: '已确认', modifying: '修改中' };
                const statusColors = { pending: '#94a3b8', communicating: '#f59e0b', confirmed: '#10b981', modifying: '#ef4444' };
                
                container.innerHTML = data.data.map(f => {
                    const reqStatus = f.requirement_status || 'pending';
                    const statusLabel = statusLabels[reqStatus] || '未知';
                    const statusColor = statusColors[reqStatus] || '#94a3b8';
                    
                    return `
                        <div class="info-card mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">${escapeHtml(f.instance_name)}</h6>
                                    <small class="text-muted">${escapeHtml(f.template_name)} · ${f.form_type || 'custom'}</small>
                                </div>
                                <span class="badge" style="background: ${statusColor}20; color: ${statusColor};">${statusLabel}</span>
                            </div>
                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <button class="btn btn-sm btn-outline-primary" onclick="copyFormFillLink('${f.fill_token}')">
                                    <i class="bi bi-link-45deg"></i> 复制填写链接
                                </button>
                                ${f.submission_count > 0 ? `
                                    <button class="btn btn-sm btn-outline-success" onclick="showFormSubmissionDetail(${f.id})">
                                        <i class="bi bi-eye"></i> 查看详情
                                    </button>
                                ` : ''}
                                ${reqStatus === 'pending' || reqStatus === 'modifying' ? `
                                    <a href="/form_fill.php?token=${f.fill_token}" class="btn btn-sm btn-primary" target="_blank">
                                        <i class="bi bi-pencil"></i> 填写表单
                                    </a>
                                ` : ''}
                                ${reqStatus === 'communicating' ? `
                                    <button class="btn btn-sm btn-success" onclick="updateFormStatus(${f.id}, 'confirmed')">
                                        <i class="bi bi-check-lg"></i> 确认需求
                                    </button>
                                ` : ''}
                                ${reqStatus === 'confirmed' ? `
                                    <button class="btn btn-sm btn-warning" onclick="updateFormStatus(${f.id}, 'modifying')">
                                        <i class="bi bi-pencil-square"></i> 允许修改
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                container.innerHTML = '<div class="text-muted">暂无表单实例，点击"创建表单"添加</div>';
            }
        })
        .catch(err => {
            console.error('加载表单失败:', err);
            container.innerHTML = '<div class="text-danger">加载失败</div>';
        });
}

// 复制表单填写链接
function copyFormFillLink(token) {
    const url = window.location.origin + '/form_fill.php?token=' + token;
    const input = document.createElement('input');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    input.value = url;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    showAlertModal('填写链接已复制', 'success');
}

// 更新表单需求状态
function updateFormStatus(instanceId, newStatus) {
    const statusLabels = { pending: '待填写', communicating: '沟通中', confirmed: '已确认', modifying: '修改中' };
    const confirmMsg = `确定将需求状态改为"${statusLabels[newStatus]}"吗？`;
    
    showConfirmModal('变更需求状态', confirmMsg, function() {
        fetch(`${API_URL}/form_requirement_status.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ instance_id: instanceId, status: newStatus })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlertModal('状态已更新', 'success');
                loadFormInstances();
            } else {
                showAlertModal('更新失败: ' + data.message, 'error');
            }
        })
        .catch(err => showAlertModal('更新失败: ' + err.message, 'error'));
    });
}

// 注：openCreateFormModal 函数已在上方定义（1248行）
</script>

<script src="js/folder-upload.js?v=1.1"></script>
<script src="js/file-transfer.js?v=1.0"></script>
<script src="js/components/resource-center.js?v=3.8"></script>
<script>
// 初始化统一资源管理中心
document.addEventListener('DOMContentLoaded', function() {
    // 检查是否有审批权限
    const isAdmin = <?= json_encode(isAdmin($user)) ?>;
    
    // 加载项目负责人
    loadProjectAssignees();
    
    // 加载动态表单列表
    loadFormInstances();
    
    ResourceCenter.init({
        container: '#resourceCenter',
        projectId: PROJECT_ID,
        isAdmin: isAdmin,
        onUploadSuccess: function() {
            loadRecentDeliverables();
        }
    });
});
</script>

<?php
layout_footer();
?>
