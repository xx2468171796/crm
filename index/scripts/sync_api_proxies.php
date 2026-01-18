<?php
/**
 * API 代理文件同步脚本
 * 
 * 用途：当 Nginx 无法配置 alias 时，需要在 public/api/ 目录创建代理文件
 * 
 * 使用方法：
 *   php scripts/sync_api_proxies.php        # 预览需要创建的文件
 *   php scripts/sync_api_proxies.php --run  # 实际创建文件
 * 
 * 注意：如果 Nginx 已配置 alias 指向 index/api/，则不需要此脚本
 */

$baseDir = dirname(__DIR__);
$apiDir = $baseDir . '/api';
$publicApiDir = $baseDir . '/public/api';

// 确保 public/api 目录存在
if (!is_dir($publicApiDir)) {
    mkdir($publicApiDir, 0755, true);
    echo "Created directory: public/api/\n";
}

// 获取所有 API 文件
$apiFiles = glob($apiDir . '/*.php');
$publicFiles = glob($publicApiDir . '/*.php');

// 获取已存在的代理文件名
$existingProxies = [];
foreach ($publicFiles as $file) {
    $existingProxies[] = basename($file);
}

// 检查缺失的代理文件
$missing = [];
foreach ($apiFiles as $apiFile) {
    $filename = basename($apiFile);
    if (!in_array($filename, $existingProxies)) {
        $missing[] = $filename;
    }
}

$dryRun = !in_array('--run', $argv);

if (empty($missing)) {
    echo "✅ 所有 API 代理文件已同步，无需操作\n";
    echo "   API 文件数: " . count($apiFiles) . "\n";
    echo "   代理文件数: " . count($publicFiles) . "\n";
    exit(0);
}

echo "📋 发现 " . count($missing) . " 个缺失的代理文件：\n\n";

foreach ($missing as $filename) {
    $proxyPath = $publicApiDir . '/' . $filename;
    $proxyContent = "<?php\nrequire_once __DIR__ . '/../../api/{$filename}';\n";
    
    if ($dryRun) {
        echo "   [预览] 将创建: public/api/{$filename}\n";
    } else {
        file_put_contents($proxyPath, $proxyContent);
        echo "   [已创建] public/api/{$filename}\n";
    }
}

echo "\n";
if ($dryRun) {
    echo "💡 这是预览模式。要实际创建文件，请运行:\n";
    echo "   php scripts/sync_api_proxies.php --run\n";
} else {
    echo "✅ 同步完成！创建了 " . count($missing) . " 个代理文件\n";
}
