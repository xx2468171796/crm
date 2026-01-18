<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅财务/管理员可访问提成计算页面。</div>';
    layout_footer();
    exit;
}

layout_header('提成计算');
finance_sidebar_start('commission_calculator');
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">提成计算</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="index.php?page=commission_rules">提成规则</a>
            <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">返回财务工作台</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">提成规则 <span class="text-danger">*</span></label>
                    <select class="form-select" id="filterRule" required>
                        <option value="">请选择规则</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">结算月份</label>
                    <input type="month" class="form-control" id="filterMonth" value="<?= date('Y-m') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">部门</label>
                    <div class="dropdown multi-select-dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" id="filterDeptBtn" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                            全部部门
                        </button>
                        <div class="dropdown-menu w-100 p-2" id="filterDeptMenu" style="max-height:250px;overflow-y:auto;"></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">销售人员</label>
                    <div class="dropdown multi-select-dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" id="filterUserBtn" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                            全部人员
                        </button>
                        <div class="dropdown-menu w-100 p-2" id="filterUserMenu" style="max-height:250px;overflow-y:auto;"></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">显示货币</label>
                    <select class="form-select" id="filterDisplayCurrency">
                        <option value="CNY">人民币 (CNY)</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">汇率类型</label>
                    <select class="form-select" id="filterRateType">
                        <option value="fixed" selected>固定汇率</option>
                        <option value="floating">浮动汇率</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnCalculate">计算提成</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnExport">导出</button>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <a href="salary_slip_detail.php" class="btn btn-outline-info btn-sm" id="btnViewSlip">📄 查看工资条</a>
                    <a href="index.php?page=exchange_rate" class="btn btn-outline-secondary btn-sm" title="汇率管理"><i class="bi bi-currency-exchange"></i> 汇率管理</a>
                    <span class="text-muted small ms-2" id="rateInfo"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>提成明细</span>
            <div class="btn-group btn-group-sm" role="group" id="viewSwitcher">
                <input type="radio" class="btn-check" name="viewMode" id="viewUser" value="user" checked>
                <label class="btn btn-outline-primary" for="viewUser">按人员</label>
                <input type="radio" class="btn-check" name="viewMode" id="viewDept" value="dept">
                <label class="btn btn-outline-primary" for="viewDept">按部门</label>
                <input type="radio" class="btn-check" name="viewMode" id="viewContract" value="contract">
                <label class="btn btn-outline-primary" for="viewContract">按合同</label>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="resultTable">
                    <thead class="table-light" id="resultHead">
                        <tr>
                            <th style="width:40px;"></th>
                            <th>销售人员</th>
                            <th>部门</th>
                            <th class="text-end">底薪</th>
                            <th class="text-end">全勤</th>
                            <th class="text-end">提成</th>
                            <th class="text-end">激励</th>
                            <th class="text-end">调整</th>
                            <th class="text-end">扣款</th>
                            <th class="text-end">总工资</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="resultBody">
                        <tr><td colspan="10" class="text-center text-muted py-4">请选择月份后点击"计算提成"</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">手动调整提成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adjUserId">
                <input type="hidden" id="adjMonth">
                <div class="mb-3">
                    <label class="form-label">销售人员</label>
                    <input type="text" class="form-control" id="adjUserName" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">调整金额</label>
                    <input type="number" step="0.01" class="form-control" id="adjAmount" placeholder="正数增加，负数减少">
                </div>
                <div class="mb-3">
                    <label class="form-label">调整原因</label>
                    <textarea class="form-control" id="adjReason" rows="2" placeholder="请填写调整原因"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnSaveAdj">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
console.log('[CALC_DEBUG] === Script loaded at ' + new Date().toISOString() + ' ===');
console.log('[CALC_DEBUG] API_URL from layout:', typeof API_URL !== 'undefined' ? API_URL : 'UNDEFINED');
let calcData = null;
let currencyList = [];
let currencyRates = {};

function apiUrl(path) {
    return API_URL + '/' + path;
}

function loadCurrencies() {
    return fetch(apiUrl('currency_list.php'), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            console.log('[CALC_DEBUG] currency_list response:', res);
            if (res && res.success && res.data) {
                currencyList = res.data;
                currencyRates = {};
                currencyList.forEach(c => {
                    currencyRates[c.code] = {
                        fixed: parseFloat(c.fixed_rate) || 1,
                        floating: parseFloat(c.floating_rate) || 1
                    };
                });
                const sel = document.getElementById('filterDisplayCurrency');
                if (sel && currencyList.length > 0) {
                    sel.innerHTML = currencyList.map(c => 
                        `<option value="${c.code}">${c.name} (${c.code})</option>`
                    ).join('');
                }
                updateRateInfo();
            }
        })
        .catch(e => console.log('[CALC_DEBUG] loadCurrencies error:', e));
}

function updateRateInfo() {
    const displayCurrency = document.getElementById('filterDisplayCurrency')?.value || 'CNY';
    const rateType = document.getElementById('filterRateType')?.value || 'fixed';
    const rate = currencyRates[displayCurrency]?.[rateType] || 1;
    const rateTypeName = rateType === 'fixed' ? '固定' : '浮动';
    const el = document.getElementById('rateInfo');
    if (el) {
        if (displayCurrency === 'CNY') {
            el.textContent = `显示货币: 人民币 (${rateTypeName}汇率)`;
        } else {
            el.textContent = `${rateTypeName}汇率: 1 CNY = ${rate.toFixed(4)} ${displayCurrency}`;
        }
    }
}

function esc(s) {
    const div = document.createElement('div');
    div.textContent = String(s ?? '');
    return div.innerHTML;
}

function fmtMoney(n) {
    return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtNum(n) {
    return Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtRate(r) {
    return (Number(r || 0) * 100).toFixed(2) + '%';
}

function fmtMethod(m) {
    const map = {
        'taiwanxu': '台湾续',
        'prepay': '预付款',
        'zhongguopaypal': '中国PayPal',
        'other': '其他',
        'alipay': '支付宝',
        'guoneiduigong': '国内对公',
        'guoneiweixin': '国内微信',
        'xiapi': '下批'
    };
    return map[m] || m || '';
}

function loadFilters() {
    console.log('[CALC_DEBUG] loadFilters started');
    
    // 加载规则列表（使用FINANCE_VIEW权限的API）
    fetch(apiUrl('commission_rule_options.php'), { credentials: 'same-origin' })
        .then(r => {
            console.log('[CALC_DEBUG] rules fetch status:', r.status);
            return r.json();
        })
        .then(res => {
            console.log('[CALC_DEBUG] rules response:', res);
            if (res && res.success && res.data) {
                const ruleSel = document.getElementById('filterRule');
                console.log('[CALC_DEBUG] rules data length:', res.data.length);
                (res.data || []).forEach(r => {
                    ruleSel.innerHTML += '<option value="' + r.id + '">' + esc(r.name) + '</option>';
                });
            } else {
                console.log('[CALC_DEBUG] rules failed:', res);
            }
        })
        .catch(e => console.log('[CALC_DEBUG] load rules error:', e));
    
    // 加载部门和人员
    fetch(apiUrl('commission_rule_scope_options.php'), { credentials: 'same-origin' })
        .then(r => {
            console.log('[CALC_DEBUG] scope fetch status:', r.status);
            return r.json();
        })
        .then(res => {
            console.log('[CALC_DEBUG] scope response:', res);
            if (res && res.success && res.data) {
                initMultiSelect('filterDeptMenu', 'filterDeptBtn', res.data.departments || [], '全部部门');
                initMultiSelect('filterUserMenu', 'filterUserBtn', res.data.users || [], '全部人员');
            } else {
                console.log('[CALC_DEBUG] scope failed:', res);
            }
        })
        .catch(e => console.log('[CALC_DEBUG] load scope error:', e));
}

let currentViewMode = 'user';

const multiSelectState = { dept: [], user: [] };
let allUsersData = [];

function initMultiSelect(menuId, btnId, items, allLabel) {
    const menu = document.getElementById(menuId);
    const btn = document.getElementById(btnId);
    const key = menuId.includes('Dept') ? 'dept' : 'user';
    
    if (key === 'user') allUsersData = items;
    
    let html = `<div class="form-check"><input class="form-check-input" type="checkbox" value="" id="${menuId}_all" checked><label class="form-check-label" for="${menuId}_all">${allLabel}</label></div><hr class="my-1">`;
    items.forEach(item => {
        html += `<div class="form-check"><input class="form-check-input item-check" type="checkbox" value="${item.id}" data-dept="${item.department_id || ''}" id="${menuId}_${item.id}"><label class="form-check-label" for="${menuId}_${item.id}">${esc(item.name)}</label></div>`;
    });
    menu.innerHTML = html;
    
    const allCheck = document.getElementById(`${menuId}_all`);
    const itemChecks = menu.querySelectorAll('.item-check');
    
    allCheck.addEventListener('change', function() {
        if (this.checked) {
            itemChecks.forEach(c => c.checked = false);
            multiSelectState[key] = [];
            btn.textContent = allLabel;
            if (key === 'dept') filterUsersByDept();
        }
    });
    
    itemChecks.forEach(c => {
        c.addEventListener('change', function() {
            if (this.checked) allCheck.checked = false;
            updateMultiSelectState(menuId, btnId, allLabel, key);
            if (key === 'dept') filterUsersByDept();
        });
    });
}

function filterUsersByDept() {
    const selectedDepts = multiSelectState.dept;
    const menu = document.getElementById('filterUserMenu');
    const userChecks = menu.querySelectorAll('.item-check');
    
    userChecks.forEach(c => {
        const userDept = c.dataset.dept;
        if (selectedDepts.length === 0) {
            c.closest('.form-check').style.display = '';
        } else {
            c.closest('.form-check').style.display = selectedDepts.includes(userDept) ? '' : 'none';
            if (!selectedDepts.includes(userDept) && c.checked) {
                c.checked = false;
            }
        }
    });
    updateMultiSelectState('filterUserMenu', 'filterUserBtn', '全部人员', 'user');
}

function updateMultiSelectState(menuId, btnId, allLabel, key) {
    const menu = document.getElementById(menuId);
    const btn = document.getElementById(btnId);
    const checked = Array.from(menu.querySelectorAll('.item-check:checked'));
    
    if (checked.length === 0) {
        document.getElementById(`${menuId}_all`).checked = true;
        multiSelectState[key] = [];
        btn.textContent = allLabel;
    } else {
        multiSelectState[key] = checked.map(c => c.value);
        btn.textContent = checked.length === 1 ? checked[0].nextElementSibling.textContent : `已选${checked.length}项`;
    }
}

function calculate() {
    console.log('[CALC_DEBUG] calculate called');
    const ruleId = document.getElementById('filterRule').value;
    const month = document.getElementById('filterMonth').value;
    const deptIds = multiSelectState.dept;
    const userIds = multiSelectState.user;
    const displayCurrency = document.getElementById('filterDisplayCurrency')?.value || 'CNY';
    const rateType = document.getElementById('filterRateType')?.value || 'fixed';
    
    if (!ruleId) {
        alert('请先选择提成规则');
        return;
    }
    
    const params = new URLSearchParams({ month, rule_id: ruleId, display_currency: displayCurrency, rate_type: rateType });
    deptIds.forEach(id => params.append('department_ids[]', id));
    userIds.forEach(id => params.append('user_ids[]', id));
    
    console.log('[CALC_DEBUG] params:', params.toString());
    document.getElementById('resultBody').innerHTML = '<tr><td colspan="11" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> 计算中...</td></tr>';
    
    fetch(apiUrl('commission_calculate.php?' + params.toString()), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            console.log('[CALC_DEBUG] response:', res);
            if (res && res.success) {
                calcData = res.data;
                renderResults();
            } else {
                document.getElementById('resultBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger py-4">' + esc(res.message || '计算失败') + '</td></tr>';
            }
        })
        .catch(e => {
            console.log('[CALC_DEBUG] error:', e);
            document.getElementById('resultBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger py-4">请求失败</td></tr>';
        });
}

function renderResults() {
    updateTableHeader();
    if (currentViewMode === 'dept') {
        renderByDepartment();
    } else if (currentViewMode === 'contract') {
        renderByContract();
    } else {
        renderByUser();
    }
}

function updateTableHeader() {
    const thead = document.getElementById('resultHead');
    if (currentViewMode === 'contract') {
        thead.innerHTML = `<tr>
            <th style="width:40px;"></th>
            <th>合同名称</th>
            <th>客户</th>
            <th>销售人员</th>
            <th class="text-end">金额</th>
            <th class="text-end">比例</th>
            <th class="text-end">提成</th>
            <th>收款人</th>
            <th>方式</th>
            <th>类型</th>
            <th></th>
        </tr>`;
    } else {
        thead.innerHTML = `<tr>
            <th style="width:40px;"></th>
            <th>销售人员</th>
            <th>部门</th>
            <th class="text-end">底薪</th>
            <th class="text-end">全勤</th>
            <th class="text-end">提成</th>
            <th class="text-end">激励</th>
            <th class="text-end">调整</th>
            <th class="text-end">扣款</th>
            <th class="text-end">总工资</th>
            <th>操作</th>
        </tr>`;
    }
}

function renderByUser() {
    const tbody = document.getElementById('resultBody');
    const summary = calcData.summary || [];
    
    if (summary.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">暂无数据</td></tr>';
        return;
    }
    
    let html = '';
    summary.forEach((s, idx) => {
        html += `
        <tr class="summary-row" data-user-id="${s.user_id}">
            <td><button class="btn btn-sm btn-outline-secondary toggle-detail" data-idx="${idx}">▶</button></td>
            <td>${esc(s.user_name)}</td>
            <td>${esc(s.department)}</td>
            <td class="text-end"><input type="number" class="form-control form-control-sm salary-input text-end" data-user-id="${s.user_id}" data-field="base_salary" value="${s.base_salary}" style="width:80px;display:inline-block;"></td>
            <td class="text-end"><input type="number" class="form-control form-control-sm salary-input text-end" data-user-id="${s.user_id}" data-field="attendance" value="${s.attendance}" style="width:80px;display:inline-block;"></td>
            <td class="text-end">
                ${fmtMoney(s.commission_display || s.commission)}
                ${s.rule_currency && s.rule_currency !== s.display_currency ? `<br><span class="text-muted small">${fmtNum(s.commission)} ${s.rule_currency}</span>` : ''}
            </td>
            <td class="text-end">${fmtMoney(s.incentive)}</td>
            <td class="text-end"><input type="number" class="form-control form-control-sm salary-input text-end" data-user-id="${s.user_id}" data-field="adjustment" value="${s.adjustment}" style="width:80px;display:inline-block;"></td>
            <td class="text-end"><input type="number" class="form-control form-control-sm salary-input text-end" data-user-id="${s.user_id}" data-field="deduction" value="${s.deduction}" style="width:80px;display:inline-block;"></td>
            <td class="text-end fw-bold total-salary" data-user-id="${s.user_id}">
                ${fmtMoney(s.total_display || s.total)} <span class="text-muted small">${s.display_currency || 'CNY'}</span>
                ${s.rule_currency && s.rule_currency !== s.display_currency ? `<br><span class="text-muted small">${fmtNum(s.total)} ${s.rule_currency}</span>` : ''}
            </td>
            <td>
                <a href="salary_slip_detail.php?user_id=${s.user_id}&month=${document.getElementById('filterMonth').value}" class="btn btn-sm btn-outline-info me-1" title="查看工资条">📄</a>
                <button class="btn btn-sm btn-outline-primary btn-adjust" data-user-id="${s.user_id}" data-user-name="${esc(s.user_name)}">调整</button>
            </td>
        </tr>
        <tr class="detail-row d-none" data-user-id="${s.user_id}">
            <td colspan="11" class="bg-light p-3">
                ${renderDetailContent(s.user_id)}
            </td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
    bindDetailEvents();
    bindSalaryInputEvents();
}

function bindSalaryInputEvents() {
    document.querySelectorAll('.salary-input').forEach(input => {
        input.addEventListener('change', function() {
            const userId = this.dataset.userId;
            const field = this.dataset.field;
            const value = this.value;
            const month = document.getElementById('filterMonth').value;
            
            const fd = new FormData();
            fd.append('user_id', userId);
            fd.append('month', month);
            fd.append('field', field);
            fd.append('value', value);
            fd.append('_csrf', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
            
            fetch(apiUrl('salary_monthly_save.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        updateRowTotal(userId);
                    } else {
                        alert(res.message || '保存失败');
                    }
                })
                .catch(e => {
                    console.error('[CALC_DEBUG] save error:', e);
                    alert('保存失败');
                });
        });
    });
}

function updateRowTotal(userId) {
    const row = document.querySelector(`tr.summary-row[data-user-id="${userId}"]`);
    if (!row) return;
    
    const baseSalary = parseFloat(row.querySelector('[data-field="base_salary"]').value) || 0;
    const attendance = parseFloat(row.querySelector('[data-field="attendance"]').value) || 0;
    const adjustment = parseFloat(row.querySelector('[data-field="adjustment"]').value) || 0;
    const deduction = parseFloat(row.querySelector('[data-field="deduction"]').value) || 0;
    
    const s = calcData.summary.find(x => x.user_id == userId);
    const commission = s ? (s.commission || 0) : 0;
    const incentive = s ? (s.incentive || 0) : 0;
    
    const total = baseSalary + attendance + commission + incentive + adjustment - deduction;
    row.querySelector(`.total-salary[data-user-id="${userId}"]`).textContent = fmtMoney(total);
}

function renderDetailContent(userId) {
    if (!calcData.details) {
        return '<div class="text-muted">无明细数据</div>';
    }
    const d = calcData.details[userId] || calcData.details[String(userId)];
    if (!d) {
        console.log('[SALARY_AUDIT] No detail for userId:', userId);
        return '<div class="text-muted">无明细数据</div>';
    }
    
    const s = (calcData.summary || []).find(item => item.user_id == userId) || {};
    const displayCurrency = document.getElementById('filterDisplayCurrency')?.value || 'CNY';
    const ruleCurrency = s.rule_currency || calcData.rule_currency || 'CNY';
    
    let html = '<div class="row g-3">';
    
    // 档位判定依据
    html += '<div class="col-12"><h6 class="mb-2">📊 档位判定依据</h6>';
    html += `<div class="mb-2"><strong>本月档位基数:</strong> ${fmtMoney(s.tier_base || d.tier_base || 0)} ${ruleCurrency} → <strong>本月档位:</strong> ${fmtRate(s.tier_rate || d.tier_rate || 0)}</div>`;
    if (d.tier_contracts && d.tier_contracts.length > 0) {
        html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>合同</th><th>客户</th><th>类型</th><th class="text-end">原始金额</th><th class="text-end">换算(' + ruleCurrency + ')</th></tr></thead><tbody>';
        d.tier_contracts.forEach(c => {
            html += `<tr><td><a href="finance_contract_detail.php?id=${c.id}" target="_blank">${esc(c.name)}</a></td><td>${esc(c.customer)}</td><td>${esc(c.type)}</td><td class="text-end">${fmtNum(c.amount)} ${c.currency}</td><td class="text-end">${fmtMoney(c.amount_in_rule)}</td></tr>`;
        });
        html += '</tbody></table>';
    } else {
        html += '<div class="text-muted small">本月无签约合同</div>';
    }
    html += '</div>';
    
    // Part1: 本月新单提成
    const part1Display = s.new_order_commission_display || s.new_order_commission || 0;
    html += '<div class="col-md-6"><h6 class="mb-2">💰 Part1: 本月新单提成 <span class="badge bg-success">' + fmtMoney(part1Display) + ' ' + displayCurrency + '</span></h6>';
    if (d.new_orders && d.new_orders.length > 0) {
        html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>合同</th><th>客户</th><th class="text-end">实收(' + displayCurrency + ')</th><th class="text-end">比例</th><th class="text-end">提成(' + displayCurrency + ')</th><th>收款人</th></tr></thead><tbody>';
        d.new_orders.forEach(o => {
            const amountShow = o.amount_display !== undefined ? fmtMoney(o.amount_display) : fmtMoney(o.amount);
            const commShow = o.commission_display !== undefined ? fmtMoney(o.commission_display) : fmtMoney(o.commission);
            const origInfo = o.currency && o.currency !== displayCurrency ? `<br><span class="text-muted small">${fmtNum(o.amount)} ${o.currency}</span>` : '';
            html += `<tr><td><a href="finance_contract_detail.php?id=${o.contract_id}" target="_blank">${esc(o.contract_name)}</a></td><td>${esc(o.customer)}</td><td class="text-end">${amountShow}${origInfo}</td><td class="text-end">${fmtRate(o.rate)}</td><td class="text-end">${commShow}</td><td>${esc(o.collector)}</td></tr>`;
        });
        html += '</tbody></table>';
    } else {
        html += '<div class="text-muted small">无数据</div>';
    }
    html += '</div>';
    
    // Part2: 往期分期提成
    const part2Display = s.installment_commission_display || s.installment_commission || 0;
    html += '<div class="col-md-6"><h6 class="mb-2">📅 Part2: 往期分期提成 <span class="badge bg-info">' + fmtMoney(part2Display) + ' ' + displayCurrency + '</span></h6>';
    if (d.installments && d.installments.length > 0) {
        // 按签约月分组
        const groupedByMonth = {};
        d.installments.forEach(i => {
            const month = i.sign_month || '未知';
            if (!groupedByMonth[month]) {
                groupedByMonth[month] = {
                    items: [],
                    tier_base: i.history_tier_base || 0,
                    tier_base_display: i.history_tier_base_display || 0,
                    tier_rate: i.history_tier_rate || i.rate || 0,
                    tier_contracts: i.history_tier_contracts || []
                };
            }
            groupedByMonth[month].items.push(i);
        });
        
        // 渲染每个签约月分组
        Object.keys(groupedByMonth).sort().reverse().forEach(month => {
            const group = groupedByMonth[month];
            const monthTotal = group.items.reduce((sum, i) => sum + (i.commission_display || i.commission || 0), 0);
            const tierBaseShow = fmtMoney(group.tier_base || 0);
            
            html += `<div class="mb-2 p-2 bg-light rounded"><strong>${month}月档位基数:</strong> ${tierBaseShow} ${ruleCurrency} → <strong>档位:</strong> ${fmtRate(group.tier_rate)}`;
            // 显示构成档位的合同明细（显示原始金额和换算后金额）
            if (group.tier_contracts && group.tier_contracts.length > 0) {
                html += '<div class="small text-muted mt-1">';
                group.tier_contracts.forEach(c => {
                    html += `├ ${esc(c.name)}: ${fmtNum(c.amount)} ${c.currency} (≈${fmtNum(c.amount_in_rule)} ${ruleCurrency})<br>`;
                });
                html += '</div>';
            }
            html += '</div>';
            
            html += '<table class="table table-sm table-bordered mb-2"><thead><tr><th>合同</th><th class="text-end">实收(原币)</th><th class="text-end">提成(' + displayCurrency + ')</th><th>收款人</th></tr></thead><tbody>';
            group.items.forEach(i => {
                const amountShow = i.amount_display !== undefined ? fmtMoney(i.amount_display) : fmtMoney(i.amount);
                const commShow = i.commission_display !== undefined ? fmtMoney(i.commission_display) : fmtMoney(i.commission);
                const origInfo = `${fmtNum(i.amount)} ${i.currency}`;
                html += `<tr><td><a href="finance_contract_detail.php?id=${i.contract_id}" target="_blank">${esc(i.contract_name)}</a></td><td class="text-end">${origInfo}</td><td class="text-end">${commShow}</td><td>${esc(i.collector)}</td></tr>`;
            });
            html += `<tr class="table-secondary"><td colspan="2" class="text-end"><strong>小计</strong></td><td class="text-end"><strong>${fmtMoney(monthTotal)}</strong></td><td></td></tr>`;
            html += '</tbody></table>';
        });
    } else {
        html += '<div class="text-muted small">无数据</div>';
    }
    html += '</div>';
    
    // 手动调整
    if (d.adjustments && d.adjustments.length > 0) {
        html += '<div class="col-12"><h6 class="mb-2">✏️ 手动调整</h6>';
        html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th class="text-end">金额</th><th>原因</th><th>时间</th></tr></thead><tbody>';
        d.adjustments.forEach(a => {
            const dt = new Date(a.created_at * 1000).toLocaleString('zh-CN');
            html += `<tr><td class="text-end">${fmtMoney(a.amount)}</td><td>${esc(a.reason)}</td><td>${dt}</td></tr>`;
        });
        html += '</tbody></table></div>';
    }
    
    html += '</div>';
    return html;
}

function bindDetailEvents() {
    document.querySelectorAll('.toggle-detail').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.closest('tr').dataset.userId;
            const detailRow = document.querySelector('.detail-row[data-user-id="' + userId + '"]');
            if (detailRow.classList.contains('d-none')) {
                detailRow.classList.remove('d-none');
                this.textContent = '▼';
            } else {
                detailRow.classList.add('d-none');
                this.textContent = '▶';
            }
        });
    });
    
    document.querySelectorAll('.btn-adjust').forEach(btn => {
        btn.addEventListener('click', function() {
            const userId = this.dataset.userId;
            const userName = this.dataset.userName;
            document.getElementById('adjUserId').value = userId;
            document.getElementById('adjMonth').value = document.getElementById('filterMonth').value;
            document.getElementById('adjUserName').value = userName;
            document.getElementById('adjAmount').value = '';
            document.getElementById('adjReason').value = '';
            new bootstrap.Modal(document.getElementById('adjustModal')).show();
        });
    });
}

function renderByDepartment() {
    const tbody = document.getElementById('resultBody');
    const summary = calcData.summary || [];
    
    if (summary.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">暂无数据</td></tr>';
        return;
    }
    
    const deptMap = {};
    summary.forEach(s => {
        const dept = s.department || '未分配部门';
        if (!deptMap[dept]) deptMap[dept] = { users: [], total: 0, commission: 0 };
        deptMap[dept].users.push(s);
        deptMap[dept].total += parseFloat(s.total) || 0;
        deptMap[dept].commission += parseFloat(s.commission) || 0;
    });
    
    let html = '';
    let deptIdx = 0;
    for (const dept in deptMap) {
        const data = deptMap[dept];
        html += `<tr class="table-secondary">
            <td><button class="btn btn-sm btn-outline-secondary toggle-dept" data-dept-idx="${deptIdx}">▶</button></td>
            <td colspan="4"><strong>${esc(dept)}</strong> (${data.users.length}人)</td>
            <td class="text-end">${fmtMoney(data.commission)}</td>
            <td colspan="3"></td>
            <td class="text-end fw-bold">${fmtMoney(data.total)}</td>
            <td></td>
        </tr>`;
        data.users.forEach(s => {
            html += `<tr class="dept-detail-row d-none" data-dept-idx="${deptIdx}">
                <td></td>
                <td>${esc(s.user_name)}</td>
                <td>${esc(s.department)}</td>
                <td class="text-end">${fmtMoney(s.base_salary)}</td>
                <td class="text-end">${fmtMoney(s.attendance)}</td>
                <td class="text-end">${fmtMoney(s.commission)}</td>
                <td class="text-end">${fmtMoney(s.incentive)}</td>
                <td class="text-end">${fmtMoney(s.adjustment)}</td>
                <td class="text-end">${fmtMoney(s.deduction)}</td>
                <td class="text-end fw-bold">${fmtMoney(s.total)}</td>
                <td></td>
            </tr>`;
        });
        deptIdx++;
    }
    
    tbody.innerHTML = html;
    bindDeptToggle();
}

function renderByContract() {
    const tbody = document.getElementById('resultBody');
    const details = calcData.details || {};
    
    let allContracts = [];
    for (const uid in details) {
        const d = details[uid];
        const userName = (calcData.summary || []).find(s => String(s.user_id) === String(uid))?.user_name || '';
        (d.new_orders || []).forEach(o => {
            allContracts.push({ ...o, user_name: userName, type: '新单' });
        });
        (d.installments || []).forEach(i => {
            allContracts.push({ ...i, user_name: userName, type: '分期' });
        });
    }
    
    if (allContracts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">暂无合同数据</td></tr>';
        return;
    }
    
    let html = '';
    allContracts.forEach(c => {
        html += `<tr>
            <td></td>
            <td><a href="finance_contract_detail.php?id=${c.contract_id}" target="_blank">${esc(c.contract_name)}</a></td>
            <td>${esc(c.customer)}</td>
            <td>${esc(c.user_name)}</td>
            <td class="text-end">${fmtMoney(c.amount)}</td>
            <td class="text-end">${fmtRate(c.rate)}</td>
            <td class="text-end">${fmtMoney(c.commission)}</td>
            <td>${esc(c.collector)}</td>
            <td>${fmtMethod(c.method)}</td>
            <td><span class="badge ${c.type === '新单' ? 'bg-success' : 'bg-info'}">${c.type}</span></td>
            <td></td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

function bindDeptToggle() {
    document.querySelectorAll('.toggle-dept').forEach(btn => {
        btn.addEventListener('click', function() {
            const deptIdx = this.dataset.deptIdx;
            const rows = document.querySelectorAll(`.dept-detail-row[data-dept-idx="${deptIdx}"]`);
            const isHidden = rows[0]?.classList.contains('d-none');
            rows.forEach(row => row.classList.toggle('d-none', !isHidden));
            this.textContent = isHidden ? '▼' : '▶';
        });
    });
}

document.querySelectorAll('input[name="viewMode"]').forEach(radio => {
    radio.addEventListener('change', function() {
        currentViewMode = this.value;
        if (calcData && calcData.summary) renderResults();
    });
});

document.getElementById('btnCalculate').addEventListener('click', calculate);

document.getElementById('btnSaveAdj').addEventListener('click', function() {
    const fd = new FormData();
    fd.append('user_id', document.getElementById('adjUserId').value);
    fd.append('month', document.getElementById('adjMonth').value);
    fd.append('amount', document.getElementById('adjAmount').value);
    fd.append('reason', document.getElementById('adjReason').value);
    fd.append('_csrf', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
    
    fetch(apiUrl('commission_adjustment_save.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (res && res.success) {
                bootstrap.Modal.getInstance(document.getElementById('adjustModal')).hide();
                calculate();
            } else {
                alert(res.message || '保存失败');
            }
        });
});

document.getElementById('btnExport').addEventListener('click', function() {
    const month = document.getElementById('filterMonth').value;
    const ruleId = document.getElementById('filterRule').value;
    const params = new URLSearchParams({ month });
    if (ruleId) params.append('rule_id', ruleId);
    window.open(apiUrl('salary_export.php?' + params.toString()), '_blank');
});

document.getElementById('filterDisplayCurrency')?.addEventListener('change', function() {
    updateRateInfo();
    if (calcData && calcData.summary) calculate();
});

document.getElementById('filterRateType')?.addEventListener('change', function() {
    updateRateInfo();
    if (calcData && calcData.summary) calculate();
});

document.addEventListener('DOMContentLoaded', function() {
    loadFilters();
    loadCurrencies();
});
</script>

<?php
finance_sidebar_end();
layout_footer();
?>
