-- 任务表（用于桌面端任务管理）
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务ID',
    `title` VARCHAR(200) NOT NULL COMMENT '任务标题',
    `description` TEXT COMMENT '任务描述',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending' COMMENT '状态',
    `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium' COMMENT '优先级',
    `deadline` DATE DEFAULT NULL COMMENT '截止日期',
    `project_id` INT DEFAULT NULL COMMENT '关联项目ID',
    `assignee_id` INT DEFAULT NULL COMMENT '负责人ID',
    `created_by` INT NOT NULL COMMENT '创建人ID',
    `create_time` INT UNSIGNED DEFAULT NULL COMMENT '创建时间戳',
    `update_time` INT UNSIGNED DEFAULT NULL COMMENT '更新时间戳',
    PRIMARY KEY (`id`),
    KEY `idx_assignee` (`assignee_id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_status` (`status`),
    KEY `idx_deadline` (`deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务表';
