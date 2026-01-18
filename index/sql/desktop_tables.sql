-- ============================================================
-- 桌面端功能数据表
-- 提案: desktop-floating-main-window
-- 创建时间: 2026-01-02
-- ============================================================

-- 每日任务表
CREATE TABLE IF NOT EXISTS `daily_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务ID',
    `user_id` INT NOT NULL COMMENT '用户ID',
    `project_id` INT DEFAULT NULL COMMENT '关联项目ID',
    `customer_id` INT DEFAULT NULL COMMENT '关联客户ID',
    `title` VARCHAR(200) NOT NULL COMMENT '任务标题',
    `description` TEXT COMMENT '任务描述',
    `task_date` DATE NOT NULL COMMENT '任务日期',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending' COMMENT '状态',
    `progress` TINYINT UNSIGNED DEFAULT 0 COMMENT '进度百分比(0-100)',
    `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' COMMENT '优先级',
    `estimated_hours` DECIMAL(4,1) DEFAULT NULL COMMENT '预估工时',
    `actual_hours` DECIMAL(4,1) DEFAULT NULL COMMENT '实际工时',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_date` (`user_id`, `task_date`),
    KEY `idx_project` (`project_id`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日任务';

-- 任务评论表
CREATE TABLE IF NOT EXISTS `task_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '评论ID',
    `task_id` INT UNSIGNED NOT NULL COMMENT '任务ID',
    `user_id` INT NOT NULL COMMENT '评论用户ID',
    `content` TEXT NOT NULL COMMENT '评论内容',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_task` (`task_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务评论';

-- 作品审批表
CREATE TABLE IF NOT EXISTS `work_approvals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '审批ID',
    `project_id` INT NOT NULL COMMENT '项目ID',
    `customer_id` INT NOT NULL COMMENT '客户ID',
    `submitter_id` INT NOT NULL COMMENT '提交人ID',
    `approver_id` INT DEFAULT NULL COMMENT '审批人ID',
    `file_type` ENUM('design', 'model', 'render', 'other') NOT NULL COMMENT '文件类型',
    `file_path` VARCHAR(500) NOT NULL COMMENT '文件路径',
    `file_name` VARCHAR(200) NOT NULL COMMENT '文件名',
    `version` INT UNSIGNED DEFAULT 1 COMMENT '版本号',
    `status` ENUM('pending', 'approved', 'rejected', 'revision') DEFAULT 'pending' COMMENT '审批状态',
    `submit_note` TEXT COMMENT '提交说明',
    `approval_note` TEXT COMMENT '审批意见',
    `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间',
    `approved_at` TIMESTAMP NULL COMMENT '审批时间',
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_submitter` (`submitter_id`),
    KEY `idx_approver` (`approver_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品审批';

-- 审批版本历史表
CREATE TABLE IF NOT EXISTS `work_approval_versions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '版本ID',
    `approval_id` INT UNSIGNED NOT NULL COMMENT '审批ID',
    `version` INT UNSIGNED NOT NULL COMMENT '版本号',
    `file_path` VARCHAR(500) NOT NULL COMMENT '文件路径',
    `file_name` VARCHAR(200) NOT NULL COMMENT '文件名',
    `submit_note` TEXT COMMENT '提交说明',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_approval` (`approval_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审批版本历史';
