<?php
/**
 * 修复项目状态数据
 * 将英文状态转换为中文状态
 */

require_once __DIR__ . '/../core/db.php';

// 状态映射：英文 -> 中文
$statusMap = [
    'pending' => '待沟通',
    'requirement' => '需求确认',
    'designing' => '设计中',
    'review' => '设计核对',
    'completed' => '设计完工',
    'evaluation' => '设计评价',
];

echo "开始修复项目状态数据...\n\n";

// 查询所有使用英文状态的项目
$projects = Db::query("
    SELECT id, project_code, current_status 
    FROM projects 
    WHERE current_status IN ('pending', 'requirement', 'designing', 'review', 'completed', 'evaluation')
    AND deleted_at IS NULL
");

echo "找到 " . count($projects) . " 个需要修复的项目\n\n";

$fixed = 0;
foreach ($projects as $project) {
    $oldStatus = $project['current_status'];
    $newStatus = $statusMap[$oldStatus] ?? null;
    
    if ($newStatus) {
        Db::execute(
            "UPDATE projects SET current_status = ? WHERE id = ?",
            [$newStatus, $project['id']]
        );
        echo "✓ {$project['project_code']}: {$oldStatus} -> {$newStatus}\n";
        $fixed++;
    } else {
        echo "✗ {$project['project_code']}: 未知状态 {$oldStatus}\n";
    }
}

echo "\n修复完成！共修复 {$fixed} 个项目\n";
