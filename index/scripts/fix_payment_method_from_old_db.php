<?php
/**
 * 从旧数据库修复收款方式数据
 * 
 * 使用方法：
 * 1. 先执行 --dry-run 模式查看将要修复的数据
 * 2. 确认无误后执行实际修复
 * 
 * php fix_payment_method_from_old_db.php --dry-run
 * php fix_payment_method_from_old_db.php --execute
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

$dryRun = true;
if (in_array('--execute', $argv ?? [])) {
    $dryRun = false;
}

echo "=== 收款方式数据修复脚本 ===\n";
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

    // 1. 先检查旧数据库的表结构
    echo "=== 检查旧数据库表结构 ===\n";
    $tables = $oldDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    // 查找收款相关表
    $receiptTable = null;
    foreach (['finance_receipts', 'receipts', 'payment_records', 'payments'] as $t) {
        if (in_array($t, $tables)) {
            $receiptTable = $t;
            break;
        }
    }
    
    if (!$receiptTable) {
        echo "旧数据库中的表列表:\n";
        foreach ($tables as $t) {
            if (stripos($t, 'receipt') !== false || stripos($t, 'payment') !== false || stripos($t, 'finance') !== false) {
                echo "  - $t (可能相关)\n";
            }
        }
        echo "\n请确认旧数据库中收款记录存储在哪个表。\n";
        exit(1);
    }
    
    echo "找到收款表: $receiptTable\n";
    
    // 检查表结构
    $columns = $oldDb->query("DESCRIBE $receiptTable")->fetchAll(PDO::FETCH_ASSOC);
    echo "表结构:\n";
    $methodColumn = null;
    foreach ($columns as $col) {
        if (stripos($col['Field'], 'method') !== false || stripos($col['Field'], 'payment') !== false) {
            echo "  - {$col['Field']} ({$col['Type']}) <- 可能是收款方式字段\n";
            if (!$methodColumn) $methodColumn = $col['Field'];
        }
    }
    
    if (!$methodColumn) {
        echo "未找到收款方式字段，请手动指定。\n";
        exit(1);
    }
    
    echo "\n使用字段: $methodColumn\n\n";

    // 2. 查询旧数据库中的收款方式分布
    echo "=== 旧数据库收款方式分布 ===\n";
    $oldMethods = $oldDb->query("
        SELECT $methodColumn as method, COUNT(*) as cnt 
        FROM $receiptTable 
        WHERE $methodColumn IS NOT NULL AND $methodColumn != ''
        GROUP BY $methodColumn
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($oldMethods as $m) {
        echo "  {$m['method']}: {$m['cnt']} 条\n";
    }
    echo "\n";

    // 3. 查询新数据库中缺失收款方式的记录
    echo "=== 新数据库缺失收款方式的记录 ===\n";
    $missingCount = $newDb->query("
        SELECT COUNT(*) FROM finance_receipts 
        WHERE method IS NULL OR method = ''
    ")->fetchColumn();
    echo "缺失收款方式的记录数: $missingCount\n\n";

    // 4. 尝试通过 ID 匹配修复
    echo "=== 通过 ID 匹配修复 ===\n";
    
    // 获取新数据库中缺失收款方式的记录
    $newReceipts = $newDb->query("
        SELECT id, contract_id, installment_id, received_date, amount_applied
        FROM finance_receipts 
        WHERE method IS NULL OR method = ''
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $matched = 0;
    $unmatched = 0;
    $updates = [];
    
    foreach ($newReceipts as $nr) {
        // 尝试在旧数据库中找到匹配的记录
        $stmt = $oldDb->prepare("
            SELECT id, $methodColumn as method
            FROM $receiptTable 
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $nr['id']]);
        $oldRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldRecord && !empty($oldRecord['method'])) {
            $matched++;
            $updates[] = [
                'id' => $nr['id'],
                'method' => $oldRecord['method']
            ];
            if ($matched <= 10) {
                echo "  匹配: ID={$nr['id']} -> method='{$oldRecord['method']}'\n";
            }
        } else {
            $unmatched++;
        }
    }
    
    if ($matched > 10) {
        echo "  ... 还有 " . ($matched - 10) . " 条匹配记录\n";
    }
    
    echo "\n匹配结果: 成功 $matched 条, 未匹配 $unmatched 条\n\n";

    // 5. 执行更新
    if (!$dryRun && count($updates) > 0) {
        echo "=== 执行更新 ===\n";
        $stmt = $newDb->prepare("UPDATE finance_receipts SET method = :method WHERE id = :id");
        $updated = 0;
        foreach ($updates as $u) {
            $stmt->execute(['id' => $u['id'], 'method' => $u['method']]);
            $updated++;
        }
        echo "✓ 已更新 $updated 条记录\n";
    } elseif ($dryRun && count($updates) > 0) {
        echo "=== DRY-RUN 模式，未执行实际更新 ===\n";
        echo "如需执行更新，请运行: php fix_payment_method_from_old_db.php --execute\n";
    } else {
        echo "没有需要更新的记录。\n";
    }

} catch (PDOException $e) {
    echo "数据库错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 完成 ===\n";
