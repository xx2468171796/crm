-- 为 portal_links 表添加明文密码字段
-- 用于在项目页面显示和复制密码

-- 检查并添加 password_plain 字段
SET @dbname = DATABASE();
SET @tablename = 'portal_links';
SET @columnname = 'password_plain';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` VARCHAR(50) DEFAULT NULL COMMENT ''明文密码(用于显示)'' AFTER `password_hash`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
