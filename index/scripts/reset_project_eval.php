<?php
require_once __DIR__ . '/../core/db.php';

$projectId = $argv[1] ?? 142;

echo "=== 重置项目 $projectId 评价状态 ===\n";

// 删除评价记录
Db::execute("DELETE FROM project_evaluations WHERE project_id = ?", [$projectId]);
echo "删除评价记录\n";

// 删除评价表单实例
Db::execute("DELETE FROM form_instances WHERE project_id = ? AND purpose = 'evaluation'", [$projectId]);
echo "删除评价表单实例\n";

// 重置项目状态
Db::execute("UPDATE projects SET completed_at = NULL, completed_by = NULL, current_status = '设计评价', evaluation_deadline = ? WHERE id = ?", 
    [date('Y-m-d H:i:s', strtotime('+7 days')), $projectId]);
echo "重置项目状态为设计评价\n";

// 获取门户token
$token = Db::queryOne("SELECT portal_token FROM customers WHERE id = (SELECT customer_id FROM projects WHERE id = ?)", [$projectId]);
echo "\n门户链接: http://crmchonggou.test/portal.php?token={$token['portal_token']}&project_id=$projectId\n";

echo "\n重置完成！\n";
