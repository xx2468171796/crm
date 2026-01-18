<?php
/**
 * 修复已删除客户关联的项目
 * 将已删除客户的项目也标记为已删除
 */

require_once __DIR__ . '/../core/db.php';

echo "=== 修复已删除客户关联的项目 ===\n\n";

// 查找已删除客户关联的未删除项目
$projects = Db::query("
    SELECT p.id, p.project_code, p.project_name, c.id as customer_id, c.name as customer_name, c.deleted_at as customer_deleted_at
    FROM projects p
    JOIN customers c ON p.customer_id = c.id
    WHERE c.deleted_at IS NOT NULL AND p.deleted_at IS NULL
");

echo "找到 " . count($projects) . " 个需要修复的项目：\n\n";

if (count($projects) === 0) {
    echo "没有需要修复的项目。\n";
    exit;
}

foreach ($projects as $p) {
    echo "- {$p['project_code']} ({$p['project_name']}) - 客户: {$p['customer_name']}\n";
}

echo "\n是否执行修复？(y/n): ";
$confirm = trim(fgets(STDIN));

if ($confirm !== 'y') {
    echo "已取消。\n";
    exit;
}

$now = time();
$count = 0;

foreach ($projects as $p) {
    Db::execute("UPDATE projects SET deleted_at = ? WHERE id = ?", [$now, $p['id']]);
    $count++;
    echo "已修复: {$p['project_code']}\n";
}

echo "\n完成！共修复 {$count} 个项目。\n";
