<?php
/**
 * 修复被破坏的 API 文件
 * 
 * 问题：文件被复制粘贴了两次，中间有 "$origin = <?php"
 * 解决：找到重复的部分，只保留第一份完整代码
 */

$files = [
    'api/desktop_diff.php',
    'api/desktop_download.php',
    'api/desktop_files.php',
    'api/desktop_group_resources.php',
    'api/desktop_upload_complete.php',
    'api/desktop_upload_init.php',
    'api/desktop_upload_part_url.php',
];

$baseDir = __DIR__ . '/../';

foreach ($files as $file) {
    $path = $baseDir . $file;
    if (!file_exists($path)) {
        echo "[跳过] {$file} - 文件不存在\n";
        continue;
    }
    
    $content = file_get_contents($path);
    
    // 检查是否有重复的 <?php 标签
    $phpTagCount = substr_count($content, '<?php');
    if ($phpTagCount <= 1) {
        echo "[正常] {$file} - 无需修复\n";
        continue;
    }
    
    // 找到第二个 <?php 的位置（错误插入的位置）
    $firstPos = strpos($content, '<?php');
    $secondPos = strpos($content, '<?php', $firstPos + 1);
    
    if ($secondPos === false) {
        echo "[正常] {$file} - 无需修复\n";
        continue;
    }
    
    // 找到 "$origin = <?php" 这个错误模式
    $errorPattern = '$origin = <?php';
    $errorPos = strpos($content, $errorPattern);
    
    if ($errorPos !== false) {
        // 截取到错误位置之前的内容
        $fixedContent = substr($content, 0, $errorPos);
        
        // 移除行尾可能的不完整代码
        $fixedContent = preg_replace('/\n[^\n]*$/', "\n", $fixedContent);
        
        // 添加 api_init.php 引用（在 <?php 后）
        $fixedContent = preg_replace(
            '/^(<\?php\s*\n)/',
            "$1require_once __DIR__ . '/../core/api_init.php';\n",
            $fixedContent
        );
        
        // 移除原来的 header('Content-Type...) 因为 api_init.php 已经设置了
        $fixedContent = preg_replace(
            "/header\s*\(\s*['\"]Content-Type:\s*application\/json[^)]+\);\s*\n/",
            '',
            $fixedContent
        );
        
        // 保存文件
        file_put_contents($path, $fixedContent);
        echo "[修复] {$file}\n";
    } else {
        echo "[警告] {$file} - 无法识别错误模式\n";
    }
}

echo "\n修复完成。请重新检查语法。\n";
