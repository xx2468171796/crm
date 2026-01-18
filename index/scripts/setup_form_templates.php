<?php
/**
 * 表单模板配置脚本
 * 1. 查看现有模板
 * 2. 配置默认需求模板
 * 3. 为现有项目创建需求表单实例
 */

require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

echo "=== 表单模板配置脚本 ===\n\n";

// 1. 查看现有表单模板
echo "【1. 现有表单模板】\n";
$stmt = $pdo->query("SELECT id, name, form_type, status FROM form_templates WHERE deleted_at IS NULL ORDER BY id");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($templates)) {
    echo "没有找到表单模板！\n";
} else {
    foreach ($templates as $t) {
        echo "  ID: {$t['id']} | 名称: {$t['name']} | 类型: " . ($t['form_type'] ?: '未设置') . " | 状态: {$t['status']}\n";
    }
}

// 2. 查看系统配置
echo "\n【2. 当前系统配置】\n";
$configStmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE config_key LIKE '%template%'");
$configs = $configStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($configs)) {
    echo "  没有找到模板相关配置\n";
} else {
    foreach ($configs as $c) {
        echo "  {$c['config_key']}: {$c['config_value']}\n";
    }
}

// 3. 查看现有表单实例
echo "\n【3. 现有表单实例（按purpose分组）】\n";
$instanceStmt = $pdo->query("
    SELECT purpose, COUNT(*) as cnt 
    FROM form_instances 
    GROUP BY purpose
");
$instances = $instanceStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($instances)) {
    echo "  没有表单实例\n";
} else {
    foreach ($instances as $i) {
        echo "  {$i['purpose']}: {$i['cnt']} 个\n";
    }
}

// 4. 查看项目数量
echo "\n【4. 项目统计】\n";
$projectStmt = $pdo->query("SELECT COUNT(*) as cnt FROM projects WHERE deleted_at IS NULL");
$projectCount = $projectStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "  总项目数: {$projectCount}\n";

// 5. 查看没有需求表单的项目
echo "\n【5. 没有需求表单的项目】\n";
$noReqStmt = $pdo->query("
    SELECT p.id, p.project_name 
    FROM projects p 
    WHERE p.deleted_at IS NULL 
    AND NOT EXISTS (SELECT 1 FROM form_instances fi WHERE fi.project_id = p.id AND fi.purpose = 'requirement')
    LIMIT 10
");
$noReqProjects = $noReqStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($noReqProjects)) {
    echo "  所有项目都有需求表单\n";
} else {
    foreach ($noReqProjects as $p) {
        echo "  ID: {$p['id']} | {$p['project_name']}\n";
    }
    if (count($noReqProjects) >= 10) {
        echo "  ... (还有更多)\n";
    }
}

echo "\n=== 完成 ===\n";
