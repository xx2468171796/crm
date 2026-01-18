-- 迁移脚本：创建项目交付流程系统核心表
-- 包括：projects, project_tech_assignments, project_status_log, timeline_events

-- 1. 创建 projects 表
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL COMMENT '客户ID',
    project_name VARCHAR(255) NOT NULL COMMENT '项目名称',
    project_code VARCHAR(50) NOT NULL COMMENT '项目编号，如 #PRJ-2024-001',
    group_code VARCHAR(50) DEFAULT NULL COMMENT '绑定的群码',
    current_status VARCHAR(50) NOT NULL DEFAULT '待沟通' COMMENT '当前状态：待沟通/确认需求/设计中/设计校对/设计完工/设计评价',
    requirements_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '需求是否锁定',
    requirements_locked_at INT DEFAULT NULL COMMENT '需求锁定时间戳',
    requirements_locked_by INT DEFAULT NULL COMMENT '需求锁定人用户ID',
    start_date DATE DEFAULT NULL COMMENT '开始日期',
    deadline DATE DEFAULT NULL COMMENT '截止日期',
    created_by INT NOT NULL COMMENT '创建人用户ID',
    create_time INT NOT NULL COMMENT '创建时间戳',
    update_time INT NOT NULL COMMENT '更新时间戳',
    deleted_at INT DEFAULT NULL COMMENT '软删除时间戳',
    UNIQUE KEY uk_project_code (project_code),
    KEY idx_customer (customer_id),
    KEY idx_status (current_status),
    KEY idx_created_by (created_by),
    KEY idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目表';

-- 2. 创建 project_tech_assignments 表
CREATE TABLE IF NOT EXISTS project_tech_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '项目ID',
    tech_user_id INT NOT NULL COMMENT '技术人员用户ID',
    assigned_by INT NOT NULL COMMENT '分配人用户ID',
    assigned_at INT NOT NULL COMMENT '分配时间戳',
    notes VARCHAR(255) DEFAULT NULL COMMENT '备注',
    UNIQUE KEY uk_project_tech (project_id, tech_user_id),
    KEY idx_tech_user (tech_user_id),
    KEY idx_project (project_id),
    KEY idx_assigned_by (assigned_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目-技术分配关系表';

-- 3. 创建 project_status_log 表
CREATE TABLE IF NOT EXISTS project_status_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL COMMENT '项目ID',
    from_status VARCHAR(50) DEFAULT NULL COMMENT '原状态',
    to_status VARCHAR(50) NOT NULL COMMENT '新状态',
    changed_by INT NOT NULL COMMENT '操作人用户ID',
    changed_at INT NOT NULL COMMENT '变更时间戳',
    notes TEXT DEFAULT NULL COMMENT '备注',
    KEY idx_project (project_id),
    KEY idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='项目状态变更日志表';

-- 4. 创建 timeline_events 表（统一时间线审计）
CREATE TABLE IF NOT EXISTS timeline_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL COMMENT '实体类型：project/deliverable/form_instance',
    entity_id INT NOT NULL COMMENT '实体ID',
    event_type VARCHAR(50) NOT NULL COMMENT '事件类型：状态变更/分配变更/表单提交/审批动作/备注',
    operator_user_id INT NOT NULL COMMENT '操作人用户ID',
    event_data_json TEXT DEFAULT NULL COMMENT '事件详情JSON',
    create_time INT NOT NULL COMMENT '创建时间戳',
    KEY idx_entity (entity_type, entity_id),
    KEY idx_create_time (create_time),
    KEY idx_operator (operator_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='时间线事件表（统一审计日志）';
