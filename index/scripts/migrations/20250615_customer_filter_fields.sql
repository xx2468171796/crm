-- 客户自定义筛选字段系统迁移脚本
-- 日期: 2025-06-15
-- 功能: 添加客户自定义筛选字段相关表

-- 1. 筛选字段定义表
CREATE TABLE IF NOT EXISTS `customer_filter_fields` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `field_name` varchar(50) NOT NULL COMMENT '字段名（英文标识）',
    `field_label` varchar(100) NOT NULL COMMENT '字段标签（显示名称）',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序顺序',
    `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_field_name` (`field_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户自定义筛选字段定义';

-- 2. 筛选字段选项表
CREATE TABLE IF NOT EXISTS `customer_filter_options` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `field_id` int(11) NOT NULL COMMENT '所属字段ID',
    `option_value` varchar(50) NOT NULL COMMENT '选项值（存储值）',
    `option_label` varchar(100) NOT NULL COMMENT '选项标签（显示名称）',
    `color` varchar(20) DEFAULT '#6366f1' COMMENT '选项颜色',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序顺序',
    `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_field_id` (`field_id`),
    UNIQUE KEY `uk_field_option` (`field_id`, `option_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户筛选字段选项';

-- 3. 客户筛选字段值表
CREATE TABLE IF NOT EXISTS `customer_filter_values` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `field_id` int(11) NOT NULL COMMENT '字段ID',
    `option_id` int(11) NOT NULL COMMENT '选项ID',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_field_id` (`field_id`),
    KEY `idx_option_id` (`option_id`),
    UNIQUE KEY `uk_customer_field` (`customer_id`, `field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户筛选字段值';

-- 4. 插入示例数据（如果表为空）
INSERT IGNORE INTO customer_filter_fields (field_name, field_label, sort_order) VALUES
('status', '客户状态', 1),
('level', '客户等级', 2),
('industry', '行业分类', 3);

INSERT IGNORE INTO customer_filter_options (field_id, option_value, option_label, color, sort_order) 
SELECT 
    (SELECT id FROM customer_filter_fields WHERE field_name = 'status'),
    v.option_value, v.option_label, v.color, v.sort_order
FROM (
    SELECT 'active' as option_value, '活跃' as option_label, '#10b981' as color, 1 as sort_order UNION ALL
    SELECT 'dormant', '休眠', '#f59e0b', 2 UNION ALL
    SELECT 'lost', '流失', '#ef4444', 3 UNION ALL
    SELECT 'potential', '潜在', '#6366f1', 4
) v
WHERE NOT EXISTS (SELECT 1 FROM customer_filter_options WHERE field_id = (SELECT id FROM customer_filter_fields WHERE field_name = 'status') AND option_value = v.option_value);

INSERT IGNORE INTO customer_filter_options (field_id, option_value, option_label, color, sort_order) 
SELECT 
    (SELECT id FROM customer_filter_fields WHERE field_name = 'level'),
    v.option_value, v.option_label, v.color, v.sort_order
FROM (
    SELECT 'a' as option_value, 'A类' as option_label, '#10b981' as color, 1 as sort_order UNION ALL
    SELECT 'b', 'B类', '#3b82f6', 2 UNION ALL
    SELECT 'c', 'C类', '#f59e0b', 3 UNION ALL
    SELECT 'd', 'D类', '#94a3b8', 4
) v
WHERE NOT EXISTS (SELECT 1 FROM customer_filter_options WHERE field_id = (SELECT id FROM customer_filter_fields WHERE field_name = 'level') AND option_value = v.option_value);

INSERT IGNORE INTO customer_filter_options (field_id, option_value, option_label, color, sort_order) 
SELECT 
    (SELECT id FROM customer_filter_fields WHERE field_name = 'industry'),
    v.option_value, v.option_label, v.color, v.sort_order
FROM (
    SELECT 'manufacturing' as option_value, '制造业' as option_label, '#6366f1' as color, 1 as sort_order UNION ALL
    SELECT 'retail', '零售业', '#8b5cf6', 2 UNION ALL
    SELECT 'service', '服务业', '#06b6d4', 3 UNION ALL
    SELECT 'tech', '科技业', '#10b981', 4 UNION ALL
    SELECT 'other', '其他', '#94a3b8', 5
) v
WHERE NOT EXISTS (SELECT 1 FROM customer_filter_options WHERE field_id = (SELECT id FROM customer_filter_fields WHERE field_name = 'industry') AND option_value = v.option_value);
