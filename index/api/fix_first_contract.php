<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';

use Core\Db;

header('Content-Type: application/json');

// 只允许管理员执行
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => '仅管理员可执行'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取所有客户ID
    $customers = Db::query('SELECT DISTINCT customer_id FROM finance_contracts WHERE customer_id IS NOT NULL');
    $fixed = 0;
    $details = [];

    foreach ($customers as $row) {
        $customerId = (int)$row['customer_id'];
        
        // 获取该客户所有合同，按签约日期排序
        $contracts = Db::query(
            'SELECT id, title, sign_date, is_first_contract FROM finance_contracts WHERE customer_id = ? ORDER BY sign_date ASC, id ASC',
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
            $currentValue = (int)($c['is_first_contract'] ?? 0);
            
            if ($currentValue !== $shouldBeFirst) {
                Db::execute(
                    'UPDATE finance_contracts SET is_first_contract = ? WHERE id = ?',
                    [$shouldBeFirst, $contractId]
                );
                $details[] = [
                    'contract_id' => $contractId,
                    'title' => $c['title'],
                    'old_value' => $currentValue,
                    'new_value' => $shouldBeFirst,
                ];
                $fixed++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "修正了 {$fixed} 个合同的is_first_contract字段",
        'fixed_count' => $fixed,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
