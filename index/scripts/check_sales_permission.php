<?php
/**
 * 检查并修复销售角色的合同创建权限
 */
require_once __DIR__ . '/../core/db.php';

echo "检查 sales 角色权限...\n";

$role = Db::queryOne("SELECT id, code, name, permissions FROM roles WHERE code = 'sales'");

if (!$role) {
    echo "未找到 sales 角色\n";
    exit(1);
}

echo "角色: {$role['name']} (code: {$role['code']})\n";
echo "当前权限: {$role['permissions']}\n";

$permissions = json_decode($role['permissions'] ?? '[]', true) ?: [];

if (in_array('contract_create', $permissions)) {
    echo "\n✓ 已有 contract_create 权限\n";
} else {
    echo "\n✗ 缺少 contract_create 权限，正在添加...\n";
    $permissions[] = 'contract_create';
    $newPermissions = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    
    Db::execute("UPDATE roles SET permissions = :perms WHERE id = :id", [
        'perms' => $newPermissions,
        'id' => $role['id']
    ]);
    
    echo "✓ 已添加 contract_create 权限\n";
    echo "新权限: {$newPermissions}\n";
}

echo "\n完成\n";
