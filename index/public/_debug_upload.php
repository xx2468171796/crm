<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "=== 上传调试 ===\n\n";

// 检查storage.php配置
$storageConfigPath = __DIR__ . '/../config/storage.php';
echo "存储配置路径: $storageConfigPath\n";
echo "配置文件存在: " . (file_exists($storageConfigPath) ? '是' : '否') . "\n\n";

if (file_exists($storageConfigPath)) {
    $config = require $storageConfigPath;
    echo "S3配置:\n";
    echo "  - endpoint: " . ($config['s3']['endpoint'] ?? '未设置') . "\n";
    echo "  - bucket: " . ($config['s3']['bucket'] ?? '未设置') . "\n";
    echo "  - region: " . ($config['s3']['region'] ?? '未设置') . "\n";
    echo "  - prefix: " . ($config['s3']['prefix'] ?? '未设置') . "\n";
}

// 检查storage_provider.php
$providerPath = __DIR__ . '/../core/storage/storage_provider.php';
echo "\n存储Provider路径: $providerPath\n";
echo "Provider文件存在: " . (file_exists($providerPath) ? '是' : '否') . "\n";

if (file_exists($providerPath)) {
    require_once $providerPath;
    echo "S3StorageProvider类存在: " . (class_exists('S3StorageProvider') ? '是' : '否') . "\n";
}

// 检查DB
require_once __DIR__ . '/../core/db.php';
$pdo = Db::pdo();

// 测试查询
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM file_share_links");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\n分享链接数量: " . $result['cnt'] . "\n";

// 检查projects表结构
$stmt = $pdo->query("DESCRIBE projects");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\nprojects表字段: " . implode(', ', $columns) . "\n";

echo "\n=== 完成 ===\n";
echo "</pre>";
