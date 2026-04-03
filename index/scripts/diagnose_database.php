<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 数据库诊断报告 ===\n\n";

$pdo = Db::pdo();

// 1. 交付物相关表
echo "【交付物相关表】\n";
$tables = ['deliverables', 'project_deliverables', 'project_files', 'files', 'customer_files'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($stmt->fetch()) {
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch()['cnt'];
        $stmt = $pdo->query("DESCRIBE $table");
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✓ $table: $count 条记录, " . count($fields) . " 个字段\n";
        echo "  字段: " . implode(', ', $fields) . "\n\n";
    } else {
        echo "✗ $table: 不存在\n\n";
    }
}

// 2. 审批相关表
echo "\n【审批相关表】\n";
$tables = ['file_approvals', 'work_approvals', 'work_approval_versions'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($stmt->fetch()) {
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch()['cnt'];
        $stmt = $pdo->query("DESCRIBE $table");
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✓ $table: $count 条记录, " . count($fields) . " 个字段\n";
        echo "  字段: " . implode(', ', $fields) . "\n\n";
    } else {
        echo "✗ $table: 不存在\n\n";
    }
}

// 3. 项目阶段相关表
echo "\n【项目阶段相关表】\n";
$tables = ['project_stage_times', 'project_stage_templates', 'project_status_log'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($stmt->fetch()) {
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch()['cnt'];
        $stmt = $pdo->query("DESCRIBE $table");
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✓ $table: $count 条记录, " . count($fields) . " 个字段\n";
        echo "  字段: " . implode(', ', $fields) . "\n\n";
    } else {
        echo "✗ $table: 不存在\n\n";
    }
}

// 4. 检查API使用情况
echo "\n【API使用情况】\n";
$apiDir = __DIR__ . '/../api';
$apis = glob($apiDir . '/*.php');
$tableUsage = [];

foreach ($apis as $api) {
    $content = file_get_contents($api);
    foreach (['deliverables', 'project_deliverables', 'file_approvals', 'work_approvals'] as $table) {
        if (stripos($content, $table) !== false) {
            $tableUsage[$table][] = basename($api);
        }
    }
}

foreach ($tableUsage as $table => $apis) {
    echo "$table 被使用于:\n";
    foreach ($apis as $api) {
        echo "  - $api\n";
    }
    echo "\n";
}

echo "=== 诊断完成 ===\n";
