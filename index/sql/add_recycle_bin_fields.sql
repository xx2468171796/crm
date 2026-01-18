-- 文件回收站功能 - 数据库修改
-- 执行方式: mysql -h 192.168.110.246 -u file1217 -p file1217 < add_recycle_bin_fields.sql

-- T1: 添加 deleted_by 字段（记录删除人）
ALTER TABLE deliverables ADD COLUMN deleted_by INT DEFAULT NULL COMMENT '删除人ID';

-- T2: 添加索引优化回收站查询
CREATE INDEX idx_deliverables_deleted ON deliverables (deleted_at, project_id);
CREATE INDEX idx_deliverables_deleted_by ON deliverables (deleted_by);
