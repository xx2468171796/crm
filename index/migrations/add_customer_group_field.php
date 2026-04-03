<?php
/**
 * 添加客户群字段迁移
 * 执行: php migrations/add_customer_group_field.php
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo = Db::pdo();
    
    // 检查字段是否已存在
    $columns = $pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_group'")->fetchAll();
    
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN customer_group VARCHAR(100) DEFAULT NULL COMMENT '客户群' AFTER mobile");
        echo "成功添加 customer_group 字段\n";
    } else {
        echo "customer_group 字段已存在\n";
    }
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
