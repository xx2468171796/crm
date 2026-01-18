<?php
/**
 * 同步项目阶段时间状态
 * 根据项目的 current_status 更新 project_stage_times 表的 status
 * 
 * 运行方式: php scripts/sync_stage_times_status.php [project_id]
 */

require_once __DIR__ . '/../core/db.php';

echo "=== 同步项目阶段时间状态 ===\n\n";

$pdo = Db::pdo();

// 状态顺序映射
$statusOrder = [
    '待沟通' => 0,
    '需求确认' => 1,
    '设计中' => 2,
    '设计核对' => 3,
    '设计完工' => 4,
    '设计评价' => 5,
];

// 检查是否指定了项目ID
$projectId = isset($argv[1]) ? intval($argv[1]) : 0;

if ($projectId > 0) {
    // 只修复指定项目
    $projects = $pdo->query("SELECT id, project_name, current_status FROM projects WHERE id = {$projectId}")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 修复所有项目
    $projects = $pdo->query("SELECT id, project_name, current_status FROM projects WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

echo "找到 " . count($projects) . " 个项目需要检查\n\n";

$fixed = 0;
foreach ($projects as $project) {
    $projectId = $project['id'];
    $projectName = $project['project_name'];
    $currentStatus = $project['current_status'];
    
    $currentIndex = $statusOrder[$currentStatus] ?? 0;
    
    // 获取项目的阶段时间记录
    $stages = $pdo->query("
        SELECT id, stage_from, stage_to, stage_order, status
        FROM project_stage_times
        WHERE project_id = {$projectId}
        ORDER BY stage_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stages)) {
        continue;
    }
    
    $needsUpdate = false;
    $updates = [];
    
    foreach ($stages as $stage) {
        $stageFromIndex = $statusOrder[$stage['stage_from']] ?? -1;
        $stageToIndex = $statusOrder[$stage['stage_to']] ?? -1;
        
        // 计算应该的状态
        $shouldStatus = 'pending';
        if ($stageToIndex <= $currentIndex) {
            // 已完成：目标状态索引 <= 当前状态索引
            $shouldStatus = 'completed';
        } elseif ($stageFromIndex <= $currentIndex && $stageToIndex > $currentIndex) {
            // 进行中：起始状态索引 <= 当前状态索引 < 目标状态索引
            $shouldStatus = 'in_progress';
        }
        
        if ($stage['status'] !== $shouldStatus) {
            $needsUpdate = true;
            $updates[] = [
                'id' => $stage['id'],
                'from' => $stage['status'],
                'to' => $shouldStatus,
                'stage' => $stage['stage_from'] . ' → ' . $stage['stage_to']
            ];
            
            $pdo->exec("UPDATE project_stage_times SET status = '{$shouldStatus}' WHERE id = {$stage['id']}");
        }
    }
    
    if ($needsUpdate) {
        echo "修复项目 #{$projectId}: {$projectName} (当前状态: {$currentStatus})\n";
        foreach ($updates as $u) {
            echo "  阶段 [{$u['stage']}]: {$u['from']} → {$u['to']}\n";
        }
        echo "\n";
        $fixed++;
    }
}

echo "=== 同步完成 ===\n";
echo "共修复 {$fixed} 个项目的阶段时间状态\n";
