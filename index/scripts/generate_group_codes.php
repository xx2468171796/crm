<?php
/**
 * 为没有群码的客户生成群码
 * 格式: Q{年月日}{序号}
 */
require_once __DIR__ . '/../core/db.php';

$empty = Db::query("SELECT id, name FROM customers WHERE (group_code IS NULL OR group_code = '') AND deleted_at IS NULL ORDER BY id");
echo "需要更新: " . count($empty) . " 个客户\n";

if (count($empty) === 0) {
    echo "没有需要更新的客户\n";
    exit(0);
}

$today = date("Ymd");
$prefix = "Q" . $today;
$maxRow = Db::queryOne("SELECT group_code FROM customers WHERE group_code LIKE ? ORDER BY group_code DESC LIMIT 1", [$prefix . "%"]);
$maxSeq = 0;
if ($maxRow && $maxRow["group_code"]) {
    $seq = (int)substr($maxRow["group_code"], strlen($prefix));
    $maxSeq = $seq;
}
echo "前缀: $prefix, 当前最大序号: $maxSeq\n\n";

$pdo = Db::pdo();
$updated = 0;
foreach ($empty as $c) {
    $maxSeq++;
    $newCode = $prefix . sprintf("%02d", $maxSeq);
    $stmt = $pdo->prepare("UPDATE customers SET group_code = ? WHERE id = ?");
    $stmt->execute([$newCode, $c["id"]]);
    echo "ID" . $c["id"] . " => " . $newCode . " | " . mb_substr($c["name"], 0, 20) . "\n";
    $updated++;
}
echo "\n完成! 更新了 $updated 个客户\n";
