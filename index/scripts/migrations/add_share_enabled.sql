-- 添加分享开关字段到deliverables表
ALTER TABLE deliverables ADD COLUMN share_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用分享 1=开启 0=关闭';

-- 添加索引
ALTER TABLE deliverables ADD INDEX idx_share_enabled (share_enabled);
