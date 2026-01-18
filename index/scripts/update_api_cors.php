<?php
/**
 * 批量更新 API 文件 CORS 配置
 * 
 * 功能：
 * 1. 扫描 api/ 目录下所有 PHP 文件
 * 2. 移除分散的 CORS 配置代码
 * 3. 添加统一的 api_init.php 引用
 * 
 * 使用方法：
 * php scripts/update_api_cors.php [--dry-run]
 * 
 * --dry-run: 只显示将要修改的文件，不实际修改
 */

$dryRun = in_array('--dry-run', $argv);
$apiDir = __DIR__ . '/../api';
$updated = 0;
$skipped = 0;
$errors = [];

// 需要移除的 CORS 代码模式
$corsPatterns = [
    // 完整的 CORS 块
    '/\$origin\s*=\s*\$_SERVER\[.HTTP_ORIGIN.\]\s*\?\?\s*.[*].;\s*\n/',
    '/header\s*\(\s*["\']Access-Control-Allow-Origin.*?\);\s*\n/',
    '/header\s*\(\s*["\']Access-Control-Allow-Methods.*?\);\s*\n/',
    '/header\s*\(\s*["\']Access-Control-Allow-Headers.*?\);\s*\n/',
    '/header\s*\(\s*["\']Access-Control-Allow-Credentials.*?\);\s*\n/',
    '/header\s*\(\s*["\']Access-Control-Max-Age.*?\);\s*\n/',
    // OPTIONS 处理块
    '/if\s*\(\s*\$_SERVER\s*\[\s*["\']REQUEST_METHOD["\']\s*\]\s*===?\s*["\']OPTIONS["\']\s*\)\s*\{[^}]*\}\s*\n?/',
];

// 要添加的统一引用
$initLine = "require_once __DIR__ . '/../core/api_init.php';\n";

echo "=== 批量更新 API CORS 配置 ===\n";
echo "模式: " . ($dryRun ? "预览 (dry-run)" : "实际执行") . "\n\n";

// 递归扫描 API 目录
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($apiDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    
    $filePath = $file->getPathname();
    $relativePath = str_replace($apiDir . DIRECTORY_SEPARATOR, '', $filePath);
    $content = file_get_contents($filePath);
    
    // 检查是否已经有 api_init.php
    if (strpos($content, 'api_init.php') !== false) {
        echo "[跳过] {$relativePath} - 已有 api_init.php\n";
        $skipped++;
        continue;
    }
    
    // 检查是否是特殊文件（不需要 CORS）
    if (strpos($relativePath, 'webhook') !== false || 
        strpos($relativePath, 'callback') !== false) {
        echo "[跳过] {$relativePath} - Webhook/Callback 文件\n";
        $skipped++;
        continue;
    }
    
    $originalContent = $content;
    
    // 移除现有的 CORS 代码
    foreach ($corsPatterns as $pattern) {
        $content = preg_replace($pattern, '', $content);
    }
    
    // 在 <?php 后添加 api_init.php 引用
    if (preg_match('/^<\?php\s*\n/', $content)) {
        $content = preg_replace(
            '/^(<\?php\s*\n)/',
            "$1{$initLine}",
            $content
        );
    } elseif (preg_match('/^<\?php/', $content)) {
        $content = preg_replace(
            '/^(<\?php)/',
            "$1\n{$initLine}",
            $content
        );
    }
    
    // 检查是否有变化
    if ($content === $originalContent) {
        echo "[无变化] {$relativePath}\n";
        $skipped++;
        continue;
    }
    
    // 保存文件
    if (!$dryRun) {
        if (file_put_contents($filePath, $content) === false) {
            $errors[] = $relativePath;
            echo "[错误] {$relativePath} - 写入失败\n";
            continue;
        }
    }
    
    echo "[更新] {$relativePath}\n";
    $updated++;
}

echo "\n=== 完成 ===\n";
echo "更新: {$updated} 个文件\n";
echo "跳过: {$skipped} 个文件\n";
if (count($errors) > 0) {
    echo "错误: " . count($errors) . " 个文件\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

if ($dryRun) {
    echo "\n提示: 这是预览模式，未实际修改文件。\n";
    echo "要执行实际更新，请运行: php scripts/update_api_cors.php\n";
}
