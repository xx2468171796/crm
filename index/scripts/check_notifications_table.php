<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== notifications 表结构 ===\n";
$stmt = $pdo->query('DESCRIBE notifications');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\n=== 测试插入 ===\n";
try {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
    ");
    $stmt->execute([1, 'system', '测试通知', '测试内容', 'admin_notification', 1, time()]);
    echo "插入成功\n";
} catch (Exception $e) {
    echo "插入失败: " . $e->getMessage() . "\n";
}
