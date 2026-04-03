<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 查找设计评价阶段的项目 ===\n";

// 查找设计评价且未完工的项目
$projects = Db::query("
    SELECT id, project_name, current_status, completed_at, evaluation_deadline
    FROM projects 
    WHERE current_status = '设计评价' 
      AND deleted_at IS NULL 
      AND completed_at IS NULL
    LIMIT 5
");

if (empty($projects)) {
    echo "没有找到未完工的设计评价项目\n";
    
    // 查找所有设计评价项目
    $allProjects = Db::query("
        SELECT id, project_name, current_status, completed_at
        FROM projects 
        WHERE current_status = '设计评价' 
          AND deleted_at IS NULL
        LIMIT 5
    ");
    echo "\n所有设计评价项目：\n";
    foreach ($allProjects as $p) {
        echo "  [{$p['id']}] {$p['project_name']} - 完工: " . ($p['completed_at'] ? '是' : '否') . "\n";
    }
} else {
    foreach ($projects as $p) {
        echo "  [{$p['id']}] {$p['project_name']}\n";
        echo "    deadline: {$p['evaluation_deadline']}\n";
    }
}

// 检查项目142的评价状态
echo "\n=== 项目142评价状态 ===\n";
$eval = Db::queryOne("SELECT * FROM project_evaluations WHERE project_id = 142 ORDER BY id DESC LIMIT 1");
if ($eval) {
    echo "评价ID: {$eval['id']}\n";
    echo "评分: {$eval['rating']}\n";
    echo "评论: {$eval['comment']}\n";
    echo "创建时间: " . date('Y-m-d H:i:s', $eval['create_time']) . "\n";
} else {
    echo "没有评价记录\n";
}

$project = Db::queryOne("SELECT id, current_status, completed_at FROM projects WHERE id = 142");
echo "项目状态: {$project['current_status']}\n";
echo "完工时间: " . ($project['completed_at'] ? date('Y-m-d H:i:s', $project['completed_at']) : '未完工') . "\n";
