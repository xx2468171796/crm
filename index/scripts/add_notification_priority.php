<?php
/**
 * 为 notifications 表添加 priority 字段
 */
require_once __DIR__ . '/../core/db.php';

echo "=== 检查 notifications 表结构 ===\n";

try {
    // 检查表是否存在
    $tables = Db::query("SHOW TABLES LIKE 'notifications'");
    if (empty($tables)) {
        echo "notifications 表不存在，跳过\n";
        exit(0);
    }
    
    // 检查 priority 字段是否存在
    $columns = Db::query("SHOW COLUMNS FROM notifications LIKE 'priority'");
    if (empty($columns)) {
        echo "添加 priority 字段...\n";
        Db::execute("ALTER TABLE notifications ADD COLUMN priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal' COMMENT '通知优先级' AFTER content");
        echo "priority 字段已添加\n";
    } else {
        echo "priority 字段已存在\n";
    }
    
    echo "\n完成!\n";
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
