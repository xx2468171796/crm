-- 每日任务表
CREATE TABLE IF NOT EXISTS `daily_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '任务所属用户',
  `title` varchar(255) NOT NULL COMMENT '任务标题',
  `description` text COMMENT '任务描述',
  `project_id` int(11) DEFAULT NULL COMMENT '关联项目',
  `customer_id` int(11) DEFAULT NULL COMMENT '关联客户',
  `task_date` date NOT NULL COMMENT '任务日期',
  `priority` enum('high','medium','low') DEFAULT 'medium' COMMENT '优先级',
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending' COMMENT '状态',
  `need_help` tinyint(1) DEFAULT 0 COMMENT '是否需要协助',
  `assigned_by` int(11) DEFAULT NULL COMMENT '分配人（主管分配时）',
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`, `task_date`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日任务表';

-- 任务评论表
CREATE TABLE IF NOT EXISTS `task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL COMMENT '任务ID',
  `user_id` int(11) NOT NULL COMMENT '评论用户',
  `content` text NOT NULL COMMENT '评论内容',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务评论表';
