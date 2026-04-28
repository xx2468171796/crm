-- ==================== 技术提成类型分类 ====================
-- 日期: 2026-04-28
-- 描述: 给技术提成增加可配置的类型分类
--   1. 新表 tech_commission_types：提成类型字典（管理员可增删改查）
--   2. project_tech_assignments 增加 commission_type_id 列（NULL = 未分类）
--   3. 内置 4 条种子：设计提成 / 改稿提成 / 奖金 / 其他

-- 1. 提成类型表
CREATE TABLE IF NOT EXISTS `tech_commission_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(64) NOT NULL COMMENT '类型名称',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序，小的在前',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 / 0停用',
    `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注',
    `create_time` INT NOT NULL DEFAULT 0 COMMENT '创建时间戳',
    `update_time` INT NOT NULL DEFAULT 0 COMMENT '更新时间戳',
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技术提成类型字典';

-- 2. 给提成记录加分类字段
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_tech_assignments'
      AND COLUMN_NAME = 'commission_type_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `project_tech_assignments`
        ADD COLUMN `commission_type_id` INT UNSIGNED DEFAULT NULL COMMENT ''提成类型ID，关联 tech_commission_types.id'' AFTER `commission_note`,
        ADD INDEX `idx_commission_type_id` (`commission_type_id`)',
    'SELECT ''commission_type_id 列已存在，跳过'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. 种子数据（用 INSERT IGNORE，已存在则跳过）
INSERT IGNORE INTO `tech_commission_types` (`name`, `sort_order`, `status`, `remark`, `create_time`, `update_time`) VALUES
    ('设计提成', 10, 1, '常规设计项目提成', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    ('改稿提成', 20, 1, '修改/返工类提成',   UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    ('奖金',     30, 1, '阶段性奖励',         UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    ('其他',     90, 1, '其它未分类',         UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

SELECT '✅ 提成类型迁移完成' AS msg;
