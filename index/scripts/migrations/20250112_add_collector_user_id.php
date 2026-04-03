<?php
/**
 * 迁移脚本：增加收款人字段
 * 
 * 1. finance_receipts 表增加 collector_user_id 字段
 * 2. 将现有记录的 collector_user_id 设为 create_user_id
 * 3. finance_contracts 表增加 is_first_contract 字段
 */

require_once __DIR__ . '/../../core/init.php';

use core\lib\Db;

echo "=== 开始迁移：增加收款人字段 ===" . PHP_EOL;

// 1. finance_receipts 表增加 collector_user_id 字段
echo ">>> 检查 finance_receipts.collector_user_id 字段..." . PHP_EOL;
$columns = Db::query("SHOW COLUMNS FROM finance_receipts LIKE 'collector_user_id'");
if (empty($columns)) {
    echo ">>> 添加 collector_user_id 字段..." . PHP_EOL;
    Db::execute("
        ALTER TABLE finance_receipts 
        ADD COLUMN collector_user_id int DEFAULT NULL COMMENT '收款人ID' 
        AFTER create_user_id
    ");
    Db::execute("ALTER TABLE finance_receipts ADD KEY idx_collector_user_id (collector_user_id)");
    echo ">>> collector_user_id 字段添加成功" . PHP_EOL;
} else {
    echo ">>> collector_user_id 字段已存在，跳过" . PHP_EOL;
}

// 2. 将现有记录的 collector_user_id 设为 create_user_id
echo ">>> 迁移现有数据：设置 collector_user_id = create_user_id..." . PHP_EOL;
$affected = Db::execute("
    UPDATE finance_receipts 
    SET collector_user_id = create_user_id 
    WHERE collector_user_id IS NULL AND create_user_id IS NOT NULL
");
echo ">>> 已更新 {$affected} 条记录" . PHP_EOL;

// 3. finance_contracts 表增加 is_first_contract 字段
echo ">>> 检查 finance_contracts.is_first_contract 字段..." . PHP_EOL;
$columns = Db::query("SHOW COLUMNS FROM finance_contracts LIKE 'is_first_contract'");
if (empty($columns)) {
    echo ">>> 添加 is_first_contract 字段..." . PHP_EOL;
    Db::execute("
        ALTER TABLE finance_contracts 
        ADD COLUMN is_first_contract tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否首单(该客户第一个合同)' 
        AFTER status
    ");
    echo ">>> is_first_contract 字段添加成功" . PHP_EOL;
} else {
    echo ">>> is_first_contract 字段已存在，跳过" . PHP_EOL;
}

// 4. 计算并设置现有合同的首单标记
echo ">>> 计算现有合同的首单标记..." . PHP_EOL;
Db::execute("
    UPDATE finance_contracts c
    INNER JOIN (
        SELECT customer_id, MIN(id) as first_contract_id
        FROM finance_contracts
        GROUP BY customer_id
    ) fc ON c.id = fc.first_contract_id
    SET c.is_first_contract = 1
    WHERE c.is_first_contract = 0
");
$firstContracts = Db::query("SELECT COUNT(*) as cnt FROM finance_contracts WHERE is_first_contract = 1")[0]['cnt'] ?? 0;
echo ">>> 已标记 {$firstContracts} 个首单合同" . PHP_EOL;

echo "=== 迁移完成 ===" . PHP_EOL;
