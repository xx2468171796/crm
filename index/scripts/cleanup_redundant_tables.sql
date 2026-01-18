-- 数据库清理脚本
-- 删除冗余的空表和不再使用的表

-- 1. 检查 project_deliverables 表是否为空
SELECT COUNT(*) as record_count FROM project_deliverables;

-- 2. 如果为空，可以安全删除（已确认为0条记录）
-- DROP TABLE IF EXISTS project_deliverables;

-- 注意：执行前请确认：
-- 1. 已备份数据库
-- 2. 确认没有API在使用 project_deliverables 表
-- 3. 确认 desktop_approval.php 已修改为使用 deliverables 表

-- 3. 检查其他可能冗余的表
SELECT 'file_approvals' as table_name, COUNT(*) as record_count FROM file_approvals
UNION ALL
SELECT 'work_approvals', COUNT(*) FROM work_approvals
UNION ALL
SELECT 'work_approval_versions', COUNT(*) FROM work_approval_versions;

-- 说明：
-- - deliverables 表是主表，包含所有交付物数据（33条记录）
-- - project_deliverables 表是空表（0条记录），可以删除
-- - file_approvals, work_approvals 需要检查是否还在使用
