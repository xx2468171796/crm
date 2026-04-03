<?php
/**
 * 同步收款方式字典：从旧数据库导入缺失的收款方式到新数据库字典表
 * 
 * php sync_payment_methods_dict.php --dry-run
 * php sync_payment_methods_dict.php --execute
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 旧数据库配置
$oldDbConfig = [
    'host' => '192.168.110.246',
    'port' => 3306,
    'dbname' => 'crm1219',
    'username' => 'crm1219',
    'password' => 'CnfzEF6SPEEAwQRr',
];

// 新数据库配置
$newDbConfig = [
    'host' => '192.168.110.246',
    'port' => 3306,
    'dbname' => 'crm20260111',
    'username' => 'crm20260111',
    'password' => 'dsAe3E2J3smxsGZB',
];

// 收款方式代码到中文标签的映射
$methodLabels = [
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
    'pos' => 'POS',
    'other' => '其他',
];

$dryRun = true;
if (in_array('--execute', $argv ?? [])) {
    $dryRun = false;
}

echo "=== 收款方式字典同步脚本 ===\n";
echo "模式: " . ($dryRun ? "DRY-RUN (仅预览)" : "EXECUTE (实际执行)") . "\n\n";

try {
    // 连接旧数据库
    $oldDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', 
        $oldDbConfig['host'], $oldDbConfig['port'], $oldDbConfig['dbname']);
    $oldDb = new PDO($oldDsn, $oldDbConfig['username'], $oldDbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ 已连接旧数据库: {$oldDbConfig['dbname']}\n";

    // 连接新数据库
    $newDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $newDbConfig['host'], $newDbConfig['port'], $newDbConfig['dbname']);
    $newDb = new PDO($newDsn, $newDbConfig['username'], $newDbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ 已连接新数据库: {$newDbConfig['dbname']}\n\n";

    // 1. 获取旧数据库中所有使用的收款方式
    echo "=== 旧数据库中使用的收款方式 ===\n";
    $oldMethods = $oldDb->query("
        SELECT DISTINCT method 
        FROM finance_receipts 
        WHERE method IS NOT NULL AND method != ''
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($oldMethods as $m) {
        $label = $methodLabels[$m] ?? $m;
        echo "  - $m => $label\n";
    }
    echo "\n";

    // 2. 获取新数据库字典中已有的收款方式
    echo "=== 新数据库字典中已有的收款方式 ===\n";
    $existingMethods = $newDb->query("
        SELECT dict_code, dict_label 
        FROM system_dict 
        WHERE dict_type = 'payment_method'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($existingMethods as $code => $label) {
        echo "  - $code => $label\n";
    }
    echo "\n";

    // 3. 找出需要添加的收款方式
    echo "=== 需要添加到字典的收款方式 ===\n";
    $toAdd = [];
    $sortOrder = 100;
    foreach ($oldMethods as $method) {
        if (!isset($existingMethods[$method])) {
            $label = $methodLabels[$method] ?? $method;
            $toAdd[] = [
                'code' => $method,
                'label' => $label,
                'sort_order' => $sortOrder++
            ];
            echo "  + $method => $label\n";
        }
    }
    echo "\n";

    if (count($toAdd) === 0) {
        echo "没有需要添加的收款方式。\n";
    } else {
        if (!$dryRun) {
            echo "=== 执行添加 ===\n";
            $stmt = $newDb->prepare("
                INSERT INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time)
                VALUES ('payment_method', :code, :label, :sort_order, 1, :now, :now)
            ");
            $now = time();
            foreach ($toAdd as $item) {
                $stmt->execute([
                    'code' => $item['code'],
                    'label' => $item['label'],
                    'sort_order' => $item['sort_order'],
                    'now' => $now
                ]);
                echo "  ✓ 已添加: {$item['code']} => {$item['label']}\n";
            }
        } else {
            echo "=== DRY-RUN 模式，未执行实际添加 ===\n";
            echo "如需执行添加，请运行: php sync_payment_methods_dict.php --execute\n";
        }
    }

} catch (PDOException $e) {
    echo "数据库错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 完成 ===\n";
