-- 文件审批表
-- 用于记录作品文件的审批流程

CREATE TABLE IF NOT EXISTS `file_approvals` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_id` INT UNSIGNED NOT NULL COMMENT '关联的文件ID',
    `submitter_id` INT UNSIGNED NOT NULL COMMENT '提交人ID',
    `reviewer_id` INT UNSIGNED NULL COMMENT '审批人ID',
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '审批状态',
    `submit_time` DATETIME NOT NULL COMMENT '提交时间',
    `review_time` DATETIME NULL COMMENT '审批时间',
    `review_note` TEXT NULL COMMENT '审批备注',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_file_id` (`file_id`),
    INDEX `idx_submitter_id` (`submitter_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_submit_time` (`submit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件审批记录表';

-- 在 customer_files 表中添加 status 和 project_id 字段（如果不存在）
-- ALTER TABLE `customer_files` ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) DEFAULT 'normal' COMMENT '文件状态';
-- ALTER TABLE `customer_files` ADD COLUMN IF NOT EXISTS `project_id` INT UNSIGNED NULL COMMENT '关联项目ID';
-- ALTER TABLE `customer_files` ADD COLUMN IF NOT EXISTS `folder_type` VARCHAR(50) DEFAULT '客户文件' COMMENT '文件夹类型';
