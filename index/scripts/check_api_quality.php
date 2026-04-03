<?php
/**
 * API 代码质量检查脚本
 * 检查常见的数据库类型不匹配、字段名错误等问题
 * 
 * 使用方法: php scripts/check_api_quality.php
 */

$apiDir = __DIR__ . '/../api';
$coreDir = __DIR__ . '/../core';

$issues = [];

echo "=== API 代码质量检查 ===\n\n";

// 扫描 API 文件
$files = glob($apiDir . '/*.php');
$coreFiles = glob($coreDir . '/*.php');
$serviceFiles = glob($coreDir . '/services/*.php');
$allFiles = array_merge($files, $coreFiles, $serviceFiles);

foreach ($allFiles as $file) {
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    $relativePath = str_replace(__DIR__ . '/../', '', $file);
    
    foreach ($lines as $lineNum => $line) {
        $lineNumber = $lineNum + 1;
        
        // 1. 检查 notifications 表错误字段
        if (preg_match('/INSERT\s+INTO\s+notifications[^;]*\bdata\b/i', $line)) {
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => 'notifications 表没有 data 字段，应使用 related_type + related_id',
                'code' => trim($line),
                'severity' => 'ERROR',
            ];
        }
        
        // 2. 检查 notifications 使用 NOW()
        if (preg_match('/INSERT\s+INTO\s+notifications[^;]*NOW\s*\(\)/i', $line)) {
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => 'notifications.create_time 是 INT 类型，应使用 time() 而不是 NOW()',
                'code' => trim($line),
                'severity' => 'ERROR',
            ];
        }
        
        // 3. 检查 notifications 使用 created_at
        if (preg_match('/INSERT\s+INTO\s+notifications[^;]*created_at/i', $line)) {
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => 'notifications 表使用 create_time，不是 created_at',
                'code' => trim($line),
                'severity' => 'ERROR',
            ];
        }
        
        // 4. 检查错误的表名 project_stages
        if (preg_match('/\bFROM\s+project_stages\b|\bINTO\s+project_stages\b|\bUPDATE\s+project_stages\b/i', $line)) {
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => '表名应为 project_stage_times，不是 project_stages',
                'code' => trim($line),
                'severity' => 'ERROR',
            ];
        }
        
        // 5. 检查 customer_files 使用 NOW()
        if (preg_match('/INSERT\s+INTO\s+customer_files[^;]*NOW\s*\(\)[^;]*uploaded_at/i', $line) ||
            preg_match('/uploaded_at[^;]*NOW\s*\(\)/i', $line)) {
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => 'customer_files.uploaded_at 是 INT 类型，应使用 time()',
                'code' => trim($line),
                'severity' => 'ERROR',
            ];
        }
        
        // 6. 检查 planned_end_date 与时间戳比较（在 SQL WHERE 子句中）
        if (preg_match('/planned_end_date\s*[<>=!]+\s*\?/', $line) && 
            !preg_match('/planned_end_date\s*=\s*\?/', $line)) { // 排除赋值
            // 需要检查上下文，看参数是否是日期字符串
            $issues[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'issue' => 'planned_end_date 是 DATE 类型，确保比较参数是日期字符串 (Y-m-d)',
                'code' => trim($line),
                'severity' => 'WARNING',
            ];
        }
    }
}

// 输出结果
echo "=== 检查结果 ===\n\n";

$errors = array_filter($issues, fn($i) => $i['severity'] === 'ERROR');
$warnings = array_filter($issues, fn($i) => $i['severity'] === 'WARNING');

if (empty($issues)) {
    echo "✅ 未发现问题！\n";
} else {
    if (!empty($errors)) {
        echo "❌ 发现 " . count($errors) . " 个错误：\n\n";
        foreach ($errors as $issue) {
            echo "  [{$issue['file']}:{$issue['line']}]\n";
            echo "  ❌ {$issue['issue']}\n";
            echo "  代码: {$issue['code']}\n\n";
        }
    }
    
    if (!empty($warnings)) {
        echo "⚠️  发现 " . count($warnings) . " 个警告：\n\n";
        foreach ($warnings as $issue) {
            echo "  [{$issue['file']}:{$issue['line']}]\n";
            echo "  ⚠️  {$issue['issue']}\n";
            echo "  代码: {$issue['code']}\n\n";
        }
    }
}

// 输出数据库字段类型参考
echo "\n=== 数据库字段类型参考 ===\n";
echo "┌─────────────────────────┬──────────────────┬──────────┬─────────────────┐\n";
echo "│ 表名                    │ 字段             │ 类型     │ PHP 写法        │\n";
echo "├─────────────────────────┼──────────────────┼──────────┼─────────────────┤\n";
echo "│ notifications           │ create_time      │ INT      │ time()          │\n";
echo "│ notifications           │ related_type     │ VARCHAR  │ 'project'       │\n";
echo "│ notifications           │ related_id       │ INT      │ \$projectId      │\n";
echo "│ customer_files          │ uploaded_at      │ INT      │ time()          │\n";
echo "│ tasks                   │ deadline         │ INT      │ time()          │\n";
echo "│ project_stage_times     │ planned_end_date │ DATE     │ date('Y-m-d')   │\n";
echo "│ file_approvals          │ submit_time      │ DATETIME │ NOW()           │\n";
echo "│ project_tech_assignments│ assigned_at      │ INT      │ time()          │\n";
echo "└─────────────────────────┴──────────────────┴──────────┴─────────────────┘\n";

echo "\n=== 检查完成 ===\n";

exit(empty($errors) ? 0 : 1);
