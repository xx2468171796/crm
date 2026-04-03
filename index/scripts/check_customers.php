<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== 检查客户数据 ===\n\n";

// 检查总客户数
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM customers WHERE deleted_at IS NULL");
$r = $stmt->fetch();
echo "总客户数（未删除）: {$r['cnt']}\n";

// 检查管理员用户
$stmt = $pdo->query("SELECT id, realname, role FROM users WHERE id = 1");
$u = $stmt->fetch();
echo "用户ID=1: " . json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";

// 检查最近的客户
echo "\n最近5个客户:\n";
$stmt = $pdo->query("SELECT id, name, customer_code, create_user_id, deleted_at FROM customers ORDER BY id DESC LIMIT 5");
while ($row = $stmt->fetch()) {
    $deleted = $row['deleted_at'] ? '已删除' : '正常';
    echo "  - ID: {$row['id']}, 名称: {$row['name']}, 创建人ID: {$row['create_user_id']}, 状态: {$deleted}\n";
}

echo "\n=== 完成 ===\n";
