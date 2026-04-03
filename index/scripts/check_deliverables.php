<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== 检查交付物审批状态 ===\n\n";

// 1. 统计各审批状态的文件数
echo "1. 审批状态分布:\n";
$stmt = $pdo->query("SELECT approval_status, COUNT(*) as cnt FROM deliverables WHERE deleted_at IS NULL GROUP BY approval_status");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "   - {$row['approval_status']}: {$row['cnt']}\n";
}

// 2. 统计可见性级别分布
echo "\n2. 可见性级别分布:\n";
$stmt = $pdo->query("SELECT visibility_level, COUNT(*) as cnt FROM deliverables WHERE deleted_at IS NULL GROUP BY visibility_level");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "   - {$row['visibility_level']}: {$row['cnt']}\n";
}

// 3. 统计文件分类分布
echo "\n3. 文件分类分布:\n";
$stmt = $pdo->query("SELECT file_category, COUNT(*) as cnt FROM deliverables WHERE deleted_at IS NULL GROUP BY file_category");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "   - {$row['file_category']}: {$row['cnt']}\n";
}

// 4. 查看最近的交付物
echo "\n4. 最近10个交付物:\n";
$stmt = $pdo->query("SELECT id, deliverable_name, file_category, visibility_level, approval_status FROM deliverables WHERE deleted_at IS NULL ORDER BY create_time DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "   - ID:{$row['id']} | {$row['deliverable_name']} | 分类:{$row['file_category']} | 可见:{$row['visibility_level']} | 审批:{$row['approval_status']}\n";
}

echo "\n=== 完成 ===\n";
