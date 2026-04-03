<?php
/**
 * 生成测试合同数据
 * 时间范围：2024年8月到现在
 * 金额：大于2000 CNY
 */
require_once __DIR__ . '/../core/db.php';

$now = time();

// 插入货币字典
$currencies = [
    ['code' => 'TWD', 'label' => '新台币', 'sort' => 1],
    ['code' => 'CNY', 'label' => '人民币', 'sort' => 2],
    ['code' => 'USD', 'label' => '美元', 'sort' => 3],
];

foreach ($currencies as $c) {
    $exists = Db::queryOne("SELECT id FROM system_dict WHERE dict_type='currency' AND dict_code=:code", ['code' => $c['code']]);
    if (!$exists) {
        Db::execute("INSERT INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time) VALUES ('currency', :code, :label, :sort, 1, :now, :now)", [
            'code' => $c['code'],
            'label' => $c['label'],
            'sort' => $c['sort'],
            'now' => $now
        ]);
        echo "插入货币: {$c['code']}\n";
    }
}

// 获取销售人员 (sales角色)
$salesUsers = Db::query("SELECT id, realname FROM users WHERE status=1 AND role IN ('sales', 'admin', 'tech')");
if (empty($salesUsers)) {
    die("没有找到销售人员\n");
}

// 获取收款方式
$paymentMethods = Db::query("SELECT dict_code FROM system_dict WHERE dict_type='payment_method' AND is_enabled=1");
if (empty($paymentMethods)) {
    $paymentMethods = [['dict_code' => 'alipay'], ['dict_code' => 'guoneiweixin'], ['dict_code' => 'guoneiduigong']];
}

// 获取客户
$customers = Db::query("SELECT id, name, owner_user_id FROM customers WHERE status=1 AND deleted_at IS NULL LIMIT 20");
if (empty($customers)) {
    die("没有找到客户\n");
}

$currencyCodes = ['TWD', 'CNY', 'USD'];
$startDate = strtotime('2024-08-01');
$endDate = time();

echo "开始生成测试合同...\n";

// 生成10个测试合同
for ($i = 0; $i < 10; $i++) {
    $customer = $customers[array_rand($customers)];
    $salesUser = $salesUsers[array_rand($salesUsers)];
    $signerUser = $salesUsers[array_rand($salesUsers)];
    $currency = $currencyCodes[array_rand($currencyCodes)];
    
    // 金额大于2000 CNY，按货币换算
    $baseAmount = rand(2500, 15000);
    if ($currency === 'TWD') {
        $grossAmount = $baseAmount * 4.5; // 换算成台币
    } elseif ($currency === 'USD') {
        $grossAmount = $baseAmount / 7; // 换算成美元
    } else {
        $grossAmount = $baseAmount;
    }
    $grossAmount = round($grossAmount, 2);
    
    // 随机签约日期
    $signTimestamp = rand($startDate, $endDate);
    $signDate = date('Y-m-d', $signTimestamp);
    
    $contractNo = 'TEST-' . date('Ymd', $signTimestamp) . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
    $title = "测试合同-{$customer['name']}-" . date('Ymd', $signTimestamp);
    
    try {
        Db::beginTransaction();
        
        // 插入合同
        Db::execute("INSERT INTO finance_contracts (
            customer_id, contract_no, title, sales_user_id, signer_user_id, sign_date,
            gross_amount, discount_in_calc, net_amount, currency,
            status, create_time, update_time, create_user_id, update_user_id
        ) VALUES (
            :customer_id, :contract_no, :title, :sales_user_id, :signer_user_id, :sign_date,
            :gross_amount, 0, :net_amount, :currency,
            'active', :now, :now, 1, 1
        )", [
            'customer_id' => $customer['id'],
            'contract_no' => $contractNo,
            'title' => $title,
            'sales_user_id' => $salesUser['id'],
            'signer_user_id' => $signerUser['id'],
            'sign_date' => $signDate,
            'gross_amount' => $grossAmount,
            'net_amount' => $grossAmount,
            'currency' => $currency,
            'now' => $now
        ]);
        
        $contractId = (int)Db::lastInsertId();
        
        // 生成2-4期分期
        $installmentCount = rand(2, 4);
        $remainingAmount = $grossAmount;
        
        for ($j = 1; $j <= $installmentCount; $j++) {
            $collector = $salesUsers[array_rand($salesUsers)];
            $method = $paymentMethods[array_rand($paymentMethods)];
            $instCurrency = $currencyCodes[array_rand($currencyCodes)];
            
            // 最后一期放剩余金额
            if ($j === $installmentCount) {
                $instAmount = $remainingAmount;
            } else {
                $instAmount = round($grossAmount / $installmentCount, 2);
                $remainingAmount -= $instAmount;
            }
            
            // 到期日从签约日开始，每期间隔30天
            $dueTimestamp = $signTimestamp + ($j * 30 * 86400);
            $dueDate = date('Y-m-d', $dueTimestamp);
            
            Db::execute("INSERT INTO finance_installments (
                contract_id, customer_id, installment_no,
                due_date, amount_due, amount_paid,
                collector_user_id, payment_method, currency,
                status, create_time, update_time, create_user_id, update_user_id
            ) VALUES (
                :contract_id, :customer_id, :installment_no,
                :due_date, :amount_due, 0.00,
                :collector_user_id, :payment_method, :currency,
                'pending', :now, :now, 1, 1
            )", [
                'contract_id' => $contractId,
                'customer_id' => $customer['id'],
                'installment_no' => $j,
                'due_date' => $dueDate,
                'amount_due' => $instAmount,
                'collector_user_id' => $collector['id'],
                'payment_method' => $method['dict_code'],
                'currency' => $instCurrency,
                'now' => $now
            ]);
        }
        
        Db::commit();
        echo "创建合同 #{$contractId}: {$contractNo} - {$customer['name']} - {$grossAmount} {$currency}\n";
        
    } catch (Exception $e) {
        Db::rollback();
        echo "创建失败: " . $e->getMessage() . "\n";
    }
}

echo "\n测试合同生成完成!\n";
