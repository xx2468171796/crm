-- =============================================
-- 企业权限系统完整方案
-- 执行日期: 2025-12-21
-- =============================================

-- 1. 扩展角色表，添加更多角色
-- =============================================

-- 清空现有角色，重新插入完整角色体系
TRUNCATE TABLE `roles`;

INSERT INTO `roles` (`id`, `name`, `code`, `description`, `permissions`, `create_time`, `update_time`) VALUES
-- 系统级角色
(1, '超级管理员', 'super_admin', '系统最高权限，可管理所有数据和配置', '["*"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(2, '系统管理员', 'admin', '管理员权限，可管理用户、角色、部门', '["customer_view","customer_edit","customer_delete","customer_export","user_manage","role_manage","dept_manage","field_manage","finance_view","finance_edit","tech_resource_view","tech_resource_edit","tech_resource_delete"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 部门级角色
(3, '部门主管', 'dept_leader', '部门主管，可管理本部门所有数据', '["customer_view","customer_edit","customer_delete","customer_export","finance_view","tech_resource_view","tech_resource_edit","dept_data_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(4, '部门管理员', 'dept_admin', '部门管理员，可管理本部门成员和数据', '["customer_view","customer_edit","customer_export","finance_view","tech_resource_view","dept_member_manage"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 业务角色
(5, '销售', 'sales', '销售人员，管理自己的客户', '["customer_view","customer_edit","customer_export","finance_view_own"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(6, '客服', 'service', '客服人员，查看和跟进客户', '["customer_view","customer_edit_basic"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(7, '技术', 'tech', '技术人员，管理技术资源文件', '["customer_view","tech_resource_view","tech_resource_edit","tech_resource_delete","tech_resource_sync"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(8, '财务', 'finance', '财务人员，管理财务数据', '["customer_view","finance_view","finance_edit","contract_view","contract_edit"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 受限角色
(9, '只读用户', 'viewer', '只读权限，只能查看数据', '["customer_view","finance_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());


-- 2. 创建权限定义表
-- =============================================

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT '权限代码',
  `name` varchar(100) NOT NULL COMMENT '权限名称',
  `module` varchar(50) NOT NULL COMMENT '所属模块',
  `description` varchar(255) DEFAULT NULL COMMENT '权限描述',
  `sort_order` int(11) DEFAULT 0 COMMENT '排序',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限定义表';

-- 插入权限定义
INSERT INTO `permissions` (`code`, `name`, `module`, `description`, `sort_order`, `create_time`) VALUES
-- 客户模块
('customer_view', '查看客户', 'customer', '查看客户列表和详情', 100, UNIX_TIMESTAMP()),
('customer_edit', '编辑客户', 'customer', '创建和编辑客户信息', 110, UNIX_TIMESTAMP()),
('customer_edit_basic', '编辑客户基本信息', 'customer', '只能编辑客户基本信息，不能修改归属', 115, UNIX_TIMESTAMP()),
('customer_delete', '删除客户', 'customer', '删除客户记录', 120, UNIX_TIMESTAMP()),
('customer_export', '导出客户', 'customer', '导出客户数据', 130, UNIX_TIMESTAMP()),
('customer_transfer', '转移客户', 'customer', '转移客户归属', 140, UNIX_TIMESTAMP()),

-- 财务模块
('finance_view', '查看财务', 'finance', '查看所有财务数据', 200, UNIX_TIMESTAMP()),
('finance_view_own', '查看自己财务', 'finance', '只查看自己客户的财务数据', 205, UNIX_TIMESTAMP()),
('finance_edit', '编辑财务', 'finance', '编辑财务数据', 210, UNIX_TIMESTAMP()),
('contract_view', '查看合同', 'finance', '查看合同信息', 220, UNIX_TIMESTAMP()),
('contract_edit', '编辑合同', 'finance', '编辑合同信息', 230, UNIX_TIMESTAMP()),

-- 技术资源模块
('tech_resource_view', '查看技术资源', 'tech_resource', '查看技术资源文件', 300, UNIX_TIMESTAMP()),
('tech_resource_edit', '编辑技术资源', 'tech_resource', '上传和编辑技术资源', 310, UNIX_TIMESTAMP()),
('tech_resource_delete', '删除技术资源', 'tech_resource', '删除技术资源文件', 320, UNIX_TIMESTAMP()),
('tech_resource_sync', '同步技术资源', 'tech_resource', '使用桌面端同步资源', 330, UNIX_TIMESTAMP()),

-- 用户管理模块
('user_manage', '用户管理', 'system', '管理系统用户', 400, UNIX_TIMESTAMP()),
('role_manage', '角色管理', 'system', '管理角色和权限', 410, UNIX_TIMESTAMP()),
('dept_manage', '部门管理', 'system', '管理部门结构', 420, UNIX_TIMESTAMP()),
('dept_member_manage', '部门成员管理', 'system', '管理本部门成员', 425, UNIX_TIMESTAMP()),
('field_manage', '字段管理', 'system', '管理自定义字段', 430, UNIX_TIMESTAMP()),

-- 数据范围
('dept_data_view', '查看部门数据', 'data_scope', '查看本部门所有数据', 500, UNIX_TIMESTAMP()),
('all_data_view', '查看所有数据', 'data_scope', '查看系统所有数据', 510, UNIX_TIMESTAMP());


-- 3. 创建用户-角色关联表（支持多角色）
-- =============================================

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `role_id` int(11) NOT NULL COMMENT '角色ID',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_role` (`user_id`, `role_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联表';


-- 4. 创建数据权限规则表
-- =============================================

DROP TABLE IF EXISTS `data_permissions`;
CREATE TABLE `data_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL COMMENT '角色ID',
  `module` varchar(50) NOT NULL COMMENT '模块名',
  `scope` enum('all','dept','dept_tree','self') NOT NULL DEFAULT 'self' COMMENT '数据范围: all=全部, dept=本部门, dept_tree=本部门及下级, self=仅自己',
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_module` (`role_id`, `module`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限规则表';

-- 插入默认数据权限规则
INSERT INTO `data_permissions` (`role_id`, `module`, `scope`, `create_time`, `update_time`) VALUES
-- 超级管理员 - 所有模块全部数据
(1, 'customer', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(1, 'finance', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(1, 'tech_resource', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 系统管理员 - 所有模块全部数据
(2, 'customer', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(2, 'finance', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(2, 'tech_resource', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 部门主管 - 本部门及下级数据
(3, 'customer', 'dept_tree', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(3, 'finance', 'dept_tree', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(3, 'tech_resource', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 部门管理员 - 本部门数据
(4, 'customer', 'dept', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(4, 'finance', 'dept', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(4, 'tech_resource', 'dept', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 销售 - 仅自己数据
(5, 'customer', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(5, 'finance', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(5, 'tech_resource', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 客服 - 仅自己数据
(6, 'customer', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(6, 'finance', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(6, 'tech_resource', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 技术 - 技术资源全部，客户本部门
(7, 'customer', 'dept', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(7, 'finance', 'dept', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(7, 'tech_resource', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 财务 - 财务全部，客户只读
(8, 'customer', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(8, 'finance', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(8, 'tech_resource', 'all', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

-- 只读用户 - 仅自己数据
(9, 'customer', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(9, 'finance', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(9, 'tech_resource', 'self', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());


-- 5. 更新部门表，添加层级支持
-- =============================================

ALTER TABLE `departments` 
ADD COLUMN `path` varchar(255) DEFAULT NULL COMMENT '部门路径，如 /1/2/3/' AFTER `parent_id`,
ADD COLUMN `level` tinyint(4) DEFAULT 1 COMMENT '部门层级' AFTER `path`;

-- 更新现有部门的路径
UPDATE `departments` SET `path` = CONCAT('/', `id`, '/'), `level` = 1 WHERE `parent_id` IS NULL;


-- 6. 迁移现有用户角色到新表
-- =============================================

-- 将现有用户的 role 字段映射到 user_roles 表
INSERT INTO `user_roles` (`user_id`, `role_id`, `create_time`)
SELECT u.id, r.id, UNIX_TIMESTAMP()
FROM `users` u
JOIN `roles` r ON u.role = r.code
WHERE u.status = 1;


-- 7. 修复部门名称乱码（如果需要）
-- =============================================

UPDATE `departments` SET `name` = '设计部', `remark` = '负责设计和技术工作' WHERE `id` = 1;
UPDATE `departments` SET `name` = '销售部', `remark` = '负责销售和客户管理' WHERE `id` = 2;
UPDATE `departments` SET `name` = '客服部', `remark` = '负责客户服务和支持' WHERE `id` = 3;
UPDATE `departments` SET `name` = '财务部', `remark` = '负责财务和合同管理' WHERE `id` = 4;


-- 8. 添加技术部门（如果不存在）
-- =============================================

INSERT INTO `departments` (`id`, `name`, `parent_id`, `path`, `level`, `sort`, `status`, `remark`, `create_time`, `update_time`)
SELECT 5, '技术部', NULL, '/5/', 1, 4, 1, '负责技术资源和桌面端同步', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `name` = '技术部');


-- 完成提示
SELECT '========================================' AS '';
SELECT '企业权限系统迁移完成' AS '';
SELECT '========================================' AS '';
SELECT '✓ 已创建 9 个标准角色' AS '';
SELECT '✓ 已创建权限定义表 (permissions)' AS '';
SELECT '✓ 已创建用户角色关联表 (user_roles)' AS '';
SELECT '✓ 已创建数据权限规则表 (data_permissions)' AS '';
SELECT '✓ 已更新部门表结构' AS '';
SELECT '✓ 已迁移现有用户角色' AS '';
SELECT '✓ 已修复部门名称' AS '';
SELECT '========================================' AS '';
