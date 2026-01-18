<?php

/**
 * 修复脚本：为 okr_cycles 表添加 type 列
 * 
 * 如果表已存在但没有 type 列，则添加该列
 * 如果表中有数据但没有 type 值，根据日期范围自动推断并更新
 * 
 * 用法：
 * php add_okr_cycles_type_column.php
 */

require_once __DIR__ . '/../../core/db.php';

echo ">>> Checking okr_cycles table for type column..." . PHP_EOL;

// 检查列是否存在
$checkSql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'okr_cycles' 
             AND COLUMN_NAME = 'type'";
$result = Db::queryOne($checkSql);

if ($result && $result['cnt'] > 0) {
    echo ">>> type column already exists, skipping..." . PHP_EOL;
} else {
    echo ">>> Adding type column to okr_cycles table..." . PHP_EOL;
    
    // 添加 type 列
    Db::execute("ALTER TABLE okr_cycles 
                 ADD COLUMN type varchar(20) NOT NULL DEFAULT 'month' 
                 COMMENT '类型：week/2week/month/quarter/4month/half_year/year/custom' 
                 AFTER name");
    
    echo ">>> type column added successfully!" . PHP_EOL;
    
    // 如果表中有数据，根据日期范围自动推断并更新 type
    echo ">>> Updating existing records with inferred type values..." . PHP_EOL;
    
    $updateSql = "UPDATE okr_cycles 
                  SET type = CASE
                      WHEN DATEDIFF(end_date, start_date) <= 7 THEN 'week'
                      WHEN DATEDIFF(end_date, start_date) <= 14 THEN '2week'
                      WHEN DATEDIFF(end_date, start_date) <= 35 THEN 'month'
                      WHEN DATEDIFF(end_date, start_date) <= 100 THEN 'quarter'
                      WHEN DATEDIFF(end_date, start_date) <= 130 THEN '4month'
                      WHEN DATEDIFF(end_date, start_date) <= 200 THEN 'half_year'
                      WHEN DATEDIFF(end_date, start_date) <= 400 THEN 'year'
                      ELSE 'custom'
                  END
                  WHERE type = 'month' OR type IS NULL OR type = ''";
    
    Db::execute($updateSql);
    
    $affected = Db::rowCount();
    echo ">>> Updated {$affected} records with inferred type values." . PHP_EOL;
}

echo ">>> Migration completed!" . PHP_EOL;

