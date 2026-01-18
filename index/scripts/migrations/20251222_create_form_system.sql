-- 表单系统数据库迁移脚本
-- 创建表单模板、版本、实例和提交记录表

-- 表单模板表
CREATE TABLE IF NOT EXISTS form_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    form_type VARCHAR(50) NOT NULL DEFAULT 'custom',
    current_version_id INT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by INT NOT NULL,
    create_time INT NOT NULL,
    update_time INT NOT NULL,
    deleted_at INT DEFAULT NULL,
    KEY idx_form_type (form_type),
    KEY idx_status (status),
    KEY idx_created_by (created_by),
    KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 表单模板版本表
CREATE TABLE IF NOT EXISTS form_template_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    schema_json LONGTEXT NOT NULL,
    published_by INT DEFAULT NULL,
    published_at INT DEFAULT NULL,
    create_time INT NOT NULL,
    KEY idx_template (template_id),
    KEY idx_version (template_id, version_number),
    CONSTRAINT fk_ftv_template FOREIGN KEY (template_id) REFERENCES form_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 表单实例表（项目绑定的表单）
CREATE TABLE IF NOT EXISTS form_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    template_id INT NOT NULL,
    template_version_id INT NOT NULL,
    instance_name VARCHAR(255) NOT NULL,
    fill_token VARCHAR(100) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_by INT NOT NULL,
    create_time INT NOT NULL,
    update_time INT NOT NULL,
    UNIQUE KEY uk_fill_token (fill_token),
    KEY idx_project (project_id),
    KEY idx_template (template_id),
    KEY idx_status (status),
    CONSTRAINT fk_fi_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_fi_template FOREIGN KEY (template_id) REFERENCES form_templates(id),
    CONSTRAINT fk_fi_version FOREIGN KEY (template_version_id) REFERENCES form_template_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 表单提交记录表
CREATE TABLE IF NOT EXISTS form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instance_id INT NOT NULL,
    submission_data_json LONGTEXT NOT NULL,
    submitted_by_type VARCHAR(20) NOT NULL DEFAULT 'user',
    submitted_by_id INT DEFAULT NULL,
    submitted_by_name VARCHAR(100) DEFAULT NULL,
    submitted_at INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    KEY idx_instance (instance_id),
    KEY idx_submitted_at (submitted_at),
    CONSTRAINT fk_fs_instance FOREIGN KEY (instance_id) REFERENCES form_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
