-- S3 加速节点表
-- 用于存储多区域加速代理节点配置，供桌面端选择使用

CREATE TABLE IF NOT EXISTS s3_acceleration_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_name VARCHAR(100) NOT NULL COMMENT '节点名称(如: 中国大陆加速、台湾加速)',
    endpoint_url VARCHAR(500) NOT NULL COMMENT '加速端点URL(如: https://proxy.example.com)',
    region_code VARCHAR(50) DEFAULT NULL COMMENT '区域代码(可选)',
    status TINYINT DEFAULT 1 COMMENT '状态: 1=启用 0=禁用',
    is_default TINYINT DEFAULT 0 COMMENT '是否默认节点: 1=是 0=否',
    sort_order INT DEFAULT 0 COMMENT '排序顺序(越小越靠前)',
    description TEXT COMMENT '备注说明',
    created_at INT COMMENT '创建时间戳',
    updated_at INT COMMENT '更新时间戳',
    INDEX idx_status (status),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='S3加速节点配置表';
