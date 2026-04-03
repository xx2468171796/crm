-- =============================================
-- 增量脚本：OKR 模块表结构
-- 说明：仅创建 OKR 相关 9 张表，适用于在已有 v3 schema 基础上加装 OKR 模块
-- 执行顺序：
--   1. mysql -u... -p... t2 < 20250101_okr_module_incremental.sql
--   2. 如需回滚，手动删除各 okr_* 表即可
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- 1. okr_cycles (周期表)
CREATE TABLE IF NOT EXISTS `okr_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '周期名称，如"2025年12月"',
  `type` varchar(20) NOT NULL DEFAULT 'month' COMMENT '类型：week/2week/month/quarter/4month/half_year/year/custom',
  `start_date` date NOT NULL COMMENT '开始日期',
  `end_date` date NOT NULL COMMENT '结束日期',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1=启用 0=归档',
  `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_date_range` (`start_date`, `end_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR周期表';

-- 2. okr_containers (OKR容器表)
CREATE TABLE IF NOT EXISTS `okr_containers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL COMMENT '周期ID',
  `user_id` int(11) NOT NULL COMMENT '负责人ID',
  `level` varchar(20) NOT NULL DEFAULT 'personal' COMMENT '层级：company/department/personal',
  `department_id` int(11) DEFAULT NULL COMMENT '部门ID（部门级OKR时使用）',
  `progress` decimal(5,2) DEFAULT 0.00 COMMENT '总进度（0-100）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1=进行中 0=已归档',
  `create_user_id` int(11) DEFAULT NULL,
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cycle_user` (`cycle_id`, `user_id`),
  KEY `idx_level` (`level`),
  KEY `idx_department` (`department_id`),
  CONSTRAINT `fk_okr_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `okr_cycles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR容器表';

-- 3. okr_objectives (目标表)
CREATE TABLE IF NOT EXISTS `okr_objectives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `container_id` int(11) NOT NULL COMMENT 'OKR容器ID',
  `title` varchar(255) NOT NULL COMMENT '目标标题',
  `sort_order` int(11) DEFAULT 0 COMMENT '排序（用于编号O1,O2...）',
  `parent_id` int(11) DEFAULT NULL COMMENT '对齐的上级目标ID',
  `progress` decimal(5,2) DEFAULT 0.00 COMMENT '进度（0-100）',
  `status` varchar(20) DEFAULT 'normal' COMMENT '状态：normal/at_risk/delayed',
  `create_user_id` int(11) DEFAULT NULL,
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_container` (`container_id`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_obj_container` FOREIGN KEY (`container_id`) REFERENCES `okr_containers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR目标表';

-- 4. okr_key_results (关键结果表)
CREATE TABLE IF NOT EXISTS `okr_key_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `objective_id` int(11) NOT NULL COMMENT '目标ID',
  `title` varchar(255) NOT NULL COMMENT 'KR标题',
  `target_value` decimal(15,2) NOT NULL DEFAULT 100 COMMENT '目标值',
  `current_value` decimal(15,2) DEFAULT 0 COMMENT '当前值',
  `start_value` decimal(15,2) DEFAULT 0 COMMENT '起始值',
  `unit` varchar(50) DEFAULT '%' COMMENT '单位',
  `weight` decimal(5,2) DEFAULT 0 COMMENT '权重（0-100%，0表示平均分配）',
  `confidence` int(11) DEFAULT 5 COMMENT '信心指数（1-10）',
  `progress_mode` varchar(20) DEFAULT 'value' COMMENT '进度模式：value=数值模式, task=任务模式',
  `progress` decimal(5,2) DEFAULT 0.00 COMMENT '进度（0-100）',
  `status` varchar(20) DEFAULT 'normal' COMMENT '状态：normal/at_risk/delayed',
  `owner_user_ids` text DEFAULT NULL COMMENT '负责人IDs（JSON数组）',
  `sort_order` int(11) DEFAULT 0 COMMENT '排序',
  `create_user_id` int(11) DEFAULT NULL,
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_objective` (`objective_id`),
  CONSTRAINT `fk_kr_objective` FOREIGN KEY (`objective_id`) REFERENCES `okr_objectives`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR关键结果表';

-- 5. okr_tasks (任务表)
CREATE TABLE IF NOT EXISTS `okr_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(300) NOT NULL COMMENT '任务标题',
  `description` text DEFAULT NULL COMMENT '任务备注/描述',
  `level` varchar(20) NOT NULL DEFAULT 'personal' COMMENT '层级：company/department/employee/personal',
  `priority` varchar(20) DEFAULT 'medium' COMMENT '优先级：low/medium/high',
  `status` varchar(20) DEFAULT 'pending' COMMENT '状态：pending/in_progress/completed/failed',
  `start_date` date DEFAULT NULL COMMENT '开始日期',
  `due_date` date DEFAULT NULL COMMENT '截止日期',
  `executor_id` int(11) NOT NULL COMMENT '执行人/负责人ID',
  `assigner_id` int(11) DEFAULT NULL COMMENT '指派人ID',
  `source_type` varchar(20) DEFAULT 'self' COMMENT '来源类型：self=自己创建, assigned=被指派',
  `department_id` int(11) DEFAULT NULL COMMENT '所属部门ID',
  `completed_at` int(11) DEFAULT NULL COMMENT '完成时间',
  `create_user_id` int(11) DEFAULT NULL,
  `create_time` int(11) DEFAULT NULL,
  `update_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_executor` (`executor_id`),
  KEY `idx_assigner` (`assigner_id`),
  KEY `idx_status` (`status`),
  KEY `idx_level` (`level`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_source_type` (`source_type`),
  KEY `idx_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR任务表';

-- 6. okr_task_assistants (任务协助人表)
CREATE TABLE IF NOT EXISTS `okr_task_assistants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL COMMENT '任务ID',
  `user_id` int(11) NOT NULL COMMENT '协助人ID',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_user` (`task_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_assistant_task` FOREIGN KEY (`task_id`) REFERENCES `okr_tasks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务协助人关联表';

-- 7. okr_task_relations (任务关联表)
CREATE TABLE IF NOT EXISTS `okr_task_relations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL COMMENT '任务ID',
  `relation_type` varchar(20) NOT NULL COMMENT '关联类型：okr/objective/kr/kpi/customer',
  `relation_id` int(11) NOT NULL COMMENT '关联对象ID',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_relation` (`task_id`, `relation_type`, `relation_id`),
  KEY `idx_relation` (`relation_type`, `relation_id`),
  CONSTRAINT `fk_relation_task` FOREIGN KEY (`task_id`) REFERENCES `okr_tasks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务关联表（多态关联）';

-- 8. okr_comments (评论表)
CREATE TABLE IF NOT EXISTS `okr_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_type` varchar(20) NOT NULL COMMENT 'okr/objective/kr/task',
  `target_id` int(11) NOT NULL COMMENT '目标对象ID',
  `content` text NOT NULL COMMENT '评论内容',
  `mention_user_ids` text DEFAULT NULL COMMENT '@提醒的用户ID（JSON）',
  `created_by` int(11) NOT NULL COMMENT '评论人ID',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR评论表';

-- 9. okr_logs (操作日志表)
CREATE TABLE IF NOT EXISTS `okr_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_type` varchar(20) NOT NULL COMMENT 'okr/objective/kr/task',
  `target_id` int(11) NOT NULL COMMENT '目标对象ID',
  `action` varchar(50) NOT NULL COMMENT '动作：create/update/delete/progress/comment等',
  `before_snapshot` json DEFAULT NULL COMMENT '变更前快照',
  `after_snapshot` json DEFAULT NULL COMMENT '变更后快照',
  `operator_id` int(11) NOT NULL COMMENT '操作人ID',
  `extra` json DEFAULT NULL COMMENT '额外信息',
  `create_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_operator` (`operator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OKR操作日志表';

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

