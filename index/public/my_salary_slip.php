<?php
/**
 * 我的工资条页面
 */
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();
layout_header('我的工资条');
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h4 class="mb-0">我的工资条</h4>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <label class="form-label">结算月份</label>
            <input type="month" class="form-control" id="filterMonth" value="<?= date('Y-m') ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary" onclick="loadSlip()">查询</button>
        </div>
        <div class="col-md-7 d-flex align-items-end justify-content-end gap-2">
            <button class="btn btn-outline-primary" onclick="printSlip()">
                <i class="bi bi-printer"></i> 打印/导出PDF
            </button>
            <button class="btn btn-outline-success" onclick="exportSlip()">
                <i class="bi bi-download"></i> 导出Excel
            </button>
        </div>
    </div>
    
    <div id="slipContent">
        <div class="text-center text-muted py-5">请选择月份后点击查询</div>
    </div>
</div>

<script>
let slipData = null;

function loadSlip() {
    const month = document.getElementById('filterMonth').value;
    if (!month) {
        alert('请选择月份');
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/salary_slip.php?month=' + encodeURIComponent(month))
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                slipData = res.data;
                renderSlip();
            } else {
                document.getElementById('slipContent').innerHTML = 
                    '<div class="alert alert-warning">' + (res.message || '获取失败') + '</div>';
            }
        })
        .catch(e => {
            console.error(e);
            document.getElementById('slipContent').innerHTML = 
                '<div class="alert alert-danger">请求失败</div>';
        });
}

function renderSlip() {
    if (!slipData) return;
    
    const methodMap = {
        'alipay': '支付宝',
        'guoneiweixin': '国内微信',
        'guoneiduigong': '国内对公',
        'zhongguopaypal': '中国PayPal',
        'taiwanxu': '台湾续费',
        'prepay': '预付款',
        'xiapi': '虾皮',
        'other': '其他'
    };
    
    const fmtMethod = (m) => methodMap[m] || m || '-';
    const fmtMoney = (v) => '¥' + (parseFloat(v) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const fmtRate = (r) => ((parseFloat(r) || 0) * 100).toFixed(1) + '%';
    const esc = (s) => {
        const div = document.createElement('div');
        div.textContent = s || '';
        return div.innerHTML;
    };
    
    let html = `
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">${esc(slipData.user_name)} - ${slipData.month} 工资条</h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4"><strong>员工姓名：</strong>${esc(slipData.user_name)}</div>
                <div class="col-md-4"><strong>所属部门：</strong>${esc(slipData.department)}</div>
                <div class="col-md-4"><strong>结算月份：</strong>${slipData.month}</div>
            </div>
        </div>
    </div>
    
    <!-- 基本工资 -->
    <div class="card mb-4">
        <div class="card-header"><strong>💰 基本工资</strong></div>
        <div class="card-body">
            <table class="table table-bordered mb-0">
                <tr><td width="200">底薪</td><td class="text-end">${fmtMoney(slipData.basic.base_salary)}</td></tr>
                <tr><td>全勤奖</td><td class="text-end">${fmtMoney(slipData.basic.attendance)}</td></tr>
                <tr class="table-light"><td><strong>小计</strong></td><td class="text-end"><strong>${fmtMoney(slipData.basic.subtotal)}</strong></td></tr>
            </table>
        </div>
    </div>
    
    <!-- 提成收入 -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>📊 提成收入</strong>
            <span class="badge bg-success">${fmtMoney(slipData.commission.subtotal)}</span>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <span class="me-4"><strong>档位基数：</strong>${fmtMoney(slipData.commission.tier_base)} <small class="text-muted">${slipData.rule_currency || 'TWD'}</small></span>
                <span><strong>档位比例：</strong>${fmtRate(slipData.commission.tier_rate)}</span>
            </div>
            <div class="small text-muted mb-2">提成规则货币: ${slipData.rule_currency || 'TWD'}</div>`;
    
    // Part1: 新单提成
    if (slipData.commission.new_orders && slipData.commission.new_orders.length > 0) {
        html += `
            <h6 class="mt-4 mb-2">Part1: 本月新单提成 <span class="badge bg-success">${fmtMoney(slipData.commission.part1_commission)}</span></h6>
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>合同名称</th>
                        <th>客户</th>
                        <th class="text-end">收款金额(${slipData.rule_currency || 'TWD'})</th>
                        <th class="text-end">比例</th>
                        <th class="text-end">提成(${slipData.rule_currency || 'TWD'})</th>
                        <th>收款人</th>
                        <th>方式</th>
                    </tr>
                </thead>
                <tbody>`;
        slipData.commission.new_orders.forEach(o => {
            html += `<tr>
                <td><a href="contract_detail.php?id=${o.contract_id}" target="_blank">${esc(o.contract_name)}</a></td>
                <td>${esc(o.customer)}</td>
                <td class="text-end">${(parseFloat(o.amount) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2})}</td>
                <td class="text-end">${fmtRate(o.rate)}</td>
                <td class="text-end">${(parseFloat(o.commission) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2})}</td>
                <td>${esc(o.collector)}</td>
                <td>${fmtMethod(o.method)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    
    // Part2: 分期提成
    if (slipData.commission.installments && slipData.commission.installments.length > 0) {
        html += `
            <h6 class="mt-4 mb-2">Part2: 往期分期提成 <span class="badge bg-info">${fmtMoney(slipData.commission.part2_commission)}</span></h6>
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>合同名称</th>
                        <th>客户</th>
                        <th class="text-end">收款金额(${slipData.rule_currency || 'TWD'})</th>
                        <th class="text-end">比例</th>
                        <th class="text-end">提成(${slipData.rule_currency || 'TWD'})</th>
                        <th>收款人</th>
                        <th>方式</th>
                    </tr>
                </thead>
                <tbody>`;
        slipData.commission.installments.forEach(i => {
            html += `<tr>
                <td><a href="contract_detail.php?id=${i.contract_id}" target="_blank">${esc(i.contract_name)}</a></td>
                <td>${esc(i.customer)}</td>
                <td class="text-end">${(parseFloat(i.amount) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2})}</td>
                <td class="text-end">${fmtRate(i.rate)}</td>
                <td class="text-end">${(parseFloat(i.commission) || 0).toLocaleString('zh-CN', {minimumFractionDigits: 2})}</td>
                <td>${esc(i.collector)}</td>
                <td>${fmtMethod(i.method)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    
    if ((!slipData.commission.new_orders || slipData.commission.new_orders.length === 0) && 
        (!slipData.commission.installments || slipData.commission.installments.length === 0)) {
        html += '<div class="text-muted">本月无提成数据</div>';
    }
    
    html += `
        </div>
    </div>
    
    <!-- 其他 -->
    <div class="card mb-4">
        <div class="card-header"><strong>📋 其他</strong></div>
        <div class="card-body">
            <table class="table table-bordered mb-0">
                <tr><td width="200">激励奖金</td><td class="text-end">${fmtMoney(slipData.other.incentive)}</td></tr>
                <tr><td>手动调整</td><td class="text-end">${fmtMoney(slipData.other.adjustment)}</td></tr>
                <tr><td>扣款</td><td class="text-end text-danger">-${fmtMoney(slipData.other.deduction)}</td></tr>
            </table>
        </div>
    </div>
    
    <!-- 总计 -->
    <div class="card border-primary">
        <div class="card-body bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">应发工资合计</h5>
                <h3 class="mb-0 text-primary">${fmtMoney(slipData.total)}</h3>
            </div>
        </div>
    </div>`;
    
    document.getElementById('slipContent').innerHTML = html;
}

function exportSlip() {
    const month = document.getElementById('filterMonth').value;
    if (!month) {
        alert('请先选择月份');
        return;
    }
    window.location.href = '<?= BASE_URL ?>/api/salary_slip_export.php?month=' + encodeURIComponent(month);
}

function printSlip() {
    const month = document.getElementById('filterMonth').value;
    if (!month) {
        alert('请先选择月份');
        return;
    }
    window.open('<?= BASE_URL ?>/api/salary_slip_print.php?month=' + encodeURIComponent(month), '_blank');
}

document.addEventListener('DOMContentLoaded', function() {
    loadSlip();
});
</script>

<?php layout_footer(); ?>
