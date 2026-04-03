<?php
/**
 * 数据分析页面
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

// 验证登录
auth_require();
$user = current_user();

// 获取所有部门（管理员可见）
$departments = [];
if ($user['role'] === 'admin') {
    $departments = Db::query('SELECT id, name FROM departments WHERE status = 1 ORDER BY sort, name');
}

// 获取员工列表（管理员和部门管理员可见）
$users = [];
if ($user['role'] === 'admin') {
    $users = Db::query('SELECT id, realname, department_id FROM users WHERE status = 1 AND role IN ("sales", "service") ORDER BY realname');
} elseif ($user['role'] === 'dept_admin') {
    $users = Db::query('SELECT id, realname FROM users WHERE status = 1 AND role IN ("sales", "service") AND department_id = ? ORDER BY realname', [$user['department_id']]);
}

layout_header('数据分析');
?>

<style>
.analytics-container {
    padding: 20px;
}
.filter-card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    margin-bottom: 20px;
}
.stats-card h3 {
    margin: 0;
    font-size: 2.5rem;
    font-weight: bold;
}
.stats-card p {
    margin: 5px 0 0 0;
    opacity: 0.9;
}
.chart-card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.chart-card h5 {
    margin-bottom: 15px;
    color: #333;
}
#customDateRange {
    display: none;
}
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.9);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.loading-overlay.active {
    display: flex;
}
</style>

<div class="analytics-container">
    <!-- 筛选面板 -->
    <div class="filter-card">
        <form id="filterForm" class="row g-3">
            <!-- 时间范围 -->
            <div class="col-md-2">
                <label class="form-label">时间范围</label>
                <select name="date_range" id="dateRangeSelect" class="form-select" onchange="toggleCustomDateRange()">
                    <option value="today" selected>今天</option>
                    <option value="yesterday">昨天</option>
                    <option value="week">本周</option>
                    <option value="month">本月</option>
                    <option value="custom">自定义时间</option>
                </select>
            </div>
            
            <!-- 自定义日期范围 -->
            <div class="col-md-4" id="customDateRange">
                <label class="form-label">自定义日期</label>
                <div class="input-group">
                    <input type="date" name="start_date" id="startDate" class="form-control">
                    <span class="input-group-text">至</span>
                    <input type="date" name="end_date" id="endDate" class="form-control">
                </div>
            </div>
            
            <?php if ($user['role'] === 'admin'): ?>
            <!-- 部门选择（仅管理员） -->
            <div class="col-md-2">
                <label class="form-label">部门</label>
                <select name="department_id" class="form-select">
                    <option value="0">全部部门</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($user['role'] === 'admin' || $user['role'] === 'dept_admin'): ?>
            <!-- 员工选择（管理员和部门管理员） -->
            <div class="col-md-2">
                <label class="form-label">员工</label>
                <select name="user_id" class="form-select">
                    <option value="0">全部员工</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['realname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-primary w-100" onclick="loadAnalytics()">
                    <i class="bi bi-search"></i> 查询
                </button>
            </div>
        </form>
    </div>
    
    <!-- 概览卡片 -->
    <div class="row" id="summaryCards">
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <h3 id="totalCustomers">-</h3>
                <p>总客户数</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h3 id="newThisPeriod">-</h3>
                <p>本期新增</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <h3 id="updatedThisPeriod">-</h3>
                <p>本期更新</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <h3 id="firstContactThisPeriod">-</h3>
                <p>本期首通</p>
            </div>
        </div>
    </div>
    
    <!-- 图表区域 -->
    <div class="row">
        <!-- 每日新建客户 -->
        <div class="col-md-6">
            <div class="chart-card">
                <h5><i class="bi bi-person-plus"></i> 每日新建客户</h5>
                <div id="newCustomersChart"></div>
            </div>
        </div>
        
        <!-- 每日更新客户 -->
        <div class="col-md-6">
            <div class="chart-card">
                <h5><i class="bi bi-arrow-repeat"></i> 每日更新客户</h5>
                <div id="updatedCustomersChart"></div>
            </div>
        </div>
    </div>
    
    <!-- 首通统计 -->
    <div class="row">
        <div class="col-md-4">
            <div class="chart-card">
                <h5><i class="bi bi-pie-chart"></i> 首通完成率</h5>
                <div id="firstContactRateChart"></div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="chart-card">
                <h5><i class="bi bi-bar-chart"></i> 每日首通数量</h5>
                <div id="dailyFirstContactChart"></div>
            </div>
        </div>
    </div>
    
    <?php if ($user['role'] === 'admin' || $user['role'] === 'dept_admin'): ?>
    <!-- 员工KPI统计 -->
    <div class="row">
        <div class="col-md-12">
            <div class="chart-card">
                <h5><i class="bi bi-trophy"></i> 员工KPI统计</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="kpiTable">
                        <thead>
                            <tr>
                                <th>排名</th>
                                <th>员工</th>
                                <th>部门</th>
                                <th>首通字段</th>
                                <th>异议字段</th>
                                <th>成交字段</th>
                                <th>自评字段</th>
                                <th>总字段数</th>
                                <th>总记录数</th>
                                <th>总分</th>
                            </tr>
                        </thead>
                        <tbody id="kpiTableBody">
                            <tr>
                                <td colspan="10" class="text-center text-muted">暂无数据</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 加载动画 -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
        <span class="visually-hidden">加载中...</span>
    </div>
</div>

<!-- ApexCharts CDN -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

<script>
// 图表实例
let newCustomersChart, updatedCustomersChart, firstContactRateChart, dailyFirstContactChart;

// 页面加载时初始化
document.addEventListener('DOMContentLoaded', function() {
    initCharts();
    loadAnalytics();
});

// 初始化图表
function initCharts() {
    // 每日新建客户图表（混合图）
    newCustomersChart = new ApexCharts(document.querySelector("#newCustomersChart"), {
        chart: {
            type: 'line',
            height: 350,
            toolbar: { show: true }
        },
        series: [{
            name: '新建客户',
            type: 'column',
            data: []
        }, {
            name: '趋势线',
            type: 'line',
            data: []
        }],
        colors: ['#008FFB', '#00E396'],
        stroke: { width: [0, 3] },
        xaxis: { categories: [] },
        yaxis: { title: { text: '客户数量' } },
        dataLabels: {
            enabled: true,
            enabledOnSeries: [0]
        },
        legend: { show: true, position: 'top' }
    });
    newCustomersChart.render();
    
    // 每日更新客户图表（柱状图）
    updatedCustomersChart = new ApexCharts(document.querySelector("#updatedCustomersChart"), {
        chart: {
            type: 'bar',
            height: 350
        },
        series: [{ name: '更新客户', data: [] }],
        colors: ['#FEB019'],
        plotOptions: {
            bar: {
                borderRadius: 4,
                dataLabels: { position: 'top' }
            }
        },
        dataLabels: {
            enabled: true,
            offsetY: -20,
            style: { fontSize: '12px', colors: ['#304758'] }
        },
        xaxis: { categories: [] },
        yaxis: { title: { text: '更新次数' } }
    });
    updatedCustomersChart.render();
    
    // 首通完成率图表（径向图）
    firstContactRateChart = new ApexCharts(document.querySelector("#firstContactRateChart"), {
        chart: {
            type: 'radialBar',
            height: 280
        },
        series: [0],
        colors: ['#20E647'],
        plotOptions: {
            radialBar: {
                hollow: { size: '70%' },
                dataLabels: {
                    name: { offsetY: -10, show: true, color: '#888', fontSize: '13px' },
                    value: {
                        color: '#111',
                        fontSize: '30px',
                        show: true,
                        formatter: function(val) { return val + '%' }
                    }
                }
            }
        },
        labels: ['完成率']
    });
    firstContactRateChart.render();
    
    // 每日首通数量图表（柱状图）
    dailyFirstContactChart = new ApexCharts(document.querySelector("#dailyFirstContactChart"), {
        chart: {
            type: 'bar',
            height: 280
        },
        series: [{ name: '首通数量', data: [] }],
        colors: ['#775DD0'],
        plotOptions: {
            bar: {
                borderRadius: 4,
                columnWidth: '60%'
            }
        },
        dataLabels: { enabled: true },
        xaxis: { categories: [] },
        yaxis: { title: { text: '首通数量' } }
    });
    dailyFirstContactChart.render();
}

// 切换自定义日期范围
function toggleCustomDateRange() {
    const dateRange = document.getElementById('dateRangeSelect').value;
    const customRange = document.getElementById('customDateRange');
    if (dateRange === 'custom') {
        customRange.style.display = 'block';
    } else {
        customRange.style.display = 'none';
    }
}

// 加载数据
function loadAnalytics() {
    const formData = new FormData(document.getElementById('filterForm'));
    formData.append('action', 'get_stats');
    
    // 显示加载动画
    document.getElementById('loadingOverlay').classList.add('active');
    
    fetch(API_URL + '/analytics.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            updateSummary(data.data.summary);
            updateCharts(data.data);
            
            // 如果是管理员或部门管理员，加载KPI数据
            <?php if ($user['role'] === 'admin' || $user['role'] === 'dept_admin'): ?>
            loadEmployeeKPI();
            <?php endif; ?>
        } else {
            showAlertModal(data.message || '加载数据失败', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlertModal('网络错误，请稍后重试', 'error');
    })
    .finally(() => {
        document.getElementById('loadingOverlay').classList.remove('active');
    });
}

// 更新概览卡片
function updateSummary(summary) {
    document.getElementById('totalCustomers').textContent = summary.total_customers || 0;
    document.getElementById('newThisPeriod').textContent = summary.new_this_period || 0;
    document.getElementById('updatedThisPeriod').textContent = summary.updated_this_period || 0;
    document.getElementById('firstContactThisPeriod').textContent = summary.first_contact_this_period || 0;
}

// 更新图表
function updateCharts(data) {
    // 每日新建客户
    const newDates = data.daily_new_customers.map(d => d.date);
    const newCounts = data.daily_new_customers.map(d => parseInt(d.count));
    newCustomersChart.updateOptions({
        xaxis: { categories: newDates }
    });
    newCustomersChart.updateSeries([
        { data: newCounts },
        { data: newCounts }
    ]);
    
    // 每日更新客户
    const updatedDates = data.daily_updated_customers.map(d => d.date);
    const updatedCounts = data.daily_updated_customers.map(d => parseInt(d.count));
    updatedCustomersChart.updateOptions({
        xaxis: { categories: updatedDates }
    });
    updatedCustomersChart.updateSeries([{ data: updatedCounts }]);
    
    // 首通完成率
    const fcStats = data.first_contact_stats;
    const completionRate = fcStats.total_first_contacts > 0 
        ? Math.round((fcStats.completed_first_contacts / fcStats.total_first_contacts) * 100) 
        : 0;
    firstContactRateChart.updateSeries([completionRate]);
    
    // 每日首通数量
    const fcDates = fcStats.daily_first_contacts.map(d => d.date);
    const fcCounts = fcStats.daily_first_contacts.map(d => parseInt(d.count));
    dailyFirstContactChart.updateOptions({
        xaxis: { categories: fcDates }
    });
    dailyFirstContactChart.updateSeries([{ data: fcCounts }]);
}

<?php if ($user['role'] === 'admin' || $user['role'] === 'dept_admin'): ?>
// 加载员工KPI数据
function loadEmployeeKPI() {
    const formData = new FormData(document.getElementById('filterForm'));
    formData.append('action', 'get_employee_kpi');
    
    fetch(API_URL + '/analytics.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            updateKPITable(data.data);
        }
    })
    .catch(error => {
        console.error('Error loading KPI:', error);
    });
}

// 更新KPI表格
function updateKPITable(employees) {
    const tbody = document.getElementById('kpiTableBody');
    if (!employees || employees.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">暂无数据</td></tr>';
        return;
    }
    
    let html = '';
    employees.forEach((emp, index) => {
        const rowClass = index === 0 ? 'table-success' : (index === 1 ? 'table-info' : '');
        const badge = index === 0 ? '🥇' : (index === 1 ? '🥈' : (index === 2 ? '🥉' : ''));
        
        html += `<tr class="${rowClass}">
            <td><span class="badge bg-${index < 3 ? 'warning' : 'secondary'}">${badge} ${emp.rank}</span></td>
            <td>${emp.user_name}</td>
            <td>${emp.department_name || '-'}</td>
            <td>${emp.firstcontact_fields || 0}</td>
            <td>${emp.objection_fields || 0}</td>
            <td>${emp.deal_fields || 0}</td>
            <td>${emp.evaluation_fields || 0}</td>
            <td><strong>${emp.total_fields || 0}</strong></td>
            <td>${emp.total_records || 0}</td>
            <td><strong class="text-primary">${emp.total_score || 0}</strong></td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}
<?php endif; ?>
</script>

<?php
layout_footer();
?>
