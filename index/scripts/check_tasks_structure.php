<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== tasks 表结构 ===\n";
$stmt = $pdo->query('DESCRIBE tasks');
$columns = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    $columns[] = $row['Field'];
}

// 检查是否有 deleted_at 和 deleted_by 字段
if (!in_array('deleted_at', $columns)) {
    echo "\n添加 deleted_at 字段...\n";
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN deleted_at INT DEFAULT NULL COMMENT '删除时间'");
        echo "deleted_at 字段已添加\n";
    } catch (Exception $e) {
        echo "添加失败: " . $e->getMessage() . "\n";
    }
}

if (!in_array('deleted_by', $columns)) {
    echo "\n添加 deleted_by 字段...\n";
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN deleted_by INT DEFAULT NULL COMMENT '删除人ID'");
        echo "deleted_by 字段已添加\n";
    } catch (Exception $e) {
        echo "添加失败: " . $e->getMessage() . "\n";
    }
}

echo "\n完成!\n";
