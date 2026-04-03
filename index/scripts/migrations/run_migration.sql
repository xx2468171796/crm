-- desktop_tokens
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- group_code_sequence
CREATE TABLE IF NOT EXISTS group_code_sequence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_key DATE NOT NULL,
    last_seq INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_date_key (date_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tech_resources
CREATE TABLE IF NOT EXISTS tech_resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(20) NOT NULL,
    asset_type ENUM('works', 'models', 'customer') NOT NULL,
    rel_path VARCHAR(512) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    storage_disk VARCHAR(32) NOT NULL DEFAULT 's3',
    storage_key VARCHAR(768) NOT NULL,
    filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) DEFAULT NULL,
    file_ext VARCHAR(16) DEFAULT NULL,
    etag VARCHAR(64) DEFAULT NULL,
    checksum_sha256 CHAR(64) DEFAULT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at INT NOT NULL,
    updated_at INT DEFAULT NULL,
    deleted_at INT DEFAULT NULL,
    deleted_by INT DEFAULT NULL,
    extra JSON DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_group_asset_path (group_code, asset_type, rel_path(255)),
    KEY idx_group_code (group_code),
    KEY idx_asset_type (asset_type),
    KEY idx_uploaded_by (uploaded_by),
    KEY idx_deleted_at (deleted_at),
    KEY idx_storage_key (storage_key(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
