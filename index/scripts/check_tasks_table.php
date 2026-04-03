<?php
require_once __DIR__ . '/../core/db.php';

$result = Db::query("SHOW TABLES LIKE 'tasks'");
echo "tasks 表: " . (count($result) > 0 ? "存在" : "不存在") . "\n";

if (count($result) > 0) {
    $columns = Db::query("DESCRIBE tasks");
    echo "表结构:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
}
