-- 文件同步日志表
CREATE TABLE IF NOT EXISTS `file_sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `project_id` int(11) DEFAULT NULL COMMENT '项目ID',
  `filename` varchar(255) NOT NULL COMMENT '文件名',
  `operation` enum('upload','download') NOT NULL COMMENT '操作类型',
  `status` enum('success','failed') NOT NULL COMMENT '状态',
  `size` bigint(20) DEFAULT 0 COMMENT '文件大小(字节)',
  `folder_type` varchar(50) DEFAULT NULL COMMENT '文件夹类型(客户文件/作品文件/模型文件)',
  `error_message` text COMMENT '错误信息',
  `create_time` int(11) NOT NULL COMMENT '创建时间(时间戳)',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_operation` (`operation`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件同步日志表';
