<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

$stages = ProjectService::STAGES;

$projects = Db::query("
    SELECT 
        p.id,
        p.project_code,
        p.project_name,
        p.current_status,
        c.name as customer_name
    FROM projects p
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
    LIMIT 10
");

echo "=== 项目状态测试 ===\n";
foreach ($projects as $project) {
    $stageInfo = $stages[$project['current_status']] ?? ['order' => 99, 'color' => '#6B7280'];
    echo "ID: {$project['id']}, 名称: {$project['project_name']}, current_status: [{$project['current_status']}], stage_name: [{$project['current_status']}], color: {$stageInfo['color']}\n";
}
