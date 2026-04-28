-- ==================== 技术提成多条记录（时间线模型） ====================
-- 日期: 2026-04-28
-- 描述: 重构提成数据模型 —— 一个 (项目, 设计师) 可以有多条提成记录
--
-- 1. 新表 tech_commission_entries：每条独立 (金额 / 备注 / 时间)
-- 2. 把现有 project_tech_assignments 里的单条提成迁移成 entries 中的 1 条
-- 3. 删除上一版做的 commission_type_id 列 + 删除 tech_commission_types 表
--    （被废弃的「提成类型」字典功能不再使用，由自由文字备注代替）
-- 4. 保留 project_tech_assignments 上的 commission_amount / note / set_at / set_by
--    作为缓存或回退查询使用，应用代码改为以 entries 表为权威数据源

-- 1. 新表
CREATE TABLE IF NOT EXISTS `tech_commission_entries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT UNSIGNED NOT NULL COMMENT '关联 project_tech_assignments.id',
    `amount` DECIMAL(10,2) NOT NULL COMMENT '本条提成金额',
    `note` VARCHAR(500) DEFAULT NULL COMMENT '备注（自由文字，描述本条情况）',
    `entry_at` INT NOT NULL COMMENT '本条提成对应的日期（unix 时间戳）',
    `created_by` INT NOT NULL DEFAULT 0 COMMENT '录入人 user.id',
    `created_at` INT NOT NULL DEFAULT 0,
    `updated_at` INT NOT NULL DEFAULT 0,
    KEY `idx_assignment_id` (`assignment_id`),
    KEY `idx_entry_at` (`entry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技术提成明细记录（多条时间线）';

-- 2. 迁移现有数据：每个有提成的 assignment 转成 1 条 entry（幂等 —— 已迁移过的不重复插）
INSERT INTO `tech_commission_entries` (`assignment_id`, `amount`, `note`, `entry_at`, `created_by`, `created_at`, `updated_at`)
SELECT
    pta.id,
    pta.commission_amount,
    pta.commission_note,
    COALESCE(pta.commission_set_at, UNIX_TIMESTAMP()),
    COALESCE(pta.commission_set_by, 0),
    COALESCE(pta.commission_set_at, UNIX_TIMESTAMP()),
    COALESCE(pta.commission_set_at, UNIX_TIMESTAMP())
FROM `project_tech_assignments` pta
LEFT JOIN `tech_commission_entries` e ON e.assignment_id = pta.id
WHERE pta.commission_amount IS NOT NULL
  AND pta.commission_amount > 0
  AND e.id IS NULL;

-- 3. 删除上一版做的 commission_type_id 列
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_tech_assignments'
      AND COLUMN_NAME = 'commission_type_id');
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `project_tech_assignments` DROP INDEX `idx_commission_type_id`, DROP COLUMN `commission_type_id`',
    'SELECT ''commission_type_id 列不存在，跳过'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. 删除上一版做的 tech_commission_types 字典表
DROP TABLE IF EXISTS `tech_commission_types`;

SELECT '✅ 提成时间线迁移完成（已废弃提成类型字典）' AS msg;
SELECT COUNT(*) AS migrated_entries FROM tech_commission_entries;
