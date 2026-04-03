<?php
/**
 * 提成货币换算功能迁移脚本
 * 
 * 1. 在 commission_rule_sets 表添加 currency 字段
 * 2. 创建汇率表 exchange_rates
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始执行提成货币换算迁移...\n";

try {
    $pdo = Db::pdo();
    
    // 1. 检查并添加 currency 字段到 commission_rule_sets
    $columns = $pdo->query("SHOW COLUMNS FROM commission_rule_sets LIKE 'currency'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE commission_rule_sets ADD COLUMN currency VARCHAR(10) DEFAULT 'CNY' COMMENT '货币类型' AFTER include_prepay");
        echo "✓ 已添加 commission_rule_sets.currency 字段\n";
    } else {
        echo "- commission_rule_sets.currency 字段已存在\n";
    }
    
    // 2. 创建汇率表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_rates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            currency_from VARCHAR(10) NOT NULL COMMENT '源货币',
            currency_to VARCHAR(10) NOT NULL COMMENT '目标货币',
            rate DECIMAL(16, 6) NOT NULL COMMENT '汇率',
            rate_type ENUM('fixed', 'realtime') DEFAULT 'fixed' COMMENT '汇率类型',
            is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            updated_by INT UNSIGNED DEFAULT NULL COMMENT '更新人',
            UNIQUE KEY uk_currency_pair (currency_from, currency_to),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='汇率表'
    ");
    echo "✓ 已创建 exchange_rates 表\n";
    
    // 3. 插入默认汇率数据
    $defaultRates = [
        ['USD', 'CNY', 7.2000],
        ['EUR', 'CNY', 7.8000],
        ['GBP', 'CNY', 9.0000],
        ['JPY', 'CNY', 0.0480],
        ['HKD', 'CNY', 0.9200],
        ['CNY', 'CNY', 1.0000],
    ];
    
    $now = time();
    $stmt = $pdo->prepare("
        INSERT INTO exchange_rates (currency_from, currency_to, rate, rate_type, is_active, created_at, updated_at)
        VALUES (?, 'CNY', ?, 'fixed', 1, ?, ?)
        ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_at = VALUES(updated_at)
    ");
    
    foreach ($defaultRates as $r) {
        $stmt->execute([$r[0], $r[2], $now, $now]);
    }
    echo "✓ 已插入默认汇率数据\n";
    
    // 4. 创建货币配置表（存储支持的货币列表）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS currencies (
            code VARCHAR(10) PRIMARY KEY COMMENT '货币代码',
            name VARCHAR(50) NOT NULL COMMENT '货币名称',
            symbol VARCHAR(10) NOT NULL COMMENT '货币符号',
            is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
            sort_order INT DEFAULT 0 COMMENT '排序'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='货币配置表'
    ");
    echo "✓ 已创建 currencies 表\n";
    
    // 5. 插入默认货币
    $currencies = [
        ['CNY', '人民币', '¥', 1, 1],
        ['USD', '美元', '$', 1, 2],
        ['EUR', '欧元', '€', 1, 3],
        ['GBP', '英镑', '£', 1, 4],
        ['JPY', '日元', '¥', 1, 5],
        ['HKD', '港币', 'HK$', 1, 6],
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO currencies (code, name, symbol, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol)
    ");
    
    foreach ($currencies as $c) {
        $stmt->execute($c);
    }
    echo "✓ 已插入默认货币数据\n";
    
    echo "\n迁移完成！\n";
    
} catch (Exception $e) {
    echo "迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
