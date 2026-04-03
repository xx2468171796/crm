-- 客户需求文档表
CREATE TABLE IF NOT EXISTS `customer_requirements` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `customer_id` int(11) NOT NULL COMMENT '客户ID',
  `content` longtext COMMENT 'Markdown格式的需求内容',
  `html_content` longtext COMMENT '渲染后的HTML内容（可选，用于快速显示）',
  `version` int(11) NOT NULL DEFAULT 1 COMMENT '版本号',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
  `update_user_id` int(11) DEFAULT NULL COMMENT '最后更新人ID',
  `last_sync_time` int(11) DEFAULT NULL COMMENT '最后同步时间（用于桌面端同步）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_id` (`customer_id`),
  KEY `idx_update_time` (`update_time`),
  KEY `idx_last_sync_time` (`last_sync_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户需求文档表';

-- 需求文档历史版本表（可选，用于版本控制）
CREATE TABLE IF NOT EXISTS `customer_requirements_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `requirement_id` int(11) NOT NULL COMMENT '需求文档ID',
  `customer_id` int(11) NOT NULL COMMENT '客户ID',
  `content` longtext COMMENT 'Markdown格式的需求内容',
  `version` int(11) NOT NULL COMMENT '版本号',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
  `change_note` varchar(500) DEFAULT NULL COMMENT '变更说明',
  PRIMARY KEY (`id`),
  KEY `idx_requirement_id` (`requirement_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户需求文档历史版本表';
