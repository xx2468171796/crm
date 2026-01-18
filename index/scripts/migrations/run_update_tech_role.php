<?php
/**
 * 执行角色名称更新：技术 -> 设计师
 */

require_once __DIR__ . '/../../core/db.php';

try {
    // 更新角色名称
    $affected = Db::execute(
        "UPDATE roles SET name = '设计师', description = '设计师，只能访问技术资源模块' WHERE code = 'tech'"
    );
    
    echo "更新完成，影响 {$affected} 行\n";
    
    // 验证结果
    $role = Db::queryOne("SELECT code, name, description FROM roles WHERE code = 'tech'");
    if ($role) {
        echo "验证结果：\n";
        echo "  code: {$role['code']}\n";
        echo "  name: {$role['name']}\n";
        echo "  description: {$role['description']}\n";
    } else {
        echo "未找到 tech 角色\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
