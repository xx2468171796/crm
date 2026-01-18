<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 检查审批数据 ===\n\n";

$pdo = Db::pdo();

// 1. 检查 file_sync_logs 表是否存在
$stmt = $pdo->query('SHOW TABLES LIKE "file_sync_logs"');
$exists = $stmt->fetch();
echo "1. file_sync_logs 表: " . ($exists ? "存在 ✓" : "不存在 ✗") . "\n\n";

if (!$exists) {
    echo "正在创建 file_sync_logs 表...\n";
    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件同步日志表'
    ");
    echo "file_sync_logs 表创建成功 ✓\n\n";
}

// 2. 检查待审批作品文件数量
$stmt = $pdo->query('
    SELECT COUNT(*) as cnt 
    FROM deliverables 
    WHERE approval_status="pending" 
    AND file_category="artwork_file" 
    AND deleted_at IS NULL
');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "2. 待审批作品文件数量: " . $row['cnt'] . "\n\n";

// 3. 列出最近5条待审批文件
$stmt = $pdo->query('
    SELECT 
        d.id, 
        d.deliverable_name, 
        d.file_category, 
        d.approval_status, 
        p.project_name, 
        u.realname as uploader,
        FROM_UNIXTIME(d.submitted_at) as submitted_time
    FROM deliverables d
    LEFT JOIN projects p ON d.project_id=p.id
    LEFT JOIN users u ON d.submitted_by=u.id
    WHERE d.approval_status="pending" 
    AND d.deleted_at IS NULL
    ORDER BY d.submitted_at DESC
    LIMIT 5
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "3. 最近待审批文件列表:\n";
if (empty($rows)) {
    echo "   (无待审批文件)\n";
} else {
    foreach ($rows as $row) {
        echo "   - ID: {$row['id']}\n";
        echo "     文件名: {$row['deliverable_name']}\n";
        echo "     分类: {$row['file_category']}\n";
        echo "     项目: {$row['project_name']}\n";
        echo "     上传者: {$row['uploader']}\n";
        echo "     提交时间: {$row['submitted_time']}\n";
        echo "\n";
    }
}

echo "=== 检查完成 ===\n";
