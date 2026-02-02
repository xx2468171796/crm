<?php
/**
 * 手续费报表页面
 */

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限访问此页面</div>';
    layout_footer();
    exit;
}

layout_header('手续费报表');
finance_sidebar_start('finance_fee_report');
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/index.php">首页</a></li>
                    <li class="breadcrumb-item"><a href="index.php?page=finance_dashboard">财务工作台</a></li>
                    <li class="breadcrumb-item active">手续费报表</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4>手续费报表</h4>
                    <p class="text-muted mb-0">按收款方式统计手续费加成情况</p>
                </div>
            </div>

            <!-- 筛选条件 -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 small text-muted">开始日期</label>
                            <input type="date" class="form-control form-control-sm" id="startDate" style="width:150px;">
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 small text-muted">结束日期</label>
                            <input type="date" class="form-control form-control-sm" id="endDate" style="width:150px;">
                        </div>
                        <button class="btn btn-primary btn-sm" id="btnSearch">查询</button>
                        <button class="btn btn-outline-secondary btn-sm" id="btnThisMonth">本月</button>
                        <button class="btn btn-outline-secondary btn-sm" id="btnLastMonth">上月</button>
                    </div>
                </div>
            </div>

            <!-- 汇总统计 -->
            <div class="row mb-3" id="summaryCards">
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <div class="small text-muted">收款笔数</div>
                            <div class="h4 mb-0" id="totalCount">0</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <div class="small text-muted">原始金额合计</div>
                            <div class="h4 mb-0" id="totalOriginal">0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <div class="small">手续费合计</div>
                            <div class="h4 mb-0" id="totalFee">0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <div class="small">实收金额合计</div>
                            <div class="h4 mb-0" id="totalReceived">0.00</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 按收款方式汇总表 -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>按收款方式汇总</strong>
                </div>
                <div class="card-body">
                    <table class="table table-hover" id="summaryTable">
                        <thead>
                            <tr>
                                <th>收款方式</th>
                                <th class="text-end">收款笔数</th>
                                <th class="text-end">原始金额</th>
                                <th class="text-end">手续费</th>
                                <th class="text-end">实收金额</th>
                                <th width="100">操作</th>
                            </tr>
                        </thead>
                        <tbody id="summaryBody">
                            <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 明细表（点击展开） -->
            <div class="card" id="detailCard" style="display:none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><span id="detailTitle">收款明细</span></strong>
                    <button class="btn btn-sm btn-outline-secondary" id="btnCloseDetail">关闭</button>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-hover" id="detailTable">
                        <thead>
                            <tr>
                                <th>收款日期</th>
                                <th>客户</th>
                                <th>合同</th>
                                <th class="text-end">原始金额</th>
                                <th class="text-end">手续费</th>
                                <th class="text-end">实收金额</th>
                                <th>备注</th>
                            </tr>
                        </thead>
                        <tbody id="detailBody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function apiUrl(path) {
    return API_URL + '/' + path;
}

function fmt(n) {
    return Number(n || 0).toFixed(2);
}

function fmtInt(n) {
    return Number(n || 0).toLocaleString();
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 设置默认日期为本月
function setThisMonth() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('startDate').value = `${year}-${month}-01`;
    const lastDay = new Date(year, now.getMonth() + 1, 0).getDate();
    document.getElementById('endDate').value = `${year}-${month}-${String(lastDay).padStart(2, '0')}`;
}

function setLastMonth() {
    const now = new Date();
    now.setMonth(now.getMonth() - 1);
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('startDate').value = `${year}-${month}-01`;
    const lastDay = new Date(year, now.getMonth() + 1, 0).getDate();
    document.getElementById('endDate').value = `${year}-${month}-${String(lastDay).padStart(2, '0')}`;
}

// 加载汇总数据
function loadSummary() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    document.getElementById('summaryBody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>';
    
    fetch(apiUrl(`finance_fee_report.php?action=summary&start_date=${startDate}&end_date=${endDate}`))
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                document.getElementById('summaryBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">加载失败</td></tr>';
                return;
            }
            
            const data = res.data;
            
            // 更新汇总卡片
            document.getElementById('totalCount').textContent = fmtInt(data.totals.receipt_count);
            document.getElementById('totalOriginal').textContent = fmt(data.totals.original_total);
            document.getElementById('totalFee').textContent = fmt(data.totals.fee_total);
            document.getElementById('totalReceived').textContent = fmt(data.totals.received_total);
            
            // 渲染表格
            const rows = data.rows || [];
            if (!rows.length) {
                document.getElementById('summaryBody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无数据</td></tr>';
                return;
            }
            
            document.getElementById('summaryBody').innerHTML = rows.map(r => `
                <tr>
                    <td>${esc(r.method_label)}</td>
                    <td class="text-end">${fmtInt(r.receipt_count)}</td>
                    <td class="text-end">${fmt(r.original_total)}</td>
                    <td class="text-end"><span class="badge bg-info">${fmt(r.fee_total)}</span></td>
                    <td class="text-end"><strong>${fmt(r.received_total)}</strong></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="loadDetail('${esc(r.method)}', '${esc(r.method_label)}')">明细</button>
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => {
            document.getElementById('summaryBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">加载失败</td></tr>';
        });
}

// 加载明细数据
function loadDetail(method, methodLabel) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    document.getElementById('detailTitle').textContent = methodLabel + ' - 收款明细';
    document.getElementById('detailCard').style.display = '';
    document.getElementById('detailBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted">加载中...</td></tr>';
    
    fetch(apiUrl(`finance_fee_report.php?action=detail&method=${encodeURIComponent(method)}&start_date=${startDate}&end_date=${endDate}`))
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                document.getElementById('detailBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">加载失败</td></tr>';
                return;
            }
            
            const rows = res.data.rows || [];
            if (!rows.length) {
                document.getElementById('detailBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无数据</td></tr>';
                return;
            }
            
            document.getElementById('detailBody').innerHTML = rows.map(r => `
                <tr>
                    <td>${esc(r.received_date)}</td>
                    <td>
                        <div>${esc(r.customer_name)}</div>
                        <div class="small text-muted">${esc(r.customer_code)}</div>
                    </td>
                    <td>
                        <div>${esc(r.contract_no)}</div>
                        <div class="small text-muted">${esc(r.contract_title)}</div>
                    </td>
                    <td class="text-end">${fmt(r.original_amount)}</td>
                    <td class="text-end"><span class="badge bg-info">${fmt(r.fee_amount)}</span></td>
                    <td class="text-end"><strong>${fmt(r.amount_received)}</strong></td>
                    <td class="small text-muted">${esc(r.note || '')}</td>
                </tr>
            `).join('');
            
            // 滚动到明细表
            document.getElementById('detailCard').scrollIntoView({ behavior: 'smooth' });
        })
        .catch(() => {
            document.getElementById('detailBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">加载失败</td></tr>';
        });
}

// 事件绑定
document.getElementById('btnSearch').addEventListener('click', loadSummary);
document.getElementById('btnThisMonth').addEventListener('click', function() {
    setThisMonth();
    loadSummary();
});
document.getElementById('btnLastMonth').addEventListener('click', function() {
    setLastMonth();
    loadSummary();
});
document.getElementById('btnCloseDetail').addEventListener('click', function() {
    document.getElementById('detailCard').style.display = 'none';
});

// 初始化
setThisMonth();
loadSummary();
</script>

<?php
finance_sidebar_end();
layout_footer();
?>
