<?php
/**
 * 创建个人网盘相关数据库表
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo = Db::getConnection();
    
    // 1. 创建 personal_drives 表 - 用户网盘信息
    $sql1 = "
    CREATE TABLE IF NOT EXISTS personal_drives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE COMMENT '用户ID',
        storage_limit BIGINT DEFAULT 53687091200 COMMENT '存储上限(字节),默认50GB',
        used_storage BIGINT DEFAULT 0 COMMENT '已用空间(字节)',
        status ENUM('active', 'disabled') DEFAULT 'active' COMMENT '状态',
        create_time INT NOT NULL COMMENT '创建时间',
        update_time INT DEFAULT NULL COMMENT '更新时间',
        INDEX idx_user_id (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户个人网盘';
    ";
    $pdo->exec($sql1);
    echo "✓ personal_drives 表创建成功\n";
    
    // 2. 创建 drive_files 表 - 网盘文件
    $sql2 = "
    CREATE TABLE IF NOT EXISTS drive_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        drive_id INT NOT NULL COMMENT '网盘ID',
        user_id INT NOT NULL COMMENT '所属用户ID',
        filename VARCHAR(500) NOT NULL COMMENT '文件名',
        original_filename VARCHAR(500) DEFAULT NULL COMMENT '原始文件名',
        folder_path VARCHAR(1000) DEFAULT '/' COMMENT '文件夹路径',
        storage_key VARCHAR(1000) NOT NULL COMMENT 'S3存储key',
        file_size BIGINT DEFAULT 0 COMMENT '文件大小(字节)',
        file_type VARCHAR(100) DEFAULT NULL COMMENT '文件类型',
        upload_source ENUM('user', 'share', 'admin') DEFAULT 'user' COMMENT '上传来源',
        uploader_ip VARCHAR(45) DEFAULT NULL COMMENT '上传者IP',
        create_time INT NOT NULL COMMENT '创建时间',
        update_time INT DEFAULT NULL COMMENT '更新时间',
        INDEX idx_drive_id (drive_id),
        INDEX idx_user_id (user_id),
        INDEX idx_folder_path (folder_path(255)),
        FOREIGN KEY (drive_id) REFERENCES personal_drives(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网盘文件';
    ";
    $pdo->exec($sql2);
    echo "✓ drive_files 表创建成功\n";
    
    // 3. 创建 drive_share_links 表 - 网盘分享链接
    $sql3 = "
    CREATE TABLE IF NOT EXISTS drive_share_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        drive_id INT NOT NULL COMMENT '网盘ID',
        user_id INT NOT NULL COMMENT '创建者用户ID',
        token VARCHAR(64) NOT NULL UNIQUE COMMENT '分享令牌',
        folder_path VARCHAR(1000) DEFAULT '/' COMMENT '分享的文件夹路径',
        password VARCHAR(255) DEFAULT NULL COMMENT '访问密码(加密存储)',
        max_visits INT DEFAULT NULL COMMENT '最大访问次数',
        visit_count INT DEFAULT 0 COMMENT '已访问次数',
        expires_at DATETIME NOT NULL COMMENT '过期时间',
        status ENUM('active', 'disabled', 'expired') DEFAULT 'active' COMMENT '状态',
        note VARCHAR(500) DEFAULT NULL COMMENT '备注',
        create_time INT NOT NULL COMMENT '创建时间',
        INDEX idx_drive_id (drive_id),
        INDEX idx_user_id (user_id),
        INDEX idx_token (token),
        INDEX idx_status (status),
        FOREIGN KEY (drive_id) REFERENCES personal_drives(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网盘分享链接';
    ";
    $pdo->exec($sql3);
    echo "✓ drive_share_links 表创建成功\n";
    
    echo "\n个人网盘数据库表创建完成!\n";
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
