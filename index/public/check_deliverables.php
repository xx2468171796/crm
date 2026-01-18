<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

// 检查是否已有share_enabled字段
$stmt = $pdo->query("DESCRIBE deliverables");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'Field');

if (!in_array('share_enabled', $columnNames)) {
    // 添加share_enabled字段
    $pdo->exec("ALTER TABLE deliverables ADD COLUMN share_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用分享 1=开启 0=关闭'");
    echo json_encode(['success' => true, 'message' => 'share_enabled字段已添加'], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => true, 'message' => 'share_enabled字段已存在'], JSON_UNESCAPED_UNICODE);
}
