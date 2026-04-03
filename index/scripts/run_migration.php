<?php
/**
 * 执行数据库迁移脚本
 */
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "开始执行迁移...\n";

try {
    // 检查 deleted_at 字段是否存在
    $stmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'deleted_at'");
    if ($stmt->rowCount() == 0) {
        echo "添加 deleted_at 字段...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD COLUMN `deleted_at` int(11) DEFAULT NULL COMMENT '软删除时间' AFTER `update_time`");
        echo "✓ deleted_at 字段已添加\n";
    } else {
        echo "✓ deleted_at 字段已存在\n";
    }
    
    // 检查 deleted_by 字段是否存在
    $stmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'deleted_by'");
    if ($stmt->rowCount() == 0) {
        echo "添加 deleted_by 字段...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD COLUMN `deleted_by` int(11) DEFAULT NULL COMMENT '删除人ID' AFTER `deleted_at`");
        echo "✓ deleted_by 字段已添加\n";
    } else {
        echo "✓ deleted_by 字段已存在\n";
    }
    
    // 检查索引是否存在
    $stmt = $pdo->query("SHOW INDEX FROM deliverables WHERE Key_name = 'idx_deleted_at'");
    if ($stmt->rowCount() == 0) {
        echo "添加 idx_deleted_at 索引...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD INDEX `idx_deleted_at` (`deleted_at`)");
        echo "✓ idx_deleted_at 索引已添加\n";
    } else {
        echo "✓ idx_deleted_at 索引已存在\n";
    }
    
    // 检查 file_category 字段
    $stmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'file_category'");
    if ($stmt->rowCount() == 0) {
        echo "添加 file_category 字段...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD COLUMN `file_category` varchar(50) DEFAULT 'artwork_file' COMMENT '文件分类' AFTER `deliverable_type`");
        echo "✓ file_category 字段已添加\n";
    } else {
        echo "✓ file_category 字段已存在\n";
    }
    
    // 检查 parent_folder_id 字段
    $stmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'parent_folder_id'");
    if ($stmt->rowCount() == 0) {
        echo "添加 parent_folder_id 字段...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD COLUMN `parent_folder_id` int(11) DEFAULT NULL COMMENT '父文件夹ID' AFTER `project_id`");
        echo "✓ parent_folder_id 字段已添加\n";
    } else {
        echo "✓ parent_folder_id 字段已存在\n";
    }
    
    // 检查 is_folder 字段
    $stmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'is_folder'");
    if ($stmt->rowCount() == 0) {
        echo "添加 is_folder 字段...\n";
        $pdo->exec("ALTER TABLE `deliverables` ADD COLUMN `is_folder` tinyint(1) DEFAULT 0 COMMENT '是否是文件夹' AFTER `parent_folder_id`");
        echo "✓ is_folder 字段已添加\n";
    } else {
        echo "✓ is_folder 字段已存在\n";
    }
    
    echo "\n迁移完成！\n";
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
