<?php
/**
 * 工资条打印版（适合导出PDF）
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

// 设置HTML响应头
header('Content-Type: text/html; charset=utf-8');

auth_require();
$user = current_user();

$month = $_GET['month'] ?? date('Y-m');
$targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user['id'];

// 权限检查
$isAdmin = canOrAdmin(PermissionCode::FINANCE_VIEW);
if (!$isAdmin && $targetUserId != $user['id']) {
    echo '<h1>无权查看他人工资条</h1>';
    exit;
}

// 调用commission_calculate.php获取一致的数据
$displayCurrency = $_GET['display_currency'] ?? 'CNY';
$rateType = $_GET['rate_type'] ?? 'fixed';

try {
    $userInfo = Db::queryOne('SELECT id, realname, username, department_id FROM users WHERE id = ?', [$targetUserId]);
    if (!$userInfo) { echo '<h1>用户不存在</h1>'; exit; }
    
    $dept = Db::queryOne('SELECT name FROM departments WHERE id = ?', [$userInfo['department_id'] ?? 0]);
    $deptName = $dept['name'] ?? '';
    
    $monthlyData = Db::queryOne('SELECT * FROM salary_user_monthly WHERE user_id = ? AND month = ?', [$targetUserId, $month]);
    
    $baseSalary = floatval($monthlyData['base_salary'] ?? 0);
    $attendance = floatval($monthlyData['attendance'] ?? 0);
    $adjustment = floatval($monthlyData['adjustment'] ?? 0);
    $deduction = floatval($monthlyData['deduction'] ?? 0);
    $incentive = floatval($monthlyData['incentive'] ?? 0);
    
    // 获取活动规则ID
    $ruleSet = Db::queryOne('SELECT id FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
    $ruleId = $ruleSet['id'] ?? 0;
    
    // 通过HTTP调用commission_calculate API获取提成数据
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    $apiUrl = $protocol . '://' . $host . $basePath . '/commission_calculate.php';
    $apiUrl .= '?month=' . urlencode($month) . '&rule_id=' . $ruleId . '&display_currency=' . $displayCurrency . '&rate_type=' . $rateType;
    
    // 关闭session写入避免死锁
    $sessionId = session_id();
    $sessionName = session_name();
    session_write_close();
    
    // 使用cURL调用API
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_COOKIE, $sessionName . '=' . $sessionId);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $apiResponse = curl_exec($ch);
    curl_close($ch);
    
    // 重新开启session
    session_id($sessionId);
    session_start();
    
    $commData = json_decode($apiResponse, true);
    
    $tierBase = 0; $tierRate = 0;
    $part1Commission = 0; $part2Commission = 0;
    $newOrdersData = []; $installmentsData = [];
    $ruleCurrency = 'TWD';
    
    if ($commData && $commData['success'] && isset($commData['data'])) {
        $calcData = $commData['data'];
        $ruleCurrency = $calcData['rule_currency'] ?? 'TWD';
        
        // 查找当前用户的数据
        $userSummary = null;
        foreach (($calcData['summary'] ?? []) as $s) {
            if ($s['user_id'] == $targetUserId) { $userSummary = $s; break; }
        }
        $userDetails = $calcData['details'][strval($targetUserId)] ?? ($calcData['details'][$targetUserId] ?? null);
        
        if ($userSummary) {
            $tierBase = $userSummary['tier_base'] ?? 0;
            $tierRate = $userSummary['tier_rate'] ?? 0;
            // 使用显示货币值，与工资条页面一致
            $part1Commission = $userSummary['new_order_commission_display'] ?? ($userSummary['new_order_commission'] ?? 0);
            $part2Commission = $userSummary['installment_commission_display'] ?? ($userSummary['installment_commission'] ?? 0);
            // 直接使用API返回的提成合计，避免计算差异
            $totalCommissionFromApi = $userSummary['commission_display'] ?? ($userSummary['commission'] ?? 0);
            $totalSalaryFromApi = $userSummary['total_display'] ?? 0;
        }
        
        if ($userDetails) {
            $newOrdersData = $userDetails['new_orders'] ?? [];
            $installmentsData = $userDetails['installments'] ?? [];
        }
    }
    
    // 优先使用API返回的提成合计，如果没有则自己计算
    $totalCommission = isset($totalCommissionFromApi) ? $totalCommissionFromApi : ($part1Commission + $part2Commission);
    // 优先使用API返回的总工资，如果没有则自己计算
    $total = isset($totalSalaryFromApi) && $totalSalaryFromApi > 0 ? $totalSalaryFromApi : ($baseSalary + $attendance + $totalCommission + $incentive + $adjustment - $deduction);
    
    $slipData = [
        'user_name' => $userInfo['realname'] ?: $userInfo['username'],
        'department' => $deptName,
        'basic' => ['base_salary' => $baseSalary, 'attendance' => $attendance, 'subtotal' => $baseSalary + $attendance],
        'commission' => ['tier_base' => $tierBase, 'tier_rate' => $tierRate, 'part1_commission' => $part1Commission, 'part2_commission' => $part2Commission, 'new_orders' => $newOrdersData, 'installments' => $installmentsData, 'subtotal' => $totalCommission],
        'other' => ['incentive' => $incentive, 'adjustment' => $adjustment, 'deduction' => $deduction],
        'total' => $total,
        'rule_currency' => $ruleCurrency,
    ];
} catch (Exception $e) {
    echo '<h1>获取数据失败</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

// 收款方式转中文
function fmtMethod($m) {
    $map = [
        'taiwanxu' => '台湾续',
        'prepay' => '预付款',
        'zhongguopaypal' => '中国PayPal',
        'alipay' => '支付宝',
        'guoneiduigong' => '国内对公',
        'guoneiweixin' => '国内微信',
        'xiapi' => '虾皮',
        'cash' => '现金',
        'transfer' => '转账',
        'wechat' => '微信',
        'other' => '其他'
    ];
    return $map[$m] ?? $m ?? '';
}

$monthDisplay = str_replace('-', '年', $month) . '月';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($slipData['user_name']) ?> <?= $monthDisplay ?> 工资条</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: "Microsoft YaHei", "SimHei", sans-serif; 
            font-size: 12px; 
            line-height: 1.6;
            padding: 20px;
            background: #fff;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .header { 
            text-align: center; 
            border-bottom: 2px solid #333; 
            padding-bottom: 15px; 
            margin-bottom: 20px;
        }
        .header h1 { font-size: 20px; margin-bottom: 5px; }
        .header .subtitle { color: #666; font-size: 14px; }
        
        .info-row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 15px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .info-item { }
        .info-label { color: #666; }
        .info-value { font-weight: bold; }
        
        .section { margin-bottom: 20px; }
        .section-title { 
            font-size: 14px; 
            font-weight: bold; 
            background: #4a90d9; 
            color: #fff; 
            padding: 8px 12px;
            margin-bottom: 0;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 10px;
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 8px 10px; 
            text-align: left;
        }
        th { 
            background: #f5f5f5; 
            font-weight: bold;
            font-size: 11px;
        }
        td { font-size: 11px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .subtotal-row { background: #fffbe6; font-weight: bold; }
        .total-row { background: #e6f7ff; font-weight: bold; font-size: 13px; }
        .grand-total { 
            background: #52c41a; 
            color: #fff; 
            font-size: 16px;
        }
        
        .footer { 
            margin-top: 30px; 
            padding-top: 20px; 
            border-top: 1px dashed #ddd;
            display: flex;
            justify-content: space-between;
        }
        .signature { width: 200px; }
        .signature-line { 
            border-bottom: 1px solid #333; 
            margin-top: 30px;
            margin-bottom: 5px;
        }
        .signature-label { font-size: 11px; color: #666; }
        
        .btn-group {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 10px 20px;
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
        
        @media print {
            .btn-group { display: none; }
            body { padding: 0; }
            .container { max-width: 100%; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>
    <div class="btn-group">
        <button class="btn btn-pdf" onclick="downloadPDF()">📥 下载PDF</button>
        <button class="btn btn-print" onclick="window.print()">🖨️ 打印</button>
    </div>
    
    <div class="container">
        <div class="header">
            <h1>工 资 条</h1>
            <div class="subtitle"><?= $monthDisplay ?></div>
        </div>
        
        <div class="info-row">
            <div class="info-item">
                <span class="info-label">员工姓名：</span>
                <span class="info-value"><?= htmlspecialchars($slipData['user_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">所属部门：</span>
                <span class="info-value"><?= htmlspecialchars($slipData['department']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">结算月份：</span>
                <span class="info-value"><?= $monthDisplay ?></span>
            </div>
        </div>
        
        <!-- 基本工资 -->
        <div class="section">
            <div class="section-title">一、基本工资</div>
            <table>
                <tr>
                    <th style="width:50%">项目</th>
                    <th class="text-right">金额（元）</th>
                </tr>
                <tr>
                    <td>底薪</td>
                    <td class="text-right"><?= number_format($slipData['basic']['base_salary'], 2) ?></td>
                </tr>
                <tr>
                    <td>全勤奖</td>
                    <td class="text-right"><?= number_format($slipData['basic']['attendance'], 2) ?></td>
                </tr>
                <tr class="subtotal-row">
                    <td>小计</td>
                    <td class="text-right"><?= number_format($slipData['basic']['subtotal'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- 提成收入 -->
        <div class="section">
            <div class="section-title">二、提成收入</div>
            <table>
                <tr>
                    <th style="width:50%">档位基数</th>
                    <td class="text-right"><?= number_format($slipData['commission']['tier_base'], 2) ?></td>
                </tr>
                <tr>
                    <th>档位比例</th>
                    <td class="text-right"><?= ($slipData['commission']['tier_rate'] * 100) ?>%</td>
                </tr>
            </table>
            
            <?php if (!empty($slipData['commission']['new_orders'])): ?>
            <table>
                <tr><th colspan="7" style="background:#e6f7ff;">Part1: 本月新单提成</th></tr>
                <tr>
                    <th>合同名称</th>
                    <th>客户</th>
                    <th class="text-right">收款金额</th>
                    <th class="text-center">比例</th>
                    <th class="text-right">提成</th>
                    <th>收款人</th>
                    <th>方式</th>
                </tr>
                <?php foreach ($slipData['commission']['new_orders'] as $o): ?>
                <tr>
                    <td><?= htmlspecialchars($o['contract_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($o['customer'] ?? '') ?></td>
                    <td class="text-right"><?= number_format($o['amount'], 2) ?></td>
                    <td class="text-center"><?= ($o['rate'] * 100) ?>%</td>
                    <td class="text-right"><?= number_format($o['commission'], 2) ?></td>
                    <td><?= htmlspecialchars($o['collector'] ?? '') ?></td>
                    <td><?= fmtMethod($o['method'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row">
                    <td colspan="4">小计</td>
                    <td class="text-right"><?= number_format($slipData['commission']['part1_commission'], 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </table>
            <?php endif; ?>
            
            <?php if (!empty($slipData['commission']['installments'])): ?>
            <table>
                <tr><th colspan="7" style="background:#fff7e6;">Part2: 往期分期提成</th></tr>
                <tr>
                    <th>合同名称</th>
                    <th>客户</th>
                    <th class="text-right">收款金额</th>
                    <th class="text-center">比例</th>
                    <th class="text-right">提成</th>
                    <th>收款人</th>
                    <th>方式</th>
                </tr>
                <?php foreach ($slipData['commission']['installments'] as $i): ?>
                <tr>
                    <td><?= htmlspecialchars($i['contract_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($i['customer'] ?? '') ?></td>
                    <td class="text-right"><?= number_format($i['amount'], 2) ?></td>
                    <td class="text-center"><?= ($i['rate'] * 100) ?>%</td>
                    <td class="text-right"><?= number_format($i['commission'], 2) ?></td>
                    <td><?= htmlspecialchars($i['collector'] ?? '') ?></td>
                    <td><?= fmtMethod($i['method'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row">
                    <td colspan="4">小计</td>
                    <td class="text-right"><?= number_format($slipData['commission']['part2_commission'], 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </table>
            <?php endif; ?>
            
            <table>
                <tr class="total-row">
                    <td style="width:50%">提成合计</td>
                    <td class="text-right"><?= number_format($slipData['commission']['subtotal'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- 其他 -->
        <div class="section">
            <div class="section-title">三、其他</div>
            <table>
                <tr>
                    <th style="width:50%">项目</th>
                    <th class="text-right">金额（元）</th>
                </tr>
                <tr>
                    <td>激励奖金</td>
                    <td class="text-right"><?= number_format($slipData['other']['incentive'], 2) ?></td>
                </tr>
                <tr>
                    <td>手动调整</td>
                    <td class="text-right"><?= number_format($slipData['other']['adjustment'], 2) ?></td>
                </tr>
                <tr>
                    <td>扣款</td>
                    <td class="text-right" style="color:#f5222d;">-<?= number_format($slipData['other']['deduction'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- 合计 -->
        <div class="section">
            <table>
                <tr class="grand-total">
                    <td style="width:50%">应发工资合计</td>
                    <td class="text-right"><?= number_format($slipData['total'], 2) ?> 元</td>
                </tr>
            </table>
        </div>
        
        <div class="footer">
            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-label">员工签字</div>
            </div>
            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-label">财务确认</div>
            </div>
            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-label">日期</div>
            </div>
        </div>
    </div>
    
    <script>
    function downloadPDF() {
        const element = document.querySelector('.container');
        const filename = '<?= htmlspecialchars($slipData['user_name']) ?>_<?= $month ?>_工资条.pdf';
        
        const opt = {
            margin: 10,
            filename: filename,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        
        document.querySelector('.btn-group').style.display = 'none';
        html2pdf().set(opt).from(element).save().then(() => {
            document.querySelector('.btn-group').style.display = 'flex';
        });
    }
    </script>
</body>
</html>
