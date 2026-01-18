<?php
/**
 * 修复提成字段名称
 */

require_once __DIR__ . '/../core/db.php';

try {
    // 检查 commission 字段是否存在
    $columns = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission'");
    
    if (!empty($columns)) {
        // 重命名 commission 为 commission_amount
        Db::execute("ALTER TABLE project_tech_assignments CHANGE COLUMN commission commission_amount DECIMAL(10,2) DEFAULT NULL COMMENT '提成金额'");
        echo "Renamed commission to commission_amount\n";
    }
    
    // 检查 commission_amount 是否存在
    $columns2 = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission_amount'");
    if (empty($columns2)) {
        Db::execute("ALTER TABLE project_tech_assignments ADD COLUMN commission_amount DECIMAL(10,2) DEFAULT NULL COMMENT '提成金额'");
        echo "Added commission_amount field\n";
    } else {
        echo "commission_amount field already exists\n";
    }
    
    // 检查 commission_set_by 是否存在
    $columns3 = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission_set_by'");
    if (empty($columns3)) {
        Db::execute("ALTER TABLE project_tech_assignments ADD COLUMN commission_set_by INT DEFAULT NULL COMMENT '提成设置人'");
        echo "Added commission_set_by field\n";
    } else {
        echo "commission_set_by field already exists\n";
    }
    
    // 检查 commission_set_at 是否存在
    $columns4 = Db::query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission_set_at'");
    if (empty($columns4)) {
        Db::execute("ALTER TABLE project_tech_assignments ADD COLUMN commission_set_at DATETIME DEFAULT NULL COMMENT '提成设置时间'");
        echo "Added commission_set_at field\n";
    } else {
        echo "commission_set_at field already exists\n";
    }
    
    echo "Done!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
