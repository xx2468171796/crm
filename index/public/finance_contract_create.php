<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CONTRACT_CREATE)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限。</div>';
    layout_footer();
    exit;
}

layout_header('新建合同');

$customerId = (int)($_GET['customer_id'] ?? 0);
$keyword = trim((string)($_GET['keyword'] ?? ''));

$customer = null;
if ($customerId > 0) {
    $customer = Db::queryOne('SELECT id, name, mobile, customer_code, activity_tag, owner_user_id FROM customers WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1', ['id' => $customerId]);
    if (!$customer) {
        echo '<div class="alert alert-danger">客户不存在</div>';
        layout_footer();
        exit;
    }
    if (($user['role'] ?? '') === 'sales' && (int)($customer['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        echo '<div class="alert alert-danger">无权限：只能给自己名下客户创建订单</div>';
        layout_footer();
        exit;
    }
}

$customers = [];
if ($customerId <= 0 && $keyword !== '') {
    $sql = 'SELECT id, name, mobile, customer_code, activity_tag FROM customers WHERE status = 1 AND deleted_at IS NULL AND (name LIKE :kw OR mobile LIKE :kw OR customer_code LIKE :kw)';
    $params = ['kw' => '%' . $keyword . '%'];

    if (($user['role'] ?? '') === 'sales') {
        $sql .= ' AND owner_user_id = :uid';
        $params['uid'] = (int)($user['id'] ?? 0);
    }

    $sql .= ' ORDER BY id DESC LIMIT 20';
    $customers = Db::query($sql, $params);
}

$salesUsers = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname');

// 从字典表加载收款方式
try {
    $paymentMethods = Db::query("SELECT dict_code, dict_label FROM system_dict WHERE dict_type = 'payment_method' AND is_enabled = 1 ORDER BY sort_order, dict_code");
} catch (Exception $e) {
    $paymentMethods = [];
}
if (empty($paymentMethods)) {
    $paymentMethods = [
        ['dict_code' => 'bank_transfer', 'dict_label' => '银行转账'],
        ['dict_code' => 'cash', 'dict_label' => '现金'],
        ['dict_code' => 'alipay', 'dict_label' => '支付宝'],
        ['dict_code' => 'wechat', 'dict_label' => '微信'],
    ];
}

// 从字典表加载货币
try {
    $currencies = Db::query("SELECT dict_code, dict_label FROM system_dict WHERE dict_type = 'currency' AND is_enabled = 1 ORDER BY sort_order, dict_code");
} catch (Exception $e) {
    $currencies = [];
}
if (empty($currencies)) {
    $currencies = [
        ['dict_code' => 'TWD', 'dict_label' => '新台币'],
        ['dict_code' => 'CNY', 'dict_label' => '人民币'],
        ['dict_code' => 'USD', 'dict_label' => '美元'],
    ];
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">新建合同</h3>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">财务工作台</a>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=my_receivables">我的应收/催款</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-3" method="GET">
            <input type="hidden" name="page" value="finance_contract_create">
            <div class="col-md-6">
                <label class="form-label">选择客户</label>
                <input type="text" class="form-control" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="输入客户姓名/手机号/编号搜索" <?= $customerId > 0 ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <?php if ($customerId > 0): ?>
                    <a class="btn btn-outline-secondary" href="index.php?page=finance_contract_create">重新选择</a>
                    <div class="text-muted small">已选：<?= htmlspecialchars($customer['name'] ?? '') ?>（<?= htmlspecialchars($customer['customer_code'] ?? '') ?>）</div>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit">搜索</button>
                    <a class="btn btn-outline-secondary" href="index.php?page=finance_contract_create">清空</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($customerId <= 0 && $keyword !== ''): ?>
            <hr>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>客户</th>
                        <th>标签</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="3" class="text-center text-muted">未找到客户</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td>
                                    <div><?= htmlspecialchars($c['name'] ?? '') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars(($c['customer_code'] ?? '') . ' ' . ($c['mobile'] ?? '')) ?></div>
                                </td>
                                <td><?= htmlspecialchars($c['activity_tag'] ?? '') ?></td>
                                <td>
                                    <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_contract_create&customer_id=<?= (int)$c['id'] ?>">选择</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($customerId > 0): ?>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="fw-semibold"><?= htmlspecialchars($customer['name'] ?? '') ?><?= ($customer['activity_tag'] ?? '') !== '' ? ('（' . htmlspecialchars($customer['activity_tag']) . '）') : '' ?></div>
                <div class="small text-muted"><?= htmlspecialchars(($customer['customer_code'] ?? '') . ' ' . ($customer['mobile'] ?? '')) ?></div>
            </div>
            <div class="col-md-6 text-md-end">
                <a class="btn btn-outline-secondary btn-sm" href="index.php?page=customer_detail&id=<?= (int)$customerId ?>#tab-finance">返回客户财务</a>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form id="contractForm">
            <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

            <!-- 基础信息 -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">合同总价 <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" name="gross_amount" id="grossAmount" required placeholder="必填">
                </div>
                <div class="col-md-4">
                    <label class="form-label">成交单价</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="unit_price" id="unitPrice" placeholder="可选">
                </div>
                <div class="col-md-4">
                    <label class="form-label">合同货币</label>
                    <select class="form-select" name="currency" id="contractCurrency">
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= htmlspecialchars($c['dict_code']) ?>" <?= $c['dict_code'] === 'TWD' ? 'selected' : '' ?>><?= htmlspecialchars($c['dict_label']) ?>(<?= htmlspecialchars($c['dict_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">合同签约人</label>
                    <select class="form-select" name="sales_user_id" id="salesUserId">
                        <option value="0">请选择</option>
                        <?php foreach ($salesUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>" <?= (int)$su['id'] === (int)($user['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($su['realname'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">签约日期</label>
                    <input type="date" class="form-control" name="sign_date" id="signDate">
                </div>
                <div class="col-md-4">
                    <label class="form-label">合同号/订单号</label>
                    <input type="text" class="form-control" name="contract_no" maxlength="64" placeholder="不填自动生成">
                </div>
            </div>

            <?php if (($user['role'] ?? '') === 'sales'): ?>
                <input type="hidden" name="sales_user_id" value="<?= (int)($user['id'] ?? 0) ?>">
            <?php endif; ?>
            <input type="hidden" name="signer_user_id" id="signerUserId" value="">

            <!-- 第二行：合同标题、合同签约人、客户归属人 -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">合同标题</label>
                    <input type="text" class="form-control" name="title" maxlength="255" placeholder="不填自动生成">
                </div>
<div class="col-md-4"></div>
                <div class="col-md-4">
                    <label class="form-label">客户归属人</label>
                    <select class="form-select" name="owner_user_id" id="ownerUserId">
                        <option value="">不修改</option>
                        <?php foreach ($salesUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>" <?= (int)$su['id'] === (int)($customer['owner_user_id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($su['realname'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 折后金额提示 -->
            <div class="alert alert-info py-2 mb-3">
                <strong>折后金额：<span id="netAmountText">0.00</span></strong>
                <span class="ms-4">分期合计：<span id="sumInstallmentsText">0.00</span></span>
            </div>

            <!-- 高级选项 - 折叠面板 -->
            <div class="accordion mb-3" id="advancedOptionsAccordion">
                <!-- 折扣设置 -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#discountPanel">
                            <span class="me-2">💰</span> 折扣设置
                            <span class="badge bg-secondary ms-2" id="discountBadge" style="display:none;">已设置</span>
                        </button>
                    </h2>
                    <div id="discountPanel" class="accordion-collapse collapse" data-bs-parent="#advancedOptionsAccordion">
                        <div class="accordion-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">折扣参与计算</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="discountInCalc" name="discount_in_calc">
                                        <label class="form-check-label" for="discountInCalc">是</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">折扣类型</label>
                                    <select class="form-select" name="discount_type" id="discountType">
                                        <option value="">不填</option>
                                        <option value="amount">减免金额</option>
                                        <option value="rate">折扣比例</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">折扣值</label>
                                    <input type="number" step="0.0001" min="0" class="form-control" name="discount_value" id="discountValue" placeholder="金额或比例">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">折扣备注</label>
                                    <input type="text" class="form-control" name="discount_note" maxlength="255" placeholder="可选">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <hr class="my-3">

            <div class="mb-3">
                <label class="form-label">合同附件（公司文件，可选）</label>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnSelectFiles">选择文件</button>
                    <span class="text-muted small" id="selectedFilesInfo">未选择文件</span>
                </div>
                <div class="form-text">创建成功后自动上传到公司文件，并绑定到该合同。</div>
                <input type="file" id="contractFiles" multiple style="display:none;">
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">分期计划</div>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="installment plan mode">
                        <input type="radio" class="btn-check" name="installment_plan_mode" id="instModeManual" value="manual" checked>
                        <label class="btn btn-outline-secondary" for="instModeManual">手动</label>
                        <input type="radio" class="btn-check" name="installment_plan_mode" id="instModeAuto" value="auto">
                        <label class="btn btn-outline-secondary" for="instModeAuto">自动</label>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddRow">新增一行</button>
                </div>
            </div>

            <div class="row g-2 align-items-end mb-2" id="autoPlanPanel" style="display:none;">
                <div class="col-md-2 col-sm-4">
                    <label class="form-label mb-0 small text-muted">期数</label>
                    <input type="number" min="1" step="1" class="form-control form-control-sm" id="autoInstCount" value="3">
                </div>
                <div class="col-md-6 col-sm-8">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnAutoBuild">生成</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAutoEqual">均分百分比</button>
                    <span class="small text-muted ms-2">自动：按折后金额×百分比计算金额（百分比合计 100%）</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle" id="instTable">
                    <thead>
                    <tr>
                        <th style="width: 150px;">到期日</th>
                        <th style="width: 100px;" id="instPercentTh" class="d-none">百分比(%)</th>
                        <th style="width: 120px;">金额</th>
                        <th style="width: 150px;">收款人</th>
                        <th style="width: 120px;">收款方式</th>
                        <th style="width: 100px;">货币</th>
                        <th style="width: 60px;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <input type="hidden" name="installments_json" id="installmentsJson">

            <button type="button" class="btn btn-success" id="btnSubmit">提交合同</button>
        </form>
    </div>
</div>

<script>
function apiUrl(path) {
    return API_URL + '/' + path;
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function fmt(n) {
    const x = Number(n || 0);
    return x.toFixed(2);
}

function setToday(el) {
    const d = new Date();
    const pad = (v) => v.toString().padStart(2, '0');
    el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function updateDiscountBadge() {
    const inCalc = document.getElementById('discountInCalc').checked;
    const type = document.getElementById('discountType').value || '';
    const dv = Number(document.getElementById('discountValue').value || 0);
    const badge = document.getElementById('discountBadge');
    if (badge) {
        badge.style.display = (inCalc && type && dv > 0) ? 'inline' : 'none';
    }
}

function calcNet() {
    const gross = Number(document.getElementById('grossAmount').value || 0);
    const inCalc = document.getElementById('discountInCalc').checked;
    const type = document.getElementById('discountType').value || '';
    const dv = Number(document.getElementById('discountValue').value || 0);

    updateDiscountBadge();

    let net = gross;
    if (inCalc && type && dv > 0) {
        if (type === 'amount') {
            net = Math.max(0, gross - dv);
        } else if (type === 'rate') {
            let r = dv;
            if (r > 1 && r <= 100) {
                r = r / 100;
            }
            if (r > 0 && r <= 1) {
                net = Math.max(0, gross * r);
            }
        }
    }
    net = Math.round(net * 100) / 100;
    document.getElementById('netAmountText').textContent = fmt(net);
    return net;
}

function collectInstallments() {
    const rows = Array.from(document.querySelectorAll('#instTable tbody tr'));
    const out = [];
    rows.forEach((tr) => {
        const due = tr.querySelector('input[data-field="due_date"]').value;
        const amt = Number(tr.querySelector('input[data-field="amount_due"]').value || 0);
        const collector = tr.querySelector('select[data-field="collector_user_id"]')?.value || '';
        const method = tr.querySelector('select[data-field="payment_method"]')?.value || '';
        const currency = tr.querySelector('select[data-field="currency"]')?.value || 'TWD';
        if (due && amt > 0) {
            out.push({ 
                due_date: due, 
                amount_due: Math.round(amt * 100) / 100,
                collector_user_id: collector ? parseInt(collector) : null,
                payment_method: method || null,
                currency: currency
            });
        }
    });
    return out;
}

function sumInstallments(insts) {
    let s = 0;
    insts.forEach((i) => {
        s += Number(i.amount_due || 0);
    });
    s = Math.round(s * 100) / 100;
    document.getElementById('sumInstallmentsText').textContent = fmt(s);
    return s;
}

function refreshTotals() {
    const net = calcNet();
    if (getPlanMode() === 'auto') {
        recalcAutoAmounts(net);
    }
    const insts = collectInstallments();
    sumInstallments(insts);
    return { net, insts };
}

function getPlanMode() {
    const el = document.querySelector('input[name="installment_plan_mode"]:checked');
    const v = el ? String(el.value || '') : 'manual';
    return v === 'auto' ? 'auto' : 'manual';
}

function applyPlanMode() {
    const mode = getPlanMode();
    const autoPanel = document.getElementById('autoPlanPanel');
    const percentTh = document.getElementById('instPercentTh');
    const addBtn = document.getElementById('btnAddRow');
    if (autoPanel) autoPanel.style.display = (mode === 'auto') ? '' : 'none';
    if (percentTh) percentTh.classList.toggle('d-none', mode !== 'auto');
    if (addBtn) addBtn.style.display = (mode === 'manual') ? '' : 'none';

    document.querySelectorAll('#instTable tbody tr').forEach(tr => {
        const pctTd = tr.querySelector('td[data-col="percent"]');
        if (pctTd) pctTd.classList.toggle('d-none', mode !== 'auto');
        const pctInput = tr.querySelector('input[data-field="percent"]');
        const amtInput = tr.querySelector('input[data-field="amount_due"]');
        if (pctInput) pctInput.disabled = (mode !== 'auto');
        if (amtInput) {
            amtInput.readOnly = (mode === 'auto');
        }
    });

    refreshTotals();
}

function equalPercents(count) {
    const n = Math.max(1, parseInt(String(count || 0), 10) || 0);
    const base = Math.floor((100 / n) * 100) / 100;
    const arr = new Array(n).fill(base);
    const sum = Math.round(arr.reduce((a, b) => a + b, 0) * 100) / 100;
    const diff = Math.round((100 - sum) * 100) / 100;
    arr[n - 1] = Math.round((arr[n - 1] + diff) * 100) / 100;
    return arr;
}

function rebuildAutoRows() {
    const cnt = Math.max(1, parseInt(String(document.getElementById('autoInstCount')?.value || '0'), 10) || 0);
    const tbody = document.querySelector('#instTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const pcts = equalPercents(cnt);
    for (let i = 0; i < cnt; i++) {
        addRow('', '', String(pcts[i]));
    }
    applyPlanMode();
}

function recalcAutoAmounts(netAmount) {
    const net = Math.round(Number(netAmount || 0) * 100) / 100;
    const trs = Array.from(document.querySelectorAll('#instTable tbody tr'));
    if (!trs.length) return;

    const pcts = trs.map(tr => {
        const v = Number(tr.querySelector('input[data-field="percent"]')?.value || 0);
        return Math.round(v * 100) / 100;
    });

    let amounts = pcts.map(p => Math.round((net * (p / 100)) * 100) / 100);
    let sum = Math.round(amounts.reduce((a, b) => a + b, 0) * 100) / 100;
    const diff = Math.round((net - sum) * 100) / 100;
    if (amounts.length > 0 && Math.abs(diff) > 0.00001) {
        amounts[amounts.length - 1] = Math.round((amounts[amounts.length - 1] + diff) * 100) / 100;
    }

    trs.forEach((tr, idx) => {
        const amtInput = tr.querySelector('input[data-field="amount_due"]');
        if (amtInput) amtInput.value = fmt(amounts[idx] || 0);
    });
}

const salesUserOptions = <?= json_encode(array_map(function($u) { return ['id' => (int)$u['id'], 'name' => $u['realname'] ?? '']; }, $salesUsers)) ?>;
const paymentMethodOptions = <?= json_encode(array_map(function($m) { return ['code' => $m['dict_code'], 'label' => $m['dict_label']]; }, $paymentMethods)) ?>;
const currencyOptions = <?= json_encode(array_map(function($c) { return ['code' => $c['dict_code'], 'label' => $c['dict_label']]; }, $currencies)) ?>;

const exchangeRates = { TWD: 1.0, CNY: 4.5, USD: 32.0 };
function convertCurrency(amount, from, to) {
    if (from === to) return amount;
    const fromRate = exchangeRates[from] || 1.0;
    const toRate = exchangeRates[to] || 1.0;
    return amount * fromRate / toRate;
}

function addRow(due = '', amt = '', pct = '', collector = '', method = '', currency = 'TWD') {
    const tbody = document.querySelector('#instTable tbody');
    const tr = document.createElement('tr');
    
    let collectorOpts = '<option value="">请选择</option>';
    salesUserOptions.forEach(u => {
        collectorOpts += `<option value="${u.id}" ${u.id == collector ? 'selected' : ''}>${esc(u.name)}</option>`;
    });
    
    let methodOpts = '<option value="">请选择</option>';
    paymentMethodOptions.forEach(m => {
        methodOpts += `<option value="${m.code}" ${m.code === method ? 'selected' : ''}>${esc(m.label)}</option>`;
    });
    
    let currencyOpts = '';
    currencyOptions.forEach(c => {
        currencyOpts += `<option value="${c.code}" ${c.code === currency ? 'selected' : ''}>${esc(c.label)}(${c.code})</option>`;
    });
    
    tr.innerHTML = `
        <td><input type="date" class="form-control form-control-sm" data-field="due_date" value="${due}"></td>
        <td data-col="percent" class="d-none"><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" data-field="percent" value="${pct}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-field="amount_due" value="${amt}"></td>
        <td><select class="form-select form-select-sm" data-field="collector_user_id">${collectorOpts}</select></td>
        <td><select class="form-select form-select-sm" data-field="payment_method">${methodOpts}</select></td>
        <td><select class="form-select form-select-sm" data-field="currency">${currencyOpts}</select></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" data-action="remove">删</button></td>
    `;
    tbody.appendChild(tr);

    tr.querySelectorAll('input').forEach((el) => {
        el.addEventListener('input', refreshTotals);
        el.addEventListener('change', refreshTotals);
    });

    const currencySelect = tr.querySelector('select[data-field="currency"]');
    if (currencySelect) {
        currencySelect.addEventListener('change', function() {
            const contractCurrency = document.getElementById('currency')?.value || 'TWD';
            const instCurrency = this.value;
            const amtInput = tr.querySelector('input[data-field="amount_due"]');
            const pctInput = tr.querySelector('input[data-field="percent"]');
            if (amtInput && pctInput) {
                const net = Math.round(Number(document.getElementById('netAmount')?.value || 0) * 100) / 100;
                const pct = Number(pctInput.value || 0);
                const baseAmt = Math.round((net * (pct / 100)) * 100) / 100;
                const converted = convertCurrency(baseAmt, contractCurrency, instCurrency);
                amtInput.value = fmt(converted);
            }
            refreshTotals();
        });
    }

    tr.querySelector('[data-action="remove"]').addEventListener('click', () => {
        tr.remove();
        refreshTotals();
    });
}

// 选择文件按钮
let pendingContractFiles = [];
document.getElementById('btnSelectFiles').addEventListener('click', function() {
    showFileSelectModal();
});

function showFileSelectModal() {
    const existing = document.getElementById('uploadModal');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'uploadModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:8px;padding:30px;width:500px;max-width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
    
    modal.innerHTML = `
        <h5 style="margin-bottom:20px;font-weight:600;">选择合同附件</h5>
        <div id="uploadDropZone" style="border:2px dashed #ccc;border-radius:8px;padding:40px 20px;cursor:pointer;transition:all 0.2s;">
            <div style="width:60px;height:60px;background:#3b82f6;border-radius:12px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;">
                <svg width="30" height="30" fill="white" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
            </div>
            <div style="font-size:16px;color:#333;margin-bottom:8px;">点击选择文件，或拖拽到此处</div>
            <div style="font-size:13px;color:#999;">支持多种文件格式</div>
            <div style="font-size:13px;color:#999;margin-top:5px;">也可以 <strong>Ctrl+V</strong> 粘贴</div>
        </div>
        <input type="file" id="uploadFileInput" multiple style="display:none;">
        <div id="selectedFilesList" style="margin-top:15px;text-align:left;max-height:150px;overflow-y:auto;"></div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;">
            <button type="button" id="uploadCancelBtn" style="padding:8px 20px;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;">取消</button>
            <button type="button" id="uploadConfirmBtn" style="padding:8px 20px;border:none;background:#3b82f6;color:#fff;border-radius:4px;cursor:pointer;">确定</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const dropZone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadFileInput');
    const filesList = document.getElementById('selectedFilesList');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    const confirmBtn = document.getElementById('uploadConfirmBtn');
    let selectedFiles = [...pendingContractFiles];
    
    function updateFilesList() {
        if (selectedFiles.length === 0) {
            filesList.innerHTML = '<div style="color:#999;font-size:13px;">未选择文件</div>';
        } else {
            filesList.innerHTML = selectedFiles.map((f, i) => 
                '<div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #eee;">' +
                '<span style="font-size:13px;">' + esc(f.name) + '</span>' +
                '<button type="button" data-remove="' + i + '" style="border:none;background:none;color:#f00;cursor:pointer;">✕</button>' +
                '</div>'
            ).join('');
            filesList.querySelectorAll('[data-remove]').forEach(btn => {
                btn.onclick = () => { selectedFiles.splice(Number(btn.dataset.remove), 1); updateFilesList(); };
            });
        }
    }
    updateFilesList();
    
    dropZone.onclick = () => fileInput.click();
    dropZone.ondragover = (e) => { e.preventDefault(); dropZone.style.borderColor = '#3b82f6'; dropZone.style.background = '#f0f7ff'; };
    dropZone.ondragleave = () => { dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; };
    dropZone.ondrop = (e) => { e.preventDefault(); dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; addFiles(e.dataTransfer.files); };
    fileInput.onchange = () => { addFiles(fileInput.files); fileInput.value = ''; };
    
    function addFiles(files) {
        for (let i = 0; i < files.length; i++) selectedFiles.push(files[i]);
        updateFilesList();
    }
    
    const pasteHandler = (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        for (let i = 0; i < items.length; i++) { if (items[i].kind === 'file') { const f = items[i].getAsFile(); if (f) selectedFiles.push(f); } }
        updateFilesList();
    };
    document.addEventListener('paste', pasteHandler);
    
    cancelBtn.onclick = () => cleanup();
    overlay.onclick = (e) => { if (e.target === overlay) cleanup(); };
    const escHandler = (e) => { if (e.key === 'Escape') cleanup(); };
    document.addEventListener('keydown', escHandler);
    
    confirmBtn.onclick = () => {
        pendingContractFiles = selectedFiles;
        document.getElementById('selectedFilesInfo').textContent = selectedFiles.length > 0 ? ('已选择 ' + selectedFiles.length + ' 个文件') : '未选择文件';
        cleanup();
    };
    
    function cleanup() { document.removeEventListener('paste', pasteHandler); document.removeEventListener('keydown', escHandler); overlay.remove(); }
}

function getSelectedContractFiles() {
    return pendingContractFiles;
}

function uploadContractFiles(customerId, contractNo, files) {
    if (!files || files.length === 0) {
        return Promise.resolve([]);
    }
    const fd = new FormData();
    fd.append('customer_id', String(customerId));
    fd.append('category', 'internal_solution');
    const folderPath = '合同/' + String(contractNo || '').trim();
    files.forEach((f) => {
        fd.append('files[]', f);
        fd.append('folder_paths[]', folderPath);
    });
    return fetch(apiUrl('customer_files.php'), {
        method: 'POST',
        body: fd,
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                throw new Error(res.message || '上传失败');
            }
            const created = res.files || [];
            return created.map(it => Number(it.id || 0)).filter(v => v > 0);
        });
}

function attachFilesToContract(contractId, fileIds) {
    if (!fileIds || fileIds.length === 0) {
        return Promise.resolve();
    }
    const fd = new FormData();
    fd.append('contract_id', String(contractId));
    fd.append('file_ids', JSON.stringify(fileIds));
    return fetch(apiUrl('finance_contract_file_attach.php'), {
        method: 'POST',
        body: fd,
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                throw new Error(res.message || '绑定失败');
            }
        });
}

setToday(document.getElementById('signDate'));
addRow();

// 同步signer_user_id和sales_user_id
function syncSignerUserId() {
    const salesEl = document.getElementById('salesUserId');
    const signerEl = document.getElementById('signerUserId');
    if (salesEl && signerEl) {
        signerEl.value = salesEl.value;
    }
}
syncSignerUserId();
document.getElementById('salesUserId')?.addEventListener('change', syncSignerUserId);

['grossAmount', 'discountInCalc', 'discountType', 'discountValue'].forEach((id) => {
    document.getElementById(id).addEventListener('input', refreshTotals);
    document.getElementById(id).addEventListener('change', refreshTotals);
});

document.getElementById('btnAddRow').addEventListener('click', () => {
    addRow();
});

document.getElementById('btnAutoBuild')?.addEventListener('click', () => {
    rebuildAutoRows();
});

document.getElementById('btnAutoEqual')?.addEventListener('click', () => {
    const trs = Array.from(document.querySelectorAll('#instTable tbody tr'));
    if (!trs.length) return;
    const pcts = equalPercents(trs.length);
    trs.forEach((tr, idx) => {
        const el = tr.querySelector('input[data-field="percent"]');
        if (el) el.value = String(pcts[idx] || 0);
    });
    refreshTotals();
});

document.getElementById('autoInstCount')?.addEventListener('change', () => {
    if (getPlanMode() !== 'auto') return;
    rebuildAutoRows();
});

document.getElementById('instModeManual')?.addEventListener('change', applyPlanMode);
document.getElementById('instModeAuto')?.addEventListener('change', () => {
    if (getPlanMode() === 'auto') {
        if (!document.querySelector('#instTable tbody tr')) {
            rebuildAutoRows();
        } else {
            applyPlanMode();
        }
    }
});

let isSubmitting = false;
document.getElementById('btnSubmit').addEventListener('click', () => {
    if (isSubmitting) {
        showAlertModal('正在提交中，请勿重复点击', 'warning');
        return;
    }

    const form = document.getElementById('contractForm');
    const fd = new FormData(form);

    const gross = Number(fd.get('gross_amount') || 0);
    if (!gross || gross <= 0) {
        showAlertModal('合同总价必须大于 0', 'warning');
        return;
    }

    const { net, insts } = refreshTotals();
    if (!insts.length) {
        showAlertModal('请至少填写 1 条分期', 'warning');
        return;
    }

    if (getPlanMode() === 'auto') {
        const trs = Array.from(document.querySelectorAll('#instTable tbody tr'));
        const pctSum = Math.round(trs.reduce((a, tr) => a + Number(tr.querySelector('input[data-field="percent"]')?.value || 0), 0) * 100) / 100;
        if (Math.abs(pctSum - 100) > 0.01) {
            showAlertModal('百分比合计必须等于 100%（当前：' + fmt(pctSum) + '%）', 'warning');
            return;
        }
    }
    const sum = Math.round(insts.reduce((a, b) => a + Number(b.amount_due || 0), 0) * 100) / 100;
    if (Math.abs(sum - net) > 0.01) {
        showAlertModal('分期合计必须等于折后金额（当前：' + fmt(sum) + ' vs ' + fmt(net) + '）', 'warning');
        return;
    }

    fd.set('installments_json', JSON.stringify(insts));

    const contractFiles = getSelectedContractFiles();

    // 禁用按钮，防止重复提交
    isSubmitting = true;
    const btn = document.getElementById('btnSubmit');
    const btnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>提交中...';

    fetch(apiUrl('finance_contract_save.php'), {
        method: 'POST',
        body: fd
    })
        .then(r => r.json())
        .then(async (res) => {
            if (!res.success) {
                let errMsg = res.message || '保存失败';
                if (res.file) errMsg += '\n文件: ' + res.file;
                if (res.line) errMsg += '\n行号: ' + res.line;
                console.error('[CSDEBUG] API错误:', res);
                showAlertModal(errMsg, 'error');
                // 恢复按钮状态
                isSubmitting = false;
                btn.disabled = false;
                btn.innerHTML = btnText;
                return;
            }

            const contractId = (res.data && res.data.contract_id) ? Number(res.data.contract_id) : 0;
            const contractNo = (res.data && res.data.contract_no) ? String(res.data.contract_no) : '';

            if (contractFiles.length > 0) {
                try {
                    const fileIds = await uploadContractFiles(<?= (int)$customerId ?>, contractNo || ('ID-' + String(contractId)), contractFiles);
                    await attachFilesToContract(contractId, fileIds);
                } catch (e) {
                    showAlertModal('合同已创建，但附件处理失败：' + esc(e.message || ''), 'warning', () => {
                        window.location.href = 'index.php?page=customer_detail&id=<?= (int)$customerId ?>#tab-finance';
                    });
                    return;
                }
            }

            showAlertModal('创建成功：' + contractNo, 'success', () => {
                window.location.href = 'index.php?page=customer_detail&id=<?= (int)$customerId ?>#tab-finance';
            });
        })
        .catch(() => {
            showAlertModal('保存失败，请查看控制台错误信息', 'error');
            // 恢复按钮状态
            isSubmitting = false;
            btn.disabled = false;
            btn.innerHTML = btnText;
        });
});

refreshTotals();
applyPlanMode();
</script>

<?php endif; ?>

<?php
layout_footer();
