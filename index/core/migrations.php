<?php
/**
 * 数据库迁移辅助函数
 */

require_once __DIR__ . '/db.php';

/**
 * 确保 customers 表有 customer_group 字段
 */
function ensureCustomerGroupField(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    try {
        $pdo = Db::pdo();
        $columns = $pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_group'")->fetchAll();
        
        if (empty($columns)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN customer_group VARCHAR(100) DEFAULT NULL COMMENT '客户群' AFTER mobile");
        }
    } catch (Exception $e) {
        // 忽略错误，可能是表不存在等情况
    }
}
