-- 添加文件哈希字段用于去重
ALTER TABLE deliverables ADD COLUMN file_hash VARCHAR(64) NULL COMMENT 'SHA256 文件哈希，用于去重';

-- 创建索引加速哈希查询
CREATE INDEX idx_deliverables_file_hash ON deliverables(file_hash);
