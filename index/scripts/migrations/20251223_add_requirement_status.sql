-- 需求表单工作流增强 - 数据库迁移脚本
-- 1. form_instances 添加 requirement_status 字段
-- 2. 创建 notifications 表

-- 为 form_instances 添加需求状态字段（先检查是否存在）
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = DATABASE() 
               AND table_name = 'form_instances' 
               AND column_name = 'requirement_status');

SET @sql := IF(@exist = 0, 
    'ALTER TABLE form_instances ADD COLUMN requirement_status VARCHAR(20) DEFAULT ''pending'' COMMENT ''需求状态: pending/communicating/confirmed/modifying''',
    'SELECT ''Column already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 创建通知表
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT '接收人ID',
    type VARCHAR(50) NOT NULL COMMENT '通知类型',
    title VARCHAR(255) NOT NULL,
    content TEXT,
    related_type VARCHAR(50) COMMENT '关联类型: form_instance/project',
    related_id INT COMMENT '关联ID',
    is_read TINYINT(1) DEFAULT 0,
    create_time INT NOT NULL,
    KEY idx_user (user_id),
    KEY idx_type (type),
    KEY idx_read (is_read),
    KEY idx_create_time (create_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 更新现有已提交表单的需求状态
UPDATE form_instances 
SET requirement_status = 'communicating' 
WHERE status = 'submitted' AND requirement_status = 'pending';
