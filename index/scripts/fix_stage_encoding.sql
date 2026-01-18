-- 修复 project_stage_templates 表中的乱码数据
-- 执行方式: mysql -h 192.168.110.246 -u file1217 -p file1217 --default-character-set=utf8mb4 < fix_stage_encoding.sql

-- 修复模板表（包括 description 字段）
UPDATE project_stage_templates SET stage_from = '待沟通', stage_to = '需求确认', description = '待沟通 → 需求确认' WHERE id = 1;
UPDATE project_stage_templates SET stage_from = '需求确认', stage_to = '设计中', description = '需求确认 → 设计中' WHERE id = 2;
UPDATE project_stage_templates SET stage_from = '设计中', stage_to = '设计核对', description = '设计中 → 设计核对' WHERE id = 3;
UPDATE project_stage_templates SET stage_from = '设计核对', stage_to = '设计完工', description = '设计核对 → 设计完工' WHERE id = 4;
UPDATE project_stage_templates SET stage_from = '设计完工', stage_to = '设计评价', description = '设计完工 → 设计评价' WHERE id = 5;

-- 修复现有项目的阶段时间数据 (project_id = 257 为例)
UPDATE project_stage_times SET stage_from = '待沟通', stage_to = '需求确认' WHERE stage_order = 1;
UPDATE project_stage_times SET stage_from = '需求确认', stage_to = '设计中' WHERE stage_order = 2;
UPDATE project_stage_times SET stage_from = '设计中', stage_to = '设计核对' WHERE stage_order = 3;
UPDATE project_stage_times SET stage_from = '设计核对', stage_to = '设计完工' WHERE stage_order = 4;
UPDATE project_stage_times SET stage_from = '设计完工', stage_to = '设计评价' WHERE stage_order = 5;

-- 验证修复结果
SELECT 'project_stage_templates:' as info;
SELECT id, stage_from, stage_to FROM project_stage_templates ORDER BY id;

SELECT 'project_stage_times (sample):' as info;
SELECT id, project_id, stage_from, stage_to FROM project_stage_times WHERE project_id = 257 ORDER BY stage_order;
