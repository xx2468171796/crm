<?php
/**
 * 悬浮窗功能数据库迁移
 * 创建 daily_tasks, task_comments, work_approvals 表
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo = Db::pdo();
    
    // 1. 创建 daily_tasks 表 - 每日任务
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT '用户ID',
            title VARCHAR(255) NOT NULL COMMENT '任务标题',
            description TEXT COMMENT '任务描述',
            project_id INT DEFAULT NULL COMMENT '关联项目ID',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' COMMENT '优先级',
            status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending' COMMENT '状态',
            due_date DATE DEFAULT NULL COMMENT '截止日期',
            estimated_hours DECIMAL(4,1) DEFAULT NULL COMMENT '预计工时',
            actual_hours DECIMAL(4,1) DEFAULT NULL COMMENT '实际工时',
            completed_at INT DEFAULT NULL COMMENT '完成时间',
            create_time INT NOT NULL COMMENT '创建时间',
            update_time INT DEFAULT NULL COMMENT '更新时间',
            INDEX idx_user_date (user_id, due_date),
            INDEX idx_status (status),
            INDEX idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='每日任务表'
    ");
    echo "✅ daily_tasks 表创建成功\n";
    
    // 2. 创建 task_comments 表 - 任务评论
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL COMMENT '任务ID',
            user_id INT NOT NULL COMMENT '用户ID',
            content TEXT NOT NULL COMMENT '评论内容',
            create_time INT NOT NULL COMMENT '创建时间',
            INDEX idx_task (task_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务评论表'
    ");
    echo "✅ task_comments 表创建成功\n";
    
    // 3. 创建 work_approvals 表 - 作品审批
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL COMMENT '项目ID',
            submitter_id INT NOT NULL COMMENT '提交者ID',
            approver_id INT DEFAULT NULL COMMENT '审批者ID',
            title VARCHAR(255) NOT NULL COMMENT '作品标题',
            description TEXT COMMENT '作品描述',
            file_path VARCHAR(500) NOT NULL COMMENT '文件路径',
            file_type VARCHAR(50) DEFAULT NULL COMMENT '文件类型',
            status ENUM('pending', 'approved', 'rejected', 'revision') DEFAULT 'pending' COMMENT '审批状态',
            feedback TEXT COMMENT '审批反馈',
            version INT DEFAULT 1 COMMENT '版本号',
            parent_id INT DEFAULT NULL COMMENT '父版本ID（修改重提）',
            submit_time INT NOT NULL COMMENT '提交时间',
            approve_time INT DEFAULT NULL COMMENT '审批时间',
            INDEX idx_project (project_id),
            INDEX idx_submitter (submitter_id),
            INDEX idx_approver (approver_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品审批表'
    ");
    echo "✅ work_approvals 表创建成功\n";
    
    echo "\n🎉 悬浮窗数据库迁移完成！\n";
    
} catch (Exception $e) {
    echo "❌ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
