<!-- 首通模块内容 - 完全动态加载版本 -->

<?php
// 引入字段渲染器
require_once __DIR__ . '/../../core/field_renderer.php';

// 准备字段值数组（用于回显）
$fieldValues = [];
if ($firstContact) {
    // 从 first_contact 表加载所有字段值（兼容旧字段）
    foreach ($firstContact as $key => $value) {
        $fieldValues[$key] = $value;
    }
    
    // 从新三层结构字段值表加载动态字段值
    $firstContactId = $firstContact['id'] ?? 0;
    if ($firstContactId > 0) {
        $dimensionValues = loadDimensionFieldValues('first_contact', $firstContactId);
        // 合并维度字段值（维度字段值优先，覆盖旧字段值）
        $fieldValues = array_merge($fieldValues, $dimensionValues);
    }
}

// 使用动态渲染函数渲染所有字段
echo renderModuleFields('first_contact', $fieldValues);
?>

<!-- 下次跟进时间 -->
<div class="field-row">
    <div class="field-label">下次跟进时间</div>
    <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
        <div>
            <?php
            // 计算默认时间（明天）
            $defaultTime = $firstContact && $firstContact['next_follow_time'] 
                ? date('Y-m-d\TH:i', $firstContact['next_follow_time']) 
                : date('Y-m-d\TH:i', strtotime('+1 day'));
            ?>
            <input type="datetime-local" name="next_follow_time" class="form-control form-control-sm" 
                   style="width:220px;" 
                   value="<?= $defaultTime ?>">
            <small class="text-muted">默认为明天</small>
        </div>
        <?php if (!$isReadonly): ?>
        <!-- 录音功能 -->
        <div id="recording-section" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <!-- 开始录音按钮（始终显示） -->
            <button type="button" class="recording-btn recording-btn-start" id="recordBtn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;">
                    <path d="M12 2C10.34 2 9 3.34 9 5v6c0 1.66 1.34 3 3 3s3-1.34 3-3V5c0-1.66-1.34-3-3-3zm0 16c-2.76 0-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-1.08c3.39-.49 6-3.39 6-6.92h-2c0 2.76-2.24 5-5 5z"/>
                </svg>
                <span>开始录音</span>
            </button>
            
            <!-- 录音计时器（录音时显示） -->
            <div id="recording-status" class="recording-status-active" style="display: none; align-items: center; gap: 8px;">
                <!-- 闪烁的红点指示器 -->
                <span class="recording-dot"></span>
                <!-- 状态文本 -->
                <span class="recording-status-text">正在录音</span>
                <!-- 录音计时器 -->
                <span id="recording-timer" class="recording-timer">00:00</span>
            </div>
            
            <!-- 停止录音按钮（录音时显示） -->
            <button type="button" class="recording-btn recording-btn-stop" id="stopRecordBtn" style="display: none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink: 0;">
                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                </svg>
                <span>停止录音</span>
            </button>
        </div>
        <style>
            /* ========== iOS风格录音按钮 ========== */
            .recording-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 10px 16px;
                border-radius: 10px;
                border: none;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
                -webkit-tap-highlight-color: transparent;
                min-height: 44px;
            }
            
            .recording-btn:active {
                transform: scale(0.96);
            }
            
            .recording-btn-start {
                background: #007AFF;
                color: white;
                box-shadow: 0 2px 8px rgba(0, 122, 255, 0.3);
            }
            
            .recording-btn-start:hover {
                background: #0056b3;
                box-shadow: 0 4px 12px rgba(0, 122, 255, 0.4);
            }
            
            .recording-btn-start:active {
                background: #004085;
            }
            
            .recording-btn-stop {
                background: #FF3B30;
                color: white;
                box-shadow: 0 2px 8px rgba(255, 59, 48, 0.3);
                padding: 8px 14px;
                font-size: 14px;
            }
            
            .recording-btn-stop:hover {
                background: #d63031;
                box-shadow: 0 4px 12px rgba(255, 59, 48, 0.4);
            }
            
            .recording-btn-stop:active {
                background: #c62828;
            }
            
            /* 录音状态显示 */
            .recording-status-active {
                display: flex !important;
                align-items: center;
                gap: 10px;
                padding: 10px 16px;
                background: rgba(255, 59, 48, 0.1);
                border: 1.5px solid rgba(255, 59, 48, 0.3);
                border-radius: 10px;
                flex-wrap: wrap;
            }
            
            .recording-dot {
                display: inline-block;
                width: 12px;
                height: 12px;
                background: #FF3B30;
                border-radius: 50%;
                animation: recording-blink 1.2s infinite;
                flex-shrink: 0;
                box-shadow: 0 0 8px rgba(255, 59, 48, 0.6);
            }
            
            .recording-status-text {
                color: #FF3B30;
                font-size: 14px;
                font-weight: 600;
                letter-spacing: 0.2px;
            }
            
            .recording-timer {
                font-weight: 700;
                color: #FF3B30;
                font-size: 16px;
                min-width: 60px;
                font-family: 'SF Mono', 'Monaco', 'Courier New', monospace;
                letter-spacing: 1px;
            }
            
            @keyframes recording-blink {
                0%, 100% { 
                    opacity: 1;
                    transform: scale(1);
                }
                50% { 
                    opacity: 0.4;
                    transform: scale(0.8);
                }
            }
        </style>
        <?php endif; ?>
        <div id="first-contact-attachment-upload" style="flex: 1; min-width: 200px;"></div>
    </div>
</div>

<!-- 首通备注 -->
<div class="field-row" style="flex: 1; align-items: stretch;">
    <div class="field-label">
        首通备注<br><small class="text-muted" style="font-weight:normal;font-size:14px;">支持Markdown</small>
    </div>
    <div class="field-options" style="display: flex; flex: 1; flex-direction: column;">
        <textarea name="remark" class="form-control remark-box" style="height: 100%; min-height: 300px; flex: 1;" placeholder="记录沟通要点... 支持Markdown格式"><?= $firstContact ? htmlspecialchars($firstContact['remark']) : '' ?></textarea>
    </div>
</div>

<!-- 独立文件管理页面按钮和意向总结 -->
<?php if ($customer && isset($customer['id']) && $customer['id'] > 0): ?>
<div class="intent-box">
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
        <a href="file_manager.php?customer_id=<?= $customer['id'] ?>" class="btn btn-outline-primary btn-sm" target="_blank" style="flex-shrink: 0;">独立文件管理页面</a>
        <h6 class="mb-0">📊 意向总结</h6>
    </div>
    <?php if ($customer['intent_summary'] ?? ''): ?>
    <p class="mb-0"><?= htmlspecialchars($customer['intent_summary']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>
