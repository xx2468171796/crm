<?php
/**
 * 一次性迁移脚本 - 添加currency字段到commission_rule_sets表
 */
require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Db::pdo();
    
    // 检查字段是否已存在
    $columns = $pdo->query("SHOW COLUMNS FROM commission_rule_sets LIKE 'currency'")->fetchAll();
    
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE commission_rule_sets ADD COLUMN currency VARCHAR(10) DEFAULT 'CNY' COMMENT '货币类型' AFTER include_prepay");
        echo json_encode(['success' => true, 'message' => '已添加 currency 字段'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => true, 'message' => 'currency 字段已存在，无需迁移'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
