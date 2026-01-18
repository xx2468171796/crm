-- ============================================
-- 桌面端所需数据库表
-- 执行时间：2025-12-20
-- ============================================

-- 0. 文件同步日志表
CREATE TABLE IF NOT EXISTS `file_sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `project_id` int(11) DEFAULT NULL COMMENT '项目ID',
  `filename` varchar(255) NOT NULL COMMENT '文件名',
  `operation` enum('upload','download') NOT NULL COMMENT '操作类型',
  `status` enum('success','failed') NOT NULL COMMENT '状态',
  `size` bigint(20) DEFAULT 0 COMMENT '文件大小(字节)',
  `folder_type` varchar(50) DEFAULT NULL COMMENT '文件夹类型(客户文件/作品文件/模型文件)',
  `error_message` text COMMENT '错误信息',
  `create_time` int(11) NOT NULL COMMENT '创建时间(时间戳)',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_operation` (`operation`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件同步日志表';

-- 1. 桌面端登录 Token 表
CREATE TABLE IF NOT EXISTS desktop_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expire_at INT NOT NULL,
    created_at INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (token),
    KEY idx_user_id (user_id),
    KEY idx_expire_at (expire_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='桌面端登录 Token';

-- 2. 群码序列表（用于生成 QYYYYMMDDNN 格式的群码）
CREATE TABLE IF NOT EXISTS group_code_sequence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_key DATE NOT NULL COMMENT '日期键（YYYY-MM-DD）',
    last_seq INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日最后使用的序号',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_date_key (date_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='群码序列表（用于生成 QYYYYMMDDNN）';

-- 3. 技术资源文件元数据表
CREATE TABLE IF NOT EXISTS tech_resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(20) NOT NULL COMMENT '群码（关联 customers.group_code）',
    asset_type ENUM('works', 'models', 'customer') NOT NULL COMMENT '资源类型：works=作品文件, models=模型文件, customer=客户文件',
    rel_path VARCHAR(512) NOT NULL COMMENT '相对路径（不含 group_code 和 asset_type 前缀）',
    filename VARCHAR(255) NOT NULL COMMENT '文件名',
    storage_disk VARCHAR(32) NOT NULL DEFAULT 's3' COMMENT '存储驱动：s3/local',
    storage_key VARCHAR(768) NOT NULL COMMENT 'MinIO/S3 对象键（完整路径）',
    filesize BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
    mime_type VARCHAR(120) DEFAULT NULL COMMENT 'MIME 类型',
    file_ext VARCHAR(16) DEFAULT NULL COMMENT '文件扩展名',
    etag VARCHAR(64) DEFAULT NULL COMMENT 'S3 ETag（用于校验）',
    checksum_sha256 CHAR(64) DEFAULT NULL COMMENT 'SHA256 校验和（可选）',
    uploaded_by INT NOT NULL COMMENT '上传人 ID',
    uploaded_at INT NOT NULL COMMENT '上传时间戳',
    updated_at INT DEFAULT NULL COMMENT '更新时间戳',
    deleted_at INT DEFAULT NULL COMMENT '软删除时间戳',
    deleted_by INT DEFAULT NULL COMMENT '删除人 ID',
    extra JSON DEFAULT NULL COMMENT '扩展字段',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_group_asset_path (group_code, asset_type, rel_path(255)),
    KEY idx_group_code (group_code),
    KEY idx_asset_type (asset_type),
    KEY idx_uploaded_by (uploaded_by),
    KEY idx_deleted_at (deleted_at),
    KEY idx_storage_key (storage_key(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技术资源文件元数据表';

-- 4. 为 customers 表添加 group_code 字段（如果不存在）
-- 注意：这个需要手动检查，因为 ALTER TABLE 不支持 IF NOT EXISTS
-- 先执行以下查询检查字段是否存在：
-- SHOW COLUMNS FROM customers LIKE 'group_code';
-- 如果不存在，执行：
-- ALTER TABLE customers ADD COLUMN group_code VARCHAR(20) DEFAULT NULL COMMENT '群码（不可变唯一标识，格式 QYYYYMMDDNN）' AFTER customer_code;
-- ALTER TABLE customers ADD UNIQUE INDEX uniq_group_code (group_code);

-- ============================================
-- 执行完成后，运行 PHP 迁移脚本为现有客户生成群码：
-- php /opt/1panel/www/sites/192.168.110.2516665/index/scripts/migrations/20251220_group_code_and_tech_resources.php
-- ============================================
