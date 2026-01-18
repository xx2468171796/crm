-- 为 deliverables 表添加软删除字段
-- 执行时间: 2025-12-31
-- 用途: 支持回收站功能

ALTER TABLE `deliverables` 
ADD COLUMN `deleted_at` int(11) DEFAULT NULL COMMENT '软删除时间' AFTER `update_time`,
ADD COLUMN `deleted_by` int(11) DEFAULT NULL COMMENT '删除人ID' AFTER `deleted_at`,
ADD INDEX `idx_deleted_at` (`deleted_at`);

-- 同时添加 file_category 和 parent_folder_id 字段（如果不存在）
-- 这些字段用于文件夹层级管理

-- 检查并添加 file_category 字段
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliverables' AND COLUMN_NAME = 'file_category');
SET @sql = IF(@col_exists = 0, 
    "ALTER TABLE `deliverables` ADD COLUMN `file_category` varchar(50) DEFAULT 'artwork_file' COMMENT '文件分类' AFTER `deliverable_type`",
    "SELECT 'file_category already exists'");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 检查并添加 parent_folder_id 字段
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliverables' AND COLUMN_NAME = 'parent_folder_id');
SET @sql = IF(@col_exists = 0, 
    "ALTER TABLE `deliverables` ADD COLUMN `parent_folder_id` int(11) DEFAULT NULL COMMENT '父文件夹ID' AFTER `project_id`",
    "SELECT 'parent_folder_id already exists'");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 检查并添加 is_folder 字段
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliverables' AND COLUMN_NAME = 'is_folder');
SET @sql = IF(@col_exists = 0, 
    "ALTER TABLE `deliverables` ADD COLUMN `is_folder` tinyint(1) DEFAULT 0 COMMENT '是否是文件夹' AFTER `parent_folder_id`",
    "SELECT 'is_folder already exists'");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
