<?php
/**
 * 多货币汇率管理 - 数据库迁移脚本
 * 1. 创建货币配置表 currencies
 * 2. 创建汇率历史表 exchange_rate_history
 * 3. 修改 finance_receipts 表添加货币字段
 * 4. 迁移历史数据（默认TWD，固定汇率4.5）
 */
require_once __DIR__ . '/../../core/db.php';

echo "=== 多货币汇率管理迁移脚本 ===" . PHP_EOL;
echo "开始时间: " . date('Y-m-d H:i:s') . PHP_EOL;

// 1. 创建货币配置表
echo PHP_EOL . ">>> 1. 创建货币配置表 currencies..." . PHP_EOL;
Db::execute("
    CREATE TABLE IF NOT EXISTS currencies (
        code VARCHAR(3) NOT NULL PRIMARY KEY COMMENT '货币代码',
        name VARCHAR(50) NOT NULL COMMENT '中文名称',
        symbol VARCHAR(10) NOT NULL COMMENT '货币符号',
        floating_rate DECIMAL(12,6) DEFAULT NULL COMMENT '浮动汇率(相对CNY)',
        fixed_rate DECIMAL(12,6) DEFAULT NULL COMMENT '固定汇率(相对CNY)',
        is_base TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否基准货币',
        status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态:1=启用',
        sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
        updated_at INT DEFAULT NULL COMMENT '更新时间',
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='货币配置表'
");

// 插入默认货币数据
echo ">>> 插入默认货币数据..." . PHP_EOL;
$currencies = [
    ['CNY', '人民币', '¥', 1.000000, 1.000000, 1, 1],
    ['TWD', '新台币', 'NT$', 4.500000, 4.500000, 0, 2],
    ['USD', '美元', '$', null, null, 0, 3],
    ['HKD', '港币', 'HK$', null, null, 0, 4],
    ['SGD', '新加坡元', 'S$', null, null, 0, 5],
    ['GBP', '英镑', '£', null, null, 0, 6],
];

foreach ($currencies as $c) {
    $exists = Db::queryOne("SELECT code FROM currencies WHERE code = ?", [$c[0]]);
    if (!$exists) {
        Db::execute(
            "INSERT INTO currencies (code, name, symbol, floating_rate, fixed_rate, is_base, sort_order, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], time()]
        );
        echo "  - 添加货币: {$c[0]} ({$c[1]})" . PHP_EOL;
    } else {
        echo "  - 货币已存在: {$c[0]}" . PHP_EOL;
    }
}

// 2. 创建汇率历史表
echo PHP_EOL . ">>> 2. 创建汇率历史表 exchange_rate_history..." . PHP_EOL;
Db::execute("
    CREATE TABLE IF NOT EXISTS exchange_rate_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        currency_code VARCHAR(3) NOT NULL COMMENT '货币代码',
        rate_type ENUM('floating', 'fixed') NOT NULL COMMENT '汇率类型',
        rate DECIMAL(12,6) NOT NULL COMMENT '汇率值',
        created_at INT NOT NULL COMMENT '创建时间',
        created_by INT DEFAULT NULL COMMENT '操作人(NULL=系统)',
        INDEX idx_currency (currency_code),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='汇率历史记录表'
");

// 3. 修改 finance_receipts 表添加货币字段
echo PHP_EOL . ">>> 3. 修改 finance_receipts 表添加货币字段..." . PHP_EOL;

$columns = Db::query("SHOW COLUMNS FROM finance_receipts");
$columnNames = array_column($columns, 'Field');

if (!in_array('currency', $columnNames)) {
    Db::execute("ALTER TABLE finance_receipts ADD COLUMN currency VARCHAR(3) DEFAULT 'TWD' COMMENT '货币代码'");
    echo "  - 添加字段: currency" . PHP_EOL;
}

if (!in_array('exchange_rate_floating', $columnNames)) {
    Db::execute("ALTER TABLE finance_receipts ADD COLUMN exchange_rate_floating DECIMAL(12,6) DEFAULT NULL COMMENT '当时浮动汇率'");
    echo "  - 添加字段: exchange_rate_floating" . PHP_EOL;
}

if (!in_array('exchange_rate_fixed', $columnNames)) {
    Db::execute("ALTER TABLE finance_receipts ADD COLUMN exchange_rate_fixed DECIMAL(12,6) DEFAULT NULL COMMENT '当时固定汇率'");
    echo "  - 添加字段: exchange_rate_fixed" . PHP_EOL;
}

if (!in_array('amount_cny', $columnNames)) {
    Db::execute("ALTER TABLE finance_receipts ADD COLUMN amount_cny DECIMAL(12,2) DEFAULT NULL COMMENT '折算人民币金额'");
    echo "  - 添加字段: amount_cny" . PHP_EOL;
}

// 4. 迁移历史数据（仅添加货币标记和汇率，不修改原始金额）
echo PHP_EOL . ">>> 4. 迁移历史数据（标记为TWD，固定汇率4.5，原始金额不变）..." . PHP_EOL;

// 只更新货币标记和汇率信息，amount_cny通过动态计算获取
$updated = Db::execute("
    UPDATE finance_receipts 
    SET currency = 'TWD',
        exchange_rate_floating = 4.5,
        exchange_rate_fixed = 4.5
    WHERE currency IS NULL OR currency = ''
");
echo "  - 更新记录数: {$updated}" . PHP_EOL;
echo "  - 注意: 原始金额(amount_received)保持不变，人民币金额通过汇率动态计算" . PHP_EOL;

// 5. 添加索引
echo PHP_EOL . ">>> 5. 添加索引..." . PHP_EOL;
try {
    Db::execute("CREATE INDEX idx_currency ON finance_receipts (currency)");
    echo "  - 添加索引: idx_currency" . PHP_EOL;
} catch (Exception $e) {
    echo "  - 索引已存在或创建失败" . PHP_EOL;
}

echo PHP_EOL . "=== 迁移完成 ===" . PHP_EOL;
echo "结束时间: " . date('Y-m-d H:i:s') . PHP_EOL;
