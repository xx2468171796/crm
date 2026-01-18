<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "添加 show_model_files 字段...\n";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'show_model_files'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN show_model_files TINYINT(1) DEFAULT 0 COMMENT '是否显示模型文件给客户'");
        echo "✓ show_model_files 字段已添加\n";
    } else {
        echo "✓ show_model_files 字段已存在\n";
    }
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

echo "完成\n";
