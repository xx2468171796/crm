-- 交付物分享链接表
CREATE TABLE IF NOT EXISTS `deliverable_shares` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `deliverable_id` INT UNSIGNED NOT NULL COMMENT '交付物ID',
    `share_token` VARCHAR(64) NOT NULL COMMENT '分享token',
    `portal_token` VARCHAR(255) DEFAULT NULL COMMENT '关联的门户token',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT '创建人ID',
    `expire_at` DATETIME DEFAULT NULL COMMENT '过期时间，NULL表示永不过期',
    `max_downloads` INT UNSIGNED DEFAULT NULL COMMENT '最大下载次数，NULL表示无限制',
    `download_count` INT UNSIGNED DEFAULT 0 COMMENT '已下载次数',
    `view_count` INT UNSIGNED DEFAULT 0 COMMENT '浏览次数',
    `password` VARCHAR(255) DEFAULT NULL COMMENT '访问密码（可选）',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_share_token` (`share_token`),
    KEY `idx_deliverable_id` (`deliverable_id`),
    KEY `idx_portal_token` (`portal_token`(100)),
    KEY `idx_expire_at` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交付物分享链接表';
