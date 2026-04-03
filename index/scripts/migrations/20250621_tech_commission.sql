-- ==================== 技术人员项目提成系统 ====================
-- 日期: 2025-06-21
-- 描述: 添加技术人员项目提成功能

-- 1. 扩展 project_tech_assignments 表添加提成字段
ALTER TABLE `project_tech_assignments` 
ADD COLUMN `commission_amount` DECIMAL(10,2) DEFAULT NULL COMMENT '提成金额' AFTER `notes`,
ADD COLUMN `commission_set_by` INT DEFAULT NULL COMMENT '设置人ID' AFTER `commission_amount`,
ADD COLUMN `commission_set_at` INT DEFAULT NULL COMMENT '设置时间戳' AFTER `commission_set_by`,
ADD COLUMN `commission_note` VARCHAR(255) DEFAULT NULL COMMENT '提成备注' AFTER `commission_set_at`;

-- 2. 添加索引
ALTER TABLE `project_tech_assignments` 
ADD INDEX `idx_commission_set_by` (`commission_set_by`);

-- 3. 添加权限
INSERT INTO `permissions` (`code`, `name`, `module`, `description`, `sort`, `create_time`) VALUES
('tech_commission_view', '查看技术提成', 'tech', '技术人员查看自己的项目提成', 34, UNIX_TIMESTAMP()),
('tech_commission_set', '设置技术提成', 'tech', '技术主管设置项目提成金额', 35, UNIX_TIMESTAMP()),
('tech_commission_report', '技术财务报表', 'tech', '管理层查看技术财务汇总报表', 36, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. 给角色分配权限（假设使用 role_permissions 表或直接在 roles 表的 permissions 字段更新）
-- 技术人员 (tech) 角色 - 添加 tech_commission_view
-- 部门主管 (dept_leader) 角色 - 添加 tech_commission_view, tech_commission_set
-- 管理员角色 - 已有全部权限

SELECT '✅ 技术提成系统数据库变更完成' AS '';
