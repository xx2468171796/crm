-- 修复阶段状态

-- 需求确认
UPDATE project_stage_times pst
JOIN projects p ON pst.project_id = p.id
SET pst.status = CASE
    WHEN pst.stage_order = 1 THEN 'completed'
    WHEN pst.stage_order = 2 THEN 'in_progress'
    ELSE 'pending'
END
WHERE p.deleted_at IS NULL AND p.current_status = '需求确认';

-- 设计中
UPDATE project_stage_times pst
JOIN projects p ON pst.project_id = p.id
SET pst.status = CASE
    WHEN pst.stage_order <= 2 THEN 'completed'
    WHEN pst.stage_order = 3 THEN 'in_progress'
    ELSE 'pending'
END
WHERE p.deleted_at IS NULL AND p.current_status = '设计中';

-- 设计核对
UPDATE project_stage_times pst
JOIN projects p ON pst.project_id = p.id
SET pst.status = CASE
    WHEN pst.stage_order <= 3 THEN 'completed'
    WHEN pst.stage_order = 4 THEN 'in_progress'
    ELSE 'pending'
END
WHERE p.deleted_at IS NULL AND p.current_status = '设计核对';

-- 设计完工
UPDATE project_stage_times pst
JOIN projects p ON pst.project_id = p.id
SET pst.status = CASE
    WHEN pst.stage_order <= 4 THEN 'completed'
    WHEN pst.stage_order = 5 THEN 'in_progress'
    ELSE 'pending'
END
WHERE p.deleted_at IS NULL AND p.current_status = '设计完工';

-- 设计评价
UPDATE project_stage_times pst
JOIN projects p ON pst.project_id = p.id
SET pst.status = 'completed'
WHERE p.deleted_at IS NULL AND p.current_status = '设计评价';
