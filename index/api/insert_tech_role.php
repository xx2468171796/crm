<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

// 检查tech角色是否存在
$exists = Db::queryOne('SELECT id FROM roles WHERE code = "tech"');
if (!$exists) {
    // 插入设计师角色
    Db::execute("INSERT INTO roles (code, name, description, permissions, is_system, create_time, update_time) VALUES ('tech', '设计师', '设计师，只能访问技术资源模块', '[\"tech_resource_view\",\"tech_resource_edit\"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
    echo "已插入设计师角色\n";
} else {
    // 更新名称
    Db::execute("UPDATE roles SET name = '设计师', description = '设计师，只能访问技术资源模块' WHERE code = 'tech'");
    echo "已更新设计师角色\n";
}

// 显示所有角色
$roles = Db::query('SELECT id, code, name FROM roles ORDER BY id');
foreach ($roles as $r) {
    echo $r['id'] . ' | ' . $r['code'] . ' | ' . $r['name'] . "\n";
}
