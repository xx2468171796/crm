<?php
/**
 * 从旧数据库同步收款方式到新数据库
 * 
 * php sync_receipt_method_from_old_db.php --dry-run
 * php sync_receipt_method_from_old_db.php --execute
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$oldDbConfig = [
    'host' => '192.168.110.246',
    'port' => 3306,
    'dbname' => 'crm1219',
    'username' => 'crm1219',
    'password' => 'CnfzEF6SPEEAwQRr',
];

$newDbConfig = [
    'host' => '192.168.110.246',
    'port' => 3306,
    'dbname' => 'crm20260111',
    'username' => 'crm20260111',
    'password' => 'dsAe3E2J3smxsGZB',
];

$dryRun = !in_array('--execute', $argv ?? []);

echo "=== 收款方式数据同步脚本 ===\n";
echo "模式: " . ($dryRun ? "DRY-RUN" : "EXECUTE") . "\n\n";

try {
    $oldDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', 
        $oldDbConfig['host'], $oldDbConfig['port'], $oldDbConfig['dbname']);
    $oldDb = new PDO($oldDsn, $oldDbConfig['username'], $oldDbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $newDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $newDbConfig['host'], $newDbConfig['port'], $newDbConfig['dbname']);
    $newDb = new PDO($newDsn, $newDbConfig['username'], $newDbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✓ 已连接数据库\n\n";

    // 获取新数据库中 method 为空的收款记录
    $emptyReceipts = $newDb->query("
        SELECT id, contract_id, installment_id, received_date, amount_applied 
        FROM finance_receipts 
        WHERE method IS NULL OR method = ''
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "新数据库中 method 为空的记录: " . count($emptyReceipts) . " 条\n\n";
    
    if (count($emptyReceipts) == 0) {
        echo "没有需要同步的记录。\n";
        exit;
    }

    $updates = [];
    
    // 尝试通过 ID 匹配
    echo "=== 通过 ID 匹配 ===\n";
    foreach ($emptyReceipts as $r) {
        $oldReceipt = $oldDb->query("
            SELECT id, method FROM finance_receipts WHERE id = {$r['id']} LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        
        if ($oldReceipt && !empty($oldReceipt['method'])) {
            $updates[] = ['id' => $r['id'], 'method' => $oldReceipt['method'], 'match_type' => 'ID'];
            echo "  匹配 ID={$r['id']} -> method='{$oldReceipt['method']}'\n";
        }
    }
    
    // 尝试通过 contract_id + installment_id 匹配
    $unmatchedByIdCount = count($emptyReceipts) - count($updates);
    if ($unmatchedByIdCount > 0) {
        echo "\n=== 通过 contract_id + installment_id 匹配 ===\n";
        $matchedIds = array_column($updates, 'id');
        
        foreach ($emptyReceipts as $r) {
            if (in_array($r['id'], $matchedIds)) continue;
            
            $oldReceipt = $oldDb->query("
                SELECT id, method FROM finance_receipts 
                WHERE contract_id = {$r['contract_id']} 
                  AND installment_id = {$r['installment_id']}
                  AND method IS NOT NULL AND method != ''
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            if ($oldReceipt && !empty($oldReceipt['method'])) {
                $updates[] = ['id' => $r['id'], 'method' => $oldReceipt['method'], 'match_type' => 'contract+installment'];
                echo "  匹配 ID={$r['id']} (contract={$r['contract_id']}, inst={$r['installment_id']}) -> method='{$oldReceipt['method']}'\n";
            }
        }
    }
    
    // 尝试通过 received_date + amount 匹配
    $stillUnmatched = count($emptyReceipts) - count($updates);
    if ($stillUnmatched > 0) {
        echo "\n=== 通过日期+金额匹配 ===\n";
        $matchedIds = array_column($updates, 'id');
        
        foreach ($emptyReceipts as $r) {
            if (in_array($r['id'], $matchedIds)) continue;
            
            $oldReceipt = $oldDb->query("
                SELECT id, method FROM finance_receipts 
                WHERE received_date = '{$r['received_date']}'
                  AND amount_applied = {$r['amount_applied']}
                  AND method IS NOT NULL AND method != ''
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            if ($oldReceipt && !empty($oldReceipt['method'])) {
                $updates[] = ['id' => $r['id'], 'method' => $oldReceipt['method'], 'match_type' => 'date+amount'];
                echo "  匹配 ID={$r['id']} (date={$r['received_date']}, amount={$r['amount_applied']}) -> method='{$oldReceipt['method']}'\n";
            }
        }
    }
    
    echo "\n匹配结果: 成功 " . count($updates) . " / " . count($emptyReceipts) . " 条\n\n";
    
    if (!$dryRun && count($updates) > 0) {
        echo "=== 执行更新 ===\n";
        $stmt = $newDb->prepare("UPDATE finance_receipts SET method = :method WHERE id = :id");
        foreach ($updates as $u) {
            $stmt->execute(['id' => $u['id'], 'method' => $u['method']]);
            echo "  ✓ ID={$u['id']} -> method='{$u['method']}'\n";
        }
        echo "\n已更新 " . count($updates) . " 条记录\n";
    } elseif ($dryRun && count($updates) > 0) {
        echo "=== DRY-RUN 模式 ===\n";
        echo "运行 --execute 执行实际更新\n";
    }
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 完成 ===\n";
