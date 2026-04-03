<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 简单的安全检查
$key = $_GET['key'] ?? '';
if ($key !== 'migrate_file_share_2025') {
    die('Unauthorized');
}

try {
    $pdo = Db::pdo();
    
    // 创建 file_share_links 表
    $sql = "
    CREATE TABLE IF NOT EXISTS file_share_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL COMMENT '关联项目ID',
        token VARCHAR(64) NOT NULL UNIQUE COMMENT '分享令牌',
        created_by INT NOT NULL COMMENT '创建者用户ID',
        region_id INT DEFAULT NULL COMMENT '分享节点ID',
        password VARCHAR(255) DEFAULT NULL COMMENT '访问密码(加密存储)',
        max_visits INT DEFAULT NULL COMMENT '最大访问次数(NULL表示不限)',
        visit_count INT DEFAULT 0 COMMENT '已访问次数',
        expires_at DATETIME NOT NULL COMMENT '过期时间',
        status ENUM('active', 'disabled', 'expired') DEFAULT 'active' COMMENT '状态',
        note VARCHAR(500) DEFAULT NULL COMMENT '备注',
        create_time INT NOT NULL COMMENT '创建时间戳',
        update_time INT DEFAULT NULL COMMENT '更新时间戳',
        INDEX idx_project_id (project_id),
        INDEX idx_token (token),
        INDEX idx_status (status),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件分享上传链接';
    ";
    
    $pdo->exec($sql);
    echo "✓ file_share_links 表创建成功<br>";
    
    // 创建 file_share_uploads 表
    $sql2 = "
    CREATE TABLE IF NOT EXISTS file_share_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        share_link_id INT NOT NULL COMMENT '关联分享链接ID',
        project_id INT NOT NULL COMMENT '项目ID',
        deliverable_id INT DEFAULT NULL COMMENT '关联deliverables表ID',
        original_filename VARCHAR(500) NOT NULL COMMENT '原始文件名',
        stored_filename VARCHAR(500) NOT NULL COMMENT '存储文件名(带前缀)',
        file_size BIGINT DEFAULT 0 COMMENT '文件大小',
        file_path VARCHAR(1000) DEFAULT NULL COMMENT '存储路径',
        storage_key VARCHAR(500) DEFAULT NULL COMMENT 'S3存储key',
        uploader_ip VARCHAR(45) DEFAULT NULL COMMENT '上传者IP',
        create_time INT NOT NULL COMMENT '上传时间戳',
        INDEX idx_share_link_id (share_link_id),
        INDEX idx_project_id (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分享链接上传记录';
    ";
    
    $pdo->exec($sql2);
    echo "✓ file_share_uploads 表创建成功<br>";
    
    echo "<br>数据库表创建完成!";
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage();
}
