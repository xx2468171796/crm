<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅财务/管理员可访问提成规则管理。</div>';
    layout_footer();
    exit;
}

layout_header('提成规则管理');
finance_sidebar_start('commission_rules');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">提成规则管理</h3>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnCreate">新增规则</button>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">返回财务工作台</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="rulesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>名称</th>
                        <th>类型</th>
                        <th>参数</th>
                        <th>适用范围</th>
                        <th>状态</th>
                        <th style="width: 220px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="text-muted small">提示：阶梯规则按“分段累计”计算；比例范围 0~1（例如 0.03 表示 3%）。</div>
    </div>
</div>

<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ruleModalTitle">提成规则</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-rule" data-bs-toggle="tab" data-bs-target="#pane-rule" type="button">提成规则</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-salary" data-bs-toggle="tab" data-bs-target="#pane-salary" type="button">工资组成配置</button>
                    </li>
                </ul>
                <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-rule" role="tabpanel">
                <div class="alert alert-info small mb-3">
                    <strong>📋 计算逻辑说明：</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li><b>档位判定</b>：基于本月新首单+复购单的合同总额确定提成比例</li>
                        <li><b>本月新单提成</b> = 本月新首单实收金额 × 本月档位比例</li>
                        <li><b>往期分期提成</b> = 往期首单本月实收 × 历史锁定档位（按签约时档位）</li>
                        <li><b>规则优先级</b>：个人规则 > 部门规则 > 全局规则</li>
                    </ul>
                </div>
                <form id="ruleForm">
                    <input type="hidden" name="id" id="ruleId" value="0">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">规则名称</label>
                            <input type="text" class="form-control" name="name" id="ruleName" maxlength="80" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">规则类型</label>
                            <select class="form-select" name="rule_type" id="ruleType" required>
                                <option value="fixed">固定比例</option>
                                <option value="tier">阶梯（分段累计）</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">结算货币</label>
                            <select class="form-select" name="currency" id="ruleCurrency">
                                <option value="CNY">人民币 (CNY)</option>
                            </select>
                        </div>

                        <div class="col-md-4" id="fixedRateWrap">
                            <label class="form-label">固定比例（0~1）</label>
                            <input type="number" step="0.0001" min="0" max="1" class="form-control" name="fixed_rate" id="fixedRate" placeholder="例如 0.03">
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_prepay" id="includePrepay" value="1">
                                <label class="form-check-label" for="includePrepay">
                                    包含预收款计入提成 <span class="text-muted small">（勾选后，客户预收入账将计入对应销售的业绩，核销出账将冲减）</span>
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="fw-semibold mb-2">适用范围 <span class="text-muted fw-normal small">（不选择则为全局规则，适用于所有人）</span></div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">适用部门</label>
                                    <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;" id="scopeDepartmentsBox"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">适用人员</label>
                                    <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;" id="scopeUsersBox"></div>
                                </div>
                            </div>
                            <div class="text-muted small mt-1">优先级：个人规则 > 部门规则 > 全局规则</div>
                        </div>

                        <div class="col-12" id="tiersWrap" style="display:none;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">阶梯明细 <span class="text-muted small" id="tiersCurrencyHint">（金额单位：CNY）</span></div>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddTier">新增阶梯</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="tiersTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 120px;">起始金额</th>
                                            <th style="width: 120px;">结束金额</th>
                                            <th style="width: 120px;">比例（0~1）</th>
                                            <th style="width: 100px;">排序</th>
                                            <th style="width: 80px;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-muted small">结束金额留空表示无上限；起始/结束金额建议按从小到大填写。</div>
                        </div>
                    </div>
                </form>
                </div>
                <div class="tab-pane fade" id="pane-salary" role="tabpanel">
                    <div class="alert alert-info small mb-3">
                        <strong>💰 工资组成配置：</strong>配置该规则下的工资组成项，在工资计算页面会根据这些配置显示对应列。
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-semibold">工资组成项</span>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddSalaryComponent">新增组成项</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="salaryComponentsTable">
                            <thead>
                                <tr>
                                    <th style="width:100px;">代码</th>
                                    <th style="width:120px;">名称</th>
                                    <th style="width:100px;">类型</th>
                                    <th style="width:100px;">默认值</th>
                                    <th style="width:100px;">货币</th>
                                    <th style="width:80px;">加/减</th>
                                    <th style="width:80px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="salaryComponentsBody"></tbody>
                        </table>
                    </div>
                    <div class="text-muted small">
                        类型说明：<b>fixed</b>=固定值(按人设置)、<b>calculated</b>=系统计算(如提成)、<b>manual</b>=每月手动输入
                    </div>
                </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnSave">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
function esc(s) {
    const div = document.createElement('div');
    div.textContent = String(s ?? '');
    return div.innerHTML;
}
function apiUrl(path) {
    return API_URL + '/' + path;
}
function fmtRate(r) {
    const x = Number(r);
    if (!isFinite(x)) return '';
    return x.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
}
function fmtMoney(n) {
    const x = Number(n || 0);
    return x.toFixed(2);
}

function ruleTypeLabel(t) {
    if (t === 'fixed') return '固定比例';
    if (t === 'tier') return '阶梯';
    return t || '';
}

let scopeOptions = { departments: [], users: [] };

function loadScopeOptions() {
    return fetch(apiUrl('commission_rule_scope_options.php'))
        .then(r => r.json())
        .then(res => {
            if (res && res.success && res.data) {
                scopeOptions = res.data;
                const deptBox = document.getElementById('scopeDepartmentsBox');
                const userBox = document.getElementById('scopeUsersBox');
                if (!deptBox || !userBox) return;
                
                deptBox.innerHTML = (scopeOptions.departments || []).map(d =>
                    '<div class="form-check"><input class="form-check-input scope-dept" type="checkbox" value="' + d.id + '" id="dept_' + d.id + '">' +
                    '<label class="form-check-label" for="dept_' + d.id + '">' + esc(d.name) + '</label></div>'
                ).join('');
                
                userBox.innerHTML = (scopeOptions.users || []).map(u =>
                    '<div class="form-check"><input class="form-check-input scope-user" type="checkbox" value="' + u.id + '" id="user_' + u.id + '">' +
                    '<label class="form-check-label" for="user_' + u.id + '">' + esc(u.name) + '</label></div>'
                ).join('');
            }
        })
        .catch(() => {});
}

function setSelectedScope(deptIds, userIds) {
    document.querySelectorAll('.scope-dept').forEach(cb => {
        cb.checked = deptIds.includes(Number(cb.value));
    });
    document.querySelectorAll('.scope-user').forEach(cb => {
        cb.checked = userIds.includes(Number(cb.value));
    });
}

function getSelectedScope() {
    const deptIds = Array.from(document.querySelectorAll('.scope-dept:checked')).map(cb => cb.value);
    const userIds = Array.from(document.querySelectorAll('.scope-user:checked')).map(cb => cb.value);
    return { department_ids: deptIds, user_ids: userIds };
}

function buildTierRow(tier) {
    const from = tier && tier.tier_from != null ? tier.tier_from : 0;
    const to = tier && tier.tier_to != null ? tier.tier_to : '';
    const rate = tier && tier.rate != null ? tier.rate : 0;
    const sortOrder = tier && tier.sort_order != null ? tier.sort_order : 1;

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm tier-from" value="${esc(from)}"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm tier-to" value="${esc(to)}" placeholder="留空=无上限"></td>
        <td><input type="number" step="0.0001" min="0" max="1" class="form-control form-control-sm tier-rate" value="${esc(rate)}"></td>
        <td><input type="number" step="1" min="1" class="form-control form-control-sm tier-sort" value="${esc(sortOrder)}"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm btnDelTier">删除</button></td>
    `;
    tr.querySelector('.btnDelTier').addEventListener('click', function() {
        tr.remove();
    });
    return tr;
}

function collectTiers() {
    const tbody = document.querySelector('#tiersTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    return rows.map((tr, idx) => {
        const from = Number(tr.querySelector('.tier-from').value || 0);
        const toRaw = tr.querySelector('.tier-to').value;
        const to = toRaw === '' ? null : Number(toRaw);
        const rate = Number(tr.querySelector('.tier-rate').value || 0);
        const sortOrder = Number(tr.querySelector('.tier-sort').value || (idx + 1));
        return { tier_from: from, tier_to: (toRaw === '' ? null : to), rate: rate, sort_order: sortOrder };
    });
}

function updateRuleTypeUI() {
    const t = document.getElementById('ruleType').value;
    const fixedWrap = document.getElementById('fixedRateWrap');
    const tiersWrap = document.getElementById('tiersWrap');
    if (t === 'fixed') {
        fixedWrap.style.display = '';
        tiersWrap.style.display = 'none';
    } else {
        fixedWrap.style.display = 'none';
        tiersWrap.style.display = '';
    }
}

function openModalForCreate() {
    document.getElementById('ruleModalTitle').textContent = '新增提成规则';
    document.getElementById('ruleId').value = 0;
    document.getElementById('ruleName').value = '';
    document.getElementById('ruleType').value = 'fixed';
    document.getElementById('ruleCurrency').value = 'CNY';
    document.getElementById('fixedRate').value = '';
    document.getElementById('includePrepay').checked = false;
    setSelectedScope([], []);
    const tbody = document.querySelector('#tiersTable tbody');
    tbody.innerHTML = '';
    tbody.appendChild(buildTierRow({ tier_from: 0, tier_to: null, rate: 0.03, sort_order: 1 }));
    updateRuleTypeUI();
    updateTiersCurrencyHint();
    salaryComponents = [];
    renderSalaryComponents();
    const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
    modal.show();
}

function openModalForEdit(rule) {
    document.getElementById('ruleModalTitle').textContent = '编辑提成规则';
    document.getElementById('ruleId').value = Number(rule.id || 0);
    document.getElementById('ruleName').value = rule.name || '';
    document.getElementById('ruleType').value = rule.rule_type || 'fixed';
    document.getElementById('ruleCurrency').value = rule.currency || 'CNY';
    document.getElementById('fixedRate').value = (rule.fixed_rate != null ? rule.fixed_rate : '');
    document.getElementById('includePrepay').checked = (Number(rule.include_prepay || 0) === 1);

    const deptIds = (rule.departments || []).map(d => d.id);
    const userIds = (rule.users || []).map(u => u.id);
    setSelectedScope(deptIds, userIds);

    const tbody = document.querySelector('#tiersTable tbody');
    tbody.innerHTML = '';
    const tiers = Array.isArray(rule.tiers) ? rule.tiers : [];
    if (tiers.length > 0) {
        tiers.forEach(t => tbody.appendChild(buildTierRow(t)));
    } else {
        tbody.appendChild(buildTierRow({ tier_from: 0, tier_to: null, rate: 0.03, sort_order: 1 }));
    }

    updateRuleTypeUI();
    updateTiersCurrencyHint();
    loadSalaryConfig(rule.id);
    const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
    modal.show();
}

function updateTiersCurrencyHint() {
    const currency = document.getElementById('ruleCurrency').value || 'CNY';
    const currencyItem = currencyList.find(c => c.code === currency);
    const currencyName = currencyItem ? currencyItem.name : currency;
    document.getElementById('tiersCurrencyHint').textContent = '（金额单位：' + currencyName + '）';
}

document.getElementById('ruleCurrency').addEventListener('change', updateTiersCurrencyHint);

function renderRules(rows) {
    const tbody = document.querySelector('#rulesTable tbody');
    tbody.innerHTML = '';

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无规则</td></tr>';
        return;
    }

    rows.forEach(r => {
        const isActive = Number(r.is_active || 0) === 1;
        const type = r.rule_type || '';
        let paramText = '';
        if (type === 'fixed') {
            paramText = '比例=' + fmtRate(r.fixed_rate);
        } else {
            const tiers = Array.isArray(r.tiers) ? r.tiers : [];
            paramText = tiers.length ? ('阶梯=' + tiers.length + '段') : '阶梯(未配置)';
        }

        const depts = r.departments || [];
        const users = r.users || [];
        let scopeHtml = '';
        if (depts.length === 0 && users.length === 0) {
            scopeHtml = '<span class="badge bg-info">全局适用</span>';
        } else {
            const labels = [];
            depts.forEach(d => labels.push('<span class="badge bg-primary me-1">' + esc(d.name) + '</span>'));
            users.forEach(u => labels.push('<span class="badge bg-secondary me-1">' + esc(u.name) + '</span>'));
            scopeHtml = labels.join('');
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${esc(r.id)}</td>
            <td>${esc(r.name)}</td>
            <td>${esc(ruleTypeLabel(type))}</td>
            <td>${esc(paramText)}</td>
            <td>${scopeHtml}</td>
            <td>${isActive ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>'}</td>
            <td>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm btnEdit">编辑</button>
                    <button type="button" class="btn btn-outline-${isActive ? 'danger' : 'success'} btn-sm btnToggle">${isActive ? '停用' : '启用'}</button>
                </div>
            </td>
        `;

        tr.querySelector('.btnEdit').addEventListener('click', function() {
            openModalForEdit(r);
        });

        tr.querySelector('.btnToggle').addEventListener('click', function() {
            const actionText = isActive ? '停用' : '启用';
            showConfirmModal('确认' + actionText + '规则？', function() {
                showLoading('提交中...');
                ajaxPost(apiUrl('commission_rule_toggle.php'), {
                    id: r.id,
                    is_active: isActive ? 0 : 1
                }, function(res) {
                    hideLoading();
                    if (!res || !res.success) {
                        showAlertModal((res && res.message) ? res.message : '操作失败', 'error');
                        return;
                    }
                    showAlertModal('已更新', 'success', function() {
                        loadRules();
                    });
                });
            });
        });

        tbody.appendChild(tr);
    });
}

function loadRules() {
    showLoading('加载规则...');
    ajaxGet(apiUrl('commission_rule_list.php'), {}, function(res) {
        hideLoading();
        if (!res || !res.success) {
            showAlertModal((res && res.message) ? res.message : '加载失败', 'error');
            return;
        }
        renderRules(res.data || []);
    });
}

document.getElementById('ruleType').addEventListener('change', updateRuleTypeUI);

document.getElementById('btnAddTier').addEventListener('click', function() {
    const tbody = document.querySelector('#tiersTable tbody');
    const rows = tbody.querySelectorAll('tr');
    const nextOrder = rows.length + 1;
    tbody.appendChild(buildTierRow({ tier_from: 0, tier_to: null, rate: 0.03, sort_order: nextOrder }));
});

document.getElementById('btnCreate').addEventListener('click', openModalForCreate);

document.getElementById('btnSave').addEventListener('click', function() {
    const name = document.getElementById('ruleName').value.trim();
    const ruleType = document.getElementById('ruleType').value;

    if (!name) {
        showAlertModal('规则名称必填', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('id', document.getElementById('ruleId').value || '0');
    fd.append('name', name);
    fd.append('rule_type', ruleType);
    fd.append('currency', document.getElementById('ruleCurrency').value || 'CNY');
    fd.append('include_prepay', document.getElementById('includePrepay').checked ? '1' : '0');

    if (ruleType === 'fixed') {
        const fixedRate = document.getElementById('fixedRate').value;
        fd.append('fixed_rate', fixedRate);
    } else {
        const tiers = collectTiers();
        if (!tiers.length) {
            showAlertModal('请至少配置一条阶梯', 'warning');
            return;
        }
        fd.append('tiers_json', JSON.stringify(tiers));
    }

    const scope = getSelectedScope();
    fd.append('department_ids', scope.department_ids.join(','));
    fd.append('user_ids', scope.user_ids.join(','));
    fd.append('_csrf', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');

    showLoading('保存中...');
    fetch(apiUrl('commission_rule_save.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res || !res.success) {
                hideLoading();
                showAlertModal((res && res.message) ? res.message : '保存失败', 'error');
                return;
            }

            const savedRuleId = (res.id != null ? String(res.id) : document.getElementById('ruleId').value);
            document.getElementById('ruleId').value = savedRuleId;

            return saveSalaryConfig();
        })
        .then(res2 => {
            hideLoading();
            if (res2 && res2.success === false) {
                showAlertModal(res2.message ? res2.message : '保存工资配置失败', 'error');
                return;
            }
            showAlertModal('已保存', 'success', function() {
                const modalEl = document.getElementById('ruleModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                loadRules();
            });
        })
        .catch(() => {
            hideLoading();
            showAlertModal('保存失败，请查看控制台错误信息', 'error');
        });
});

// 工资组成项管理
let salaryComponents = [];

function loadSalaryConfig(ruleId) {
    if (!ruleId) return;
    fetch(apiUrl('salary_config_get.php?rule_id=' + ruleId))
        .then(r => r.json())
        .then(res => {
            if (res && res.success && res.data) {
                salaryComponents = res.data.components || [];
                renderSalaryComponents();
            }
        })
        .catch(() => {});
}

function renderSalaryComponents() {
    const tbody = document.getElementById('salaryComponentsBody');
    if (!tbody) return;
    
    if (salaryComponents.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无配置，点击"新增组成项"添加</td></tr>';
        return;
    }
    
    const currencyOptions = currencyList.map(cur => `<option value="${cur.code}">${cur.code}</option>`).join('');
    
    tbody.innerHTML = salaryComponents.map((c, idx) => `
        <tr>
            <td><input type="text" class="form-control form-control-sm" value="${esc(c.code)}" data-idx="${idx}" data-field="code"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(c.name)}" data-idx="${idx}" data-field="name"></td>
            <td>
                <select class="form-select form-select-sm" data-idx="${idx}" data-field="type">
                    <option value="fixed" ${c.type==='fixed'?'selected':''}>fixed</option>
                    <option value="calculated" ${c.type==='calculated'?'selected':''}>calculated</option>
                    <option value="manual" ${c.type==='manual'?'selected':''}>manual</option>
                </select>
            </td>
            <td><input type="number" step="0.01" class="form-control form-control-sm" value="${c.default||0}" data-idx="${idx}" data-field="default"></td>
            <td>
                <select class="form-select form-select-sm" data-idx="${idx}" data-field="currency">
                    ${currencyList.map(cur => `<option value="${cur.code}" ${(c.currency||'CNY')===cur.code?'selected':''}>${cur.code}</option>`).join('')}
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" data-idx="${idx}" data-field="op">
                    <option value="+" ${c.op==='+'?'selected':''}>+</option>
                    <option value="-" ${c.op==='-'?'selected':''}>-</option>
                </select>
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger btn-del-component" data-idx="${idx}">删除</button></td>
        </tr>
    `).join('');
    
    bindSalaryComponentEvents();
}

function bindSalaryComponentEvents() {
    document.querySelectorAll('#salaryComponentsBody input, #salaryComponentsBody select').forEach(el => {
        el.addEventListener('change', function() {
            const idx = parseInt(this.dataset.idx);
            const field = this.dataset.field;
            if (field === 'default') {
                salaryComponents[idx][field] = parseFloat(this.value) || 0;
            } else {
                salaryComponents[idx][field] = this.value;
            }
        });
    });
    
    document.querySelectorAll('.btn-del-component').forEach(btn => {
        btn.addEventListener('click', function() {
            const idx = parseInt(this.dataset.idx);
            salaryComponents.splice(idx, 1);
            renderSalaryComponents();
        });
    });
}

document.getElementById('btnAddSalaryComponent').addEventListener('click', function() {
    const defaultCurrency = document.getElementById('ruleCurrency').value || 'CNY';
    salaryComponents.push({ code: 'new_field', name: '新字段', type: 'manual', default: 0, currency: defaultCurrency, op: '+' });
    renderSalaryComponents();
});

function saveSalaryConfig() {
    const ruleId = document.getElementById('ruleId').value;
    if (!ruleId || ruleId === '0') return Promise.resolve();
    
    const fd = new FormData();
    fd.append('rule_id', ruleId);
    fd.append('components', JSON.stringify(salaryComponents));
    fd.append('_csrf', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
    
    return fetch(apiUrl('salary_config_save.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json());
}

let currencyList = [];

function loadCurrencies() {
    console.log('[CURRENCY_DEBUG] Loading currencies...');
    return fetch(apiUrl('currency_list.php'), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            console.log('[CURRENCY_DEBUG] API response:', res);
            if (res && res.success && res.data) {
                currencyList = res.data;
                console.log('[CURRENCY_DEBUG] currencyList:', currencyList);
                const sel = document.getElementById('ruleCurrency');
                if (currencyList.length > 0) {
                    sel.innerHTML = currencyList.map(c => `<option value="${c.code}">${c.name} (${c.code})</option>`).join('');
                }
            }
        })
        .catch(e => console.log('[CURRENCY_DEBUG] load error:', e));
}

document.addEventListener('DOMContentLoaded', function() {
    loadCurrencies().then(() => loadScopeOptions()).then(() => loadRules());
});

</script>

<?php
finance_sidebar_end();
layout_footer();
?>

