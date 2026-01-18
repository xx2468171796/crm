<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "添加门户权限...\n";

$permissions = [
    ['portal_view', '查看客户门户', 'portal', '可以打开客户门户链接', 1],
    ['portal_copy_link', '复制门户链接', 'portal', '可以复制门户访问链接', 2],
    ['portal_view_password', '查看门户密码', 'portal', '可以查看门户访问密码', 3],
    ['portal_edit_password', '修改门户密码', 'portal', '可以修改门户访问密码', 4],
];

$stmt = $pdo->prepare("
    INSERT INTO permissions (code, name, module, description, sort_order) 
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)
");

foreach ($permissions as $perm) {
    try {
        $stmt->execute($perm);
        echo "✓ {$perm[0]} - {$perm[1]}\n";
    } catch (Exception $e) {
        echo "✗ {$perm[0]} - " . $e->getMessage() . "\n";
    }
}

echo "\n完成\n";
