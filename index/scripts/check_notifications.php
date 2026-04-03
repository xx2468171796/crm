<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 最近10条通知记录 ===\n\n";

$rows = Db::query("SELECT id, user_id, type, title, create_time FROM notifications ORDER BY id DESC LIMIT 10");

foreach ($rows as $r) {
    echo "ID: {$r['id']} | 用户: {$r['user_id']} | 类型: {$r['type']} | 标题: {$r['title']} | 时间: " . date('Y-m-d H:i:s', $r['create_time']) . "\n";
}

echo "\n=== 任务类型通知数量 ===\n";
$taskCount = Db::queryOne("SELECT COUNT(*) as cnt FROM notifications WHERE type = 'task'");
echo "task 类型通知: " . $taskCount['cnt'] . " 条\n";
