<?php
require_once __DIR__ . '/../core/db.php';

$projectId = $argv[1] ?? 142;

echo "=== 为项目 $projectId 创建评价表单 ===\n";

// 获取项目信息
$project = Db::queryOne("SELECT id, customer_id, project_name FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    echo "错误: 项目不存在\n";
    exit(1);
}

// 获取默认评价模板配置
$config = Db::queryOne("SELECT config_value FROM system_config WHERE config_key = 'default_evaluation_template_id'");
$templateId = intval($config['config_value'] ?? 0);
if ($templateId <= 0) {
    echo "错误: 未配置默认评价模板\n";
    exit(1);
}

// 检查模板
$template = Db::queryOne("SELECT id, name, current_version_id FROM form_templates WHERE id = ? AND status = 'published'", [$templateId]);
if (!$template) {
    echo "错误: 模板不存在或未发布\n";
    exit(1);
}

// 检查是否已存在
$exist = Db::queryOne("SELECT id FROM form_instances WHERE project_id = ? AND purpose = 'evaluation'", [$projectId]);
if ($exist) {
    echo "评价表单已存在 (ID: {$exist['id']})\n";
    exit(0);
}

// 创建表单实例
$now = time();
$instanceName = $template['name'] . ' - 项目评价';
$fillToken = bin2hex(random_bytes(32));

Db::execute("
    INSERT INTO form_instances (template_id, template_version_id, project_id, instance_name, status, purpose, fill_token, created_by, create_time, update_time)
    VALUES (?, ?, ?, ?, 'pending', 'evaluation', ?, 0, ?, ?)
", [$templateId, $template['current_version_id'], $projectId, $instanceName, $fillToken, $now, $now]);

$newId = Db::lastInsertId();
echo "评价表单创建成功!\n";
echo "表单ID: $newId\n";
echo "填写令牌: $fillToken\n";
echo "门户链接: /form_fill.php?token=$fillToken\n";
