<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "添加 password_plain 字段...\n";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM portal_links LIKE 'password_plain'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE portal_links ADD COLUMN password_plain VARCHAR(50) DEFAULT NULL COMMENT '明文密码' AFTER password_hash");
        echo "✓ password_plain 字段已添加\n";
    } else {
        echo "✓ password_plain 字段已存在\n";
    }
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

echo "完成\n";
