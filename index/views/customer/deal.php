<?php
// 敲定成交模块视图

// 加载敲定成交记录
$dealRecord = null;
if ($customer) {
    $dealRecord = Db::queryOne('SELECT * FROM deal_record WHERE customer_id = :id', ['id' => $customer['id']]);
}

// 定义任务清单结构
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
?>

<style>
.deal-container {
    display: flex;
    flex-direction: column;
    gap: 15px;
    height: 100%;
    overflow: hidden;
}

.deal-table-wrapper {
    flex: 1;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.deal-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

.deal-table th {
    background: #f8f9fa;
    padding: 8px 10px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
    font-size: 14px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.deal-table td {
    padding: 6px 10px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 14px;
}

.deal-table tbody tr {
    cursor: pointer;
}

.deal-table tbody tr:hover {
    background: #f8f9fa;
}

.category-cell {
    font-weight: 600;
    color: #495057;
    width: 100px;
    background: #f8f9fa;
    font-size: 13px;
}

.task-cell {
    font-size: 13px;
    color: #333;
}

.checkbox-cell {
    text-align: center;
    width: 60px;
}

.checkbox-cell input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.notes-cell {
    width: 280px;
}

.notes-input {
    width: 100%;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 13px;
}

.notes-input:focus {
    outline: none;
    border-color: #0d6efd;
}

.other-notes-section {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px;
}

.other-notes-section label {
    font-weight: 600;
    color: #495057;
    font-size: 14px;
    margin-bottom: 8px;
    display: block;
}

.other-notes-textarea {
    width: 100%;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 8px;
    font-size: 13px;
    resize: vertical;
    min-height: 60px;
    max-height: 120px;
}

.other-notes-textarea:focus {
    outline: none;
    border-color: #0d6efd;
}
</style>

<div class="deal-container">
    <div class="deal-table-wrapper">
        <table class="deal-table">
            <thead>
                <tr>
                    <th>分类</th>
                    <th>任务项目</th>
                    <th>勾选</th>
                    <th>备注</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($taskCategories as $category => $tasks): ?>
                    <?php $firstTask = true; ?>
                    <?php foreach ($tasks as $field => $label): ?>
                    <tr onclick="toggleCheckbox(this, event)" data-field="<?= $field ?>">
                        <?php if ($firstTask): ?>
                        <td class="category-cell" rowspan="<?= count($tasks) ?>"><?= $category ?></td>
                        <?php $firstTask = false; ?>
                        <?php endif; ?>
                        
                        <td class="task-cell"><?= $label ?></td>
                        
                        <td class="checkbox-cell">
                            <input type="checkbox" 
                                   name="<?= $field ?>" 
                                   value="1"
                                   id="checkbox_<?= $field ?>"
                                   <?= ($dealRecord && $dealRecord[$field]) ? 'checked' : '' ?>
                                   <?= $isReadonly ? 'disabled' : '' ?>>
                        </td>
                        
                        <td class="notes-cell">
                            <input type="text" 
                                   class="notes-input" 
                                   name="note_<?= $field ?>" 
                                   placeholder="备注"
                                   value="<?= $dealRecord && isset($dealRecord['note_' . $field]) ? htmlspecialchars($dealRecord['note_' . $field]) : '' ?>"
                                   <?= $isReadonly ? 'readonly' : '' ?>
                                   onclick="event.stopPropagation()">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- 其他待办事项 -->
    <div class="other-notes-section">
        <label>📝 请输入其他待办事项</label>
        <textarea name="other_notes" 
                  class="other-notes-textarea" 
                  placeholder="记录其他需要跟进的事项..."
                  <?= $isReadonly ? 'readonly' : '' ?>><?= $dealRecord ? htmlspecialchars($dealRecord['other_notes'] ?? '') : '' ?></textarea>
    </div>
</div>

<script>
// 点击整行切换checkbox
function toggleCheckbox(row, event) {
    // 如果是只读模式，不处理
    <?php if ($isReadonly): ?>
    return;
    <?php endif; ?>
    
    // 如果点击的是备注输入框，不处理
    if (event.target.classList.contains('notes-input')) {
        return;
    }
    
    // 获取checkbox
    const field = row.getAttribute('data-field');
    const checkbox = document.getElementById('checkbox_' + field);
    
    if (checkbox && !checkbox.disabled) {
        checkbox.checked = !checkbox.checked;
    }
}
</script>
