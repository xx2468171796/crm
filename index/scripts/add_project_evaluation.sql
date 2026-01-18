-- =============================================
-- 项目评价与自动完工迁移脚本
-- Change ID: auto-complete-with-evaluation
-- =============================================

-- 1. 创建 project_evaluations 表
CREATE TABLE IF NOT EXISTS `project_evaluations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `project_id` INT(11) NOT NULL COMMENT '项目ID',
    `customer_id` INT(11) NOT NULL COMMENT '客户ID',
    `rating` TINYINT(1) NOT NULL DEFAULT 5 COMMENT '评分(1-5星)',
    `comment` TEXT COMMENT '评价内容',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '评价时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_project` (`project_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_rating` (`rating`),
    CONSTRAINT `fk_pe_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pe_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目评价表';

-- 2. 为 projects 表添加 evaluation_deadline 字段
-- 使用存储过程安全添加列
DELIMITER $$
DROP PROCEDURE IF EXISTS add_evaluation_deadline$$
CREATE PROCEDURE add_evaluation_deadline()
BEGIN
    DECLARE v_count INT;
    
    SELECT COUNT(*) INTO v_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'evaluation_deadline';
    
    IF v_count = 0 THEN
        ALTER TABLE `projects` ADD COLUMN `evaluation_deadline` DATETIME DEFAULT NULL COMMENT '评价截止时间(进入设计评价阶段+7天)' AFTER `timeline_start_date`;
    END IF;
END$$
DELIMITER ;

CALL add_evaluation_deadline();
DROP PROCEDURE IF EXISTS add_evaluation_deadline;

-- 3. 添加 completed_at 字段记录完工时间
DELIMITER $$
DROP PROCEDURE IF EXISTS add_completed_at$$
CREATE PROCEDURE add_completed_at()
BEGIN
    DECLARE v_count INT;
    
    SELECT COUNT(*) INTO v_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'completed_at';
    
    IF v_count = 0 THEN
        ALTER TABLE `projects` ADD COLUMN `completed_at` DATETIME DEFAULT NULL COMMENT '完工时间' AFTER `evaluation_deadline`;
    END IF;
END$$
DELIMITER ;

CALL add_completed_at();
DROP PROCEDURE IF EXISTS add_completed_at;

-- 4. 添加 completed_by 字段记录完工操作人
DELIMITER $$
DROP PROCEDURE IF EXISTS add_completed_by$$
CREATE PROCEDURE add_completed_by()
BEGIN
    DECLARE v_count INT;
    
    SELECT COUNT(*) INTO v_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'completed_by';
    
    IF v_count = 0 THEN
        ALTER TABLE `projects` ADD COLUMN `completed_by` VARCHAR(50) DEFAULT NULL COMMENT '完工方式(customer/admin/auto)' AFTER `completed_at`;
    END IF;
END$$
DELIMITER ;

CALL add_completed_by();
DROP PROCEDURE IF EXISTS add_completed_by;
