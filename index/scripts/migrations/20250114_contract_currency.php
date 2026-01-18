<?php
/**
 * 数据库迁移：为finance_contracts表添加currency字段
 * 执行方式：通过Web访问或API调用
 */

require_once __DIR__ . '/../../core/db.php';

header('Content-Type: text/plain; charset=utf-8');

$results = [];

try {
    // 1. 检查finance_contracts表是否存在currency字段
    $columns = Db::query("SHOW COLUMNS FROM finance_contracts LIKE 'currency'");
    
    if (empty($columns)) {
        // 添加currency字段
        Db::execute("ALTER TABLE finance_contracts ADD COLUMN currency VARCHAR(10) DEFAULT 'TWD' COMMENT '合同货币代码' AFTER gross_amount");
        $results[] = "✓ 已添加 finance_contracts.currency 字段";
        
        // 2. 为历史合同设置默认货币TWD
        $affected = Db::execute("UPDATE finance_contracts SET currency = 'TWD' WHERE currency IS NULL OR currency = ''");
        $results[] = "✓ 已更新 " . $affected . " 条历史合同货币为TWD";
    } else {
        $results[] = "○ finance_contracts.currency 字段已存在，跳过";
    }
    
    // 3. 检查是否需要添加索引
    $indexes = Db::query("SHOW INDEX FROM finance_contracts WHERE Key_name = 'idx_currency'");
    if (empty($indexes)) {
        Db::execute("ALTER TABLE finance_contracts ADD INDEX idx_currency (currency)");
        $results[] = "✓ 已添加 idx_currency 索引";
    } else {
        $results[] = "○ idx_currency 索引已存在，跳过";
    }
    
    echo "迁移完成！\n\n";
    echo implode("\n", $results);
    echo "\n\n执行时间: " . date('Y-m-d H:i:s');
    
} catch (Exception $e) {
    echo "迁移失败: " . $e->getMessage();
}
