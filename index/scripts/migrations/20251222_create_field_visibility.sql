-- 字段可见性配置表
CREATE TABLE IF NOT EXISTS field_visibility_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_label VARCHAR(255) NOT NULL,
    visibility_level VARCHAR(20) NOT NULL DEFAULT 'internal',
    tech_visible TINYINT(1) NOT NULL DEFAULT 1,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    create_time INT NOT NULL,
    update_time INT NOT NULL,
    UNIQUE KEY uk_entity_field (entity_type, field_key),
    KEY idx_entity_type (entity_type),
    KEY idx_visibility (visibility_level),
    KEY idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始化默认配置：客户字段
INSERT IGNORE INTO field_visibility_config (entity_type, field_key, field_label, visibility_level, tech_visible, client_visible, sort_order, is_system, create_time, update_time) VALUES
('customer', 'name', '客户名称', 'internal', 1, 1, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'phone', '联系电话', 'internal', 1, 0, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'wechat', '微信号', 'internal', 0, 0, 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'group_code', '群二维码', 'internal', 1, 0, 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'source', '客户来源', 'internal', 0, 0, 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'address', '地址', 'internal', 1, 0, 6, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('customer', 'notes', '备注', 'internal', 1, 0, 7, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- 初始化默认配置：项目字段
INSERT IGNORE INTO field_visibility_config (entity_type, field_key, field_label, visibility_level, tech_visible, client_visible, sort_order, is_system, create_time, update_time) VALUES
('project', 'project_name', '项目名称', 'internal', 1, 1, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('project', 'project_code', '项目编号', 'internal', 1, 1, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('project', 'current_status', '当前状态', 'internal', 1, 1, 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('project', 'group_code', '群二维码', 'internal', 1, 0, 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('project', 'start_date', '开始日期', 'internal', 1, 1, 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('project', 'deadline', '截止日期', 'internal', 1, 1, 6, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
