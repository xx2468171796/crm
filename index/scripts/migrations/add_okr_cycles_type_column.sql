-- =============================================
-- 修复脚本：为 okr_cycles 表添加 type 列
-- 说明：如果表已存在但没有 type 列，则添加该列
-- 执行方式：mysql -u... -p... database_name < add_okr_cycles_type_column.sql
-- =============================================

SET NAMES utf8mb4;

-- 检查并添加 type 列（如果不存在）
SET @dbname = DATABASE();
SET @tablename = 'okr_cycles';
SET @columnname = 'type';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1', -- 列已存在，不执行任何操作
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' varchar(20) NOT NULL DEFAULT ''month'' COMMENT ''类型：week/2week/month/quarter/4month/half_year/year/custom'' AFTER name')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 如果表中有数据但没有 type 值，根据日期范围自动推断并更新
UPDATE okr_cycles 
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
WHERE type = 'month' OR type IS NULL OR type = '';

