<?php
/**
 * 修正is_first_contract字段
 * 每个客户的第一个合同（按sign_date最早）标记为首单
 */

require_once __DIR__ . '/../../init.php';

use Core\Db;

echo ">>> Fixing is_first_contract field..." . PHP_EOL;

// 获取所有客户ID
$customers = Db::query('SELECT DISTINCT customer_id FROM finance_contracts WHERE customer_id IS NOT NULL');
$fixed = 0;

foreach ($customers as $row) {
    $customerId = (int)$row['customer_id'];
    
    // 获取该客户所有合同，按签约日期排序
    $contracts = Db::query(
        'SELECT id, sign_date FROM finance_contracts WHERE customer_id = ? ORDER BY sign_date ASC, id ASC',
        [$customerId]
    );
    
    if (empty($contracts)) {
        continue;
    }
    
    // 第一个合同是首单
    $firstContractId = (int)$contracts[0]['id'];
    
    foreach ($contracts as $idx => $c) {
        $contractId = (int)$c['id'];
        $shouldBeFirst = ($contractId === $firstContractId) ? 1 : 0;
        
        Db::execute(
            'UPDATE finance_contracts SET is_first_contract = ? WHERE id = ?',
            [$shouldBeFirst, $contractId]
        );
        $fixed++;
    }
}

echo "  - Fixed {$fixed} contracts" . PHP_EOL;
echo ">>> Done!" . PHP_EOL;
