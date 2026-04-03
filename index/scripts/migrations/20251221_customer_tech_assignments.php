<?php
/**
 * 迁移脚本：创建客户-技术分配表
 * 用于记录销售/管理员将客户分配给技术人员的关系
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始迁移：创建 customer_tech_assignments 表\n";

try {
    $pdo = Db::pdo();
    
    // 创建客户-技术分配表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_tech_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL COMMENT '客户ID',
            tech_user_id INT NOT NULL COMMENT '技术人员用户ID',
            assigned_by INT NOT NULL COMMENT '分配人用户ID',
            assigned_at INT NOT NULL COMMENT '分配时间戳',
            notes VARCHAR(255) DEFAULT NULL COMMENT '备注',
            UNIQUE KEY uk_customer_tech (customer_id, tech_user_id),
            KEY idx_tech_user (tech_user_id),
            KEY idx_customer (customer_id),
            KEY idx_assigned_by (assigned_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户-技术分配关系表'
    ");
    echo "✓ 创建 customer_tech_assignments 表成功\n";
    
    echo "\n迁移完成！\n";
    
} catch (Exception $e) {
    echo "✗ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
