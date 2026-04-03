<?php
/**
 * 工资条详情页面（管理员视角）
 * 支持多选部门、多选人员、展开收起、批量导出ZIP
 */
require_once __DIR__ . '/../core/layout.php';

auth_require();
requirePermission(PermissionCode::FINANCE_VIEW, false);

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$month = $_GET['month'] ?? date('Y-m');

layout_header('工资条详情');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<style>
.multi-check-dropdown { position: relative; }
.multi-check-dropdown .dropdown-menu { min-width: 250px; max-height: 300px; overflow-y: auto; padding: 10px; }
.multi-check-dropdown .check-item { padding: 5px 10px; cursor: pointer; border-radius: 4px; }
.multi-check-dropdown .check-item:hover { background: #f0f0f0; }
.multi-check-dropdown .check-item input { margin-right: 8px; }
.multi-check-dropdown .select-all { border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 8px; font-weight: bold; }
.slip-card { transition: all 0.3s; }
.slip-card.collapsed .card-body { display: none; }
.slip-card .card-header { cursor: pointer; }
.slip-card .toggle-icon { transition: transform 0.3s; }
.slip-card.collapsed .toggle-icon { transform: rotate(-90deg); }
.batch-actions { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.selected-count { font-weight: bold; color: #1890ff; }
.export-progress { display: none; margin-top: 10px; }
.export-progress.active { display: block; }
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="commission_calculator.php">工资计算</a></li>
                    <li class="breadcrumb-item active">工资条详情</li>
                </ol>
            </nav>
            <h4 class="mb-0">工资条详情</h4>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">结算月份</label>
                    <input type="month" class="form-control" id="filterMonth" value="<?= htmlspecialchars($month) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">显示货币</label>
                    <select class="form-select" id="filterDisplayCurrency">
                        <option value="CNY">CNY</option>
                        <option value="TWD">TWD</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">汇率类型</label>
                    <select class="form-select" id="filterRateType">
                        <option value="fixed">固定</option>
                        <option value="floating">浮动</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">选择部门</label>
                    <div class="multi-check-dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="deptDropdownBtn">
                            全部部门
                        </button>
                        <div class="dropdown-menu w-100" id="deptDropdown">
                            <div class="check-item select-all">
                                <label><input type="checkbox" id="deptSelectAll" checked onchange="toggleAllDepts()"> 全选</label>
                            </div>
                            <div id="deptList"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">选择人员</label>
                    <div class="multi-check-dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="userDropdownBtn">
                            全部人员
                        </button>
                        <div class="dropdown-menu w-100" id="userDropdown">
                            <div class="check-item select-all">
                                <label><input type="checkbox" id="userSelectAll" checked onchange="toggleAllUsers()"> 全选</label>
                            </div>
                            <div id="userList"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary" onclick="loadAllSlips()">
                        <i class="bi bi-search"></i> 查询
                    </button>
                    <button class="btn btn-outline-secondary" onclick="toggleAllCards()">
                        <i class="bi bi-arrows-collapse"></i> 展开/收起
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-download"></i> 批量导出
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="batchExportZip();return false;">📦 导出PDF压缩包</a></li>
                            <li><a class="dropdown-item" href="#" onclick="batchExportExcel();return false;">📊 导出Excel汇总</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="slipSummary" class="batch-actions" style="display:none;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex flex-wrap gap-3">
                <span>已加载 <span class="badge bg-primary" id="loadedCount">0</span> 人</span>
                <span>基本工资：<strong class="text-secondary" id="totalBasic">¥0.00</strong></span>
                <span>提成合计：<strong class="text-success" id="totalCommission">¥0.00</strong></span>
                <span>工资总额：<strong class="text-primary fs-5" id="totalSalary">¥0.00</strong></span>
            </div>
        </div>
        <div id="exportProgress" class="export-progress">
            <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:20px;">
                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <span id="progressText" class="text-muted small">准备中...</span>
            </div>
        </div>
    </div>
    
    <div id="slipContent">
        <div class="text-center text-muted py-5">请选择月份、部门、人员后点击查询</div>
    </div>
</div>

<script>
let allDepts = [];
let allUsers = [];
let loadedSlips = [];
const initUserId = <?= $userId ?>;

function loadOptions() {
    fetch('<?= BASE_URL ?>/api/commission_rule_scope_options.php')
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                allDepts = res.data.departments || [];
                allUsers = res.data.users || [];
                renderDeptList();
                renderUserList();
                
                // 如果有initUserId，只选中该用户并自动加载
                if (initUserId > 0) {
                    document.querySelectorAll('.user-check').forEach(c => {
                        c.checked = (parseInt(c.value) === initUserId);
                    });
                    document.getElementById('userSelectAll').checked = false;
                    updateUserDropdownBtn();
                    loadAllSlips();
                }
            }
        });
}

function renderDeptList() {
    const container = document.getElementById('deptList');
    container.innerHTML = allDepts.map(d => `
        <div class="check-item">
            <label><input type="checkbox" class="dept-check" value="${d.id}" checked onchange="onDeptChange()"> ${escHtml(d.name)}</label>
        </div>
    `).join('');
}

function renderUserList() {
    const selectedDepts = getSelectedDepts();
    const filteredUsers = selectedDepts.length === allDepts.length 
        ? allUsers 
        : allUsers.filter(u => selectedDepts.includes(String(u.department_id)));
    
    const container = document.getElementById('userList');
    container.innerHTML = filteredUsers.map(u => `
        <div class="check-item">
            <label><input type="checkbox" class="user-check" value="${u.id}" checked> ${escHtml(u.name)}</label>
        </div>
    `).join('');
    updateUserDropdownBtn();
}

function getSelectedDepts() {
    return [...document.querySelectorAll('.dept-check:checked')].map(c => c.value);
}

function getSelectedUsers() {
    return [...document.querySelectorAll('.user-check:checked')].map(c => c.value);
}

function toggleAllDepts() {
    const checked = document.getElementById('deptSelectAll').checked;
    document.querySelectorAll('.dept-check').forEach(c => c.checked = checked);
    onDeptChange();
}

function toggleAllUsers() {
    const checked = document.getElementById('userSelectAll').checked;
    document.querySelectorAll('.user-check').forEach(c => c.checked = checked);
    updateUserDropdownBtn();
}

function onDeptChange() {
    const selected = getSelectedDepts();
    document.getElementById('deptSelectAll').checked = selected.length === allDepts.length;
    document.getElementById('deptDropdownBtn').textContent = 
        selected.length === allDepts.length ? '全部部门' : `已选${selected.length}个部门`;
    renderUserList();
}

function updateUserDropdownBtn() {
    const selected = getSelectedUsers();
    const total = document.querySelectorAll('.user-check').length;
    document.getElementById('userSelectAll').checked = selected.length === total;
    document.getElementById('userDropdownBtn').textContent = 
        selected.length === total ? '全部人员' : `已选${selected.length}人`;
}

function escHtml(s) {
    const div = document.createElement('div');
    div.textContent = s || '';
    return div.innerHTML;
}

let commissionCalcData = null; // 缓存提成计算数据

async function loadAllSlips() {
    const month = document.getElementById('filterMonth').value;
    const userIds = getSelectedUsers();
    const displayCurrency = document.getElementById('filterDisplayCurrency').value;
    const rateType = document.getElementById('filterRateType').value;
    
    if (!month) { alert('请选择月份'); return; }
    if (userIds.length === 0) { alert('请至少选择一名员工'); return; }
    
    document.getElementById('slipContent').innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div> 加载中...</div>';
    
    // 先获取提成计算数据（与提成报表一致）
    try {
        const ruleRes = await fetch('<?= BASE_URL ?>/api/commission_rule_options.php');
        const ruleData = await ruleRes.json();
        const activeRuleId = ruleData.success && ruleData.data && ruleData.data.length > 0 ? ruleData.data[0].id : 0;
        console.log('[CSDEBUG] 活动规则ID:', activeRuleId, ruleData);
        
        if (activeRuleId > 0) {
            const calcRes = await fetch('<?= BASE_URL ?>/api/commission_calculate.php?month=' + encodeURIComponent(month) + '&rule_id=' + activeRuleId + '&display_currency=' + displayCurrency + '&rate_type=' + rateType);
            commissionCalcData = await calcRes.json();
            console.log('[CSDEBUG] 提成计算数据:', commissionCalcData);
        }
    } catch (e) { console.error('[CSDEBUG] 获取提成数据失败:', e); }
    
    // 再获取各用户的基本工资数据
    loadedSlips = [];
    for (const uid of userIds) {
        try {
            const res = await fetch('<?= BASE_URL ?>/api/salary_slip.php?user_id=' + uid + '&month=' + encodeURIComponent(month) + '&display_currency=' + displayCurrency + '&rate_type=' + rateType);
            const data = await res.json();
            if (data.success && data.data) {
                // 合并提成计算数据
                const slipData = { userId: uid, ...data.data };
                if (commissionCalcData && commissionCalcData.success && commissionCalcData.data) {
                    const calcData = commissionCalcData.data;
                    const userSummary = (calcData.summary || []).find(s => String(s.user_id) === String(uid));
                    const userDetails = calcData.details ? calcData.details[String(uid)] : null;
                    console.log('[CSDEBUG] 用户', uid, '汇总:', userSummary, '明细:', userDetails);
                    if (userSummary) {
                        slipData.commission = {
                            tier_base: userSummary.tier_base || 0,
                            tier_rate: userSummary.tier_rate || 0,
                            tier_contracts: userDetails?.tier_contracts || [],
                            part1_commission: userSummary.new_order_commission || 0,
                            part1_commission_display: userSummary.new_order_commission_display || 0,
                            part2_commission: userSummary.installment_commission || 0,
                            part2_commission_display: userSummary.installment_commission_display || 0,
                            new_orders: userDetails?.new_orders || [],
                            installments: userDetails?.installments || [],
                            subtotal: userSummary.commission || 0,
                            subtotal_display: userSummary.commission_display || 0,
                        };
                        slipData.rule_currency = calcData.rule_currency || 'TWD';
                        slipData.display_currency = calcData.display_currency || displayCurrency;
                        slipData.total_display = userSummary.total_display || slipData.total;
                    }
                }
                loadedSlips.push(slipData);
            }
        } catch (e) { console.error(e); }
    }
    
    renderAllSlips();
}

function renderAllSlips() {
    if (loadedSlips.length === 0) {
        document.getElementById('slipContent').innerHTML = '<div class="alert alert-warning">没有找到工资条数据</div>';
        document.getElementById('slipSummary').style.display = 'none';
        return;
    }
    
    const displayCurrency = document.getElementById('filterDisplayCurrency').value || 'CNY';
    const fmtMoney = (v) => displayCurrency + (parseFloat(v) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // 计算汇总数据（使用显示货币值）
    let totalBasic = 0, totalCommission = 0, totalSalary = 0;
    loadedSlips.forEach(s => {
        totalBasic += (parseFloat(s.basic?.subtotal) || 0);
        totalCommission += (parseFloat(s.commission?.subtotal_display) || parseFloat(s.commission?.subtotal) || 0);
        totalSalary += (parseFloat(s.total_display) || parseFloat(s.total) || 0);
    });
    
    document.getElementById('loadedCount').textContent = loadedSlips.length;
    document.getElementById('totalBasic').textContent = fmtMoney(totalBasic);
    document.getElementById('totalCommission').textContent = fmtMoney(totalCommission);
    document.getElementById('totalSalary').textContent = fmtMoney(totalSalary);
    document.getElementById('slipSummary').style.display = 'block';
    
    let html = '';
    loadedSlips.forEach((slip, idx) => {
        html += renderSingleSlipCard(slip, idx);
    });
    
    document.getElementById('slipContent').innerHTML = html;
}

function renderSingleSlipCard(slip, idx) {
    const fmtMoney = (v) => (parseFloat(v) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const fmtRate = (r) => ((parseFloat(r) || 0) * 100).toFixed(2) + '%';
    const ruleCurrency = slip.rule_currency || 'TWD';
    const displayCurrency = slip.display_currency || 'CNY';
    
    // 渲染新单明细
    let newOrdersHtml = '';
    if (slip.commission?.new_orders && slip.commission.new_orders.length > 0) {
        newOrdersHtml = `
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr><th>合同</th><th>客户</th><th class="text-end">实收(${ruleCurrency})</th><th class="text-end">比例</th><th class="text-end">提成(${ruleCurrency})</th><th>收款人</th></tr></thead>
            <tbody>
                ${slip.commission.new_orders.map(o => `
                    <tr>
                        <td><a href="finance_contract_detail.php?id=${o.contract_id}" target="_blank">${escHtml(o.contract_name)}</a></td>
                        <td>${escHtml(o.customer)}</td>
                        <td class="text-end">${fmtMoney(o.amount)}</td>
                        <td class="text-end">${fmtRate(o.rate)}</td>
                        <td class="text-end">${fmtMoney(o.commission)}</td>
                        <td>${escHtml(o.collector)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
    } else {
        newOrdersHtml = '<div class="text-muted small">本月无新单收款</div>';
    }
    
    // 渲染分期明细（按月份分组）
    let installmentsHtml = '';
    const historyGroups = slip.commission?.history_groups || [];
    if (historyGroups.length > 0) {
        installmentsHtml = historyGroups.map(group => `
            <div class="mb-2 p-2 bg-light rounded">
                <strong>${group.month}月档位基数:</strong> ${fmtMoney(group.tier_base)} ${ruleCurrency} → <strong>档位:</strong> ${fmtRate(group.tier_rate)}
                <span class="float-end badge bg-warning text-dark">${fmtMoney(group.commission)} ${ruleCurrency}</span>
            </div>
            <div class="small text-muted mb-1">
                ${(group.receipts || []).map(r => `├ ${escHtml(r.contract_name)}: ${fmtMoney(r.amount)} ${r.currency || ruleCurrency} (≈${fmtMoney(r.amount_in_rule || r.amount)} ${ruleCurrency})`).join('<br>')}
            </div>
        `).join('');
    } else if (slip.commission?.installments && slip.commission.installments.length > 0) {
        // 兼容旧格式
        installmentsHtml = `
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr><th>合同</th><th>客户</th><th class="text-end">实收(${ruleCurrency})</th><th class="text-end">比例</th><th class="text-end">提成(${ruleCurrency})</th><th>收款人</th></tr></thead>
            <tbody>
                ${slip.commission.installments.map(i => `
                    <tr>
                        <td><a href="finance_contract_detail.php?id=${i.contract_id}" target="_blank">${escHtml(i.contract_name)}</a></td>
                        <td>${escHtml(i.customer)}</td>
                        <td class="text-end">${fmtMoney(i.amount)}</td>
                        <td class="text-end">${fmtRate(i.rate)}</td>
                        <td class="text-end">${fmtMoney(i.commission)}</td>
                        <td>${escHtml(i.collector)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
    } else {
        installmentsHtml = '<div class="text-muted small">本月无往期分期收款</div>';
    }
    
    return `
    <div class="card mb-3 slip-card" id="slip-${idx}">
        <div class="card-header d-flex justify-content-between align-items-center" onclick="toggleCard(${idx})" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;">
            <div>
                <i class="bi bi-chevron-down toggle-icon"></i>
                <strong class="ms-2">${escHtml(slip.user_name)}</strong>
                <span class="ms-2" style="opacity:0.9">${escHtml(slip.department)}</span>
            </div>
            <div>
                <span class="badge bg-light text-dark fs-6">¥${fmtMoney(slip.total)}</span>
                <button class="btn btn-sm btn-outline-light ms-2" onclick="event.stopPropagation();openSinglePDF('${slip.userId}')">PDF</button>
            </div>
        </div>
        <div class="card-body">
            <!-- 档位信息 -->
            <div class="alert alert-info py-2 mb-3">
                <strong>📊 本月档位基数:</strong> ${fmtMoney(slip.commission?.tier_base)} ${ruleCurrency} → <strong>本月档位:</strong> ${fmtRate(slip.commission?.tier_rate)}
            </div>
            
            <div class="row">
                <!-- 基本工资 -->
                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-header py-2" style="background:#f0f5ff;border-left:4px solid #667eea;">
                            <strong>💰 基本工资</strong>
                        </div>
                        <div class="card-body p-2">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td>底薪</td><td class="text-end">¥${fmtMoney(slip.basic?.base_salary)}</td></tr>
                                <tr><td>全勤奖</td><td class="text-end">¥${fmtMoney(slip.basic?.attendance)}</td></tr>
                                <tr class="border-top"><td><strong>小计</strong></td><td class="text-end"><strong>¥${fmtMoney(slip.basic?.subtotal)}</strong></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Part1: 本月新单提成 -->
                <div class="col-md-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between" style="background:#e6fffb;border-left:4px solid #13c2c2;">
                            <strong>💼 Part1: 本月新单提成</strong>
                            <span class="badge bg-success">${fmtMoney(slip.commission?.part1_commission)} ${ruleCurrency}</span>
                        </div>
                        <div class="card-body p-2" style="max-height:200px;overflow-y:auto;">
                            ${newOrdersHtml}
                        </div>
                    </div>
                </div>
                
                <!-- Part2: 往期分期提成 -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between" style="background:#fff7e6;border-left:4px solid #fa8c16;">
                            <strong>📅 Part2: 往期分期提成</strong>
                            <span class="badge bg-warning text-dark">${fmtMoney(slip.commission?.part2_commission)} ${ruleCurrency}</span>
                        </div>
                        <div class="card-body p-2" style="max-height:200px;overflow-y:auto;">
                            ${installmentsHtml}
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 其他项目和汇总 -->
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-2" style="background:#f6ffed;border-left:4px solid #52c41a;">
                            <strong>📋 其他收入</strong>
                        </div>
                        <div class="card-body p-2">
                            <div class="row">
                                <div class="col-4"><span class="text-muted">激励奖金</span><br><strong>¥${fmtMoney(slip.other?.incentive)}</strong></div>
                                <div class="col-4"><span class="text-muted">手动调整</span><br><strong>¥${fmtMoney(slip.other?.adjustment)}</strong></div>
                                <div class="col-4"><span class="text-muted">扣款</span><br><strong class="text-danger">-¥${fmtMoney(slip.other?.deduction)}</strong></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="small" style="opacity:0.9">提成合计: ${displayCurrency}${fmtMoney(slip.commission?.subtotal_display || slip.commission?.subtotal)}</div>
                                    <div class="small" style="opacity:0.9">基本工资: ${displayCurrency}${fmtMoney(slip.basic?.subtotal)}</div>
                                </div>
                                <div class="text-end">
                                    <div class="small" style="opacity:0.9">应发工资合计 (${displayCurrency})</div>
                                    <div class="fs-3 fw-bold">${displayCurrency}${fmtMoney(slip.total_display || slip.total)}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>`;
}

function toggleCard(idx) {
    document.getElementById('slip-' + idx).classList.toggle('collapsed');
}

function toggleAllCards() {
    const cards = document.querySelectorAll('.slip-card');
    const allCollapsed = [...cards].every(c => c.classList.contains('collapsed'));
    cards.forEach(c => {
        if (allCollapsed) c.classList.remove('collapsed');
        else c.classList.add('collapsed');
    });
}

function openSinglePDF(userId) {
    const month = document.getElementById('filterMonth').value;
    const displayCurrency = document.getElementById('filterDisplayCurrency').value || 'CNY';
    const rateType = document.getElementById('filterRateType').value || 'fixed';
    window.open('<?= BASE_URL ?>/api/salary_slip_print.php?user_id=' + userId + '&month=' + encodeURIComponent(month) + '&display_currency=' + displayCurrency + '&rate_type=' + rateType, '_blank');
}

async function batchExportZip() {
    if (loadedSlips.length === 0) {
        alert('请先查询数据');
        return;
    }
    
    const month = document.getElementById('filterMonth').value;
    const progressDiv = document.getElementById('exportProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    progressDiv.classList.add('active');
    progressText.textContent = '准备生成PDF...';
    
    const zip = new JSZip();
    const total = loadedSlips.length;
    
    for (let i = 0; i < total; i++) {
        const slip = loadedSlips[i];
        progressText.textContent = `正在生成 ${slip.user_name} 的PDF (${i+1}/${total})`;
        progressBar.style.width = ((i + 1) / total * 100) + '%';
        
        const pdfBlob = await generateSlipPDF(slip, month);
        const filename = slip.user_name + '_' + month + '_工资条.pdf';
        zip.file(filename, pdfBlob);
    }
    
    progressText.textContent = '正在打包ZIP...';
    const zipBlob = await zip.generateAsync({ type: 'blob' });
    saveAs(zipBlob, month + '_工资条(' + total + '人).zip');
    
    progressDiv.classList.remove('active');
    alert('导出完成！共 ' + total + ' 人');
}

function generateSlipPDF(slip, month) {
    return new Promise((resolve) => {
        const displayCurrency = slip.display_currency || document.getElementById('filterDisplayCurrency').value || 'CNY';
        const ruleCurrency = slip.rule_currency || 'TWD';
        const fmtMoney = (v) => displayCurrency + (parseFloat(v) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const fmtMoneyRule = (v) => ruleCurrency + (parseFloat(v) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const fmtRate = (r) => ((parseFloat(r) || 0) * 100).toFixed(1) + '%';
        const monthDisplay = month.replace('-', '年') + '月';
        
        const html = `
        <div style="font-family:'Microsoft YaHei',sans-serif;padding:20px;max-width:700px;">
            <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;text-align:center;margin-bottom:20px;">
                <h1 style="font-size:24px;margin:0 0 5px 0;">工 资 条</h1>
                <p style="margin:0;opacity:.9">${monthDisplay}</p>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px dashed #ddd;margin-bottom:15px;">
                <span><strong>员工：</strong>${escHtml(slip.user_name)}</span>
                <span><strong>部门：</strong>${escHtml(slip.department)}</span>
                <span><strong>结算月份：</strong>${monthDisplay}</span>
            </div>
            <div style="margin-bottom:15px;">
                <h3 style="background:#e6f7ff;padding:10px;margin:0 0 10px 0;border-left:4px solid #1890ff;color:#1890ff;">一、基本工资</h3>
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="background:#fafafa;"><th style="padding:8px;border:1px solid #ddd;text-align:left;">项目</th><th style="padding:8px;border:1px solid #ddd;text-align:right;">金额 (${displayCurrency})</th></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">底薪</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.basic?.base_salary)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">全勤奖</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.basic?.attendance)}</td></tr>
                    <tr style="background:#f0f5ff;font-weight:bold;"><td style="padding:8px;border:1px solid #ddd;">小计</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.basic?.subtotal)}</td></tr>
                </table>
            </div>
            <div style="margin-bottom:15px;">
                <h3 style="background:#f6ffed;padding:10px;margin:0 0 10px 0;border-left:4px solid #52c41a;color:#52c41a;">二、提成收入</h3>
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="background:#fafafa;"><th style="padding:8px;border:1px solid #ddd;text-align:left;">项目</th><th style="padding:8px;border:1px solid #ddd;text-align:right;">金额</th></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">档位基数</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoneyRule(slip.commission?.tier_base)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">档位比例</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtRate(slip.commission?.tier_rate)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">Part1: 新单提成</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.commission?.part1_commission_display || slip.commission?.part1_commission)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">Part2: 分期提成</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.commission?.part2_commission_display || slip.commission?.part2_commission)}</td></tr>
                    <tr style="background:#f6ffed;font-weight:bold;"><td style="padding:8px;border:1px solid #ddd;">提成合计</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.commission?.subtotal_display || slip.commission?.subtotal)}</td></tr>
                </table>
            </div>
            <div style="margin-bottom:15px;">
                <h3 style="background:#fff7e6;padding:10px;margin:0 0 10px 0;border-left:4px solid #fa8c16;color:#fa8c16;">三、其他</h3>
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="background:#fafafa;"><th style="padding:8px;border:1px solid #ddd;text-align:left;">项目</th><th style="padding:8px;border:1px solid #ddd;text-align:right;">金额 (${displayCurrency})</th></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">激励奖金</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.other?.incentive)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">手动调整</td><td style="padding:8px;border:1px solid #ddd;text-align:right;">${fmtMoney(slip.other?.adjustment)}</td></tr>
                    <tr><td style="padding:8px;border:1px solid #ddd;">扣款</td><td style="padding:8px;border:1px solid #ddd;text-align:right;color:#f5222d;">-${fmtMoney(slip.other?.deduction)}</td></tr>
                </table>
            </div>
            <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:16px;">应发工资合计 (${displayCurrency})</span>
                <span style="font-size:28px;font-weight:bold;">${fmtMoney(slip.total_display || slip.total)}</span>
            </div>
        </div>`;
        
        const container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);
        
        html2pdf().set({
            margin: 10,
            filename: slip.user_name + '.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        }).from(container).outputPdf('blob').then(blob => {
            document.body.removeChild(container);
            resolve(blob);
        });
    });
}

function batchExportExcel() {
    const month = document.getElementById('filterMonth').value;
    const userIds = getSelectedUsers();
    if (!month) { alert('请选择月份'); return; }
    
    let url = '<?= BASE_URL ?>/api/salary_slip_batch_export.php?month=' + encodeURIComponent(month);
    if (userIds.length > 0 && userIds.length < allUsers.length) {
        url += '&user_ids=' + userIds.join(',');
    }
    window.location.href = url;
}

document.addEventListener('DOMContentLoaded', loadOptions);
</script>

<?php layout_footer(); ?>
