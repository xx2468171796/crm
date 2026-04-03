<?php
require_once __DIR__ . '/../core/db.php';

header('Content-Type: text/html; charset=utf-8');

$key = $_GET['key'] ?? '';
if ($key !== 'migrate2025contract') {
    echo "Invalid key. Use ?key=migrate2025contract";
    exit;
}

echo "<pre>\n";
echo "=== 合同货币字段迁移 ===\n\n";

try {
    $columns = Db::query("SHOW COLUMNS FROM finance_contracts LIKE 'currency'");
    
    if (empty($columns)) {
        Db::execute("ALTER TABLE finance_contracts ADD COLUMN currency VARCHAR(10) DEFAULT 'TWD' COMMENT '合同货币代码' AFTER gross_amount");
        echo "✓ 已添加 finance_contracts.currency 字段\n";
        
        $affected = Db::execute("UPDATE finance_contracts SET currency = 'TWD' WHERE currency IS NULL OR currency = ''");
        echo "✓ 已更新 " . $affected . " 条历史合同货币为TWD\n";
    } else {
        echo "○ finance_contracts.currency 字段已存在\n";
        $affected = Db::execute("UPDATE finance_contracts SET currency = 'TWD' WHERE currency IS NULL OR currency = ''");
        if ($affected > 0) {
            echo "✓ 已更新 " . $affected . " 条空货币合同为TWD\n";
        }
    }
    
    $indexes = Db::query("SHOW INDEX FROM finance_contracts WHERE Key_name = 'idx_currency'");
    if (empty($indexes)) {
        Db::execute("ALTER TABLE finance_contracts ADD INDEX idx_currency (currency)");
        echo "✓ 已添加 idx_currency 索引\n";
    } else {
        echo "○ idx_currency 索引已存在\n";
    }
    
    echo "\n=== 迁移完成 ===\n";
    echo "时间: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "</pre>";
