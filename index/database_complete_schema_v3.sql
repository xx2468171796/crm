DELIMITER $$

DROP PROCEDURE IF EXISTS bootstrap_full_schema$$
CREATE PROCEDURE bootstrap_full_schema()
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET FOREIGN_KEY_CHECKS = 1;
    SELECT '✗ 数据库初始化失败，已回滚未提交的数据' AS message;
    RESIGNAL;
  END;

  -- =============================================
  -- ANKOTTI 客户跟进系统 - 完整数据库结构
  -- 版本: v3.0.0 (正确的三层结构)
  -- 创建日期: 2025-11-20
  -- 数据库: t2
  -- 字符集: utf8mb4
  -- 
  -- 三层结构说明:
  -- v3.0.0 - 2025-11-20
  --   - 第1层：menus (菜单) - 业务模块（首通、异议处理等）
  --   - 第2层：dimensions (维度) - 数据分类（身份、客户需求等）
  --   - 第3层：fields (字段) - 具体选项（业主、设计师等），只有这层有类型、位置、样式配置
  -- =============================================

  SET NAMES utf8mb4;
  SET FOREIGN_KEY_CHECKS = 0;
  START TRANSACTION;

  -- =============================================
  -- 1. 用户与权限相关表
  -- =============================================

  -- 1.1 部门表
  DROP TABLE IF EXISTS `departments`;
  CREATE TABLE `departments` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '部门ID',
    `name` varchar(100) NOT NULL COMMENT '部门名称',
    `parent_id` int(11) DEFAULT NULL COMMENT '上级部门ID(预留,暂不使用)',
    `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序(数字越小越靠前)',
    `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态:1=启用,0=停用',
    `remark` varchar(255) DEFAULT NULL COMMENT '备注',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_parent_id` (`parent_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部门表';

  -- 1.2 用户表
  DROP TABLE IF EXISTS `users`;
  CREATE TABLE `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '用户ID',
    `username` varchar(50) NOT NULL COMMENT '登录账号',
    `password` varchar(255) NOT NULL COMMENT '登录密码(哈希)',
    `realname` varchar(50) NOT NULL COMMENT '真实姓名',
    `role` varchar(20) NOT NULL DEFAULT 'sales' COMMENT '角色: admin=管理员, sales=销售, service=客服',
    `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
    `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
    `department_id` int(11) DEFAULT NULL COMMENT '所属部门ID',
    `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态:1=正常,0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    KEY `idx_department_id` (`department_id`),
    KEY `idx_role` (`role`),
    KEY `idx_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统用户表';

  -- =============================================
  -- 2. 客户相关表
  -- =============================================

  -- 2.1 客户主表
  DROP TABLE IF EXISTS `customers`;
  CREATE TABLE `customers` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_code` varchar(30) NOT NULL COMMENT '系统自动生成ID(例如 CUST-2025-000001)',
    `group_code` varchar(20) DEFAULT NULL COMMENT '群码(不可变唯一标识,格式 QYYYYMMDDNN)',
    `custom_id` varchar(50) DEFAULT NULL COMMENT '用户手动填写的ID',
    `name` varchar(50) NOT NULL COMMENT '客户姓名',
    `alias` varchar(50) DEFAULT NULL COMMENT '客户别名(门户显示名)',
    `mobile` varchar(30) DEFAULT NULL COMMENT '联系方式',
    `customer_group` varchar(100) DEFAULT NULL COMMENT '客户群',
    `gender` varchar(10) DEFAULT NULL COMMENT '性别',
    `age` int(11) DEFAULT NULL COMMENT '年龄',
    `identity` varchar(50) DEFAULT NULL COMMENT '身份(业主/设计师/装修公司等)',
    `demand_time_type` varchar(30) DEFAULT NULL COMMENT '需求时间分类',
    `activity_tag` varchar(50) DEFAULT NULL COMMENT '客户活动标签',
    `intent_level` enum('high','medium','low') DEFAULT NULL COMMENT '意向等级:high=高,medium=中,low=低',
    `intent_score` int(11) DEFAULT NULL COMMENT '意向分数(0-100)',
    `intent_summary` text COMMENT '意向总结',
    `owner_user_id` int(11) NOT NULL COMMENT '归属员工用户ID',
    `department_id` int(11) DEFAULT NULL COMMENT '所属部门ID',
    `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态:1=正常,0=停用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '更新人ID',
    `deleted_at` int(11) DEFAULT NULL COMMENT '删除时间(软删)',
    `deleted_by` int(11) DEFAULT NULL COMMENT '删除人ID',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_customer_code` (`customer_code`),
    UNIQUE KEY `uniq_group_code` (`group_code`),
    KEY `idx_owner_user_id` (`owner_user_id`),
    KEY `idx_department_id` (`department_id`),
    KEY `idx_intent_level` (`intent_level`),
    KEY `idx_create_time` (`create_time`),
    KEY `idx_deleted_at` (`deleted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户主表';

  -- 2.2 首通记录表
  DROP TABLE IF EXISTS `first_contact`;
  CREATE TABLE `first_contact` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `identity` varchar(50) DEFAULT NULL COMMENT '身份(冗余字段,便于查询)',
    `demand_time_type` varchar(30) DEFAULT NULL COMMENT '需求时间(当天/1-3天等)',
    `key_questions` text COMMENT '客户关键疑问(多选汇总)',
    `key_messages` text COMMENT '关键信息传递(多选汇总)',
    `materials_to_send` text COMMENT '需要发送的资料(多选汇总)',
    `helpers` text COMMENT '需要协助的人(多选汇总)',
    `next_follow_time` int(11) DEFAULT NULL COMMENT '下次跟进时间',
    `remark` text COMMENT '首通备注',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '更新人ID',
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_next_follow_time` (`next_follow_time`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='首通记录表';

  -- 2.3 异议处理表
  DROP TABLE IF EXISTS `objection`;
  CREATE TABLE `objection` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `method` varchar(50) DEFAULT NULL COMMENT '采用的方法(五步法/拆分法等)',
    `objection_content` text COMMENT '异议内容',
    `response_script` text COMMENT '应对话术',
    `result` text COMMENT '处理结果/客户反馈',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '更新人ID',
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='异议处理记录表';

  -- 2.4 敲定成交记录表（完整版）
  DROP TABLE IF EXISTS `deal_record`;
  CREATE TABLE `deal_record` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    
    -- 收款确认
    `payment_confirmed` tinyint(1) DEFAULT 0 COMMENT '确认款项入账',
    `note_payment_confirmed` varchar(500) DEFAULT NULL COMMENT '备注-确认款项入账',
    `payment_invoice` tinyint(1) DEFAULT 0 COMMENT '更新内部记录',
    `note_payment_invoice` varchar(500) DEFAULT NULL COMMENT '备注-更新内部记录',
    `payment_stored` tinyint(1) DEFAULT 0 COMMENT '截图留存',
    `note_payment_stored` varchar(500) DEFAULT NULL COMMENT '备注-截图留存',
    `payment_reply` tinyint(1) DEFAULT 0 COMMENT '向内部回复【客户已付款】',
    `note_payment_reply` varchar(500) DEFAULT NULL COMMENT '备注-向内部回复',
    
    -- 客户通知
    `notify_receipt` tinyint(1) DEFAULT 0 COMMENT '发送付款成功通知',
    `note_notify_receipt` varchar(500) DEFAULT NULL COMMENT '备注-发送付款成功通知',
    `notify_schedule` tinyint(1) DEFAULT 0 COMMENT '明确后续流程说明',
    `note_notify_schedule` varchar(500) DEFAULT NULL COMMENT '备注-明确后续流程说明',
    `notify_timeline` tinyint(1) DEFAULT 0 COMMENT '告知预计启动时间',
    `note_notify_timeline` varchar(500) DEFAULT NULL COMMENT '备注-告知预计启动时间',
    `notify_group` tinyint(1) DEFAULT 0 COMMENT '创建 Line / WhatsApp 客户服务群',
    `note_notify_group` varchar(500) DEFAULT NULL COMMENT '备注-创建客户服务群',
    
    -- 建立群组
    `group_invite` tinyint(1) DEFAULT 0 COMMENT '邀请设计师 / 负责人加入',
    `note_group_invite` varchar(500) DEFAULT NULL COMMENT '备注-邀请设计师加入',
    `group_intro` tinyint(1) DEFAULT 0 COMMENT '发送自动话术',
    `note_group_intro` varchar(500) DEFAULT NULL COMMENT '备注-发送自动话术',
    
    -- 资料收集
    `collect_materials` tinyint(1) DEFAULT 0 COMMENT '发送资料准备清单',
    `note_collect_materials` varchar(500) DEFAULT NULL COMMENT '备注-发送资料准备清单',
    `collect_timeline` tinyint(1) DEFAULT 0 COMMENT '询问客户资料供应的时间',
    `note_collect_timeline` varchar(500) DEFAULT NULL COMMENT '备注-询问客户资料供应时间',
    `collect_photos` tinyint(1) DEFAULT 0 COMMENT '汇整客户户型',
    `note_collect_photos` varchar(500) DEFAULT NULL COMMENT '备注-汇整客户户型',
    
    -- 项目交接
    `handover_designer` tinyint(1) DEFAULT 0 COMMENT '提供给主要或签约设计团队',
    `note_handover_designer` varchar(500) DEFAULT NULL COMMENT '备注-提供给设计团队',
    `handover_confirm` tinyint(1) DEFAULT 0 COMMENT '确认设计团队已接收任务',
    `note_handover_confirm` varchar(500) DEFAULT NULL COMMENT '备注-确认设计团队已接收',
    
    -- 内部回报
    `report_progress` tinyint(1) DEFAULT 0 COMMENT '回报今日进度',
    `note_report_progress` varchar(500) DEFAULT NULL COMMENT '备注-回报今日进度',
    `report_new` tinyint(1) DEFAULT 0 COMMENT '更新项目进度（已建群 / 周付费 / 等待材）',
    `note_report_new` varchar(500) DEFAULT NULL COMMENT '备注-更新项目进度',
    `report_care` tinyint(1) DEFAULT 0 COMMENT '当日晚间发送关怀性信息',
    `note_report_care` varchar(500) DEFAULT NULL COMMENT '备注-当日晚间发送关怀性信息',
    
    -- 关怀性跟进
    `care_message` tinyint(1) DEFAULT 0 COMMENT '建立客户作业与服务延续感',
    `note_care_message` varchar(500) DEFAULT NULL COMMENT '备注-建立客户作业与服务延续感',
    
    `other_notes` text COMMENT '其他待办事项',
    
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '更新人ID',
    
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='敲定成交记录表';

  -- 2.5 客户回访表
  DROP TABLE IF EXISTS `customer_feedback`;
  CREATE TABLE `customer_feedback` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `feedback_time` int(11) DEFAULT NULL COMMENT '回访时间',
    `feedback_content` text COMMENT '回访内容',
    `satisfaction_score` int(11) DEFAULT NULL COMMENT '满意度评分(1-5)',
    `remark` text COMMENT '备注',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '更新人ID',
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户回访表';

  -- 2.6 沟通自评表
  DROP TABLE IF EXISTS `self_evaluation`;
  CREATE TABLE `self_evaluation` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `evaluation_data` text COMMENT '评分数据(JSON格式)',
    `total_score` int(11) DEFAULT NULL COMMENT '总分',
    `summary` text COMMENT '自评总结',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='沟通自评表';

  -- =============================================
  -- 3. 文件与链接表
  -- =============================================

  -- 3.1 文件管理表
  DROP TABLE IF EXISTS `files`;
  CREATE TABLE `files` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `file_type` enum('customer','our') NOT NULL DEFAULT 'customer' COMMENT '文件类型:customer=客户文件,our=我们提供的文件',
    `file_name` varchar(255) NOT NULL COMMENT '文件原始名称',
    `file_path` varchar(500) NOT NULL COMMENT '文件存储路径',
    `file_size` int(11) DEFAULT NULL COMMENT '文件大小(字节)',
    `mime_type` varchar(100) DEFAULT NULL COMMENT '文件MIME类型',
    `uploader_user_id` int(11) DEFAULT NULL COMMENT '上传人ID',
    `create_time` int(11) DEFAULT NULL COMMENT '上传时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_file_type` (`file_type`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件管理表(旧版，兼容历史数据)';

  -- 新版客户文件表（支持 S3/本地等 StorageProvider）
  DROP TABLE IF EXISTS `customer_files`;
  CREATE TABLE `customer_files` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `category` enum('client_material','internal_solution') NOT NULL DEFAULT 'client_material' COMMENT '文件分类',
    `folder_path` varchar(255) NOT NULL DEFAULT '' COMMENT '相对子目录',
    `filename` varchar(255) NOT NULL COMMENT '原始文件名',
    `storage_disk` varchar(32) NOT NULL DEFAULT 'local' COMMENT '存储介质标识',
    `storage_key` varchar(512) NOT NULL COMMENT '对象存储路径 customer/{id}/{uuid}',
    `filesize` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
    `mime_type` varchar(120) DEFAULT NULL COMMENT 'MIME类型',
    `file_ext` varchar(16) DEFAULT NULL COMMENT '文件扩展名',
    `checksum_md5` char(32) DEFAULT NULL COMMENT '文件MD5',
    `preview_supported` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否支持在线预览',
    `uploaded_by` int(11) NOT NULL COMMENT '上传人ID',
    `uploaded_at` int(11) NOT NULL COMMENT '上传时间',
    `notes` varchar(255) DEFAULT NULL COMMENT '备注',
    `deleted_at` int(11) DEFAULT NULL COMMENT '删除时间(软删)',
    `deleted_by` int(11) DEFAULT NULL COMMENT '删除人ID',
    `extra` json DEFAULT NULL COMMENT '额外存储信息（如对象存储响应）',
    PRIMARY KEY (`id`),
    KEY `idx_customer_category` (`customer_id`,`category`),
    KEY `idx_storage_key` (`storage_key`(191)),
    KEY `idx_uploaded_by` (`uploaded_by`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_customer_folder` (`customer_id`,`folder_path`(120)),
    KEY `idx_customer_deleted_at` (`customer_id`, `deleted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户文件表(新版)';

  -- 客户文件操作日志
  DROP TABLE IF EXISTS `customer_logs`;
  CREATE TABLE `customer_logs` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `file_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT '关联文件ID',
    `action` varchar(50) NOT NULL COMMENT '操作类型 file_uploaded/file_downloaded/file_deleted',
    `actor_id` int(11) NOT NULL COMMENT '操作人ID',
    `ip` varchar(45) DEFAULT NULL COMMENT 'IP地址',
    `extra` json DEFAULT NULL COMMENT '额外信息（storage_key、耗时等）',
    `created_at` int(11) NOT NULL COMMENT '操作时间',
    PRIMARY KEY (`id`),
    KEY `idx_customer_action` (`customer_id`,`action`),
    KEY `idx_file_id` (`file_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户文件操作日志';

  -- 3.2 客户分享链接表
  DROP TABLE IF EXISTS `customer_links`;
  CREATE TABLE `customer_links` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `token` varchar(64) NOT NULL COMMENT '唯一链接token',
    `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用:1=启用,0=停用',
    `password` varchar(255) DEFAULT NULL COMMENT '访问密码(加密存储)',
    `org_permission` varchar(10) DEFAULT 'edit' COMMENT '组织内权限:none=禁止访问,view=只读,edit=可编辑',
    `password_permission` varchar(10) DEFAULT 'editable' COMMENT '密码权限级别:readonly=只读,editable=可编辑',
    `allowed_view_users` text DEFAULT NULL COMMENT '允许查看的用户ID列表(JSON数组格式)',
    `allowed_edit_users` text DEFAULT NULL COMMENT '允许编辑的用户ID列表(JSON数组格式)',
    `created_at` int(11) DEFAULT NULL COMMENT '创建时间',
    `updated_at` int(11) DEFAULT NULL COMMENT '更新时间',
    `last_access_at` int(11) DEFAULT NULL COMMENT '最后访问时间',
    `last_access_ip` varchar(45) DEFAULT NULL COMMENT '最后访问IP',
    `access_count` int(11) DEFAULT '0' COMMENT '访问次数',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_org_permission` (`org_permission`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户分享链接表(含细粒度权限控制)';

  -- 3.3 文件分享链接表
  DROP TABLE IF EXISTS `file_links`;
  CREATE TABLE `file_links` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `file_id` bigint(20) UNSIGNED NOT NULL COMMENT '文件ID(外键关联customer_files.id)',
    `token` varchar(64) NOT NULL COMMENT '唯一链接token',
    `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用:1=启用,0=停用',
    `password` varchar(255) DEFAULT NULL COMMENT '访问密码(加密存储)',
    `org_permission` varchar(10) DEFAULT 'edit' COMMENT '组织内权限:none=禁止访问,view=只读,edit=可编辑',
    `password_permission` varchar(10) DEFAULT 'editable' COMMENT '密码权限级别:readonly=只读,editable=可编辑',
    `allowed_view_users` text DEFAULT NULL COMMENT '允许查看的用户ID列表(JSON数组格式)',
    `allowed_edit_users` text DEFAULT NULL COMMENT '允许编辑的用户ID列表(JSON数组格式)',
    `created_at` int(11) DEFAULT NULL COMMENT '创建时间',
    `updated_at` int(11) DEFAULT NULL COMMENT '更新时间',
    `last_access_at` int(11) DEFAULT NULL COMMENT '最后访问时间',
    `last_access_ip` varchar(45) DEFAULT NULL COMMENT '最后访问IP',
    `access_count` int(11) DEFAULT '0' COMMENT '访问次数',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_file_id` (`file_id`),
    KEY `idx_org_permission` (`org_permission`),
    CONSTRAINT `fk_file_links_file_id` FOREIGN KEY (`file_id`) REFERENCES `customer_files`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件分享链接表(含细粒度权限控制)';

  -- 3.4 文件管理分享链接表
  DROP TABLE IF EXISTS `file_manager_links`;
  CREATE TABLE `file_manager_links` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID(外键关联customers.id)',
    `token` varchar(64) NOT NULL COMMENT '唯一链接token',
    `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用:1=启用,0=停用',
    `password` varchar(255) DEFAULT NULL COMMENT '访问密码(加密存储)',
    `org_permission` varchar(10) DEFAULT 'edit' COMMENT '组织内权限:none=禁止访问,view=只读,edit=可编辑',
    `password_permission` varchar(10) DEFAULT 'editable' COMMENT '密码权限级别:readonly=只读,editable=可编辑',
    `allowed_view_users` text DEFAULT NULL COMMENT '允许查看的用户ID列表(JSON数组格式)',
    `allowed_edit_users` text DEFAULT NULL COMMENT '允许编辑的用户ID列表(JSON数组格式)',
    `created_at` int(11) DEFAULT NULL COMMENT '创建时间',
    `updated_at` int(11) DEFAULT NULL COMMENT '更新时间',
    `last_access_at` int(11) DEFAULT NULL COMMENT '最后访问时间',
    `last_access_ip` varchar(45) DEFAULT NULL COMMENT '最后访问IP',
    `access_count` int(11) DEFAULT '0' COMMENT '访问次数',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_org_permission` (`org_permission`),
    CONSTRAINT `fk_file_manager_links_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件管理分享链接表(含细粒度权限控制)';

  -- =============================================
  -- 4. 后台管理表
  -- =============================================

  -- 4.1 角色表
  DROP TABLE IF EXISTS `roles`;
  CREATE TABLE `roles` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL COMMENT '角色名称',
    `code` varchar(50) NOT NULL COMMENT '角色代码',
    `description` varchar(255) DEFAULT NULL COMMENT '角色描述',
    `permissions` text COMMENT '权限列表(JSON)',
    `create_time` int(11) DEFAULT NULL,
    `update_time` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `code` (`code`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';

  -- 插入默认角色
  INSERT INTO `roles` (`name`, `code`, `description`, `permissions`, `create_time`, `update_time`) VALUES
  ('超级管理员', 'super_admin', '超级管理员，拥有所有权限', '["*"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('管理员', 'admin', '系统管理员，拥有所有权限', '["customer_view","customer_edit","customer_delete","customer_export","user_manage","role_manage","field_manage"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('部门主管', 'dept_leader', '部门主管，可管理本部门数据', '["customer_view","customer_edit","customer_delete","customer_export","dept_data_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('部门管理员', 'dept_admin', '部门管理员', '["customer_view","customer_edit","dept_data_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('销售', 'sales', '销售人员，可以查看和编辑客户', '["customer_view","customer_edit","customer_export"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('客服', 'service', '客服人员，只能查看客户', '["customer_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('技术', 'tech', '技术人员，只能访问技术资源模块', '["tech_resource_view","tech_resource_edit"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('财务', 'finance', '财务人员，可以管理财务模块', '["finance_view","finance_edit","customer_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('只读', 'viewer', '只读用户，只能查看数据', '["customer_view"]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 4.2 权限定义表
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

  -- 插入默认权限
  INSERT INTO `permissions` (`code`, `name`, `module`, `description`, `sort_order`, `create_time`) VALUES
  ('customer_view', '查看客户', 'customer', '查看客户信息', 1, UNIX_TIMESTAMP()),
  ('customer_edit', '编辑客户', 'customer', '创建和修改客户信息', 2, UNIX_TIMESTAMP()),
  ('customer_edit_basic', '编辑客户基础信息', 'customer', '只能编辑客户基础信息', 3, UNIX_TIMESTAMP()),
  ('customer_delete', '删除客户', 'customer', '删除客户', 4, UNIX_TIMESTAMP()),
  ('customer_transfer', '转移客户', 'customer', '将客户转移给其他销售', 5, UNIX_TIMESTAMP()),
  ('customer_export', '导出客户', 'customer', '导出客户数据', 6, UNIX_TIMESTAMP()),
  ('file_upload', '上传文件', 'file', '上传客户文件', 10, UNIX_TIMESTAMP()),
  ('file_delete', '删除文件', 'file', '删除客户文件', 11, UNIX_TIMESTAMP()),
  ('deal_manage', '成交管理', 'deal', '管理成交记录', 15, UNIX_TIMESTAMP()),
  ('finance_view', '查看财务', 'finance', '查看财务信息', 20, UNIX_TIMESTAMP()),
  ('finance_view_own', '查看自己的财务', 'finance', '只能查看自己的财务信息', 21, UNIX_TIMESTAMP()),
  ('finance_edit', '编辑财务', 'finance', '创建和修改财务信息', 22, UNIX_TIMESTAMP()),
  ('finance_status_edit', '修改财务状态', 'finance', '修改合同和分期状态', 23, UNIX_TIMESTAMP()),
  ('contract_view', '查看合同', 'finance', '查看合同信息', 24, UNIX_TIMESTAMP()),
  ('contract_edit', '编辑合同', 'finance', '创建和修改合同', 25, UNIX_TIMESTAMP()),
  ('tech_resource_view', '查看技术资源', 'tech', '查看技术资源', 30, UNIX_TIMESTAMP()),
  ('tech_resource_edit', '编辑技术资源', 'tech', '上传和修改技术资源', 31, UNIX_TIMESTAMP()),
  ('tech_resource_delete', '删除技术资源', 'tech', '删除技术资源', 32, UNIX_TIMESTAMP()),
  ('tech_resource_sync', '同步技术资源', 'tech', '使用桌面端同步资源', 33, UNIX_TIMESTAMP()),
  ('project_view', '查看项目', 'project', '查看项目列表和详情', 35, UNIX_TIMESTAMP()),
  ('project_create', '创建项目', 'project', '创建新项目', 36, UNIX_TIMESTAMP()),
  ('project_edit', '编辑项目', 'project', '编辑项目信息', 37, UNIX_TIMESTAMP()),
  ('project_delete', '删除项目', 'project', '删除项目', 38, UNIX_TIMESTAMP()),
  ('project_status_edit', '修改项目状态', 'project', '修改项目状态', 39, UNIX_TIMESTAMP()),
  ('project_assign', '分配项目', 'project', '分配项目技术人员', 40, UNIX_TIMESTAMP()),
  ('objection_view', '查看异议', 'objection', '查看异议处理记录', 45, UNIX_TIMESTAMP()),
  ('objection_edit', '编辑异议', 'objection', '创建和修改异议处理', 41, UNIX_TIMESTAMP()),
  ('analytics_view', '查看数据分析', 'analytics', '查看数据分析报表', 50, UNIX_TIMESTAMP()),
  ('all_data_view', '查看全部数据', 'data', '可查看所有数据', 60, UNIX_TIMESTAMP()),
  ('dept_data_view', '查看部门数据', 'data', '可查看本部门数据', 61, UNIX_TIMESTAMP()),
  ('user_manage', '用户管理', 'system', '管理系统用户', 70, UNIX_TIMESTAMP()),
  ('role_manage', '角色管理', 'system', '管理角色和权限', 71, UNIX_TIMESTAMP()),
  ('dept_manage', '部门管理', 'system', '管理部门', 72, UNIX_TIMESTAMP()),
  ('dept_member_manage', '部门成员管理', 'system', '管理部门成员', 73, UNIX_TIMESTAMP()),
  ('field_manage', '字段管理', 'system', '管理自定义字段', 74, UNIX_TIMESTAMP());

  -- 4.3 用户角色关联表
  DROP TABLE IF EXISTS `user_roles`;
  CREATE TABLE `user_roles` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL COMMENT '用户ID',
    `role_id` int(11) NOT NULL COMMENT '角色ID',
    `create_time` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_role` (`user_id`,`role_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_role_id` (`role_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联表';

  -- 4.4 数据权限配置表
  DROP TABLE IF EXISTS `data_permissions`;
  CREATE TABLE `data_permissions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `role_id` int(11) NOT NULL COMMENT '角色ID',
    `module` varchar(50) NOT NULL COMMENT '模块名称',
    `scope` enum('all','dept_tree','dept','self') NOT NULL DEFAULT 'self' COMMENT '数据范围:all=全部,dept_tree=本部门及下级,dept=仅本部门,self=仅自己',
    `create_time` int(11) DEFAULT NULL,
    `update_time` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_role_module` (`role_id`,`module`),
    KEY `idx_role_id` (`role_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限配置表';

  -- 插入默认管理员账号
  -- 用户名: admin
  -- 密码: 123456 (登录后可在后台修改)
  INSERT INTO `users` (`username`, `password`, `realname`, `role`, `email`, `mobile`, `status`, `create_time`, `update_time`) VALUES
  ('admin', '$2y$10$s6fdVFUit6xZprhyf1YwWejleHOlVt5fuY/WBPgetEbPubcTFzy/W', '系统管理员', 'admin', 'admin@example.com', '', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 4.2 菜单表（第1层：业务模块）
  DROP TABLE IF EXISTS `fields`;
  DROP TABLE IF EXISTS `dimensions`;
  DROP TABLE IF EXISTS `menus`;

  CREATE TABLE `menus` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `menu_name` varchar(50) NOT NULL COMMENT '菜单名称',
    `menu_code` varchar(50) NOT NULL COMMENT '菜单代码（英文）',
    `menu_icon` varchar(50) DEFAULT NULL COMMENT '菜单图标',
    `description` varchar(200) DEFAULT NULL COMMENT '菜单描述',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_menu_code` (`menu_code`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜单表（第1层）';

  -- 4.3 维度表（第2层：数据分类）
  CREATE TABLE `dimensions` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `menu_id` int(11) NOT NULL COMMENT '所属菜单ID',
    `dimension_name` varchar(50) NOT NULL COMMENT '维度名称',
    `dimension_code` varchar(50) NOT NULL COMMENT '维度代码',
    `description` varchar(200) DEFAULT NULL COMMENT '维度描述',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dimension_code` (`dimension_code`),
    KEY `idx_menu` (`menu_id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`),
    CONSTRAINT `fk_dimension_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='维度表（第2层）';

  -- 4.4 字段表（第3层：具体选项，有类型、位置、样式配置）
  CREATE TABLE `fields` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `dimension_id` int(11) NOT NULL COMMENT '所属维度ID',
    `field_name` varchar(50) NOT NULL COMMENT '字段名称',
    `field_code` varchar(50) NOT NULL COMMENT '字段代码',
    `field_value` varchar(200) DEFAULT NULL COMMENT '字段值',
    `description` varchar(200) DEFAULT NULL COMMENT '字段描述',
    `field_type` varchar(20) NOT NULL DEFAULT 'text' COMMENT '字段类型: radio/checkbox/text/textarea/select/date',
    `is_required` tinyint(1) DEFAULT 0 COMMENT '是否必填',
    `allow_custom` tinyint(1) DEFAULT 0 COMMENT '是否允许自定义输入',
    `row_order` int(11) DEFAULT 0 COMMENT '行序号',
    `col_order` int(11) DEFAULT 0 COMMENT '列序号',
    `width` varchar(20) DEFAULT 'auto' COMMENT '字段宽度',
    `display_type` varchar(20) DEFAULT 'inline' COMMENT '显示方式',
    `placeholder` varchar(100) DEFAULT NULL COMMENT '占位符',
    `help_text` varchar(200) DEFAULT NULL COMMENT '帮助文本',
    `parent_field_id` int(11) DEFAULT NULL COMMENT '父级字段ID（级联）',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_field_code` (`field_code`),
    KEY `idx_dimension` (`dimension_id`),
    KEY `idx_layout` (`row_order`, `col_order`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_field_dimension` FOREIGN KEY (`dimension_id`) REFERENCES `dimensions`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字段表（第3层）';

  INSERT INTO menus (menu_name, menu_code, description, sort_order, status, create_time, update_time) VALUES
  ('首通', 'first_contact', '首次接触客户的信息记录', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
  SET @menu_first_contact = LAST_INSERT_ID();

  INSERT INTO menus (menu_name, menu_code, description, sort_order, status, create_time, update_time) VALUES
  ('异议处理', 'objection', '客户异议及处理策略记录', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
  SET @menu_objection = LAST_INSERT_ID();

  INSERT INTO menus (menu_name, menu_code, description, sort_order, status, create_time, update_time) VALUES
  ('敲定成交', 'deal', '成交流程、通知与资料交接', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
  SET @menu_deal = LAST_INSERT_ID();

  INSERT INTO menus (menu_name, menu_code, description, sort_order, status, create_time, update_time) VALUES
  ('客户回访', 'customer_feedback', '客户回访与满意度记录', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
  SET @menu_feedback = LAST_INSERT_ID();

  INSERT INTO menus (menu_name, menu_code, description, sort_order, status, create_time, update_time) VALUES
  ('沟通自评', 'self_evaluation', '销售沟通复盘与自评', 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
  SET @menu_self_eval = LAST_INSERT_ID();

  -- 插入默认维度（首通菜单）
  INSERT INTO dimensions (menu_id, dimension_name, dimension_code, description, sort_order, status, create_time, update_time) VALUES
  (@menu_first_contact, '身份', 'identity', '客户的身份类型', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_first_contact, '客户需求', 'customer_demand', '客户的需求时间分类', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_first_contact, '客户关键疑问', 'key_questions', '客户对我们的主要疑问点', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_first_contact, '关键信息传递', 'key_messages', '需要向客户传递的关键信息', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_first_contact, '需要发送的资料', 'materials_to_send', '需要发送给客户的资料类型', 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_first_contact, '需要协助的人', 'helpers', '需要协助跟进的人员', 6, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认维度（异议处理菜单）
  INSERT INTO dimensions (menu_id, dimension_name, dimension_code, description, sort_order, status, create_time, update_time) VALUES
  (@menu_objection, '异议类型', 'objection_type', '客户提出的异议来源', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_objection, '处理方法', 'objection_method', '采用的异议处理策略', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_objection, '处理结果', 'objection_result', '异议处理的结果状态', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认维度（敲定成交菜单）
  INSERT INTO dimensions (menu_id, dimension_name, dimension_code, description, sort_order, status, create_time, update_time) VALUES
  (@menu_deal, '收款确认', 'deal_payment', '收款确认节点', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_deal, '客户通知', 'deal_notify', '通知客户的动作', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_deal, '资料交接', 'deal_handover', '资料准备与交接', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_deal, '关怀跟进', 'deal_care', '内部回报与关怀动作', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认维度（客户回访菜单）
  INSERT INTO dimensions (menu_id, dimension_name, dimension_code, description, sort_order, status, create_time, update_time) VALUES
  (@menu_feedback, '回访渠道', 'feedback_channel', '客户回访方式', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_feedback, '满意度评价', 'feedback_score', '客户满意度等级', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@menu_feedback, '后续动作', 'feedback_action', '需要执行的后续动作', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认维度（沟通自评菜单）
  INSERT INTO dimensions (menu_id, dimension_name, dimension_code, description, sort_order, status, create_time, update_time) VALUES
  (@menu_self_eval, '沟通自评指标', 'self_eval_metrics', '销售复盘自评指标', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 获取维度ID
  SET @identity_id = (SELECT id FROM dimensions WHERE dimension_code = 'identity' LIMIT 1);
  SET @demand_id = (SELECT id FROM dimensions WHERE dimension_code = 'customer_demand' LIMIT 1);
  SET @questions_id = (SELECT id FROM dimensions WHERE dimension_code = 'key_questions' LIMIT 1);
  SET @messages_id = (SELECT id FROM dimensions WHERE dimension_code = 'key_messages' LIMIT 1);
  SET @materials_id = (SELECT id FROM dimensions WHERE dimension_code = 'materials_to_send' LIMIT 1);
  SET @helpers_id = (SELECT id FROM dimensions WHERE dimension_code = 'helpers' LIMIT 1);
  SET @objection_type_id = (SELECT id FROM dimensions WHERE dimension_code = 'objection_type' LIMIT 1);
  SET @objection_method_id = (SELECT id FROM dimensions WHERE dimension_code = 'objection_method' LIMIT 1);
  SET @objection_result_id = (SELECT id FROM dimensions WHERE dimension_code = 'objection_result' LIMIT 1);
  SET @deal_payment_id = (SELECT id FROM dimensions WHERE dimension_code = 'deal_payment' LIMIT 1);
  SET @deal_notify_id = (SELECT id FROM dimensions WHERE dimension_code = 'deal_notify' LIMIT 1);
  SET @deal_handover_id = (SELECT id FROM dimensions WHERE dimension_code = 'deal_handover' LIMIT 1);
  SET @deal_care_id = (SELECT id FROM dimensions WHERE dimension_code = 'deal_care' LIMIT 1);
  SET @feedback_channel_id = (SELECT id FROM dimensions WHERE dimension_code = 'feedback_channel' LIMIT 1);
  SET @feedback_score_id = (SELECT id FROM dimensions WHERE dimension_code = 'feedback_score' LIMIT 1);
  SET @feedback_action_id = (SELECT id FROM dimensions WHERE dimension_code = 'feedback_action' LIMIT 1);
  SET @self_eval_metrics_id = (SELECT id FROM dimensions WHERE dimension_code = 'self_eval_metrics' LIMIT 1);

  -- 插入默认字段（身份维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, sort_order, status, create_time, update_time) VALUES
  (@identity_id, '业主', 'identity_owner', '业主', 'radio', 1, 1, 'auto', 1, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@identity_id, '独立设计师', 'identity_designer', '独立设计师', 'radio', 1, 2, 'auto', 1, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@identity_id, '设计工作室', 'identity_studio', '设计工作室', 'radio', 1, 3, 'auto', 1, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@identity_id, '装修公司', 'identity_company', '装修公司', 'radio', 1, 4, 'auto', 1, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@identity_id, '施工方', 'identity_self', '施工方', 'radio', 1, 5, 'auto', 1, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@identity_id, '设计师助理', 'field_1763736875192', '设计师助理', 'radio', 1, 6, 'auto', 0, 60, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（客户需求维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, sort_order, status, create_time, update_time) VALUES
  (@demand_id, '当天有案子', 'demand_today', '当天有案子', 'radio', 2, 1, 'auto', 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@demand_id, '1-3天有案子', 'demand_1_3', '1-3天有案子', 'radio', 2, 2, 'auto', 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@demand_id, '3-7天有案子', 'demand_3_7', '3-7天有案子', 'radio', 2, 3, 'auto', 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@demand_id, '7-14天有案子', 'demand_7_14', '7-14天有案子', 'radio', 2, 4, 'auto', 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@demand_id, '14-30天有案子', 'demand_14_30', '14-30天有案子', 'radio', 2, 5, 'auto', 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@demand_id, '30+天有案子', 'demand_30_plus', '30+天有案子', 'radio', 2, 6, 'auto', 60, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（客户关键疑问维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@questions_id, '对我们作品质量', 'question_quality', '对我们作品质量', 'checkbox', 3, 1, 'auto', 1, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@questions_id, '对我们专业性', 'question_professional', '对我们专业性', 'checkbox', 3, 2, 'auto', 1, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@questions_id, '对我们价格', 'question_price', '对我们价格', 'checkbox', 3, 3, 'auto', 1, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@questions_id, '对我们服务流程', 'question_service', '对我们服务流程', 'checkbox', 3, 4, 'auto', 1, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（关键信息传递维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@messages_id, '案例质量', 'message_quality', '案例质量', 'checkbox', 4, 1, 'auto', 1, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@messages_id, '专业性', 'message_professional', '专业性', 'checkbox', 4, 2, 'auto', 1, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@messages_id, '平台', 'message_platform', '平台', 'checkbox', 4, 3, 'auto', 1, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@messages_id, '服务流程', 'message_service', '服务流程', 'checkbox', 4, 4, 'auto', 1, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（需要发送的资料维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@materials_id, '效果图案例资料', 'material_samples', '效果图案例资料', 'checkbox', 5, 1, 'auto', 1, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '报价资料-施工图资料', 'material_quotation', '报价资料-施工图资料', 'checkbox', 5, 2, 'auto', 1, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '设计图资料', 'material_design', '设计图资料', 'checkbox', 5, 3, 'auto', 1, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '名片资料', 'material_card', '名片资料', 'checkbox', 5, 4, 'auto', 1, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '装修活动资料', 'material_activity', '装修活动资料', 'checkbox', 6, 1, 'auto', 1, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '设计合同资料', 'material_contract_design', '设计合同资料', 'checkbox', 6, 2, 'auto', 1, 60, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@materials_id, '效果图合同资料', 'material_contract_render', '效果图合同资料', 'checkbox', 6, 3, 'auto', 1, 70, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（需要协助的人维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@helpers_id, '师傅', 'helper_master', '师傅', 'checkbox', 7, 1, 'auto', 1, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@helpers_id, '坦克', 'helper_tank', '坦克', 'checkbox', 7, 2, 'auto', 1, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@helpers_id, '经理', 'helper_manager', '经理', 'checkbox', 7, 3, 'auto', 1, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（异议处理维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@objection_type_id, '价格异议', 'objection_type_price', '价格异议', 'checkbox', 1, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_type_id, '时间异议', 'objection_type_timing', '时间异议', 'checkbox', 1, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_type_id, '信任异议', 'objection_type_trust', '信任异议', 'checkbox', 1, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_type_id, '流程异议', 'objection_type_process', '流程异议', 'checkbox', 1, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_type_id, '其他异议', 'objection_type_other', '其他异议', 'checkbox', 1, 5, 'auto', 0, 1, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@objection_method_id, 'CARE五步法', 'objection_method_care', 'CARE五步法', 'checkbox', 2, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_method_id, '三明治法', 'objection_method_sandwich', '三明治法', 'checkbox', 2, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_method_id, '镜像法', 'objection_method_mirror', '镜像法', 'checkbox', 2, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_method_id, '拆分法', 'objection_method_split', '拆分法', 'checkbox', 2, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_method_id, '举例法', 'objection_method_story', '举例法', 'checkbox', 2, 5, 'auto', 0, 0, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@objection_result_id, '已解决', 'objection_result_done', '已解决', 'radio', 3, 1, 'auto', 1, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_result_id, '进入下一步', 'objection_result_next', '进入下一步', 'radio', 3, 2, 'auto', 1, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@objection_result_id, '待跟进', 'objection_result_pending', '待跟进', 'radio', 3, 3, 'auto', 1, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（敲定成交维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@deal_payment_id, '确认款项入账', 'deal_payment_confirmed', '确认款项入账', 'checkbox', 1, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_payment_id, '更新内部记录', 'deal_payment_invoice', '更新内部记录', 'checkbox', 1, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_payment_id, '截图留存', 'deal_payment_snapshot', '截图留存', 'checkbox', 1, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_payment_id, '内部回报已付款', 'deal_payment_report', '内部回报已付款', 'checkbox', 1, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@deal_notify_id, '发送付款成功通知', 'deal_notify_receipt', '发送付款成功通知', 'checkbox', 2, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_notify_id, '说明后续流程', 'deal_notify_schedule', '说明后续流程', 'checkbox', 2, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_notify_id, '告知预计启动时间', 'deal_notify_timeline', '告知预计启动时间', 'checkbox', 2, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_notify_id, '创建客户服务群', 'deal_notify_group', '创建客户服务群', 'checkbox', 2, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@deal_handover_id, '邀请设计师入群', 'deal_handover_invite', '邀请设计师入群', 'checkbox', 3, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '发送自动话术', 'deal_handover_script', '发送自动话术', 'checkbox', 3, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '发送资料清单', 'deal_handover_materials', '发送资料清单', 'checkbox', 3, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '确认资料时间', 'deal_handover_timeline', '确认资料时间', 'checkbox', 3, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '汇整客户户型', 'deal_handover_photos', '汇整客户户型', 'checkbox', 3, 5, 'auto', 0, 0, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '提供给设计团队', 'deal_handover_delivery', '提供给设计团队', 'checkbox', 3, 6, 'auto', 0, 0, 60, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_handover_id, '确认设计团队接收', 'deal_handover_confirm', '确认设计团队接收', 'checkbox', 3, 7, 'auto', 0, 0, 70, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@deal_care_id, '回报今日进度', 'deal_care_progress', '回报今日进度', 'checkbox', 4, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_care_id, '更新项目状态', 'deal_care_status', '更新项目状态', 'checkbox', 4, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_care_id, '发送关怀信息', 'deal_care_message', '发送关怀信息', 'checkbox', 4, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@deal_care_id, '记录其他待办', 'deal_care_notes', '记录其他待办', 'checkbox', 4, 4, 'auto', 0, 1, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（客户回访维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@feedback_channel_id, '电话回访', 'feedback_channel_phone', '电话回访', 'checkbox', 1, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_channel_id, '到店面谈', 'feedback_channel_store', '到店面谈', 'checkbox', 1, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_channel_id, '微信群互动', 'feedback_channel_wechat', '微信群互动', 'checkbox', 1, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_channel_id, '视频会议', 'feedback_channel_video', '视频会议', 'checkbox', 1, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@feedback_score_id, '非常满意', 'feedback_score_5', '非常满意', 'radio', 2, 1, 'auto', 1, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_score_id, '满意', 'feedback_score_4', '满意', 'radio', 2, 2, 'auto', 1, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_score_id, '一般', 'feedback_score_3', '一般', 'radio', 2, 3, 'auto', 1, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_score_id, '待提升', 'feedback_score_2', '待提升', 'radio', 2, 4, 'auto', 1, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_score_id, '不满意', 'feedback_score_1', '不满意', 'radio', 2, 5, 'auto', 1, 0, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@feedback_action_id, '安排复访', 'feedback_action_followup', '安排复访', 'checkbox', 3, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_action_id, '升级处理', 'feedback_action_escalate', '升级处理', 'checkbox', 3, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@feedback_action_id, '继续观察', 'feedback_action_observe', '继续观察', 'checkbox', 3, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 插入默认字段（沟通自评维度）
  INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, is_required, allow_custom, sort_order, status, create_time, update_time) VALUES
  (@self_eval_metrics_id, '客户理解', 'self_eval_understanding', '客户理解', 'checkbox', 1, 1, 'auto', 0, 0, 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@self_eval_metrics_id, '节奏把控', 'self_eval_rhythm', '节奏把控', 'checkbox', 1, 2, 'auto', 0, 0, 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@self_eval_metrics_id, '方案匹配', 'self_eval_solution', '方案匹配', 'checkbox', 1, 3, 'auto', 0, 0, 30, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@self_eval_metrics_id, '价值呈现', 'self_eval_value', '价值呈现', 'checkbox', 1, 4, 'auto', 0, 0, 40, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (@self_eval_metrics_id, '复盘总结', 'self_eval_review', '复盘总结', 'checkbox', 1, 5, 'auto', 0, 0, 50, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- =============================================
  -- 4.5 模块与自定义字段（兼容旧版页面）
  -- =============================================

  -- ⚠️ 以下表供 admin_fields / get_field_options 等旧版接口使用
  DROP TABLE IF EXISTS `field_options`;
  DROP TABLE IF EXISTS `custom_fields`;
  DROP TABLE IF EXISTS `modules`;

  CREATE TABLE `modules` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '模块ID',
    `module_name` varchar(50) NOT NULL COMMENT '模块名称',
    `module_code` varchar(50) NOT NULL COMMENT '模块代码(英文)',
    `module_icon` varchar(50) DEFAULT NULL COMMENT '模块图标',
    `description` varchar(200) DEFAULT NULL COMMENT '模块描述',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序(数字越小越靠前)',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_module_code` (`module_code`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模块表（旧版字段管理）';

  CREATE TABLE `custom_fields` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '字段ID',
    `module_id` int(11) DEFAULT NULL COMMENT '所属模块ID',
    `field_name` varchar(50) NOT NULL COMMENT '字段名称',
    `description` varchar(200) DEFAULT NULL COMMENT '字段描述',
    `field_code` varchar(50) NOT NULL COMMENT '字段代码',
    `module` varchar(50) DEFAULT 'first_contact' COMMENT '兼容旧版的模块标识',
    `field_type` varchar(20) NOT NULL COMMENT '字段类型:text/textarea/select/radio/checkbox/date/cascading_select',
    `display_type` varchar(20) DEFAULT 'inline' COMMENT '显示方式:inline/block/grid',
    `width` varchar(20) DEFAULT 'auto' COMMENT '字段宽度:auto/25%/50%/75%/100%',
    `row_order` int(11) DEFAULT 0 COMMENT '行序号',
    `col_order` int(11) DEFAULT 0 COMMENT '列序号',
    `placeholder` varchar(100) DEFAULT NULL COMMENT '占位符',
    `help_text` varchar(200) DEFAULT NULL COMMENT '帮助文本',
    `validation_rules` text COMMENT '验证规则(JSON)',
    `option_source` varchar(20) DEFAULT 'inline' COMMENT '选项来源:inline/table',
    `parent_field_id` int(11) DEFAULT NULL COMMENT '父级字段ID（级联）',
    `field_options` text COMMENT '内联选项(JSON)',
    `allow_custom` tinyint(1) DEFAULT 0 COMMENT '允许自定义输入',
    `is_required` tinyint(1) DEFAULT 0 COMMENT '是否必填',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `field_code` (`field_code`),
    KEY `idx_module` (`module`),
    KEY `idx_parent` (`parent_field_id`),
    KEY `idx_module_id` (`module_id`),
    KEY `idx_layout` (`row_order`,`col_order`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义字段定义表（兼容旧版）';

  CREATE TABLE `field_options` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '选项ID',
    `field_id` int(11) NOT NULL COMMENT '所属字段ID',
    `option_value` varchar(100) NOT NULL COMMENT '选项值',
    `option_label` varchar(100) NOT NULL COMMENT '选项显示内容',
    `parent_option_id` int(11) DEFAULT NULL COMMENT '父级选项ID(级联)',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序号',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_field_id` (`field_id`),
    KEY `idx_parent_option` (`parent_option_id`),
    KEY `idx_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义字段选项表（兼容旧版）';

  -- 自定义字段值表（兼容旧版）
  DROP TABLE IF EXISTS `custom_field_values`;
  CREATE TABLE `custom_field_values` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '值记录ID',
    `field_id` int(11) NOT NULL COMMENT '字段ID',
    `target_type` varchar(50) NOT NULL COMMENT '目标类型(customer/first_contact等)',
    `target_id` int(11) NOT NULL COMMENT '目标对象ID',
    `field_value` text COMMENT '字段值',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_field_id` (`field_id`),
    KEY `idx_target` (`target_type`,`target_id`),
    CONSTRAINT `fk_custom_field_values_field_id` FOREIGN KEY (`field_id`) REFERENCES `custom_fields`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义字段值表（兼容旧版）';

  -- 新三层结构字段值表
  DROP TABLE IF EXISTS `dimension_field_values`;
  CREATE TABLE `dimension_field_values` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '值记录ID',
    `dimension_id` int(11) NOT NULL COMMENT '维度ID',
    `target_type` varchar(50) NOT NULL COMMENT '目标类型(first_contact/objection/deal等)',
    `target_id` int(11) NOT NULL COMMENT '目标对象ID(如first_contact表的id)',
    `dimension_value` text COMMENT '维度字段值',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dimension_target` (`dimension_id`, `target_type`, `target_id`),
    KEY `idx_dimension_id` (`dimension_id`),
    KEY `idx_target` (`target_type`, `target_id`),
    CONSTRAINT `fk_dimension_field_values_dimension_id` FOREIGN KEY (`dimension_id`) REFERENCES `dimensions`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='新三层结构字段值表';

  -- =============================================
  -- 5. 审计与日志表
  -- =============================================

  -- 5.1 操作日志表
  DROP TABLE IF EXISTS `operation_logs`;
  CREATE TABLE `operation_logs` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
    `user_id` int(11) NOT NULL COMMENT '操作人ID',
    `module` varchar(50) NOT NULL COMMENT '模块名称(customer/file等)',
    `action` varchar(50) NOT NULL COMMENT '操作动作(create/update/delete/download...)',
    `target_type` varchar(50) DEFAULT NULL COMMENT '目标对象类型(customer/file等)',
    `target_id` bigint(20) DEFAULT NULL COMMENT '目标对象ID',
    `customer_id` int(11) DEFAULT NULL COMMENT '关联客户ID',
    `file_id` bigint(20) DEFAULT NULL COMMENT '关联文件ID',
    `description` varchar(255) DEFAULT NULL COMMENT '操作描述',
    `extra` json DEFAULT NULL COMMENT '附加数据(JSON)',
    `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
    `user_agent` varchar(255) DEFAULT NULL COMMENT '浏览器User-Agent',
    `created_at` int(11) NOT NULL COMMENT '操作时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_target` (`target_type`,`target_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_file_id` (`file_id`),
    KEY `idx_created_at` (`created_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

  -- 5.2 系统字典表
  DROP TABLE IF EXISTS `system_dict`;
  CREATE TABLE `system_dict` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `dict_type` varchar(50) NOT NULL COMMENT '字典类型',
    `dict_code` varchar(50) NOT NULL COMMENT '字典代码',
    `dict_label` varchar(100) NOT NULL COMMENT '字典标签',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `is_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    `create_time` int(11) unsigned DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) unsigned DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_type_code` (`dict_type`,`dict_code`),
    KEY `idx_type_enabled` (`dict_type`,`is_enabled`,`sort_order`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统字典表';

  -- =============================================
  -- 6. 财务模块表
  -- =============================================

  DROP TABLE IF EXISTS `finance_installment_change_logs`;
  DROP TABLE IF EXISTS `finance_collection_logs`;
  DROP TABLE IF EXISTS `finance_status_change_logs`;
  DROP TABLE IF EXISTS `finance_contract_files`;
  DROP TABLE IF EXISTS `finance_prepay_ledger`;
  DROP TABLE IF EXISTS `finance_receipts`;
  DROP TABLE IF EXISTS `finance_installments`;
  DROP TABLE IF EXISTS `finance_contracts`;
  DROP TABLE IF EXISTS `finance_saved_views`;

  CREATE TABLE `finance_contracts` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` int NOT NULL,
    `contract_no` varchar(64) DEFAULT NULL,
    `title` varchar(255) DEFAULT NULL,
    `sales_user_id` int NOT NULL,
    `sign_date` date DEFAULT NULL,
    `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `discount_in_calc` tinyint(1) NOT NULL DEFAULT 0,
    `discount_type` enum('amount','rate') DEFAULT NULL,
    `discount_value` decimal(12,4) DEFAULT NULL,
    `discount_note` varchar(255) DEFAULT NULL,
    `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `status` varchar(20) NOT NULL DEFAULT 'active',
    `manual_status` varchar(20) DEFAULT NULL,
    `create_time` int NOT NULL,
    `update_time` int NOT NULL,
    `create_user_id` int DEFAULT NULL,
    `update_user_id` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_sales_user_id` (`sales_user_id`),
    KEY `idx_sign_date` (`sign_date`),
    KEY `idx_status` (`status`),
    KEY `idx_manual_status` (`manual_status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_installments` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `contract_id` bigint UNSIGNED NOT NULL,
    `customer_id` int NOT NULL,
    `installment_no` int NOT NULL DEFAULT 1,
    `due_date` date NOT NULL,
    `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
    `status` varchar(20) NOT NULL DEFAULT 'pending',
    `manual_status` varchar(20) DEFAULT NULL,
    `create_time` int NOT NULL,
    `update_time` int NOT NULL,
    `create_user_id` int DEFAULT NULL,
    `update_user_id` int DEFAULT NULL,
    `deleted_at` int DEFAULT NULL,
    `deleted_by` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_contract_installment` (`contract_id`, `installment_no`),
    KEY `idx_contract_id` (`contract_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_due_date` (`due_date`),
    KEY `idx_status` (`status`),
    KEY `idx_manual_status` (`manual_status`),
    KEY `idx_deleted_at` (`deleted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_receipts` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` int NOT NULL,
    `contract_id` bigint UNSIGNED NOT NULL,
    `installment_id` bigint UNSIGNED NOT NULL,
    `sales_user_id_snapshot` int DEFAULT NULL,
    `source_type` varchar(30) DEFAULT NULL,
    `source_id` bigint UNSIGNED DEFAULT NULL,
    `received_date` date NOT NULL,
    `amount_received` decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount_overflow` decimal(12,2) NOT NULL DEFAULT 0.00,
    `method` varchar(30) DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    `create_time` int NOT NULL,
    `create_user_id` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_contract_id` (`contract_id`),
    KEY `idx_installment_id` (`installment_id`),
    KEY `idx_received_date` (`received_date`),
    KEY `idx_sales_user_id_snapshot` (`sales_user_id_snapshot`),
    KEY `idx_source_type` (`source_type`),
    KEY `idx_source_id` (`source_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_prepay_ledger` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` int NOT NULL,
    `direction` enum('in','out') NOT NULL,
    `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `source_type` enum('receipt_overflow','apply_to_installment','manual_adjust') NOT NULL,
    `source_id` bigint UNSIGNED DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    `created_at` int NOT NULL,
    `created_by` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_source` (`source_type`, `source_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_receipt_files` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_id` bigint UNSIGNED NOT NULL COMMENT '收款记录ID',
    `file_id` bigint UNSIGNED NOT NULL COMMENT '文件ID（关联customer_files）',
    `created_at` int NOT NULL,
    `created_by` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_receipt_id` (`receipt_id`),
    KEY `idx_file_id` (`file_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_collection_logs` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` int NOT NULL,
    `contract_id` bigint UNSIGNED DEFAULT NULL,
    `installment_id` bigint UNSIGNED NOT NULL,
    `actor_user_id` int NOT NULL,
    `action_time` int NOT NULL,
    `method` varchar(30) DEFAULT NULL,
    `result` varchar(50) DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_installment_id` (`installment_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_action_time` (`action_time`),
    KEY `idx_actor_user_id` (`actor_user_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_status_change_logs` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` enum('contract','installment') NOT NULL,
    `entity_id` bigint UNSIGNED NOT NULL,
    `customer_id` int NOT NULL,
    `contract_id` bigint UNSIGNED DEFAULT NULL,
    `installment_id` bigint UNSIGNED DEFAULT NULL,
    `old_status` varchar(50) DEFAULT NULL,
    `new_status` varchar(50) NOT NULL,
    `reason` varchar(255) NOT NULL,
    `actor_user_id` int NOT NULL,
    `change_time` int NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_entity` (`entity_type`,`entity_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_contract_id` (`contract_id`),
    KEY `idx_installment_id` (`installment_id`),
    KEY `idx_change_time` (`change_time`),
    KEY `idx_actor_user_id` (`actor_user_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_contract_files` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `contract_id` bigint UNSIGNED NOT NULL,
    `customer_id` int NOT NULL,
    `file_id` bigint UNSIGNED NOT NULL,
    `created_by` int NOT NULL,
    `created_at` int NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_contract_file` (`contract_id`,`file_id`),
    KEY `idx_contract_id` (`contract_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_file_id` (`file_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_installment_change_logs` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `installment_id` bigint UNSIGNED NOT NULL,
    `contract_id` bigint UNSIGNED NOT NULL,
    `customer_id` int NOT NULL,
    `actor_user_id` int NOT NULL,
    `change_time` int NOT NULL,
    `old_due_date` date DEFAULT NULL,
    `new_due_date` date DEFAULT NULL,
    `old_amount_due` decimal(12,2) DEFAULT NULL,
    `new_amount_due` decimal(12,2) DEFAULT NULL,
    `note` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_installment_id` (`installment_id`),
    KEY `idx_change_time` (`change_time`),
    KEY `idx_customer_id` (`customer_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `commission_rule_sets` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(80) NOT NULL,
    `rule_type` enum('fixed','tier') NOT NULL,
    `fixed_rate` decimal(10,6) DEFAULT NULL,
    `include_prepay` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否包含预收款计入提成',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` int NOT NULL,
    `created_by` int DEFAULT NULL,
    `updated_at` int NOT NULL,
    `updated_by` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rule_type` (`rule_type`),
    KEY `idx_is_active` (`is_active`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `commission_rule_tiers` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_set_id` bigint UNSIGNED NOT NULL,
    `tier_from` decimal(12,2) NOT NULL DEFAULT 0.00,
    `tier_to` decimal(12,2) DEFAULT NULL,
    `rate` decimal(10,6) NOT NULL DEFAULT 0.00,
    `sort_order` int NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_rule_set_id` (`rule_set_id`),
    KEY `idx_sort_order` (`sort_order`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `commission_settlements` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `month_key` varchar(7) NOT NULL,
    `user_id` int NOT NULL,
    `rule_set_id` bigint UNSIGNED DEFAULT NULL,
    `settlement_type` enum('main','supplement') NOT NULL DEFAULT 'main',
    `parent_settlement_id` bigint UNSIGNED DEFAULT NULL,
    `status` enum('draft','locked','paid') NOT NULL DEFAULT 'draft',
    `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    `notes` varchar(255) DEFAULT NULL,
    `created_at` int NOT NULL,
    `created_by` int DEFAULT NULL,
    `updated_at` int NOT NULL,
    `updated_by` int DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_month_key` (`month_key`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_parent` (`parent_settlement_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `commission_settlement_items` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `settlement_id` bigint UNSIGNED NOT NULL,
    `receipt_id` bigint UNSIGNED NOT NULL,
    `received_date` date NOT NULL,
    `customer_id` int NOT NULL,
    `contract_id` bigint UNSIGNED NOT NULL,
    `installment_id` bigint UNSIGNED NOT NULL,
    `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
    `source_type` varchar(30) DEFAULT NULL,
    `created_at` int NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_settlement_receipt` (`settlement_id`,`receipt_id`),
    KEY `idx_settlement_id` (`settlement_id`),
    KEY `idx_receipt_id` (`receipt_id`),
    KEY `idx_received_date` (`received_date`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE `finance_saved_views` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `page_key` varchar(50) NOT NULL,
    `name` varchar(80) NOT NULL,
    `filters_json` text NOT NULL,
    `sort_json` text DEFAULT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT 0,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `create_time` int NOT NULL,
    `update_time` int NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_page` (`user_id`, `page_key`),
    KEY `idx_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- =============================================
  -- 6. 初始数据
  -- =============================================

  -- 6.1 插入默认部门
  INSERT INTO `departments` (`id`, `name`, `sort`, `status`, `remark`, `create_time`, `update_time`) VALUES
  (1, '销售部', 1, 1, '负责客户开发和销售', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (2, '设计部', 2, 1, '负责效果图制作', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (3, '管理部', 3, 1, '负责公司管理', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (4, '客服部', 3, 1, '负责客户回访与售后', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

  -- 6.2 插入默认管理员（已在前面插入，此处删除重复）
  -- INSERT INTO `users` ... 已在第292行插入

  -- =============================================
  -- 7. 数据分析功能性能优化索引
  -- =============================================

  -- 7.1 客户表索引优化（数据分析查询）
  -- idx_create_time 已在表定义中创建
  -- 注意：如果索引已存在，ALTER TABLE 会报错，但不会影响整体执行
  -- 在生产环境中，建议先检查索引是否存在再添加
  ALTER TABLE `customers` 
  ADD INDEX `idx_update_time` (`update_time`),
  ADD INDEX `idx_create_user_id` (`create_user_id`),
  ADD INDEX `idx_create_user_time` (`create_user_id`, `create_time`),
  ADD INDEX `idx_dept_create_time` (`department_id`, `create_time`);

  -- 7.2 首通表索引优化
  -- idx_customer_id 和 idx_next_follow_time 已在表定义中创建
  ALTER TABLE `first_contact` 
  ADD INDEX `idx_create_time` (`create_time`),
  ADD INDEX `idx_update_time` (`update_time`);

  -- 7.3 异议处理表索引优化
  -- idx_customer_id 已在表定义中创建
  ALTER TABLE `objection` 
  ADD INDEX `idx_create_time` (`create_time`),
  ADD INDEX `idx_update_time` (`update_time`);

  -- 7.4 成交记录表索引优化
  -- idx_customer_id 已在表定义中创建
  ALTER TABLE `deal_record` 
  ADD INDEX `idx_create_time` (`create_time`),
  ADD INDEX `idx_update_time` (`update_time`);

  -- 7.5 沟通自评表索引优化
  -- idx_customer_id 已在表定义中创建
  ALTER TABLE `self_evaluation` 
  ADD INDEX `idx_create_time` (`create_time`),
  ADD INDEX `idx_update_time` (`update_time`);

  -- 7.6 用户表索引优化
  -- 注意：如果索引已存在，ALTER TABLE 会报错，但不会影响整体执行
  ALTER TABLE `users` 
  ADD INDEX `idx_status_role` (`status`, `role`);

  COMMIT;
  SET FOREIGN_KEY_CHECKS = 1;

  -- =============================================
  -- 8. 数据验证和统计
  -- =============================================

  SHOW TABLES;

  SELECT '========================================' AS '';
  SELECT '数据库初始化完成！' AS '';
  SELECT '========================================' AS '';

  -- 8.1 统计菜单数据
  SELECT '菜单统计：' AS '';
  SELECT 
      menu_name AS '菜单名称',
      menu_code AS '菜单代码',
      (SELECT COUNT(*) FROM dimensions WHERE menu_id = menus.id) AS '维度数量',
      CASE WHEN status = 1 THEN '启用' ELSE '禁用' END AS '状态'
  FROM menus
  ORDER BY sort_order;

  -- 8.2 统计维度数据
  SELECT '维度统计：' AS '';
  SELECT 
      d.dimension_name AS '维度名称',
      d.dimension_code AS '维度代码',
      m.menu_name AS '所属菜单',
      (SELECT COUNT(*) FROM fields WHERE dimension_id = d.id) AS '字段数量',
      CASE WHEN d.status = 1 THEN '启用' ELSE '禁用' END AS '状态'
  FROM dimensions d
  LEFT JOIN menus m ON d.menu_id = m.id
  ORDER BY d.sort_order;

  -- 8.3 统计字段数据
  SELECT '字段统计：' AS '';
  SELECT 
      d.dimension_name AS '所属维度',
      COUNT(f.id) AS '字段数量',
      GROUP_CONCAT(DISTINCT f.field_type) AS '字段类型'
  FROM dimensions d
  LEFT JOIN fields f ON d.id = f.dimension_id
  GROUP BY d.id, d.dimension_name
  ORDER BY d.sort_order;

  -- 8.4 总体统计
  SELECT '========================================' AS '';
  SELECT '总体数据统计：' AS '';
  SELECT 
      (SELECT COUNT(*) FROM menus) AS '菜单总数',
      (SELECT COUNT(*) FROM dimensions) AS '维度总数',
      (SELECT COUNT(*) FROM fields) AS '字段总数';

  SELECT '========================================' AS '';
  SELECT '预期数据：' AS '';
  SELECT '- 菜单数：5个（首通、异议处理、敲定成交、客户回访、沟通自评）' AS '';
  SELECT '- 维度数：17个（身份、客户需求、客户关键疑问等）' AS '';
  SELECT '- 字段数：79个（业主、独立设计师、装修公司、施工方、设计师助理等）' AS '';
  SELECT '========================================' AS '';
  SELECT '✓ 数据库初始化完成！' AS '';
  SELECT '✓ 已创建正确的三层结构表（menus, dimensions, fields）' AS '';
  SELECT '✓ 已插入默认数据（5个菜单、17个维度、79个字段）' AS '';
  SELECT '✓ 已添加外键约束和数据分析优化索引' AS '';
  SELECT '✓ 已创建文件管理分享链接表（file_manager_links）' AS '';
  SELECT '✓ 已创建桌面端相关表（desktop_tokens, group_code_sequence, tech_resources, customer_tech_assignments, tech_resource_shares）' AS '';
  SELECT '✓ 已创建权限系统表（permissions, user_roles, data_permissions）' AS '';
  SELECT '========================================' AS '';

  -- =============================================
  -- 9. 桌面端相关表
  -- =============================================

  -- 9.1 桌面端登录 Token 表
  DROP TABLE IF EXISTS `desktop_tokens`;
  CREATE TABLE `desktop_tokens` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL COMMENT '用户ID',
    `token` varchar(64) NOT NULL COMMENT 'Token',
    `expire_at` int(11) NOT NULL COMMENT '过期时间戳',
    `created_at` int(11) NOT NULL COMMENT '创建时间戳',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expire_at` (`expire_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='桌面端登录Token表';

  -- 9.2 群码序列表
  DROP TABLE IF EXISTS `group_code_sequence`;
  CREATE TABLE `group_code_sequence` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `date_key` date NOT NULL COMMENT '日期键(YYYY-MM-DD)',
    `last_seq` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '当日最后使用的序号',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_date_key` (`date_key`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群码序列表(用于生成QYYYYMMDDNN)';

  -- 9.3 技术资源文件元数据表
  DROP TABLE IF EXISTS `tech_resources`;
  CREATE TABLE `tech_resources` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `group_code` varchar(20) NOT NULL COMMENT '群码(关联customers.group_code)',
    `asset_type` enum('works','models','customer') NOT NULL COMMENT '资源类型:works=作品文件,models=模型文件,customer=客户文件',
    `rel_path` varchar(512) NOT NULL COMMENT '相对路径',
    `filename` varchar(255) NOT NULL COMMENT '文件名',
    `storage_disk` varchar(32) NOT NULL DEFAULT 's3' COMMENT '存储驱动:s3/local',
    `storage_key` varchar(768) NOT NULL COMMENT 'MinIO/S3对象键(完整路径)',
    `filesize` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '文件大小(字节)',
    `mime_type` varchar(120) DEFAULT NULL COMMENT 'MIME类型',
    `file_ext` varchar(16) DEFAULT NULL COMMENT '文件扩展名',
    `etag` varchar(64) DEFAULT NULL COMMENT 'S3 ETag(用于校验)',
    `checksum_sha256` char(64) DEFAULT NULL COMMENT 'SHA256校验和',
    `uploaded_by` int(11) NOT NULL COMMENT '上传人ID',
    `uploaded_at` int(11) NOT NULL COMMENT '上传时间戳',
    `updated_at` int(11) DEFAULT NULL COMMENT '更新时间戳',
    `deleted_at` int(11) DEFAULT NULL COMMENT '软删除时间戳',
    `deleted_by` int(11) DEFAULT NULL COMMENT '删除人ID',
    `extra` json DEFAULT NULL COMMENT '扩展字段',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_group_asset_path` (`group_code`,`asset_type`,`rel_path`(255)),
    KEY `idx_group_code` (`group_code`),
    KEY `idx_asset_type` (`asset_type`),
    KEY `idx_uploaded_by` (`uploaded_by`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_storage_key` (`storage_key`(255))
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技术资源文件元数据表';

  -- 9.4 客户-技术分配关系表
  DROP TABLE IF EXISTS `customer_tech_assignments`;
  CREATE TABLE `customer_tech_assignments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `tech_user_id` int(11) NOT NULL COMMENT '技术人员用户ID',
    `assigned_by` int(11) NOT NULL COMMENT '分配人用户ID',
    `assigned_at` int(11) NOT NULL COMMENT '分配时间戳',
    `notes` varchar(255) DEFAULT NULL COMMENT '备注',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customer_tech` (`customer_id`,`tech_user_id`),
    KEY `idx_tech_user` (`tech_user_id`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_assigned_by` (`assigned_by`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户-技术分配关系表';

  -- 9.5 技术资源分享表
  DROP TABLE IF EXISTS `tech_resource_shares`;
  CREATE TABLE `tech_resource_shares` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `group_code` varchar(20) NOT NULL COMMENT '群码',
    `asset_type` enum('works','models') NOT NULL COMMENT '资源类型(仅作品/模型可分享)',
    `share_token` varchar(64) NOT NULL COMMENT '分享令牌',
    `password` varchar(32) DEFAULT NULL COMMENT '访问密码',
    `expires_at` datetime DEFAULT NULL COMMENT '过期时间',
    `max_access_count` int(11) DEFAULT NULL COMMENT '最大访问次数',
    `access_count` int(11) DEFAULT 0 COMMENT '已访问次数',
    `created_by` int(11) NOT NULL COMMENT '创建者ID',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_share_token` (`share_token`),
    KEY `idx_group_asset` (`group_code`,`asset_type`),
    KEY `idx_expires` (`expires_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技术资源分享表';

-- ==================== 项目交付流程系统表 ====================

-- 项目表
CREATE TABLE IF NOT EXISTS `projects` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `project_name` varchar(255) NOT NULL COMMENT '项目名称',
    `project_code` varchar(50) NOT NULL COMMENT '项目编号，如 #PRJ-2024-001',
    `group_code` varchar(50) DEFAULT NULL COMMENT '绑定的群码',
    `current_status` varchar(50) NOT NULL DEFAULT '待沟通' COMMENT '当前状态：待沟通/确认需求/设计中/设计校对/设计完工/设计评价',
    `requirements_locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT '需求是否锁定',
    `requirements_locked_at` int(11) DEFAULT NULL COMMENT '需求锁定时间戳',
    `requirements_locked_by` int(11) DEFAULT NULL COMMENT '需求锁定人用户ID',
    `start_date` date DEFAULT NULL COMMENT '开始日期',
    `deadline` date DEFAULT NULL COMMENT '截止日期',
    `created_by` int(11) NOT NULL COMMENT '创建人用户ID',
    `create_time` int(11) NOT NULL COMMENT '创建时间戳',
    `update_time` int(11) NOT NULL COMMENT '更新时间戳',
    `deleted_at` int(11) DEFAULT NULL COMMENT '软删除时间戳',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_project_code` (`project_code`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_status` (`current_status`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_deleted` (`deleted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目表';

-- 项目-技术分配关系表
CREATE TABLE IF NOT EXISTS `project_tech_assignments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `project_id` int(11) NOT NULL COMMENT '项目ID',
    `tech_user_id` int(11) NOT NULL COMMENT '技术人员用户ID',
    `assigned_by` int(11) NOT NULL COMMENT '分配人用户ID',
    `assigned_at` int(11) NOT NULL COMMENT '分配时间戳',
    `notes` varchar(255) DEFAULT NULL COMMENT '备注',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_project_tech` (`project_id`,`tech_user_id`),
    KEY `idx_tech_user` (`tech_user_id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_assigned_by` (`assigned_by`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目-技术分配关系表';

-- 项目状态变更日志表
CREATE TABLE IF NOT EXISTS `project_status_log` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `project_id` int(11) NOT NULL COMMENT '项目ID',
    `from_status` varchar(50) DEFAULT NULL COMMENT '原状态',
    `to_status` varchar(50) NOT NULL COMMENT '新状态',
    `changed_by` int(11) NOT NULL COMMENT '操作人用户ID',
    `changed_at` int(11) NOT NULL COMMENT '变更时间戳',
    `notes` text DEFAULT NULL COMMENT '备注',
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_changed_at` (`changed_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目状态变更日志表';

-- 时间线事件表（统一审计日志）
CREATE TABLE IF NOT EXISTS `timeline_events` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `entity_type` varchar(50) NOT NULL COMMENT '实体类型：project/deliverable/form_instance',
    `entity_id` int(11) NOT NULL COMMENT '实体ID',
    `event_type` varchar(50) NOT NULL COMMENT '事件类型：状态变更/分配变更/表单提交/审批动作/备注',
    `operator_user_id` int(11) NOT NULL COMMENT '操作人用户ID',
    `event_data_json` text DEFAULT NULL COMMENT '事件详情JSON',
    `create_time` int(11) NOT NULL COMMENT '创建时间戳',
    PRIMARY KEY (`id`),
    KEY `idx_entity` (`entity_type`,`entity_id`),
    KEY `idx_create_time` (`create_time`),
    KEY `idx_operator` (`operator_user_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='时间线事件表（统一审计日志）';

-- 交付物表
CREATE TABLE IF NOT EXISTS `deliverables` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `project_id` int(11) NOT NULL COMMENT '项目ID',
    `file_id` int(11) DEFAULT NULL COMMENT '文件元数据ID',
    `deliverable_name` varchar(255) NOT NULL COMMENT '交付物名称',
    `deliverable_type` varchar(50) DEFAULT NULL COMMENT '类型',
    `file_path` varchar(500) DEFAULT NULL COMMENT '文件路径',
    `file_size` bigint(20) DEFAULT NULL COMMENT '文件大小',
    `visibility_level` varchar(20) NOT NULL DEFAULT 'client' COMMENT '可见级别',
    `approval_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '审批状态',
    `submitted_by` int(11) NOT NULL COMMENT '提交人',
    `submitted_at` int(11) NOT NULL COMMENT '提交时间',
    `approved_by` int(11) DEFAULT NULL COMMENT '审批人',
    `approved_at` int(11) DEFAULT NULL COMMENT '审批时间',
    `reject_reason` text DEFAULT NULL COMMENT '驳回原因',
    `version` varchar(50) DEFAULT NULL COMMENT '版本号',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_approval_status` (`approval_status`),
    KEY `idx_submitted_by` (`submitted_by`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交付物表';

-- 项目资料表
CREATE TABLE IF NOT EXISTS `project_files` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `project_id` int(11) NOT NULL COMMENT '项目ID',
    `file_id` int(11) DEFAULT NULL COMMENT '文件元数据ID',
    `file_name` varchar(255) NOT NULL COMMENT '文件名称',
    `file_path` varchar(500) NOT NULL COMMENT '文件路径',
    `file_size` bigint(20) DEFAULT NULL COMMENT '文件大小',
    `file_category` varchar(50) DEFAULT NULL COMMENT '分类',
    `visibility_level` varchar(20) NOT NULL DEFAULT 'internal' COMMENT '可见级别',
    `uploaded_by` int(11) NOT NULL COMMENT '上传人',
    `upload_time` int(11) NOT NULL COMMENT '上传时间',
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_uploaded_by` (`uploaded_by`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目资料表';

-- 客户级门户链接表
CREATE TABLE IF NOT EXISTS `portal_links` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `token` varchar(100) NOT NULL COMMENT '访问令牌',
    `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
    `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    `expires_at` int(11) DEFAULT NULL COMMENT '过期时间',
    `last_access_at` int(11) DEFAULT NULL COMMENT '最后访问时间',
    `access_count` int(11) NOT NULL DEFAULT 0 COMMENT '访问次数',
    `created_by` int(11) NOT NULL COMMENT '创建人',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_enabled` (`enabled`),
    KEY `idx_expires` (`expires_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户级门户链接表';

-- ==================== 动态表单系统表 ====================

-- 表单模板表
CREATE TABLE IF NOT EXISTS `form_templates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL COMMENT '模板名称',
    `description` text DEFAULT NULL COMMENT '模板描述',
    `form_type` varchar(50) NOT NULL DEFAULT 'custom' COMMENT '表单类型',
    `current_version_id` int(11) DEFAULT NULL COMMENT '当前版本ID',
    `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT '状态: draft/published',
    `created_by` int(11) NOT NULL COMMENT '创建人',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    `deleted_at` int(11) DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_form_type` (`form_type`),
    KEY `idx_status` (`status`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_deleted_at` (`deleted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单模板表';

-- 表单模板版本表
CREATE TABLE IF NOT EXISTS `form_template_versions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `template_id` int(11) NOT NULL COMMENT '模板ID',
    `version_number` int(11) NOT NULL DEFAULT 1 COMMENT '版本号',
    `schema_json` longtext NOT NULL COMMENT '表单结构JSON',
    `published_by` int(11) DEFAULT NULL COMMENT '发布人',
    `published_at` int(11) DEFAULT NULL COMMENT '发布时间',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_template` (`template_id`),
    KEY `idx_version` (`template_id`,`version_number`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单模板版本表';

-- 表单实例表（项目绑定的表单）
CREATE TABLE IF NOT EXISTS `form_instances` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `project_id` int(11) NOT NULL COMMENT '项目ID',
    `template_id` int(11) NOT NULL COMMENT '模板ID',
    `template_version_id` int(11) NOT NULL COMMENT '模板版本ID',
    `instance_name` varchar(255) NOT NULL COMMENT '实例名称',
    `fill_token` varchar(100) DEFAULT NULL COMMENT '填写令牌',
    `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '状态: pending/submitted',
    `requirement_status` varchar(20) DEFAULT 'pending' COMMENT '需求状态: pending/communicating/confirmed/modifying',
    `created_by` int(11) NOT NULL COMMENT '创建人',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fill_token` (`fill_token`),
    KEY `idx_project` (`project_id`),
    KEY `idx_template` (`template_id`),
    KEY `idx_status` (`status`),
    KEY `idx_requirement_status` (`requirement_status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单实例表';

-- 表单提交记录表
CREATE TABLE IF NOT EXISTS `form_submissions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `instance_id` int(11) NOT NULL COMMENT '实例ID',
    `submission_data_json` longtext NOT NULL COMMENT '提交数据JSON',
    `submitted_by_type` varchar(20) NOT NULL DEFAULT 'user' COMMENT '提交人类型: user/guest',
    `submitted_by_id` int(11) DEFAULT NULL COMMENT '提交人ID',
    `submitted_by_name` varchar(100) DEFAULT NULL COMMENT '提交人名称',
    `submitted_at` int(11) NOT NULL COMMENT '提交时间',
    `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP地址',
    `user_agent` text DEFAULT NULL COMMENT '浏览器UA',
    PRIMARY KEY (`id`),
    KEY `idx_instance` (`instance_id`),
    KEY `idx_submitted_at` (`submitted_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单提交记录表';

-- ==================== 客户自定义筛选字段系统 ====================

-- 筛选字段定义表
CREATE TABLE IF NOT EXISTS `customer_filter_fields` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `field_name` varchar(50) NOT NULL COMMENT '字段名（英文标识）',
    `field_label` varchar(100) NOT NULL COMMENT '字段标签（显示名称）',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序顺序',
    `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_field_name` (`field_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户自定义筛选字段定义';

-- 筛选字段选项表
CREATE TABLE IF NOT EXISTS `customer_filter_options` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `field_id` int(11) NOT NULL COMMENT '所属字段ID',
    `option_value` varchar(50) NOT NULL COMMENT '选项值（存储值）',
    `option_label` varchar(100) NOT NULL COMMENT '选项标签（显示名称）',
    `color` varchar(20) DEFAULT '#6366f1' COMMENT '选项颜色',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序顺序',
    `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_field_id` (`field_id`),
    UNIQUE KEY `uk_field_option` (`field_id`, `option_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户筛选字段选项';

-- 客户筛选字段值表
CREATE TABLE IF NOT EXISTS `customer_filter_values` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `field_id` int(11) NOT NULL COMMENT '字段ID',
    `option_id` int(11) NOT NULL COMMENT '选项ID',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_field_id` (`field_id`),
    KEY `idx_option_id` (`option_id`),
    UNIQUE KEY `uk_customer_field` (`customer_id`, `field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户筛选字段值';

-- ==================== 通知系统表 ====================

-- 通知表
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL COMMENT '接收人ID',
    `type` varchar(50) NOT NULL COMMENT '通知类型',
    `title` varchar(255) NOT NULL COMMENT '通知标题',
    `content` text DEFAULT NULL COMMENT '通知内容',
    `related_type` varchar(50) DEFAULT NULL COMMENT '关联类型: form_instance/project',
    `related_id` int(11) DEFAULT NULL COMMENT '关联ID',
    `is_read` tinyint(1) DEFAULT 0 COMMENT '是否已读',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_type` (`type`),
    KEY `idx_read` (`is_read`),
    KEY `idx_create_time` (`create_time`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知表';

END$$

DELIMITER ;
CALL bootstrap_full_schema();
DROP PROCEDURE IF EXISTS bootstrap_full_schema;
