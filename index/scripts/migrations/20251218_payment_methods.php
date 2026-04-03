<?php
/**
 * 支付方式配置表迁移
 * 创建 system_dict 表用于存储系统字典（如支付方式）
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始执行支付方式配置表迁移...\n";

try {
    $pdo = Db::pdo();
    
    // 1. 创建系统字典表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_dict (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dict_type VARCHAR(50) NOT NULL COMMENT '字典类型',
            dict_code VARCHAR(50) NOT NULL COMMENT '字典代码',
            dict_label VARCHAR(100) NOT NULL COMMENT '显示名称',
            sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
            create_time INT UNSIGNED DEFAULT NULL COMMENT '创建时间',
            update_time INT UNSIGNED DEFAULT NULL COMMENT '更新时间',
            UNIQUE KEY uk_type_code (dict_type, dict_code),
            KEY idx_type_enabled (dict_type, is_enabled, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统字典表'
    ");
    echo "✓ 创建 system_dict 表成功\n";
    
    // 2. 插入默认支付方式
    $now = time();
    $methods = [
        ['payment_method', 'cash', '现金', 1],
        ['payment_method', 'transfer', '转账', 2],
        ['payment_method', 'wechat', '微信', 3],
        ['payment_method', 'alipay', '支付宝', 4],
        ['payment_method', 'pos', 'POS', 5],
        ['payment_method', 'other', '其他', 99],
    ];
    
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time)
        VALUES (?, ?, ?, ?, 1, ?, ?)
    ");
    
    foreach ($methods as $m) {
        $stmt->execute([$m[0], $m[1], $m[2], $m[3], $now, $now]);
    }
    echo "✓ 插入默认支付方式成功\n";
    
    echo "\n迁移完成！\n";
    
} catch (Exception $e) {
    echo "✗ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
