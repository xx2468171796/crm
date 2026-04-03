<?php
require_once __DIR__ . '/../core/db.php';

echo "=== 检查评价表单模板 ===\n";

// 获取模板
$template = Db::queryOne("SELECT * FROM form_templates WHERE id = 4");
echo "模板名称: {$template['name']}\n";
echo "模板状态: {$template['status']}\n";
echo "当前版本ID: {$template['current_version_id']}\n";

// 获取版本信息
$version = Db::queryOne("SELECT * FROM form_template_versions WHERE id = ?", [$template['current_version_id']]);
if ($version) {
    echo "\n版本号: {$version['version_number']}\n";
    echo "schema_json:\n";
    $schema = json_decode($version['schema_json'], true);
    print_r($schema);
} else {
    echo "\n警告: 没有找到版本信息!\n";
}

// 检查表单实例
echo "\n=== 检查表单实例 159 ===\n";
$instance = Db::queryOne("SELECT * FROM form_instances WHERE id = 159");
if ($instance) {
    echo "实例名称: {$instance['instance_name']}\n";
    echo "模板版本ID: {$instance['template_version_id']}\n";
    echo "状态: {$instance['status']}\n";
}
