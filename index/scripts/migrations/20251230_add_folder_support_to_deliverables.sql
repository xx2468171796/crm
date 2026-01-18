-- 为deliverables表添加文件夹支持
-- 新增parent_folder_id字段支持文件夹嵌套（如果不存在）
ALTER TABLE deliverables ADD COLUMN IF NOT EXISTS parent_folder_id INT DEFAULT NULL AFTER project_id;

-- 新增is_folder字段区分文件和文件夹（如果不存在）
ALTER TABLE deliverables ADD COLUMN IF NOT EXISTS is_folder TINYINT(1) DEFAULT 0 AFTER parent_folder_id;

-- 添加索引优化查询（忽略已存在的索引错误）
-- ALTER TABLE deliverables ADD INDEX idx_parent_folder (parent_folder_id);

-- 添加外键约束（自引用，文件夹嵌套）- 如果不存在
-- ALTER TABLE deliverables ADD CONSTRAINT fk_parent_folder 
--     FOREIGN KEY (parent_folder_id) REFERENCES deliverables(id) ON DELETE CASCADE;
