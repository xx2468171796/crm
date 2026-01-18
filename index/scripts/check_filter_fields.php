<?php
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== 检查筛选字段 ===\n\n";

// 检查筛选字段表是否存在
try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM customer_filter_fields WHERE is_active = 1");
    $r = $stmt->fetch();
    echo "激活的筛选字段数: {$r['cnt']}\n";
    
    $stmt = $pdo->query("SELECT id, field_label FROM customer_filter_fields WHERE is_active = 1");
    while ($row = $stmt->fetch()) {
        echo "  - ID: {$row['id']}, 标签: {$row['field_label']}\n";
    }
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

echo "\n=== 完成 ===\n";
