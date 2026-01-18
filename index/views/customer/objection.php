<?php
// 异议处理模块视图
// 加载异议处理记录
$objections = [];
$latestObjection = null;
if ($customer) {
    $objections = Db::query('SELECT * FROM objection WHERE customer_id = :id ORDER BY create_time DESC', ['id' => $customer['id']]);
    // 获取最新的异议处理记录用于表单回填
    if (!empty($objections)) {
        $latestObjection = $objections[0];
    }
}
?>

<!-- 显示客户已有信息（紧凑卡片） -->
<div class="card mb-3" style="border-left: 4px solid #0d6efd;">
    <div class="card-body p-3">
        <h6 class="mb-2" style="color: #0d6efd;"><strong>👤 客户信息</strong></h6>
        <div class="row small">
            <div class="col-md-4">
                <strong>姓名：</strong><?= htmlspecialchars($customer['name'] ?? '') ?><br>
                <strong>手机：</strong><?= htmlspecialchars($customer['mobile'] ?? '') ?>
            </div>
            <div class="col-md-4">
                <strong>身份：</strong><?= htmlspecialchars($customer['identity'] ?? '') ?><br>
                <strong>需求时间：</strong><?= htmlspecialchars($customer['demand_time_type'] ?? '') ?>
            </div>
            <div class="col-md-4">
                <?php if ($firstContact): ?>
                <strong>关键疑问：</strong><?= htmlspecialchars($firstContact['key_questions'] ?? '无') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 新增处理方案 -->
<div class="card" style="border-left: 4px solid #28a745;">
    <div class="card-body p-3">
        <h6 class="mb-3" style="color: #28a745;"><strong>✍️ 新增处理方案</strong></h6>

<!-- 处理方法 -->
<div class="field-row">
    <div class="field-label">处理方法</div>
    <div class="field-options">
        <?php
        $methods = [
            '五步法',
            '一步法',
            '镜像法',
            '房子法',
            '转化法',
            '拆分法'
        ];
        // 从最新的异议处理记录获取已选择的方法
        $selectedMethods = [];
        if ($latestObjection && $latestObjection['method']) {
            $selectedMethods = explode('、', $latestObjection['method']);
        }
        
        foreach ($methods as $method):
            $checked = in_array($method, $selectedMethods) ? 'checked' : '';
        ?>
        <label>
            <input type="checkbox" name="handling_methods[]" value="<?= $method ?>" 
                   <?= $checked ?> <?= $isReadonly ? 'disabled' : '' ?>> <?= $method ?>
        </label>
        <?php endforeach; ?>
        <label>自定义 
            <input type="text" name="method_custom" class="form-control form-control-sm d-inline-block" 
                   style="width:150px;margin-left:5px;" placeholder="输入其他方法" 
                   <?= $isReadonly ? 'readonly' : '' ?>>
        </label>
    </div>
</div>

<!-- 话术和处理内容 -->
<div class="field-row" style="flex: 1; align-items: stretch;">
    <div class="field-label">
        我的话术方案<br><small class="text-muted" style="font-weight:normal;font-size:14px;">支持Markdown</small>
        <div id="objection-attachment-upload" style="margin-top: 8px;"></div>
    </div>
    <div class="field-options" style="display: flex; flex: 1; flex-direction: column;">
        <textarea name="solution" class="form-control" 
                  style="height: 100%; min-height: 300px; flex: 1;" 
                  placeholder="详细记录处理话术和方法... 支持Markdown格式" 
                  <?= $isReadonly ? 'readonly' : '' ?>><?= htmlspecialchars($latestObjection['response_script'] ?? '') ?></textarea>
    </div>
</div>

    </div>
</div>


<!-- 异议处理历史记录（放在最下面） -->
<?php if (!empty($objections)): ?>
<div class="mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0">📚 历史异议处理记录 (<?= count($objections) ?>条)</h6>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleHistory()">
            <span id="toggleIcon">▲</span> <span id="toggleText">收起</span>
        </button>
    </div>
    <div id="historyRecords" style="max-height: 400px; overflow-y: auto;">
        <?php foreach ($objections as $obj): ?>
        <div class="card mb-3 objection-record" id="record-<?= $obj['id'] ?>">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div>
                    <strong style="font-size: 15px;">📌 <?= htmlspecialchars($obj['method']) ?></strong>
                    <small class="text-muted ms-2"><?= date('Y-m-d H:i', $obj['create_time']) ?></small>
                </div>
                <?php if (!$isReadonly): ?>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editObjection(<?= $obj['id'] ?>)">
                        ✏️ 编辑
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteObjection(<?= $obj['id'] ?>)">
                        🗑️ 删除
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="objection-content">
                    <pre style="white-space: pre-wrap; font-family: inherit; margin: 0;"><?= htmlspecialchars($obj['response_script']) ?></pre>
                </div>
                <div class="objection-edit" style="display: none;">
                    <textarea class="form-control mb-2" id="edit-script-<?= $obj['id'] ?>" rows="8"><?= htmlspecialchars($obj['response_script']) ?></textarea>
                    <div>
                        <button type="button" class="btn btn-sm btn-success" onclick="saveEdit(<?= $obj['id'] ?>)">💾 保存修改</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $obj['id'] ?>)">取消</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>
