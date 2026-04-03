<?php
/**
 * 添加提成字段到 project_tech_assignments 表
 */

require_once __DIR__ . '/../core/db.php';

try {
    // 检查字段是否存在
    $columns = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission'");
    
    if (empty($columns)) {
        // 添加 commission 字段
        Db::execute("ALTER TABLE project_tech_assignments ADD COLUMN commission DECIMAL(10,2) DEFAULT NULL COMMENT '提成金额'");
        echo "Added commission field\n";
    } else {
        echo "commission field already exists\n";
    }
    
    $columns2 = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission_note'");
    
    if (empty($columns2)) {
        // 添加 commission_note 字段
        Db::execute("ALTER TABLE project_tech_assignments ADD COLUMN commission_note VARCHAR(500) DEFAULT NULL COMMENT '提成备注'");
        echo "Added commission_note field\n";
    } else {
        echo "commission_note field already exists\n";
    }
    
    echo "Done!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
