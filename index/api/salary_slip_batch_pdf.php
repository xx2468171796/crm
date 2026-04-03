<?php
/**
 * 批量工资条PDF导出页面
 * 显示指定月份所有员工的工资条，支持一键下载PDF
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: text/html; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo '<h1>无权访问</h1>';
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
$displayCurrency = trim($_GET['display_currency'] ?? 'CNY');
$rateType = trim($_GET['rate_type'] ?? 'fixed');
$monthDisplay = date('Y年m月', strtotime($month . '-01'));

// 获取所有有工资数据的员工
$users = Db::query(
    "SELECT DISTINCT u.id, u.realname, u.username, u.department_id, d.name as dept_name
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     LEFT JOIN salary_user_monthly s ON u.id = s.user_id AND s.month = ?
     WHERE u.status = 'active' AND (s.id IS NOT NULL OR EXISTS (
         SELECT 1 FROM finance_contracts c WHERE c.sales_user_id = u.id AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?
     ))
     ORDER BY d.name, u.realname",
    [$month, $month]
);

// 获取提成规则
$ruleSet = Db::queryOne('SELECT id, currency FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
$ruleId = $ruleSet['id'] ?? 0;
$ruleCurrency = $ruleSet['currency'] ?? 'CNY';
$tiers = $ruleId ? Db::query('SELECT tier_from, tier_to, rate FROM commission_rule_tiers WHERE rule_set_id = ? ORDER BY tier_from ASC', [$ruleId]) : [];

// 获取所有汇率
$allCurrencies = Db::query("SELECT code, fixed_rate, floating_rate FROM currencies WHERE status = 1");
$currencyRates = [];
foreach ($allCurrencies as $cur) {
    $currencyRates[$cur['code']] = [
        'fixed' => (float)($cur['fixed_rate'] ?? 1),
        'floating' => (float)($cur['floating_rate'] ?? 1),
    ];
}

function convertCurrency($amount, $fromCurrency, $toCurrency, $currencyRates, $rateType) {
    if ($fromCurrency === $toCurrency) return $amount;
    $fromRate = $currencyRates[$fromCurrency][$rateType] ?? 1;
    $toRate = $currencyRates[$toCurrency][$rateType] ?? 1;
    $amountInCNY = $amount / $fromRate;
    return $amountInCNY * $toRate;
}

// 货币符号映射
$currencySymbols = ['CNY' => '¥', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'HKD' => 'HK$', 'TWD' => 'NT$'];
$displaySymbol = $currencySymbols[$displayCurrency] ?? '¥';
$ruleSymbol = $currencySymbols[$ruleCurrency] ?? '¥';
$rateTypeName = $rateType === 'fixed' ? '固定' : '浮动';

// 收款方式转中文
function fmtMethod($m) {
    $map = [
        'taiwanxu' => '台湾续',
        'prepay' => '预付款',
        'zhongguopaypal' => '中国PayPal',
        'alipay' => '支付宝',
        'guoneiduigong' => '国内对公',
        'guoneiweixin' => '国内微信',
        'wechat' => '微信',
        'other' => '其他'
    ];
    return $map[$m] ?? ($m ?: '-');
}

// 获取单个员工的工资条数据
function getSlipData($userId, $month, $tiers, $ruleCurrency, $displayCurrency, $currencyRates, $rateType) {
    $userInfo = Db::queryOne('SELECT id, realname, username, department_id FROM users WHERE id = ?', [$userId]);
    if (!$userInfo) return null;
    
    $dept = Db::queryOne('SELECT name FROM departments WHERE id = ?', [$userInfo['department_id'] ?? 0]);
    $deptName = $dept['name'] ?? '';
    
    $monthlyData = Db::queryOne('SELECT * FROM salary_user_monthly WHERE user_id = ? AND month = ?', [$userId, $month]);
    
    $baseSalary = floatval($monthlyData['base_salary'] ?? 0);
    $attendance = floatval($monthlyData['attendance'] ?? 0);
    $adjustment = floatval($monthlyData['adjustment'] ?? 0);
    $deduction = floatval($monthlyData['deduction'] ?? 0);
    $incentive = floatval($monthlyData['incentive'] ?? 0);
    
    // 获取本月新单
    $newOrders = Db::query(
        "SELECT c.id as contract_id, c.title as contract_name, cu.name as customer, r.amount_received as amount, u.realname as collector, r.method
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         LEFT JOIN customers cu ON c.customer_id = cu.id LEFT JOIN users u ON r.collector_user_id = u.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') = ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    // 获取往期分期
    $installments = Db::query(
        "SELECT c.id as contract_id, c.title as contract_name, cu.name as customer, r.amount_received as amount, u.realname as collector, r.method
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         LEFT JOIN customers cu ON c.customer_id = cu.id LEFT JOIN users u ON r.collector_user_id = u.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') < ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    // 计算档位基数
    $tierContracts = Db::query(
        "SELECT c.net_amount as amount FROM finance_contracts c WHERE c.sales_user_id = ? AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?",
        [$userId, $month]
    );
    $tierBase = 0;
    foreach ($tierContracts as $tc) { $tierBase += floatval($tc['amount']); }
    
    // 确定档位比例
    $tierRate = 0;
    foreach ($tiers as $tier) {
        if ($tierBase >= $tier['tier_from'] && ($tier['tier_to'] === null || $tierBase < $tier['tier_to'])) { $tierRate = floatval($tier['rate']); break; }
    }
    if ($tierRate == 0 && count($tiers) > 0) { $tierRate = floatval($tiers[0]['rate']); }
    
    // 计算提成
    $part1Commission = 0;
    foreach ($newOrders as $o) { $part1Commission += floatval($o['amount']) * $tierRate; }
    
    $part2Commission = 0;
    foreach ($installments as $i) { $part2Commission += floatval($i['amount']) * $tierRate; }
    
    $totalCommission = $part1Commission + $part2Commission;
    $total = $baseSalary + $attendance + $totalCommission + $incentive + $adjustment - $deduction;
    $totalDisplay = convertCurrency($total, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
    $commissionDisplay = convertCurrency($totalCommission, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
    
    return [
        'user_name' => $userInfo['realname'] ?: $userInfo['username'],
        'department' => $deptName,
        'basic' => ['base_salary' => $baseSalary, 'attendance' => $attendance, 'subtotal' => $baseSalary + $attendance],
        'commission' => [
            'tier_base' => $tierBase, 
            'tier_rate' => $tierRate, 
            'part1_commission' => round($part1Commission, 2), 
            'part2_commission' => round($part2Commission, 2), 
            'subtotal' => round($totalCommission, 2),
            'subtotal_display' => round($commissionDisplay, 2),
        ],
        'other' => ['incentive' => $incentive, 'adjustment' => $adjustment, 'deduction' => $deduction],
        'total' => round($total, 2),
        'total_display' => round($totalDisplay, 2),
        'rule_currency' => $ruleCurrency,
        'display_currency' => $displayCurrency,
    ];
}

// 收集所有员工数据
$allSlips = [];
foreach ($users as $u) {
    $slip = getSlipData($u['id'], $month, $tiers, $ruleCurrency, $displayCurrency, $currencyRates, $rateType);
    if ($slip && $slip['total'] != 0) {
        $allSlips[] = $slip;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>批量工资条 - <?= $monthDisplay ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Microsoft YaHei', Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        
        .btn-group {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 12px 24px;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-pdf { background: #52c41a; }
        .btn-pdf:hover { background: #73d13d; }
        .btn-print { background: #1890ff; }
        .btn-print:hover { background: #40a9ff; }
        
        .slip-container { max-width: 800px; margin: 0 auto 30px; }
        
        .slip {
            background: #fff;
            border: 1px solid #ddd;
            margin-bottom: 30px;
            page-break-after: always;
        }
        .slip:last-child { page-break-after: auto; }
        
        .slip-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 20px;
            text-align: center;
        }
        .slip-header h2 { margin-bottom: 5px; font-size: 20px; }
        .slip-header .subtitle { font-size: 14px; opacity: 0.9; }
        
        .slip-body { padding: 20px; }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #eee;
        }
        
        .section { margin: 15px 0; }
        .section-title {
            font-weight: bold;
            color: #333;
            padding: 8px 0;
            border-bottom: 2px solid #667eea;
            margin-bottom: 10px;
        }
        
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td {
            padding: 8px 12px;
            border: 1px solid #eee;
            text-align: left;
        }
        .data-table th { background: #f9f9f9; font-weight: normal; color: #666; }
        .data-table .text-right { text-align: right; }
        .data-table .highlight { background: #f0f5ff; font-weight: bold; }
        
        .total-row {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .total-row .label { font-size: 16px; }
        .total-row .amount { font-size: 24px; font-weight: bold; }
        
        .summary {
            background: #fff;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .summary h3 { margin-bottom: 15px; color: #333; }
        .summary-stats { display: flex; gap: 30px; flex-wrap: wrap; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 24px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #999; }
        
        @media print {
            .btn-group { display: none; }
            .summary { display: none; }
            body { background: #fff; padding: 0; }
            .slip { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="btn-group">
        <button class="btn btn-pdf" onclick="downloadAllPDF()">📥 下载全部PDF</button>
        <button class="btn btn-print" onclick="window.print()">🖨️ 打印全部</button>
    </div>
    
    <div class="summary">
        <h3>📊 <?= $monthDisplay ?> 工资汇总</h3>
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-value"><?= count($allSlips) ?></div>
                <div class="stat-label">员工人数</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">¥<?= number_format(array_sum(array_column($allSlips, 'total')), 2) ?></div>
                <div class="stat-label">工资总额</div>
            </div>
        </div>
    </div>
    
    <div class="slip-container" id="slipContainer">
    <?php foreach ($allSlips as $slip): ?>
        <div class="slip">
            <div class="slip-header">
                <h2>工 资 条</h2>
                <div class="subtitle"><?= $monthDisplay ?></div>
            </div>
            
            <div class="slip-body">
                <div class="info-row">
                    <span><strong>员工姓名：</strong><?= htmlspecialchars($slip['user_name']) ?></span>
                    <span><strong>所属部门：</strong><?= htmlspecialchars($slip['department']) ?></span>
                </div>
                
                <div class="section">
                    <div class="section-title">💰 基本工资</div>
                    <table class="data-table">
                        <tr><td>底薪</td><td class="text-right">¥<?= number_format($slip['basic']['base_salary'], 2) ?></td></tr>
                        <tr><td>全勤奖</td><td class="text-right">¥<?= number_format($slip['basic']['attendance'], 2) ?></td></tr>
                        <tr class="highlight"><td>小计</td><td class="text-right">¥<?= number_format($slip['basic']['subtotal'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="section">
                    <div class="section-title">📊 提成收入</div>
                    <table class="data-table">
                        <tr><td>档位基数</td><td class="text-right">¥<?= number_format($slip['commission']['tier_base'], 2) ?></td></tr>
                        <tr><td>档位比例</td><td class="text-right"><?= number_format($slip['commission']['tier_rate'] * 100, 1) ?>%</td></tr>
                        <tr><td>本月新单提成</td><td class="text-right">¥<?= number_format($slip['commission']['part1_commission'], 2) ?></td></tr>
                        <tr><td>往期分期提成</td><td class="text-right">¥<?= number_format($slip['commission']['part2_commission'], 2) ?></td></tr>
                        <tr class="highlight"><td>小计</td><td class="text-right">¥<?= number_format($slip['commission']['subtotal'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="section">
                    <div class="section-title">📋 其他</div>
                    <table class="data-table">
                        <tr><td>激励奖金</td><td class="text-right">¥<?= number_format($slip['other']['incentive'], 2) ?></td></tr>
                        <tr><td>手动调整</td><td class="text-right">¥<?= number_format($slip['other']['adjustment'], 2) ?></td></tr>
                        <tr><td>扣款</td><td class="text-right" style="color:#f5222d;">-¥<?= number_format($slip['other']['deduction'], 2) ?></td></tr>
                    </table>
                </div>
            </div>
            
            <div class="total-row">
                <span class="label">应发工资合计 (<?= $slip['display_currency'] ?>)</span>
                <span class="amount"><?= $displaySymbol ?><?= number_format($slip['total_display'], 2) ?></span>
            </div>
            <?php if ($slip['rule_currency'] !== $slip['display_currency']): ?>
            <div class="total-row" style="background: #f0f5ff; color: #333;">
                <span class="label">原币金额 (<?= $slip['rule_currency'] ?>, <?= $rateTypeName ?>汇率)</span>
                <span class="amount" style="font-size:18px;"><?= $ruleSymbol ?><?= number_format($slip['total'], 2) ?></span>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    
    <script>
    function downloadAllPDF() {
        const element = document.getElementById('slipContainer');
        const filename = '<?= $month ?>_全员工资条.pdf';
        
        const opt = {
            margin: 10,
            filename: filename,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: 'css', after: '.slip' }
        };
        
        document.querySelector('.btn-group').style.display = 'none';
        document.querySelector('.summary').style.display = 'none';
        
        html2pdf().set(opt).from(element).save().then(() => {
            document.querySelector('.btn-group').style.display = 'flex';
            document.querySelector('.summary').style.display = 'block';
        });
    }
    </script>
</body>
</html>
