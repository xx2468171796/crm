<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 检查评价模板配置 ===\n";

// 检查系统配置
$config = Db::queryOne("SELECT * FROM system_config WHERE config_key = 'default_evaluation_template_id'");
if ($config) {
    echo "默认评价模板ID: " . $config['config_value'] . "\n";
    
    // 检查模板是否存在
    $template = Db::queryOne("SELECT id, name, status FROM form_templates WHERE id = ?", [$config['config_value']]);
    if ($template) {
        echo "模板名称: " . $template['name'] . "\n";
        echo "模板状态: " . $template['status'] . "\n";
    } else {
        echo "警告: 模板不存在!\n";
    }
} else {
    echo "警告: 未配置默认评价模板!\n";
    echo "请在后台 表单模板 中设置默认评价模板\n";
}

// 检查项目142的评价表单
echo "\n=== 检查项目142的评价表单 ===\n";
$form = Db::queryOne("SELECT * FROM form_instances WHERE project_id = 142 AND purpose = 'evaluation'");
if ($form) {
    echo "评价表单ID: " . $form['id'] . "\n";
    echo "表单名称: " . $form['instance_name'] . "\n";
    echo "状态: " . $form['status'] . "\n";
    echo "填写令牌: " . $form['fill_token'] . "\n";
} else {
    echo "项目142没有评价表单\n";
}

// 列出所有表单模板
echo "\n=== 可用表单模板 ===\n";
$templates = Db::query("SELECT id, name, status FROM form_templates WHERE deleted_at IS NULL");
foreach ($templates as $t) {
    echo "  [{$t['id']}] {$t['name']} ({$t['status']})\n";
}
