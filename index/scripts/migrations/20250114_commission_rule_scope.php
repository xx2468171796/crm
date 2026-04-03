<?php
/**
 * Migration: 创建提成规则适用范围关联表
 * - commission_rule_departments: 规则-部门关联
 * - commission_rule_users: 规则-人员关联
 */
require_once __DIR__ . '/../../core/db.php';

echo "开始创建提成规则适用范围关联表...\n";

try {
    // 创建 commission_rule_departments 表
    $sql1 = "CREATE TABLE IF NOT EXISTS commission_rule_departments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rule_set_id INT UNSIGNED NOT NULL COMMENT '规则集ID',
        department_id INT UNSIGNED NOT NULL COMMENT '部门ID',
        created_at INT UNSIGNED DEFAULT NULL COMMENT '创建时间',
        UNIQUE KEY uk_rule_dept (rule_set_id, department_id),
        INDEX idx_department (department_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提成规则-部门关联表'";
    
    Db::exec($sql1);
    echo "✓ 创建表 commission_rule_departments 成功\n";

    // 创建 commission_rule_users 表
    $sql2 = "CREATE TABLE IF NOT EXISTS commission_rule_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rule_set_id INT UNSIGNED NOT NULL COMMENT '规则集ID',
        user_id INT UNSIGNED NOT NULL COMMENT '用户ID',
        created_at INT UNSIGNED DEFAULT NULL COMMENT '创建时间',
        UNIQUE KEY uk_rule_user (rule_set_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提成规则-人员关联表'";
    
    Db::exec($sql2);
    echo "✓ 创建表 commission_rule_users 成功\n";

    echo "\n迁移完成！\n";

} catch (Exception $e) {
    echo "✗ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
