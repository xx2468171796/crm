<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dict.php';

auth_require();
$user = current_user();

layout_header('收款台');

// 获取所有启用的支付方式及手续费配置
$paymentMethods = getPaymentMethodsWithFee();
?>

<style>
.cashier-container {
    max-width: 800px;
    margin: 0 auto;
}
.payment-method-card {
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
}
.payment-method-card:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
}
.payment-method-card.selected {
    border-color: #0d6efd;
    background-color: #e7f1ff;
}
.fee-badge {
    font-size: 0.75rem;
}
.result-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
.result-amount {
    font-size: 2.5rem;
    font-weight: bold;
}
.amount-input {
    font-size: 1.5rem;
    text-align: center;
    font-weight: bold;
}
</style>

<div class="cashier-container">
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>收款台</h5>
        </div>
        <div class="card-body">
            <!-- 输入金额 -->
            <div class="mb-4">
                <label class="form-label fw-bold">输入原始金额</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text">¥</span>
                    <input type="number" step="0.01" min="0" class="form-control amount-input" id="originalAmount" placeholder="0.00" autofocus>
                </div>
            </div>
            
            <!-- 选择支付方式 -->
            <div class="mb-4">
                <label class="form-label fw-bold">选择支付方式</label>
                <div class="row g-3" id="paymentMethodsContainer">
                    <?php foreach ($paymentMethods as $method): ?>
                    <div class="col-md-4 col-6">
                        <div class="card payment-method-card h-100" 
                             data-code="<?= htmlspecialchars($method['dict_code']) ?>"
                             data-fee-type="<?= htmlspecialchars($method['fee_type'] ?? '') ?>"
                             data-fee-value="<?= htmlspecialchars($method['fee_value'] ?? '0') ?>">
                            <div class="card-body text-center py-3">
                                <div class="fw-bold"><?= htmlspecialchars($method['dict_label']) ?></div>
                                <?php if (!empty($method['fee_type']) && $method['fee_value'] > 0): ?>
                                    <?php if ($method['fee_type'] === 'fixed'): ?>
                                        <span class="badge bg-warning fee-badge">+<?= number_format($method['fee_value'], 2) ?>元</span>
                                    <?php elseif ($method['fee_type'] === 'percent'): ?>
                                        <span class="badge bg-info fee-badge">+<?= number_format($method['fee_value'] * 100, 2) ?>%</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary fee-badge">无手续费</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 手续费调整 -->
            <div class="mb-4" id="feeAdjustDiv" style="display:none;">
                <label class="form-label fw-bold">手续费金额 <small class="text-muted fw-normal">(可手动调整)</small></label>
                <div class="input-group">
                    <span class="input-group-text">¥</span>
                    <input type="number" step="0.01" min="0" class="form-control" id="feeAmount" placeholder="0.00">
                    <button type="button" class="btn btn-outline-secondary" id="btnResetFee" title="重置为自动计算值">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>
                <small class="text-muted" id="feeHint"></small>
            </div>
        </div>
    </div>
    
    <!-- 计算结果 -->
    <div class="card result-card mb-4" id="resultCard" style="display:none;">
        <div class="card-body text-center py-4">
            <div class="row">
                <div class="col-4">
                    <div class="text-white-50 small">原始金额</div>
                    <div class="fs-4" id="displayOriginal">¥0.00</div>
                </div>
                <div class="col-4">
                    <div class="text-white-50 small">手续费</div>
                    <div class="fs-4" id="displayFee">+¥0.00</div>
                </div>
                <div class="col-4">
                    <div class="text-white-50 small">实收金额</div>
                    <div class="result-amount" id="displayTotal">¥0.00</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 快捷金额按钮 -->
    <div class="card mb-4">
        <div class="card-body">
            <label class="form-label fw-bold">快捷金额</label>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="100">¥100</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="200">¥200</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="500">¥500</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="1000">¥1000</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="2000">¥2000</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="5000">¥5000</button>
                <button type="button" class="btn btn-outline-primary quick-amount" data-amount="10000">¥10000</button>
                <button type="button" class="btn btn-outline-secondary" id="btnClear">清空</button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedMethod = null;
let autoFeeAmount = 0;
let feeManuallyEdited = false;

function fmt(n) {
    return Number(n || 0).toFixed(2);
}

// 选择支付方式
document.querySelectorAll('.payment-method-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        
        selectedMethod = {
            code: this.dataset.code,
            feeType: this.dataset.feeType,
            feeValue: parseFloat(this.dataset.feeValue) || 0
        };
        
        feeManuallyEdited = false;
        updateCalculation();
    });
});

// 输入金额变化
document.getElementById('originalAmount').addEventListener('input', function() {
    feeManuallyEdited = false;
    updateCalculation();
});

// 手续费手动调整
document.getElementById('feeAmount').addEventListener('input', function() {
    feeManuallyEdited = true;
    updateDisplay();
});

// 重置手续费
document.getElementById('btnResetFee').addEventListener('click', function() {
    feeManuallyEdited = false;
    document.getElementById('feeAmount').value = fmt(autoFeeAmount);
    updateDisplay();
});

// 快捷金额
document.querySelectorAll('.quick-amount').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('originalAmount').value = this.dataset.amount;
        feeManuallyEdited = false;
        updateCalculation();
    });
});

// 清空
document.getElementById('btnClear').addEventListener('click', function() {
    document.getElementById('originalAmount').value = '';
    document.getElementById('feeAmount').value = '';
    document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
    selectedMethod = null;
    feeManuallyEdited = false;
    document.getElementById('resultCard').style.display = 'none';
    document.getElementById('feeAdjustDiv').style.display = 'none';
});

// 计算手续费
function updateCalculation() {
    const originalAmount = parseFloat(document.getElementById('originalAmount').value) || 0;
    
    if (!selectedMethod || originalAmount <= 0) {
        document.getElementById('resultCard').style.display = 'none';
        document.getElementById('feeAdjustDiv').style.display = 'none';
        return;
    }
    
    // 计算自动手续费
    autoFeeAmount = 0;
    let feeHint = '';
    
    if (selectedMethod.feeType === 'fixed' && selectedMethod.feeValue > 0) {
        autoFeeAmount = selectedMethod.feeValue;
        feeHint = '固定手续费 ' + fmt(selectedMethod.feeValue) + ' 元';
    } else if (selectedMethod.feeType === 'percent' && selectedMethod.feeValue > 0) {
        autoFeeAmount = originalAmount * selectedMethod.feeValue;
        feeHint = '按 ' + (selectedMethod.feeValue * 100).toFixed(2) + '% 计算';
    } else {
        feeHint = '该支付方式无手续费';
    }
    
    // 更新手续费输入框
    if (!feeManuallyEdited) {
        document.getElementById('feeAmount').value = fmt(autoFeeAmount);
    }
    document.getElementById('feeHint').textContent = feeHint;
    
    // 显示手续费调整区域
    document.getElementById('feeAdjustDiv').style.display = '';
    
    updateDisplay();
}

// 更新显示
function updateDisplay() {
    const originalAmount = parseFloat(document.getElementById('originalAmount').value) || 0;
    const feeAmount = parseFloat(document.getElementById('feeAmount').value) || 0;
    const totalAmount = originalAmount + feeAmount;
    
    if (originalAmount <= 0 || !selectedMethod) {
        document.getElementById('resultCard').style.display = 'none';
        return;
    }
    
    document.getElementById('displayOriginal').textContent = '¥' + fmt(originalAmount);
    document.getElementById('displayFee').textContent = '+¥' + fmt(feeAmount);
    document.getElementById('displayTotal').textContent = '¥' + fmt(totalAmount);
    document.getElementById('resultCard').style.display = '';
}

// 默认选中第一个支付方式
const firstCard = document.querySelector('.payment-method-card');
if (firstCard) {
    firstCard.click();
}
</script>

<?php layout_footer(); ?>
