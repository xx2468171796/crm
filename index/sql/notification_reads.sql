-- 消息已读记录表
CREATE TABLE IF NOT EXISTS `notification_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `notification_id` varchar(100) NOT NULL COMMENT '通知ID (如 form_123)',
  `read_at` int(11) NOT NULL COMMENT '已读时间戳',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_notification` (`user_id`, `notification_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息已读记录';
