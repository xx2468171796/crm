<?php
// 共享的 OKR 模态框与模板（桌面 & 移动端共用）
?>
<!-- 周期管理 -->
<div class="okr-modal" id="okrModalCycle">
    <div class="okr-modal__dialog">
        <div class="okr-modal__header">
            <h3 id="cycleModalTitle">周期管理</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <div class="cycle-form-section">
                <div class="cycle-form-header">
                    <h4 id="cycleFormTitle">新建周期</h4>
                    <button class="okr-btn secondary small" data-action="new-cycle">+ 新建周期</button>
                </div>
                <form id="okrCycleForm" class="form-grid">
                    <input type="hidden" name="id" id="cycleFormId" value="">
                    <div>
                        <label>周期名称</label>
                        <input type="text" name="name" id="cycleFormName" placeholder="例如：2025 Q1" required>
                    </div>
                    <div>
                        <label>类型</label>
                        <select name="type" id="cycleFormType" required>
                            <option value="month">月度</option>
                            <option value="quarter">季度</option>
                            <option value="half">半年</option>
                            <option value="year">年度</option>
                            <option value="custom">自定义</option>
                        </select>
                    </div>
                    <div>
                        <label>开始日期</label>
                        <input type="date" name="start_date" id="cycleFormStartDate" required>
                    </div>
                    <div>
                        <label>结束日期</label>
                        <input type="date" name="end_date" id="cycleFormEndDate" required>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="okr-btn secondary" data-action="cancel-cycle-form">取消</button>
                        <button type="submit" class="okr-btn primary" data-action="save-cycle">保存</button>
                    </div>
                </form>
            </div>
            <div class="cycle-list-section">
                <div class="cycle-list-header">
                    <h4>周期列表</h4>
                </div>
                <div class="cycle-list" id="okrCycleList"></div>
            </div>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">关闭</button>
        </div>
    </div>
</div>

<!-- 周期选择模态框（左上角周期选择器使用） -->
<div class="okr-modal" id="okrModalCycleSelect">
    <div class="okr-modal__dialog" style="width: 480px;">
        <div class="okr-modal__header">
            <h3>选择周期</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <div class="form-group">
                <label class="form-label">周期类型</label>
                <div class="segment-control cycle-type-selector" style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <span class="segment-item" data-days="7">1周</span>
                    <span class="segment-item" data-days="14">2周</span>
                    <span class="segment-item active" data-days="30">1个月</span>
                    <span class="segment-item" data-days="90">3个月</span>
                    <span class="segment-item" data-days="120">4个月</span>
                    <span class="segment-item" data-days="180">半年</span>
                    <span class="segment-item" data-days="365">1年</span>
                    <span class="segment-item" data-days="custom">自定义</span>
                </div>
            </div>

            <div class="form-row" style="display: flex; gap: 16px; margin-top: 20px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">开始日期</label>
                    <input type="date" class="form-input" id="cycleSelectStart" value="">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">结束日期</label>
                    <input type="date" class="form-input" id="cycleSelectEnd" value="">
                </div>
            </div>

            <div class="form-group" style="margin-top: 20px;">
                <label class="form-label">快速选择</label>
                <div id="cycleQuickSelectList" style="display: flex; flex-direction: column; gap: 8px; margin-top: 8px;"></div>
            </div>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">取消</button>
            <button class="okr-btn primary" data-action="confirm-cycle-select">确定</button>
        </div>
    </div>
</div>

<!-- 新建 OKR -->
<div class="okr-modal" id="okrModalCreate">
    <div class="okr-modal__dialog okr-create-dialog">
        <div class="okr-modal__header">
            <h3>新建 OKR</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body okr-create-body">
            <form id="okrContainerForm">
                <!-- 对齐区域 -->
                <div class="okr-create-align-section">
                    <button type="button" class="okr-create-align-btn" data-action="open-align-modal">
                        <span class="align-icon">↳</span>
                        <span class="align-text">+ 添加对齐</span>
                    </button>
                    <div class="okr-create-align-list" id="okrAlignList"></div>
                </div>

                <!-- 目标输入区域 -->
                <div class="okr-create-objective-section">
                    <div class="okr-create-objective-input">
                        <span class="okr-badge-o" id="okrOBadge">O1</span>
                        <textarea name="objective_title" 
                                  placeholder="输入名称：目标表达精准，确保可被上下对齐" 
                                  required></textarea>
                    </div>
                </div>

                <!-- 设置区域 -->
                <div class="okr-create-settings">
                    <div class="okr-create-setting-row">
                        <span class="setting-label">目标周期</span>
                        <button type="button" class="setting-value clickable" data-action="open-cycle-select">
                            <span id="okrCreateCycleName">2025年第4季度</span>
                            <span class="chevron">›</span>
                        </button>
                    </div>
                    <div class="okr-create-setting-row">
                        <span class="setting-label">类型</span>
                        <div class="okr-level-tabs">
                            <button type="button" class="level-tab" data-level="company">公司级</button>
                            <button type="button" class="level-tab" data-level="department" <?= !$departmentId ? 'disabled' : '' ?>>部门级</button>
                            <button type="button" class="level-tab active" data-level="personal">个人级</button>
                        </div>
                        <input type="hidden" name="level" value="personal">
                    </div>
                    <div class="okr-create-setting-row">
                        <span class="setting-label">负责人</span>
                        <button type="button" class="setting-value clickable user-selector" data-action="open-user-select">
                            <span class="user-avatar-mini">
                                <?= mb_substr($userName, 0, 1) ?>
                            </span>
                            <span id="okrCreateUserName"><?= htmlspecialchars($userName) ?></span>
                            <span class="chevron">›</span>
                        </button>
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <input type="hidden" name="department_id" value="<?= $departmentId ? (int)$departmentId : '' ?>">
                    </div>
                </div>

                <!-- KR 列表 -->
                <div class="okr-create-kr-section" id="okrKrList"></div>

                <!-- 添加KR按钮 -->
                <button type="button" class="okr-create-add-kr-btn" data-action="add-kr-row">
                    + 添加关键结果
                </button>
            </form>
        </div>
        <div class="okr-modal__footer okr-create-footer">
            <div class="footer-left">
                <button type="button" class="footer-icon-btn" data-action="open-okr-more-settings" title="更多设置">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    <span>更多设置</span>
                </button>
                <button type="button" class="footer-icon-btn" data-action="open-template-center" title="模板中心">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    <span>模板中心</span>
                </button>
            </div>
            <div class="footer-right">
                <button class="okr-btn secondary" data-action="close-modal">取消</button>
                <button class="okr-btn primary" data-action="submit-create-okr">提交</button>
            </div>
        </div>
    </div>
</div>

<!-- 对齐选择弹窗 -->
<div class="okr-modal" id="okrModalAlign">
    <div class="okr-modal__dialog okr-align-dialog">
        <div class="okr-modal__header okr-align-header">
            <button class="modal-back" data-action="close-modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <div class="align-header-center">
                <span id="okrAlignCycleName">2025年第4季度</span>
                <span class="dropdown-icon">▼</span>
            </div>
            <div></div>
        </div>
        <div class="okr-align-toolbar">
            <button type="button" class="align-filter-btn active" data-align-filter="superior">直属上级</button>
            <div class="align-selected-count">
                已对齐(<span id="okrAlignCount">0</span>)
                <span class="chevron">›</span>
            </div>
        </div>
        <div class="okr-modal__body okr-align-body" id="okrAlignListBody">
            <!-- 动态渲染对齐列表 -->
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">取消</button>
            <button class="okr-btn primary" data-action="confirm-align">确定</button>
        </div>
    </div>
</div>

<!-- KR更多设置弹窗 -->
<div class="okr-modal" id="okrModalKrSettings">
    <div class="okr-modal__dialog okr-kr-settings-dialog">
        <div class="okr-modal__header okr-kr-settings-header">
            <button class="modal-back" data-action="close-modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <h3>添加关键结果</h3>
            <div></div>
        </div>
        <div class="okr-modal__body okr-kr-settings-body">
            <div class="kr-settings-name">
                <textarea id="krSettingsTitle" placeholder="输入KR名称：KR要写工作结果（做到什么），不能只写动作描述（做什么）"></textarea>
            </div>
            <div class="kr-settings-section">
                <label>信心指数</label>
                <div class="kr-confidence-slider">
                    <input type="range" id="krSettingsConfidence" min="1" max="10" value="5">
                    <span class="confidence-value"><span id="krConfidenceValue">5</span>/10</span>
                </div>
            </div>
            <div class="kr-settings-row">
                <span class="setting-label">权重(%)</span>
                <button type="button" class="setting-value clickable" id="krSettingsWeightBtn">
                    <span id="krSettingsWeightValue">50</span>
                    <span class="chevron">›</span>
                </button>
            </div>
            <div class="kr-settings-divider"></div>
            <div class="kr-settings-row">
                <span class="setting-label">单位</span>
                <button type="button" class="setting-value clickable" id="krSettingsUnitBtn">
                    <span id="krSettingsUnitValue">%</span>
                </button>
            </div>
            <div class="kr-settings-row">
                <span class="setting-label">起始值(%)</span>
                <input type="number" class="setting-input" id="krSettingsStartValue" value="0">
            </div>
            <div class="kr-settings-row">
                <span class="setting-label">目标值(%)</span>
                <input type="number" class="setting-input" id="krSettingsTargetValue" value="100">
            </div>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn primary full-width" data-action="save-kr-settings">保存</button>
        </div>
    </div>
</div>

<!-- 用户选择弹窗 -->
<div class="okr-modal" id="okrModalUserSelect">
    <div class="okr-modal__dialog okr-select-dialog">
        <div class="okr-modal__header">
            <h3>选择负责人</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <div class="user-select-list" id="okrUserSelectList">
                <?php foreach ($userOptions as $option): ?>
                    <div class="user-select-item" data-user-id="<?= (int)$option['id'] ?>" data-user-name="<?= htmlspecialchars($option['name'] ?: ('用户#' . $option['id'])) ?>">
                        <span class="user-avatar-mini"><?= mb_substr($option['name'] ?: '?', 0, 1) ?></span>
                        <span class="user-name"><?= htmlspecialchars($option['name'] ?: ('用户#' . $option['id'])) ?></span>
                        <span class="check-icon" style="display:none;">✓</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 新建任务 -->
<div class="okr-modal" id="okrModalTask">
    <div class="okr-modal__dialog">
        <div class="okr-modal__header">
            <h3>新建任务</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <form id="okrTaskForm">
                <div class="form-grid">
                    <div>
                        <label>任务标题</label>
                        <input type="text" name="title" required placeholder="输入任务">
                    </div>
                    <div>
                        <label>负责人</label>
                        <select name="executor_id" required>
                            <?php foreach ($userOptions as $option): ?>
                                <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === (int)$user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option['name'] ?: ('用户#' . $option['id'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>优先级</label>
                        <select name="priority">
                            <option value="high">高</option>
                            <option value="medium" selected>中</option>
                            <option value="low">低</option>
                        </select>
                    </div>
                    <div>
                        <label>状态</label>
                        <select name="status">
                            <option value="pending">待处理</option>
                            <option value="in_progress">进行中</option>
                            <option value="completed">已完成</option>
                            <option value="failed">未达成</option>
                        </select>
                    </div>
                    <div>
                        <label>开始日期</label>
                        <input type="date" name="start_date">
                    </div>
                    <div>
                        <label>截止日期</label>
                        <input type="date" name="due_date">
                    </div>
                    <div>
                        <label>关联 KR</label>
                        <select name="relation_kr" id="okrTaskRelationSelect">
                            <option value="">-- 选择 KR --</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:20px;">
                    <label>任务描述</label>
                    <textarea name="description" placeholder="补充说明、成功标准等"></textarea>
                </div>
                <div style="margin-top:20px;">
                    <label>录音 / 附件</label>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="button" class="okr-btn secondary" disabled>🎙️ 录音入口</button>
                        <button type="button" class="okr-btn secondary" disabled>📎 附件入口</button>
                        <div style="flex-basis:100%; font-size:12px; color:var(--text-secondary);">
                            预留占位，后续接入语音与附件上传模块，保持与原型图一致。
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">取消</button>
            <button class="okr-btn primary" data-action="submit-task">保存任务</button>
        </div>
    </div>
</div>

<!-- 进度更新 -->
<div class="okr-modal" id="okrModalProgress">
    <div class="okr-modal__dialog">
        <div class="okr-modal__header">
            <h3>批量更新 KR 进度</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <div id="okrProgressList"></div>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">取消</button>
            <button class="okr-btn primary" data-action="submit-progress">更新进度</button>
        </div>
    </div>
</div>

<!-- 评论 -->
<div class="okr-modal" id="okrModalComment">
    <div class="okr-modal__dialog">
        <div class="okr-modal__header">
            <h3>评论与记录</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body">
            <div id="okrCommentList" class="comment-list"></div>
            <form id="okrCommentForm" style="margin-top:16px;">
                <textarea name="content" placeholder="输入评论，支持 @ 提及" required></textarea>
            </form>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">关闭</button>
            <button class="okr-btn primary" data-action="submit-comment">发表评论</button>
        </div>
    </div>
</div>

<!-- 任务选择弹窗 -->
<div class="okr-modal" id="okrModalTaskSelect">
    <div class="okr-modal__dialog okr-task-select-dialog">
        <div class="okr-modal__header">
            <h3>关联任务</h3>
            <button class="modal-close" data-action="close-modal">&times;</button>
        </div>
        <div class="okr-modal__body okr-task-select-body">
            <div class="task-select-toolbar">
                <button type="button" class="task-select-btn primary" data-action="create-task-for-kr">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>新建任务</span>
                </button>
                <div class="task-select-search">
                    <input type="text" id="taskSelectSearch" placeholder="搜索任务...">
                </div>
            </div>
            <div class="task-select-list" id="taskSelectList">
                <div class="task-select-loading">加载中...</div>
            </div>
        </div>
        <div class="okr-modal__footer">
            <button class="okr-btn secondary" data-action="close-modal">取消</button>
            <button class="okr-btn primary" data-action="confirm-task-select">确定</button>
        </div>
    </div>
</div>

<!-- KR模板 -->
<template id="okrKrTemplate">
    <div class="kr-editor-item" data-kr-row>
        <div class="kr-editor-header">
            <span class="kr-badge">KR1</span>
            <textarea name="kr_title[]" placeholder="输入KR名称：KR要写工作结果（做到什么），不能只写动作描述（做什么）" required></textarea>
        </div>
        <div class="kr-editor-actions">
            <button type="button" class="kr-action-btn" data-action="kr-confidence">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                    <line x1="9" y1="9" x2="9.01" y2="9"/>
                    <line x1="15" y1="9" x2="15.01" y2="9"/>
                </svg>
                <span class="kr-confidence-display">100%</span>
            </button>
            <button type="button" class="kr-action-btn" data-action="kr-link-tasks">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 11l3 3L22 4"/>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                <span class="kr-tasks-count">关联任务</span>
            </button>
            <button type="button" class="kr-action-btn" data-action="open-kr-settings">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <span>更多设置</span>
            </button>
            <button type="button" class="kr-action-btn delete" data-action="remove-kr-row">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
        </div>
        <!-- 隐藏字段存储KR设置 -->
        <input type="hidden" name="kr_target[]" value="100">
        <input type="hidden" name="kr_unit[]" value="%">
        <input type="hidden" name="kr_weight[]" value="25">
        <input type="hidden" name="kr_confidence[]" value="5">
        <input type="hidden" name="kr_task_ids[]" value="" class="kr-task-ids-input">
    </div>
</template>

