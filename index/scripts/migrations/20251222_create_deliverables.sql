-- 创建交付物表
CREATE TABLE IF NOT EXISTS deliverables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    file_id INT DEFAULT NULL,
    deliverable_name VARCHAR(255) NOT NULL,
    deliverable_type VARCHAR(50) DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_size BIGINT DEFAULT NULL,
    visibility_level VARCHAR(20) NOT NULL DEFAULT 'client',
    approval_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_by INT NOT NULL,
    submitted_at INT NOT NULL,
    approved_by INT DEFAULT NULL,
    approved_at INT DEFAULT NULL,
    reject_reason TEXT DEFAULT NULL,
    version VARCHAR(50) DEFAULT NULL,
    create_time INT NOT NULL,
    update_time INT NOT NULL,
    KEY idx_project (project_id),
    KEY idx_approval_status (approval_status),
    KEY idx_submitted_by (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 创建项目资料表
CREATE TABLE IF NOT EXISTS project_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    file_id INT DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT NULL,
    file_category VARCHAR(50) DEFAULT NULL,
    visibility_level VARCHAR(20) NOT NULL DEFAULT 'internal',
    uploaded_by INT NOT NULL,
    upload_time INT NOT NULL,
    KEY idx_project (project_id),
    KEY idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
