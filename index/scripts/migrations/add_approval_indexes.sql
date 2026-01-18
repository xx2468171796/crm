-- 为审批查询优化添加索引
-- 执行时间：2026-01-10

-- deliverables 表索引（如果索引已存在会报错，可忽略）
ALTER TABLE deliverables ADD INDEX idx_approval_status (approval_status);
ALTER TABLE deliverables ADD INDEX idx_submitted_by (submitted_by);
ALTER TABLE deliverables ADD INDEX idx_submitted_at (submitted_at);
ALTER TABLE deliverables ADD INDEX idx_file_category (file_category);

-- 复合索引（用于常见查询）
ALTER TABLE deliverables ADD INDEX idx_status_time (approval_status, submitted_at);
ALTER TABLE deliverables ADD INDEX idx_project_status (project_id, approval_status);
ALTER TABLE deliverables ADD INDEX idx_category_status (file_category, approval_status);

-- project_tech_assignments 表索引
ALTER TABLE project_tech_assignments ADD INDEX idx_project_tech (project_id, tech_user_id);
ALTER TABLE project_tech_assignments ADD INDEX idx_tech_user (tech_user_id);

-- projects 表索引
ALTER TABLE projects ADD INDEX idx_current_status (current_status);

-- 检查索引是否创建成功
SHOW INDEX FROM deliverables WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM project_tech_assignments WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM projects WHERE Key_name LIKE 'idx_%';
