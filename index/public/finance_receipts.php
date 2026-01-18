<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅财务/管理员可进行收款登记。</div>';
    layout_footer();
    exit;
}

layout_header('收款登记');

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">收款登记</h3>
    <div>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">返回财务工作台</a>
    </div>
</div>

<div class="card mb-2">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted">分期ID</label>
                <input type="number" class="form-control form-control-sm" id="installmentId" placeholder="请输入分期ID" min="1" style="width:120px;">
            </div>
            <button class="btn btn-primary btn-sm" id="btnLoad">查询分期</button>
            <button class="btn btn-outline-secondary btn-sm" id="btnClear">清空</button>
            <span class="small text-muted">收款必须选择单个分期；允许超收并自动转为客户预收款</span>
        </div>
    </div>
</div>

<div class="card mb-3" id="infoCard" style="display:none;">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="fw-semibold">客户</div>
                <div id="infoCustomer"></div>
                <div class="small text-muted" id="infoCustomerSub"></div>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold">合同</div>
                <div id="infoContract"></div>
                <div class="small text-muted" id="infoContractSub"></div>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold">分期</div>
                <div id="infoInstallment"></div>
                <div class="small text-muted" id="infoInstallmentSub"></div>
            </div>
        </div>
        <hr>
        <div class="row g-3">
            <div class="col-md-2">
                <div class="fw-semibold">分期货币</div>
                <div id="infoInstCurrency"></div>
            </div>
            <div class="col-md-2">
                <div class="fw-semibold">应收</div>
                <div id="infoAmountDue"></div>
                <div class="small text-muted" id="infoAmountDueConverted"></div>
            </div>
            <div class="col-md-2">
                <div class="fw-semibold">已收</div>
                <div id="infoAmountPaid"></div>
            </div>
            <div class="col-md-2">
                <div class="fw-semibold">未收</div>
                <div id="infoAmountUnpaid"></div>
                <div class="small text-muted" id="infoAmountUnpaidConverted"></div>
            </div>
            <div class="col-md-2">
                <div class="fw-semibold">合同货币</div>
                <div id="infoContractCurrency"></div>
            </div>
            <div class="col-md-2">
                <div class="fw-semibold">状态</div>
                <div id="infoStatus"></div>
            </div>
        </div>
    </div>
</div>

<div class="card" id="formCard" style="display:none;">
    <div class="card-body">
        <form id="receiptForm">
            <input type="hidden" name="installment_id" id="formInstallmentId">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">收款日期</label>
                    <input type="date" class="form-control" name="received_date" id="receivedDate" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">实收金额</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="amount_received" id="amountReceived" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">收款方式</label>
                    <select class="form-select" name="method" id="method">
                        <?= renderPaymentMethodOptions() ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">收款货币</label>
                    <select class="form-select" name="receive_currency" id="receiveCurrency">
                        <option value="TWD" selected>TWD（新台币）</option>
                        <option value="CNY">CNY（人民币）</option>
                        <option value="USD">USD（美元）</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">汇率类型</label>
                    <select class="form-select" name="exchange_rate_type" id="exchangeRateType">
                        <option value="fixed" selected>固定汇率</option>
                        <option value="floating">浮动汇率</option>
                    </select>
                </div>
                <div class="col-md-3" id="floatingRateDiv" style="display:none;">
                    <label class="form-label">实际汇率</label>
                    <input type="number" step="0.0001" min="0" class="form-control" name="exchange_rate" id="exchangeRate" placeholder="输入当时汇率">
                </div>
                <div class="col-md-12">
                    <label class="form-label">备注</label>
                    <input type="text" class="form-control" name="note" id="note" maxlength="255" placeholder="可选，最多255字">
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-success" id="btnSubmit">提交收款</button>
                </div>
            </div>
        </form>
        <div class="small text-muted mt-2" id="calcHint"></div>
    </div>
</div>

<script>
function apiUrl(path) {
    return API_URL + '/' + path;
}

function fmt(n) {
    const x = Number(n || 0);
    return x.toFixed(2);
}

const exchangeRates = { TWD: 1.0, CNY: 4.5, USD: 32.0 };
function convertCurrency(amount, from, to) {
    if (from === to) return amount;
    const fromRate = exchangeRates[from] || 1.0;
    const toRate = exchangeRates[to] || 1.0;
    return amount * fromRate / toRate;
}

function setToday(el) {
    const d = new Date();
    const pad = (v) => v.toString().padStart(2, '0');
    el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

let currentInstallment = null;

function clearAll() {
    document.getElementById('installmentId').value = '';
    document.getElementById('infoCard').style.display = 'none';
    document.getElementById('formCard').style.display = 'none';
    document.getElementById('receiptForm').reset();
    document.getElementById('calcHint').textContent = '';
    currentInstallment = null;
}

function renderInfo(data) {
    document.getElementById('infoCustomer').textContent = (data.customer_name || '') + (data.activity_tag ? ('（' + data.activity_tag + '）') : '');
    document.getElementById('infoCustomerSub').textContent = (data.customer_code || '') + ' ' + (data.customer_mobile || '');
    document.getElementById('infoContract').textContent = data.contract_no || '';
    document.getElementById('infoContractSub').textContent = (data.contract_title || '') + (data.sales_name ? (' | 签约人：' + data.sales_name) : '');
    document.getElementById('infoInstallment').textContent = '第 ' + (data.installment_no || '') + ' 期 / ID=' + (data.installment_id || '');
    document.getElementById('infoInstallmentSub').textContent = '到期日：' + (data.due_date || '');
    const instCurrency = data.installment_currency || 'TWD';
    const contractCurrency = data.contract_currency || 'TWD';
    document.getElementById('infoInstCurrency').textContent = instCurrency;
    document.getElementById('infoContractCurrency').textContent = contractCurrency;
    document.getElementById('infoAmountDue').textContent = fmt(data.amount_due) + ' ' + instCurrency;
    document.getElementById('infoAmountPaid').textContent = fmt(data.amount_paid) + ' ' + instCurrency;
    document.getElementById('infoAmountUnpaid').textContent = fmt(data.amount_unpaid) + ' ' + instCurrency;
    if (instCurrency !== contractCurrency) {
        const convertedDue = convertCurrency(data.amount_due, instCurrency, contractCurrency);
        const convertedUnpaid = convertCurrency(data.amount_unpaid, instCurrency, contractCurrency);
        document.getElementById('infoAmountDueConverted').textContent = '≈' + fmt(convertedDue) + ' ' + contractCurrency;
        document.getElementById('infoAmountUnpaidConverted').textContent = '≈' + fmt(convertedUnpaid) + ' ' + contractCurrency;
    } else {
        document.getElementById('infoAmountDueConverted').textContent = '';
        document.getElementById('infoAmountUnpaidConverted').textContent = '';
    }
    document.getElementById('infoStatus').textContent = data.installment_status || '';

    document.getElementById('infoCard').style.display = '';
    document.getElementById('formCard').style.display = '';
    document.getElementById('formInstallmentId').value = data.installment_id;
    setToday(document.getElementById('receivedDate'));
    document.getElementById('amountReceived').value = fmt(data.amount_unpaid);
    updateCalcHint();
}

function updateCalcHint() {
    const hint = document.getElementById('calcHint');
    if (!currentInstallment) {
        hint.textContent = '';
        return;
    }
    const unpaid = Number(currentInstallment.amount_unpaid || 0);
    const amt = Number(document.getElementById('amountReceived').value || 0);
    const applied = Math.min(amt, unpaid);
    const overflow = Math.max(0, amt - applied);
    hint.textContent = '本次将冲抵：' + fmt(applied) + '；超收转预收：' + fmt(overflow);
}

document.getElementById('btnClear').addEventListener('click', function() {
    clearAll();
});

document.getElementById('btnLoad').addEventListener('click', function() {
    const id = parseInt(document.getElementById('installmentId').value || '0', 10);
    if (!id) {
        showAlertModal('请输入分期ID', 'warning');
        return;
    }

    fetch(apiUrl('finance_installment_get.php?id=' + id))
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '查询失败', 'error');
                return;
            }
            currentInstallment = res.data;
            renderInfo(res.data);
        })
        .catch(() => {
            showAlertModal('查询失败，请查看控制台错误信息', 'error');
        });
});

document.getElementById('amountReceived').addEventListener('input', updateCalcHint);

document.getElementById('exchangeRateType').addEventListener('change', function() {
    const floatingDiv = document.getElementById('floatingRateDiv');
    if (this.value === 'floating') {
        floatingDiv.style.display = '';
    } else {
        floatingDiv.style.display = 'none';
        document.getElementById('exchangeRate').value = '';
    }
    updateReceiveAmount();
});

document.getElementById('receiveCurrency').addEventListener('change', updateReceiveAmount);
document.getElementById('exchangeRate').addEventListener('input', updateReceiveAmount);

function updateReceiveAmount() {
    if (!currentInstallment) return;
    const instCurrency = currentInstallment.installment_currency || 'TWD';
    const receiveCurrency = document.getElementById('receiveCurrency').value || 'TWD';
    const rateType = document.getElementById('exchangeRateType').value;
    const unpaid = Number(currentInstallment.amount_unpaid || 0);
    
    let converted = unpaid;
    if (instCurrency !== receiveCurrency) {
        if (rateType === 'floating') {
            const customRate = Number(document.getElementById('exchangeRate').value || 0);
            if (customRate > 0) {
                converted = unpaid * (exchangeRates[instCurrency] || 1) / customRate;
            }
        } else {
            converted = convertCurrency(unpaid, instCurrency, receiveCurrency);
        }
    }
    document.getElementById('amountReceived').value = fmt(converted);
    updateCalcHint();
}

document.getElementById('btnSubmit').addEventListener('click', function() {
    if (!currentInstallment) {
        showAlertModal('请先查询分期', 'warning');
        return;
    }
    const form = document.getElementById('receiptForm');
    const fd = new FormData(form);
    const amt = Number(fd.get('amount_received') || 0);
    if (!amt || amt <= 0) {
        showAlertModal('收款金额必须大于0', 'warning');
        return;
    }

    fetch(apiUrl('finance_receipt_save.php'), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showAlertModal(res.message || '提交失败', 'error');
            return;
        }
        const d = res.data || {};
        showAlertModal('收款登记成功（冲抵 ' + fmt(d.amount_applied) + '，预收 ' + fmt(d.amount_overflow) + '）', 'success', function() {
            document.getElementById('btnLoad').click();
        });
    })
    .catch(() => {
        showAlertModal('提交失败，请查看控制台错误信息', 'error');
    });
});
</script>

<?php
layout_footer();
