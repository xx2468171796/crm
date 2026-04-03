-- 评价表单模板系统配置
-- 执行时间: 2026-01-03

-- 1. 检查并创建系统配置表（如果不存在）
CREATE TABLE IF NOT EXISTS `system_config` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `config_key` VARCHAR(100) NOT NULL COMMENT '配置键',
    `config_value` TEXT COMMENT '配置值',
    `description` VARCHAR(255) COMMENT '配置描述',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- 2. 插入默认评价模板配置（如果不存在）
INSERT IGNORE INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('default_evaluation_template_id', '0', '默认评价表单模板ID，0表示使用简单评分');

-- 3. 为 form_instances 添加 purpose 字段（区分评价表单实例）
-- 检查字段是否存在，不存在则添加
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'form_instances' 
               AND COLUMN_NAME = 'purpose');

SET @sql := IF(@exist = 0, 
    'ALTER TABLE `form_instances` ADD COLUMN `purpose` VARCHAR(50) DEFAULT NULL COMMENT ''表单用途: evaluation/requirement/custom'' AFTER `status`',
    'SELECT ''Column purpose already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. 添加索引
SET @idx_exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'form_instances' 
                   AND INDEX_NAME = 'idx_purpose');

SET @sql := IF(@idx_exist = 0, 
    'ALTER TABLE `form_instances` ADD INDEX `idx_purpose` (`purpose`)',
    'SELECT ''Index idx_purpose already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✅ 评价表单配置迁移完成' AS result;
