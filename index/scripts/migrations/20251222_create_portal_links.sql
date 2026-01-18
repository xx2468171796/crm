-- 创建客户级门户链接表
CREATE TABLE IF NOT EXISTS portal_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    token VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    expires_at INT DEFAULT NULL,
    last_access_at INT DEFAULT NULL,
    access_count INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    create_time INT NOT NULL,
    update_time INT NOT NULL,
    UNIQUE KEY uk_token (token),
    KEY idx_customer (customer_id),
    KEY idx_enabled (enabled),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
