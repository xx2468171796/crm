<?php
/**
 * 批量工资条PDF打包下载（ZIP格式）
 * 为每个员工生成独立的HTML文件，打包成ZIP下载
 * 用户下载后可用浏览器打开HTML并打印为PDF
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '无权访问']);
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
$userIdsParam = $_GET['user_ids'] ?? '';
$userIds = $userIdsParam ? explode(',', $userIdsParam) : [];

// 获取提成规则
$ruleSet = Db::queryOne('SELECT id FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
$ruleId = $ruleSet['id'] ?? 0;
$tiers = $ruleId ? Db::query('SELECT tier_from, tier_to, rate FROM commission_rule_tiers WHERE rule_set_id = ? ORDER BY tier_from ASC', [$ruleId]) : [];

// 收款方式转中文
function fmtMethod($m) {
    $map = ['taiwanxu'=>'台湾续','prepay'=>'预付款','zhongguopaypal'=>'中国PayPal','alipay'=>'支付宝','guoneiduigong'=>'国内对公','guoneiweixin'=>'国内微信','wechat'=>'微信','other'=>'其他'];
    return $map[$m] ?? ($m ?: '-');
}

// 获取单个员工的工资条数据
function getSlipData($userId, $month, $tiers) {
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
    
    $newOrders = Db::query(
        "SELECT c.title as contract_name, cu.name as customer, r.amount_received as amount, r.method
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         LEFT JOIN customers cu ON c.customer_id = cu.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') = ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    $installments = Db::query(
        "SELECT c.title as contract_name, cu.name as customer, r.amount_received as amount, r.method
         FROM finance_receipts r JOIN finance_contracts c ON r.contract_id = c.id
         LEFT JOIN customers cu ON c.customer_id = cu.id
         WHERE c.sales_user_id = ? AND c.is_first_contract = 1 AND DATE_FORMAT(c.sign_date, '%Y-%m') < ? AND DATE_FORMAT(r.received_date, '%Y-%m') = ?",
        [$userId, $month, $month]
    );
    
    $tierContracts = Db::query("SELECT c.net_amount as amount FROM finance_contracts c WHERE c.sales_user_id = ? AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?", [$userId, $month]);
    $tierBase = array_sum(array_column($tierContracts, 'amount'));
    
    $tierRate = 0;
    foreach ($tiers as $tier) {
        if ($tierBase >= $tier['tier_from'] && ($tier['tier_to'] === null || $tierBase < $tier['tier_to'])) { $tierRate = floatval($tier['rate']); break; }
    }
    if ($tierRate == 0 && count($tiers) > 0) $tierRate = floatval($tiers[0]['rate']);
    
    $part1Commission = array_sum(array_map(fn($o) => floatval($o['amount']) * $tierRate, $newOrders));
    $part2Commission = array_sum(array_map(fn($i) => floatval($i['amount']) * $tierRate, $installments));
    $totalCommission = $part1Commission + $part2Commission;
    $total = $baseSalary + $attendance + $totalCommission + $incentive + $adjustment - $deduction;
    
    return [
        'user_name' => $userInfo['realname'] ?: $userInfo['username'],
        'department' => $deptName,
        'basic' => ['base_salary' => $baseSalary, 'attendance' => $attendance, 'subtotal' => $baseSalary + $attendance],
        'commission' => ['tier_base' => $tierBase, 'tier_rate' => $tierRate, 'part1_commission' => $part1Commission, 'part2_commission' => $part2Commission, 'new_orders' => $newOrders, 'installments' => $installments, 'subtotal' => $totalCommission],
        'other' => ['incentive' => $incentive, 'adjustment' => $adjustment, 'deduction' => $deduction],
        'total' => $total,
    ];
}

// 生成单人HTML
function generateSlipHtml($slip, $month) {
    $monthDisplay = date('Y年m月', strtotime($month . '-01'));
    $fmtMoney = fn($v) => '¥' . number_format(floatval($v), 2);
    $fmtRate = fn($r) => number_format(floatval($r) * 100, 1) . '%';
    
    $newOrdersHtml = '';
    if (!empty($slip['commission']['new_orders'])) {
        $newOrdersHtml = '<h4>Part1: 本月新单提成</h4><table><tr><th>合同</th><th>客户</th><th>金额</th><th>方式</th></tr>';
        foreach ($slip['commission']['new_orders'] as $o) {
            $newOrdersHtml .= '<tr><td>' . htmlspecialchars($o['contract_name']) . '</td><td>' . htmlspecialchars($o['customer']) . '</td><td class="r">' . $fmtMoney($o['amount']) . '</td><td>' . fmtMethod($o['method']) . '</td></tr>';
        }
        $newOrdersHtml .= '</table>';
    }
    
    $installmentsHtml = '';
    if (!empty($slip['commission']['installments'])) {
        $installmentsHtml = '<h4>Part2: 往期分期提成</h4><table><tr><th>合同</th><th>客户</th><th>金额</th><th>方式</th></tr>';
        foreach ($slip['commission']['installments'] as $i) {
            $installmentsHtml .= '<tr><td>' . htmlspecialchars($i['contract_name']) . '</td><td>' . htmlspecialchars($i['customer']) . '</td><td class="r">' . $fmtMoney($i['amount']) . '</td><td>' . fmtMethod($i['method']) . '</td></tr>';
        }
        $installmentsHtml .= '</table>';
    }
    
    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>{$slip['user_name']} - {$monthDisplay} 工资条</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Microsoft YaHei',sans-serif;padding:20px;max-width:800px;margin:0 auto}
.header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;text-align:center;margin-bottom:20px}
.header h1{font-size:24px;margin-bottom:5px}.header p{opacity:.9}
.info{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px dashed #ddd;margin-bottom:20px}
.section{margin-bottom:20px}.section h3{background:#f5f5f5;padding:10px;margin-bottom:10px;border-left:4px solid #667eea}
table{width:100%;border-collapse:collapse}th,td{padding:8px 12px;border:1px solid #ddd;text-align:left}th{background:#f9f9f9}.r{text-align:right}
.highlight{background:#f0f5ff;font-weight:bold}
.total{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;display:flex;justify-content:space-between;align-items:center;margin-top:20px}
.total .amount{font-size:28px;font-weight:bold}
h4{margin:15px 0 10px;color:#666;font-size:14px}
@media print{body{padding:0}.header{-webkit-print-color-adjust:exact;print-color-adjust:exact}.total{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>
<div class="header"><h1>工 资 条</h1><p>{$monthDisplay}</p></div>
<div class="info"><span><strong>员工：</strong>{$slip['user_name']}</span><span><strong>部门：</strong>{$slip['department']}</span></div>
<div class="section"><h3>💰 基本工资</h3><table>
<tr><td>底薪</td><td class="r">{$fmtMoney($slip['basic']['base_salary'])}</td></tr>
<tr><td>全勤奖</td><td class="r">{$fmtMoney($slip['basic']['attendance'])}</td></tr>
<tr class="highlight"><td>小计</td><td class="r">{$fmtMoney($slip['basic']['subtotal'])}</td></tr>
</table></div>
<div class="section"><h3>📊 提成收入</h3><table>
<tr><td>档位基数</td><td class="r">{$fmtMoney($slip['commission']['tier_base'])}</td></tr>
<tr><td>档位比例</td><td class="r">{$fmtRate($slip['commission']['tier_rate'])}</td></tr>
<tr><td>新单提成</td><td class="r">{$fmtMoney($slip['commission']['part1_commission'])}</td></tr>
<tr><td>分期提成</td><td class="r">{$fmtMoney($slip['commission']['part2_commission'])}</td></tr>
<tr class="highlight"><td>小计</td><td class="r">{$fmtMoney($slip['commission']['subtotal'])}</td></tr>
</table>{$newOrdersHtml}{$installmentsHtml}</div>
<div class="section"><h3>📋 其他</h3><table>
<tr><td>激励奖金</td><td class="r">{$fmtMoney($slip['other']['incentive'])}</td></tr>
<tr><td>手动调整</td><td class="r">{$fmtMoney($slip['other']['adjustment'])}</td></tr>
<tr><td>扣款</td><td class="r" style="color:#f5222d">-{$fmtMoney($slip['other']['deduction'])}</td></tr>
</table></div>
<div class="total"><span>应发工资合计</span><span class="amount">{$fmtMoney($slip['total'])}</span></div>
<p style="text-align:center;color:#999;margin-top:20px;font-size:12px">打印此页面可生成PDF文件</p>
</body></html>
HTML;
}

// 获取要导出的用户
if (empty($userIds)) {
    $users = Db::query(
        "SELECT DISTINCT u.id FROM users u
         LEFT JOIN salary_user_monthly s ON u.id = s.user_id AND s.month = ?
         WHERE u.status = 'active' AND (s.id IS NOT NULL OR EXISTS (
             SELECT 1 FROM finance_contracts c WHERE c.sales_user_id = u.id AND DATE_FORMAT(c.sign_date, '%Y-%m') = ?
         ))",
        [$month, $month]
    );
    $userIds = array_column($users, 'id');
}

// 创建ZIP
$zipFile = tempnam(sys_get_temp_dir(), 'salary_') . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '创建压缩文件失败']);
    exit;
}

$count = 0;
foreach ($userIds as $uid) {
    $slip = getSlipData($uid, $month, $tiers);
    if ($slip && $slip['total'] != 0) {
        $html = generateSlipHtml($slip, $month);
        $filename = preg_replace('/[\/\\\\:*?"<>|]/', '_', $slip['user_name']) . '_' . $month . '_工资条.html';
        $zip->addFromString($filename, $html);
        $count++;
    }
}

$zip->close();

if ($count === 0) {
    unlink($zipFile);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '没有可导出的工资条数据']);
    exit;
}

// 下载
$downloadName = $month . '_工资条(' . $count . '人).zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, no-store, must-revalidate');

readfile($zipFile);
unlink($zipFile);
