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
    
    // 添加 file_hash 字段到 deliverables 表
    try {
        $pdo->exec("ALTER TABLE deliverables ADD COLUMN file_hash VARCHAR(64) NULL COMMENT 'SHA256 文件哈希，用于去重'");
    } catch (Exception $e) {
        // 字段可能已存在，忽略错误
    }
    
    // 创建 file_hash 索引
    try {
        $pdo->exec("CREATE INDEX idx_deliverables_file_hash ON deliverables(file_hash)");
    } catch (Exception $e) {
        // 索引可能已存在，忽略错误
    }
    
    echo json_encode(['success' => true, 'message' => '迁移完成']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
