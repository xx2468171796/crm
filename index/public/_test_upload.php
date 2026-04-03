<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/db.php';

echo "<pre>";
echo "=== deliverables 表结构 ===\n";
$pdo = Db::pdo();
$stmt = $pdo->query("DESCRIBE deliverables");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " - " . $row['Type'] . " - " . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
}

echo "\n=== file_share_uploads 表是否存在 ===\n";
try {
    $stmt = $pdo->query("DESCRIBE file_share_uploads");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . " - " . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
} catch (Exception $e) {
    echo "表不存在: " . $e->getMessage() . "\n";
}

echo "\n=== S3存储配置 ===\n";
$config = require __DIR__ . '/../config/storage.php';
$s3 = $config['s3'] ?? [];
echo "bucket: " . ($s3['bucket'] ?? '未设置') . "\n";
echo "region: " . ($s3['region'] ?? '未设置') . "\n";
echo "endpoint: " . ($s3['endpoint'] ?? '未设置') . "\n";
echo "prefix: " . ($s3['prefix'] ?? '未设置') . "\n";
echo "access_key: " . (isset($s3['access_key']) ? '已设置' : '未设置') . "\n";
echo "secret_key: " . (isset($s3['secret_key']) ? '已设置' : '未设置') . "\n";

echo "</pre>";
