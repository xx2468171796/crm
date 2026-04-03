<?php
/**
 * 为deliverables表添加文件夹支持
 */
require_once __DIR__ . '/../../core/db.php';

try {
    $pdo = Db::getPdo();
    
    // 检查parent_folder_id列是否存在
    $columns = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'parent_folder_id'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE deliverables ADD COLUMN parent_folder_id INT DEFAULT NULL AFTER project_id");
        echo "Added parent_folder_id column\n";
    } else {
        echo "parent_folder_id column already exists\n";
    }
    
    // 检查is_folder列是否存在
    $columns = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'is_folder'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE deliverables ADD COLUMN is_folder TINYINT(1) DEFAULT 0 AFTER parent_folder_id");
        echo "Added is_folder column\n";
    } else {
        echo "is_folder column already exists\n";
    }
    
    // 检查索引是否存在
    $indexes = $pdo->query("SHOW INDEX FROM deliverables WHERE Key_name = 'idx_parent_folder'")->fetchAll();
    if (empty($indexes)) {
        $pdo->exec("ALTER TABLE deliverables ADD INDEX idx_parent_folder (parent_folder_id)");
        echo "Added idx_parent_folder index\n";
    } else {
        echo "idx_parent_folder index already exists\n";
    }
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
