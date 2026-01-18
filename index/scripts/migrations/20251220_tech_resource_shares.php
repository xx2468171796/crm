<?php
/**
 * 技术资源分享表迁移
 * 支持作品/模型文件的匿名分享功能
 */

require_once __DIR__ . '/../../core/db.php';

$pdo = db_connect();

echo "开始创建技术资源分享表...\n";

$sql = "
CREATE TABLE IF NOT EXISTS tech_resource_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_code VARCHAR(20) NOT NULL COMMENT '群码',
    asset_type ENUM('works', 'models') NOT NULL COMMENT '资源类型（仅作品/模型可分享）',
    share_token VARCHAR(64) NOT NULL UNIQUE COMMENT '分享令牌',
    password VARCHAR(32) DEFAULT NULL COMMENT '访问密码（明文或简单加密）',
    expires_at DATETIME DEFAULT NULL COMMENT '过期时间',
    max_access_count INT DEFAULT NULL COMMENT '最大访问次数',
    access_count INT DEFAULT 0 COMMENT '已访问次数',
    created_by INT NOT NULL COMMENT '创建者ID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_group_asset (group_code, asset_type),
    INDEX idx_token (share_token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技术资源分享表';
";

try {
    $pdo->exec($sql);
    echo "✓ 技术资源分享表创建成功\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "⚠ 表已存在，跳过创建\n";
    } else {
        echo "✗ 创建表失败: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n迁移完成！\n";
