-- 设计对接资料问卷表
CREATE TABLE IF NOT EXISTS `design_questionnaires` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `token` varchar(64) NOT NULL COMMENT '外部访问token',
    
    -- 一、基本资讯与联络方式
    `client_name` varchar(100) DEFAULT NULL COMMENT '客户姓名',
    `contact_method` varchar(200) DEFAULT NULL COMMENT '常用联系方式(JSON: line/wechat/phone等)',
    `contact_phone` varchar(50) DEFAULT NULL COMMENT '联系电话',
    `contact_time` varchar(200) DEFAULT NULL COMMENT '方便联系的时间',
    `communication_style` varchar(500) DEFAULT NULL COMMENT '习惯的沟通方式(JSON数组)',
    
    -- 二、设计服务内容
    `service_items` varchar(500) DEFAULT NULL COMMENT '服务项目(JSON数组: floor_plan/rendering/construction/exterior)',
    `rendering_type` varchar(200) DEFAULT NULL COMMENT '效果图类型(JSON数组: single_3d/720_panorama)',
    
    -- 三、空间细节与改造程度
    `total_area` varchar(50) DEFAULT NULL COMMENT '设计总面积',
    `area_unit` varchar(20) DEFAULT 'sqm' COMMENT '面积单位: sqm/ping',
    `house_status` varchar(50) DEFAULT NULL COMMENT '房屋现况: rough/decorated/renovation/commercial',
    `include_balcony_kitchen` tinyint(1) DEFAULT NULL COMMENT '阳台/厨卫是否包含',
    `ceiling_wall_modify` varchar(20) DEFAULT NULL COMMENT '天花板/墙体是否拆改: yes/no/designer_suggest',
    `rewire_plumbing` varchar(10) DEFAULT NULL COMMENT '水电管线是否全室重新配管: yes/no',
    
    -- 四、风格倾向与审美偏好
    `style_maturity` varchar(50) DEFAULT NULL COMMENT '风格成熟度: has_reference/rough_idea/no_idea',
    `style_description` varchar(500) DEFAULT NULL COMMENT '风格描述(如: 现代风、法式奶油风)',
    `color_preference` varchar(200) DEFAULT NULL COMMENT '色系偏好',
    `design_taboo` text COMMENT '设计禁忌',
    `reference_images` text COMMENT '参考图片(JSON数组，存储路径)',
    
    -- 五、生活功能与使用习惯
    `household_members` varchar(200) DEFAULT NULL COMMENT '常住成员',
    `special_function_needs` text COMMENT '特殊功能需求',
    `life_focus` varchar(500) DEFAULT NULL COMMENT '生活重心(JSON数组)',
    
    -- 六、项目执行与预算
    `budget_type` varchar(50) DEFAULT NULL COMMENT '预算类型: economy/standard/premium/custom',
    `budget_range` varchar(200) DEFAULT NULL COMMENT '具体预算范围',
    `delivery_deadline` varchar(200) DEFAULT NULL COMMENT '预计交付或完工节点',
    
    -- 七、原始资料提供
    `has_floor_plan` tinyint(1) DEFAULT 0 COMMENT '是否有原始平面图',
    `has_site_photos` tinyint(1) DEFAULT 0 COMMENT '是否有现场照片/影片',
    `has_key_dimensions` tinyint(1) DEFAULT 0 COMMENT '是否有关键尺寸数据',
    `original_files` text COMMENT '原始资料文件(JSON数组)',
    
    -- 八、其他备注
    `extra_notes` text COMMENT '其他补充说明',
    
    -- 系统字段
    `status` tinyint(1) DEFAULT 1 COMMENT '状态: 1=启用 0=禁用',
    `version` int(11) DEFAULT 1 COMMENT '版本号',
    `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
    `update_user_id` int(11) DEFAULT NULL COMMENT '最后修改人ID',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customer_id` (`customer_id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设计对接资料问卷';

-- 问卷修改历史表
CREATE TABLE IF NOT EXISTS `design_questionnaire_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `questionnaire_id` int(11) NOT NULL COMMENT '问卷ID',
    `customer_id` int(11) NOT NULL COMMENT '客户ID',
    `field_name` varchar(50) NOT NULL COMMENT '修改的字段名',
    `old_value` text COMMENT '修改前的值',
    `new_value` text COMMENT '修改后的值',
    `change_source` varchar(20) DEFAULT 'internal' COMMENT '修改来源: internal/external',
    `change_user_id` int(11) DEFAULT NULL COMMENT '修改人ID(内部用户)',
    `change_user_name` varchar(100) DEFAULT NULL COMMENT '修改人名称',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_questionnaire` (`questionnaire_id`),
    KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='问卷修改历史';
