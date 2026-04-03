<?php
/**
 * 创建缺失的数据库表
 */

require_once __DIR__ . '/../core/db.php';

echo "开始创建缺失的表...\n";

// 创建 tasks 表
$tasksSql = "
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务表'
";

try {
    Db::execute($tasksSql);
    echo "✓ tasks 表创建成功\n";
} catch (Exception $e) {
    echo "✗ tasks 表创建失败: " . $e->getMessage() . "\n";
}

// 创建 project_deliverables 表（如果不存在）
$deliverablesSql = "
CREATE TABLE IF NOT EXISTS `project_deliverables` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT NOT NULL COMMENT '项目ID',
    `filename` VARCHAR(255) NOT NULL COMMENT '文件名',
    `file_path` VARCHAR(500) NOT NULL COMMENT '文件路径',
    `file_size` BIGINT DEFAULT 0 COMMENT '文件大小',
    `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME类型',
    `is_folder` TINYINT(1) DEFAULT 0 COMMENT '是否文件夹',
    `parent_id` INT DEFAULT NULL COMMENT '父文件夹ID',
    `approval_status` TINYINT DEFAULT 0 COMMENT '审批状态: 0=待审批, 1=已通过, 2=已驳回',
    `approved_by` INT DEFAULT NULL COMMENT '审批人ID',
    `approved_at` DATETIME DEFAULT NULL COMMENT '审批时间',
    `rejection_reason` VARCHAR(500) DEFAULT NULL COMMENT '驳回原因',
    `created_by` INT NOT NULL COMMENT '上传人ID',
    `create_time` INT UNSIGNED DEFAULT NULL COMMENT '创建时间戳',
    `update_time` INT UNSIGNED DEFAULT NULL COMMENT '更新时间戳',
    `deleted_at` DATETIME DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_project` (`project_id`),
    KEY `idx_parent` (`parent_id`),
    KEY `idx_approval` (`approval_status`),
    KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目交付物表'
";

try {
    Db::execute($deliverablesSql);
    echo "✓ project_deliverables 表创建成功\n";
} catch (Exception $e) {
    echo "✗ project_deliverables 表创建失败: " . $e->getMessage() . "\n";
}

echo "\n完成！\n";
