<?php
require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
if ($key !== 'migrate2025contract') {
    echo json_encode(['success' => false, 'message' => 'Invalid key']);
    exit;
}

$results = [];

try {
    $columns = Db::query("SHOW COLUMNS FROM finance_contracts LIKE 'currency'");
    
    if (empty($columns)) {
        Db::execute("ALTER TABLE finance_contracts ADD COLUMN currency VARCHAR(10) DEFAULT 'TWD' COMMENT '合同货币代码' AFTER gross_amount");
        $results[] = "已添加 finance_contracts.currency 字段";
        
        $affected = Db::execute("UPDATE finance_contracts SET currency = 'TWD' WHERE currency IS NULL OR currency = ''");
        $results[] = "已更新 " . $affected . " 条历史合同货币为TWD";
    } else {
        $results[] = "finance_contracts.currency 字段已存在";
    }
    
    $indexes = Db::query("SHOW INDEX FROM finance_contracts WHERE Key_name = 'idx_currency'");
    if (empty($indexes)) {
        Db::execute("ALTER TABLE finance_contracts ADD INDEX idx_currency (currency)");
        $results[] = "已添加 idx_currency 索引";
    }
    
    echo json_encode(['success' => true, 'message' => '迁移完成', 'details' => $results], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
