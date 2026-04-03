<?php
/**
 * 导出单人工资条Excel
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

$month = $_GET['month'] ?? date('Y-m');
$targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user['id'];

// 权限检查
$isAdmin = canOrAdmin(PermissionCode::FINANCE_VIEW);
if (!$isAdmin && $targetUserId != $user['id']) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '无权导出他人工资条'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 调用salary_slip API获取数据
$_GET['user_id'] = $targetUserId;
$_GET['month'] = $month;
ob_start();
include __DIR__ . '/salary_slip.php';
$response = ob_get_clean();
$data = json_decode($response, true);

if (!$data || !$data['success']) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '获取工资条数据失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slipData = $data['data'];

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

// 生成Excel（使用简单的CSV格式，兼容Excel）
$filename = $slipData['user_name'] . '_' . $month . '_工资条.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// 输出BOM以支持中文
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// 标题
fputcsv($output, [$slipData['user_name'] . ' ' . $month . ' 工资条']);
fputcsv($output, []);

// 基本信息
fputcsv($output, ['员工姓名', $slipData['user_name']]);
fputcsv($output, ['所属部门', $slipData['department']]);
fputcsv($output, ['结算月份', $month]);
fputcsv($output, []);

// 基本工资
fputcsv($output, ['【基本工资】']);
fputcsv($output, ['底薪', number_format($slipData['basic']['base_salary'], 2)]);
fputcsv($output, ['全勤奖', number_format($slipData['basic']['attendance'], 2)]);
fputcsv($output, ['小计', number_format($slipData['basic']['subtotal'], 2)]);
fputcsv($output, []);

// 提成收入
fputcsv($output, ['【提成收入】']);
fputcsv($output, ['档位基数', number_format($slipData['commission']['tier_base'], 2)]);
fputcsv($output, ['档位比例', ($slipData['commission']['tier_rate'] * 100) . '%']);
fputcsv($output, []);

// 新单提成明细
if (!empty($slipData['commission']['new_orders'])) {
    fputcsv($output, ['Part1: 本月新单提成']);
    fputcsv($output, ['合同名称', '客户', '收款金额', '比例', '提成', '收款人', '方式']);
    foreach ($slipData['commission']['new_orders'] as $o) {
        fputcsv($output, [
            $o['contract_name'],
            $o['customer'],
            number_format($o['amount'], 2),
            ($o['rate'] * 100) . '%',
            number_format($o['commission'], 2),
            $o['collector'],
            fmtMethod($o['method'])
        ]);
    }
    fputcsv($output, ['小计', '', '', '', number_format($slipData['commission']['part1_commission'], 2)]);
    fputcsv($output, []);
}

// 分期提成明细
if (!empty($slipData['commission']['installments'])) {
    fputcsv($output, ['Part2: 往期分期提成']);
    fputcsv($output, ['合同名称', '客户', '收款金额', '比例', '提成', '收款人', '方式']);
    foreach ($slipData['commission']['installments'] as $i) {
        fputcsv($output, [
            $i['contract_name'],
            $i['customer'],
            number_format($i['amount'], 2),
            ($i['rate'] * 100) . '%',
            number_format($i['commission'], 2),
            $i['collector'],
            fmtMethod($i['method'])
        ]);
    }
    fputcsv($output, ['小计', '', '', '', number_format($slipData['commission']['part2_commission'], 2)]);
    fputcsv($output, []);
}

fputcsv($output, ['提成合计', number_format($slipData['commission']['subtotal'], 2)]);
fputcsv($output, []);

// 其他
fputcsv($output, ['【其他】']);
fputcsv($output, ['激励奖金', number_format($slipData['other']['incentive'], 2)]);
fputcsv($output, ['手动调整', number_format($slipData['other']['adjustment'], 2)]);
fputcsv($output, ['扣款', number_format($slipData['other']['deduction'], 2)]);
fputcsv($output, []);

// 总计
fputcsv($output, ['【应发工资合计】', number_format($slipData['total'], 2)]);

fclose($output);
