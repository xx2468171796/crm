-- =============================================
-- 项目阶段时间管理迁移脚本
-- Change ID: add-project-stage-timeline
-- 创建日期: 2025-01-XX
-- 更新日期: 2025-01-XX
-- =============================================
-- 兼容性说明：
-- - 使用 INSERT IGNORE 避免重复插入错误
-- - 使用存储过程安全添加列（兼容不支持 IF NOT EXISTS 的MySQL版本）
-- - 所有操作幂等，可重复执行
-- =============================================

-- =============================================
-- 第一部分：辅助存储过程（安全添加列）
-- =============================================

DELIMITER $$

-- 安全添加列的存储过程
DROP PROCEDURE IF EXISTS safe_add_column$$
CREATE PROCEDURE safe_add_column(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(500)
)
BEGIN
    DECLARE v_count INT;
    
    SELECT COUNT(*) INTO v_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column;
    
    IF v_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- =============================================
-- 第二部分：customers 表更新
-- =============================================

-- 添加 group_name 字段（客户群名称）
CALL safe_add_column('customers', 'group_name', "VARCHAR(100) DEFAULT NULL COMMENT '客户群名称' AFTER `group_code`");

-- =============================================
-- 第三部分：创建 project_stage_templates 表
-- =============================================

CREATE TABLE IF NOT EXISTS `project_stage_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `stage_from` VARCHAR(50) NOT NULL COMMENT '起始阶段',
    `stage_to` VARCHAR(50) NOT NULL COMMENT '目标阶段',
    `stage_order` INT(11) NOT NULL DEFAULT 0 COMMENT '阶段顺序',
    `default_days` INT(11) NOT NULL DEFAULT 1 COMMENT '默认天数',
    `description` VARCHAR(255) DEFAULT NULL COMMENT '阶段描述',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `created_by` INT(11) DEFAULT NULL COMMENT '创建人ID',
    `updated_by` INT(11) DEFAULT NULL COMMENT '更新人ID',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_stage_transition` (`stage_from`, `stage_to`),
    KEY `idx_stage_order` (`stage_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目阶段时间模板表';

-- 插入默认模板数据（使用 INSERT IGNORE 避免重复）
INSERT IGNORE INTO `project_stage_templates` (`stage_from`, `stage_to`, `stage_order`, `default_days`, `description`) VALUES
('待沟通', '需求确认', 1, 3, '待沟通 → 需求确认'),
('需求确认', '设计中', 2, 2, '需求确认 → 设计中'),
('设计中', '设计核对', 3, 5, '设计中 → 设计核对'),
('设计核对', '设计完工', 4, 3, '设计核对 → 设计完工'),
('设计完工', '设计评价', 5, 2, '设计完工 → 设计评价');

-- =============================================
-- 第四部分：创建 project_stage_times 表
-- =============================================

CREATE TABLE IF NOT EXISTS `project_stage_times` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `project_id` INT(11) NOT NULL COMMENT '项目ID',
    `stage_from` VARCHAR(50) NOT NULL COMMENT '起始阶段',
    `stage_to` VARCHAR(50) NOT NULL COMMENT '目标阶段',
    `stage_order` INT(11) NOT NULL DEFAULT 0 COMMENT '阶段顺序',
    `planned_days` INT(11) NOT NULL DEFAULT 1 COMMENT '计划天数',
    `planned_start_date` DATE DEFAULT NULL COMMENT '计划开始日期',
    `planned_end_date` DATE DEFAULT NULL COMMENT '计划结束日期',
    `actual_start_date` DATE DEFAULT NULL COMMENT '实际开始日期',
    `actual_end_date` DATE DEFAULT NULL COMMENT '实际结束日期',
    `status` ENUM('pending', 'in_progress', 'completed', 'skipped') NOT NULL DEFAULT 'pending' COMMENT '状态',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_project_stage` (`project_id`, `stage_from`, `stage_to`),
    KEY `idx_project_id` (`project_id`),
    KEY `idx_status` (`status`),
    KEY `idx_planned_end_date` (`planned_end_date`),
    CONSTRAINT `fk_pst_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目阶段时间表';

-- =============================================
-- 第五部分：projects 表更新
-- =============================================

-- 添加 timeline_enabled 字段
CALL safe_add_column('projects', 'timeline_enabled', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用时间线管理' AFTER `current_status`");

-- 添加 timeline_start_date 字段
CALL safe_add_column('projects', 'timeline_start_date', "DATE DEFAULT NULL COMMENT '时间线起始日期' AFTER `timeline_enabled`");

-- =============================================
-- 清理：删除辅助存储过程
-- =============================================
DROP PROCEDURE IF EXISTS safe_add_column;
