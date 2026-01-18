<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

// 测试获取项目
$project = Db::queryOne("SELECT id, project_name, current_status FROM projects WHERE deleted_at IS NULL LIMIT 1");
echo "测试项目: " . json_encode($project, JSON_UNESCAPED_UNICODE) . "\n";

// 测试状态更新
$projectService = ProjectService::getInstance();
$result = $projectService->updateStatus(
    $project['id'],
    '需求确认',
    1,
    '测试用户'
);
echo "更新结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

// 查看更新后的状态
$updated = Db::queryOne("SELECT id, project_name, current_status FROM projects WHERE id = ?", [$project['id']]);
echo "更新后: " . json_encode($updated, JSON_UNESCAPED_UNICODE) . "\n";
