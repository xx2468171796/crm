<?php
/**
 * 执行角色名称更新：技术 -> 设计师
 * 并查询所有角色
 */
require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 更新角色名称
    $affected = Db::execute(
        "UPDATE roles SET name = '设计师', description = '设计师，只能访问技术资源模块' WHERE code = 'tech'"
    );
    
    // 查询所有角色
    $allRoles = Db::query("SELECT id, code, name, description FROM roles ORDER BY id");
    
    echo json_encode([
        'success' => true,
        'affected' => $affected,
        'all_roles' => $allRoles
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
