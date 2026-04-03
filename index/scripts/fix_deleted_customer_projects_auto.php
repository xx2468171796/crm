<?php
/**
 * 自动修复已删除客户关联的项目
 */

require_once __DIR__ . '/../core/db.php';

echo "=== 修复已删除客户关联的项目 ===\n\n";

$now = time();

// 直接执行修复
$count = Db::execute("
    UPDATE projects p
    JOIN customers c ON p.customer_id = c.id
    SET p.deleted_at = ?
    WHERE c.deleted_at IS NOT NULL AND p.deleted_at IS NULL
", [$now]);

echo "完成！共修复 {$count} 个项目。\n";
