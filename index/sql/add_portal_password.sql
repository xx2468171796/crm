-- 添加门户密码字段到 portal_links 表
ALTER TABLE portal_links ADD COLUMN password_plain VARCHAR(100) NULL COMMENT '明文密码（便于查看）' AFTER token;
