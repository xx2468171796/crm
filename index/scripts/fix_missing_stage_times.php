<?php
/**
 * 修复缺失的项目阶段时间数据
 * 为没有阶段时间记录的项目初始化默认阶段时间
 * 
 * 运行方式: php scripts/fix_missing_stage_times.php
 */

require_once __DIR__ . '/../core/db.php';

echo "=== 修复项目阶段时间数据 ===\n\n";

$pdo = Db::pdo();

// 1. 检查阶段模板是否存在
$templatesStmt = $pdo->query("SELECT COUNT(*) FROM project_stage_templates WHERE is_active = 1");
$templateCount = $templatesStmt->fetchColumn();

if ($templateCount == 0) {
    echo "⚠️ 没有找到阶段模板，先插入默认模板...\n";
    
    $pdo->exec("
        INSERT IGNORE INTO `project_stage_templates` (`stage_from`, `stage_to`, `stage_order`, `default_days`, `description`, `is_active`) VALUES
        ('待沟通', '需求确认', 1, 3, '待沟通 → 需求确认', 1),
        ('需求确认', '设计中', 2, 2, '需求确认 → 设计中', 1),
        ('设计中', '设计核对', 3, 5, '设计中 → 设计核对', 1),
        ('设计核对', '设计完工', 4, 3, '设计核对 → 设计完工', 1),
        ('设计完工', '设计评价', 5, 2, '设计完工 → 设计评价', 1)
    ");
    
    echo "✅ 已插入默认阶段模板\n\n";
}

// 2. 获取所有没有阶段时间记录的项目
$projectsStmt = $pdo->query("
    SELECT p.id, p.project_name, p.create_time, p.timeline_start_date
    FROM projects p
    LEFT JOIN project_stage_times pst ON p.id = pst.project_id
    WHERE pst.id IS NULL
    ORDER BY p.id DESC
");
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

echo "找到 " . count($projects) . " 个项目缺少阶段时间数据\n\n";

if (count($projects) == 0) {
    echo "✅ 所有项目都已有阶段时间数据，无需修复\n";
    exit;
}

// 3. 获取阶段模板
$templatesStmt = $pdo->query("
    SELECT * FROM project_stage_templates 
    WHERE is_active = 1 
    ORDER BY stage_order ASC
");
$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 为每个项目初始化阶段时间
$insertStmt = $pdo->prepare("
    INSERT INTO project_stage_times 
    (project_id, stage_from, stage_to, stage_order, planned_days, 
     planned_start_date, planned_end_date, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
");

$updateProjectStmt = $pdo->prepare("
    UPDATE projects SET timeline_enabled = 1, timeline_start_date = ? WHERE id = ?
");

$fixed = 0;
foreach ($projects as $project) {
    $projectId = $project['id'];
    $projectName = $project['project_name'];
    
    // 使用项目创建时间或当前时间作为起始日期
    $startDate = $project['timeline_start_date'] 
        ?: ($project['create_time'] ? date('Y-m-d', $project['create_time']) : date('Y-m-d'));
    
    $currentDate = new DateTime($startDate);
    
    echo "修复项目 #{$projectId}: {$projectName}\n";
    echo "  起始日期: {$startDate}\n";
    
    foreach ($templates as $t) {
        $plannedDays = intval($t['default_days']);
        $plannedStart = $currentDate->format('Y-m-d');
        
        $endDate = clone $currentDate;
        $endDate->modify('+' . ($plannedDays - 1) . ' days');
        $plannedEnd = $endDate->format('Y-m-d');
        
        $insertStmt->execute([
            $projectId,
            $t['stage_from'],
            $t['stage_to'],
            $t['stage_order'],
            $plannedDays,
            $plannedStart,
            $plannedEnd
        ]);
        
        echo "  阶段 {$t['stage_order']}: {$t['stage_from']} → {$t['stage_to']} ({$plannedDays}天: {$plannedStart} ~ {$plannedEnd})\n";
        
        $currentDate = clone $endDate;
        $currentDate->modify('+1 day');
    }
    
    // 更新项目的 timeline 设置
    $updateProjectStmt->execute([$startDate, $projectId]);
    
    $fixed++;
    echo "  ✅ 完成\n\n";
}

echo "=== 修复完成 ===\n";
echo "共修复 {$fixed} 个项目的阶段时间数据\n";
