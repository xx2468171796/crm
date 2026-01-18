<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

auth_require();
$user = current_user();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>审批数据检查</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #4CAF50; color: white; }
    </style>
</head>
<body>
    <h1>审批数据检查工具</h1>
    
    <?php
    $pdo = Db::pdo();
    
    // 1. 检查并创建 file_sync_logs 表
    echo '<div class="section">';
    echo '<h2>1. file_sync_logs 表检查</h2>';
    $stmt = $pdo->query('SHOW TABLES LIKE "file_sync_logs"');
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo '<p class="success">✓ file_sync_logs 表已存在</p>';
    } else {
        echo '<p class="error">✗ file_sync_logs 表不存在，正在创建...</p>';
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `file_sync_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL COMMENT '用户ID',
                  `project_id` int(11) DEFAULT NULL COMMENT '项目ID',
                  `filename` varchar(255) NOT NULL COMMENT '文件名',
                  `operation` enum('upload','download') NOT NULL COMMENT '操作类型',
                  `status` enum('success','failed') NOT NULL COMMENT '状态',
                  `size` bigint(20) DEFAULT 0 COMMENT '文件大小(字节)',
                  `folder_type` varchar(50) DEFAULT NULL COMMENT '文件夹类型',
                  `error_message` text COMMENT '错误信息',
                  `create_time` int(11) NOT NULL COMMENT '创建时间',
                  PRIMARY KEY (`id`),
                  KEY `idx_user_id` (`user_id`),
                  KEY `idx_project_id` (`project_id`),
                  KEY `idx_operation` (`operation`),
                  KEY `idx_create_time` (`create_time`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            echo '<p class="success">✓ file_sync_logs 表创建成功</p>';
        } catch (Exception $e) {
            echo '<p class="error">✗ 创建失败: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    echo '</div>';
    
    // 2. 统计待审批文件
    echo '<div class="section">';
    echo '<h2>2. 待审批文件统计</h2>';
    
    $stats = [
        '作品文件(artwork_file)' => $pdo->query('SELECT COUNT(*) as cnt FROM deliverables WHERE approval_status="pending" AND file_category="artwork_file" AND deleted_at IS NULL')->fetch()['cnt'],
        '客户文件(customer_file)' => $pdo->query('SELECT COUNT(*) as cnt FROM deliverables WHERE approval_status="pending" AND file_category="customer_file" AND deleted_at IS NULL')->fetch()['cnt'],
        '模型文件(model_file)' => $pdo->query('SELECT COUNT(*) as cnt FROM deliverables WHERE approval_status="pending" AND file_category="model_file" AND deleted_at IS NULL')->fetch()['cnt'],
        '全部待审批' => $pdo->query('SELECT COUNT(*) as cnt FROM deliverables WHERE approval_status="pending" AND deleted_at IS NULL')->fetch()['cnt'],
    ];
    
    echo '<table>';
    echo '<tr><th>分类</th><th>数量</th></tr>';
    foreach ($stats as $label => $count) {
        echo "<tr><td>{$label}</td><td class='info'>{$count}</td></tr>";
    }
    echo '</table>';
    echo '</div>';
    
    // 3. 列出最近待审批文件
    echo '<div class="section">';
    echo '<h2>3. 最近待审批文件（前10条）</h2>';
    
    $stmt = $pdo->query('
        SELECT 
            d.id, 
            d.deliverable_name, 
            d.file_category, 
            d.approval_status,
            p.project_name,
            c.name as customer_name,
            u.realname as uploader,
            FROM_UNIXTIME(d.submitted_at) as submitted_time
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id=p.id
        LEFT JOIN customers c ON p.customer_id=c.id
        LEFT JOIN users u ON d.submitted_by=u.id
        WHERE d.approval_status="pending" AND d.deleted_at IS NULL
        ORDER BY d.submitted_at DESC
        LIMIT 10
    ');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo '<p class="info">暂无待审批文件</p>';
    } else {
        echo '<table>';
        echo '<tr><th>ID</th><th>文件名</th><th>分类</th><th>项目</th><th>客户</th><th>上传者</th><th>提交时间</th></tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['deliverable_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['file_category']) . '</td>';
            echo '<td>' . htmlspecialchars($row['project_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['customer_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['uploader']) . '</td>';
            echo '<td>' . htmlspecialchars($row['submitted_time']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 4. 审批页面链接
    echo '<div class="section">';
    echo '<h2>4. 快速链接</h2>';
    echo '<p><a href="/public/admin_approval.php" target="_blank">→ 打开审批工作台</a></p>';
    echo '<p class="info">提示：审批页面只显示作品文件(artwork_file)的待审批项</p>';
    echo '</div>';
    ?>
    
</body>
</html>
