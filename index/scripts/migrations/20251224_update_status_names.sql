-- 更新项目状态名称
-- 确认需求 → 需求确认
-- 设计校对 → 设计核对

-- 更新 projects 表
UPDATE projects SET current_status = '需求确认' WHERE current_status = '确认需求';
UPDATE projects SET current_status = '设计核对' WHERE current_status = '设计校对';

-- 更新 project_status_logs 表（如果存在）
UPDATE project_status_logs SET old_status = '需求确认' WHERE old_status = '确认需求';
UPDATE project_status_logs SET new_status = '需求确认' WHERE new_status = '确认需求';
UPDATE project_status_logs SET old_status = '设计核对' WHERE old_status = '设计校对';
UPDATE project_status_logs SET new_status = '设计核对' WHERE new_status = '设计校对';
