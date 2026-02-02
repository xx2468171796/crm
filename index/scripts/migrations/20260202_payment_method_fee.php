<?php
/**
 * 迁移脚本：为支付方式添加手续费配置字段
 * 
 * 1. system_dict 表添加 fee_type, fee_value 字段
 * 2. finance_receipts 表添加 fee_type, fee_value, fee_amount, original_amount 字段
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始执行迁移：支付方式手续费配置...\n";

$pdo = Db::pdo();

// 1. 为 system_dict 表添加手续费字段
echo "1. 检查 system_dict 表...\n";

$columns = $pdo->query("SHOW COLUMNS FROM system_dict")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('fee_type', $columns)) {
    echo "   添加 fee_type 字段...\n";
    $pdo->exec("ALTER TABLE system_dict ADD COLUMN fee_type VARCHAR(20) DEFAULT NULL COMMENT '手续费类型: fixed=固定金额, percent=百分比, null=无' AFTER is_enabled");
}

if (!in_array('fee_value', $columns)) {
    echo "   添加 fee_value 字段...\n";
    $pdo->exec("ALTER TABLE system_dict ADD COLUMN fee_value DECIMAL(10,4) DEFAULT NULL COMMENT '手续费值: 固定金额或百分比(0.03表示3%)' AFTER fee_type");
}

echo "   system_dict 表更新完成\n";

// 2. 为 finance_receipts 表添加手续费记录字段
echo "2. 检查 finance_receipts 表...\n";

$columns = $pdo->query("SHOW COLUMNS FROM finance_receipts")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('original_amount', $columns)) {
    echo "   添加 original_amount 字段...\n";
    $pdo->exec("ALTER TABLE finance_receipts ADD COLUMN original_amount DECIMAL(12,2) DEFAULT NULL COMMENT '原始收款金额(不含手续费)' AFTER amount_received");
}

if (!in_array('fee_type', $columns)) {
    echo "   添加 fee_type 字段...\n";
    $pdo->exec("ALTER TABLE finance_receipts ADD COLUMN fee_type VARCHAR(20) DEFAULT NULL COMMENT '手续费类型: fixed=固定金额, percent=百分比' AFTER original_amount");
}

if (!in_array('fee_value', $columns)) {
    echo "   添加 fee_value 字段...\n";
    $pdo->exec("ALTER TABLE finance_receipts ADD COLUMN fee_value DECIMAL(10,4) DEFAULT NULL COMMENT '手续费配置值' AFTER fee_type");
}

if (!in_array('fee_amount', $columns)) {
    echo "   添加 fee_amount 字段...\n";
    $pdo->exec("ALTER TABLE finance_receipts ADD COLUMN fee_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '手续费金额' AFTER fee_value");
}

echo "   finance_receipts 表更新完成\n";

echo "\n迁移完成！\n";
