<?php
/**
 * 修复 deliverables 表中包含路径前缀的文件名
 */
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

// 查找包含路径分隔符的文件名
$stmt = $pdo->query("SELECT id, deliverable_name FROM deliverables WHERE deliverable_name LIKE '%\\\\%' OR deliverable_name LIKE '%/%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo count($rows) . " rows to fix\n";

foreach ($rows as $row) {
    $fixed = basename(str_replace('\\', '/', $row['deliverable_name']));
    echo $row['id'] . ': ' . $row['deliverable_name'] . ' -> ' . $fixed . "\n";
    $pdo->prepare('UPDATE deliverables SET deliverable_name = ? WHERE id = ?')->execute([$fixed, $row['id']]);
}

echo "Done\n";
