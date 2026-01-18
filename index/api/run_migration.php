<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json');

try {
    $pdo = Db::pdo();
    
    // 创建 file_approvals 表
    $sql = "CREATE TABLE IF NOT EXISTS `file_approvals` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `file_id` INT UNSIGNED NOT NULL COMMENT '关联的文件ID',
        `submitter_id` INT UNSIGNED NOT NULL COMMENT '提交人ID',
        `reviewer_id` INT UNSIGNED NULL COMMENT '审批人ID',
        `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '审批状态',
        `submit_time` DATETIME NOT NULL COMMENT '提交时间',
        `review_time` DATETIME NULL COMMENT '审批时间',
        `review_note` TEXT NULL COMMENT '审批备注',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX `idx_file_id` (`file_id`),
        INDEX `idx_submitter_id` (`submitter_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_submit_time` (`submit_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件审批记录表'";
    
    $pdo->exec($sql);
    
    echo json_encode(['success' => true, 'message' => 'file_approvals 表创建成功']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
