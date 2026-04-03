<?php
/**
 * 客户自定义筛选字段 - 数据库迁移脚本
 * 执行: php run_customer_filter_migration.php
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo = Db::pdo();
    
    echo "开始创建客户筛选字段表...\n";
    
    // 1. 创建筛选字段定义表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_filter_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_name VARCHAR(50) NOT NULL COMMENT '字段名（英文标识）',
            field_label VARCHAR(100) NOT NULL COMMENT '字段标签（显示名称）',
            sort_order INT DEFAULT 0 COMMENT '排序顺序',
            is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_field_name (field_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户自定义筛选字段定义'
    ");
    echo "✓ customer_filter_fields 表创建成功\n";
    
    // 2. 创建筛选字段选项表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_filter_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            field_id INT NOT NULL COMMENT '所属字段ID',
            option_value VARCHAR(50) NOT NULL COMMENT '选项值（存储值）',
            option_label VARCHAR(100) NOT NULL COMMENT '选项标签（显示名称）',
            color VARCHAR(20) DEFAULT '#6366f1' COMMENT '选项颜色',
            sort_order INT DEFAULT 0 COMMENT '排序顺序',
            is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (field_id) REFERENCES customer_filter_fields(id) ON DELETE CASCADE,
            UNIQUE KEY uk_field_option (field_id, option_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户筛选字段选项'
    ");
    echo "✓ customer_filter_options 表创建成功\n";
    
    // 3. 创建客户筛选字段值表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_filter_values (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL COMMENT '客户ID',
            field_id INT NOT NULL COMMENT '字段ID',
            option_id INT NOT NULL COMMENT '选项ID',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES customer_filter_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (option_id) REFERENCES customer_filter_options(id) ON DELETE CASCADE,
            UNIQUE KEY uk_customer_field (customer_id, field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户筛选字段值'
    ");
    echo "✓ customer_filter_values 表创建成功\n";
    
    // 4. 检查是否需要插入示例数据
    $stmt = $pdo->query("SELECT COUNT(*) FROM customer_filter_fields");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        echo "插入示例数据...\n";
        
        // 插入字段
        $pdo->exec("
            INSERT INTO customer_filter_fields (field_name, field_label, sort_order) VALUES
            ('status', '客户状态', 1),
            ('level', '客户等级', 2),
            ('industry', '行业分类', 3)
        ");
        
        // 插入选项
        $pdo->exec("
            INSERT INTO customer_filter_options (field_id, option_value, option_label, color, sort_order) VALUES
            (1, 'active', '活跃', '#10b981', 1),
            (1, 'dormant', '休眠', '#f59e0b', 2),
            (1, 'lost', '流失', '#ef4444', 3),
            (1, 'potential', '潜在', '#6366f1', 4),
            (2, 'a', 'A类', '#10b981', 1),
            (2, 'b', 'B类', '#3b82f6', 2),
            (2, 'c', 'C类', '#f59e0b', 3),
            (2, 'd', 'D类', '#94a3b8', 4),
            (3, 'manufacturing', '制造业', '#6366f1', 1),
            (3, 'retail', '零售业', '#8b5cf6', 2),
            (3, 'service', '服务业', '#06b6d4', 3),
            (3, 'tech', '科技业', '#10b981', 4),
            (3, 'other', '其他', '#94a3b8', 5)
        ");
        echo "✓ 示例数据插入成功\n";
    } else {
        echo "! 已有数据，跳过示例数据插入\n";
    }
    
    echo "\n迁移完成！\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
