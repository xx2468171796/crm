-- 悬浮窗 V2 - 任务管理扩展
-- 执行时间: 2026-01-04

-- 1. 扩展 daily_tasks 表
ALTER TABLE daily_tasks ADD COLUMN assigned_by INT NULL COMMENT '上级分配人ID';
ALTER TABLE daily_tasks ADD COLUMN need_help TINYINT(1) DEFAULT 0 COMMENT '是否需要协助';
ALTER TABLE daily_tasks ADD INDEX idx_assigned_by (assigned_by);
ALTER TABLE daily_tasks ADD INDEX idx_need_help (need_help);

-- 2. 确保 project_id 字段可为空（默认关联项目但可选）
-- ALTER TABLE daily_tasks MODIFY COLUMN project_id INT NULL;
