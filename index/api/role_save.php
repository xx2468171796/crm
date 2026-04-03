<?php
require_once __DIR__ . '/../core/api_init.php';
// 角色保存API
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json');

auth_require();
$currentUser = current_user();

if (!canOrAdmin(PermissionCode::ROLE_MANAGE)) {
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

try {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $permissions = $_POST['permissions'] ?? [];
    
    if (empty($name) || empty($code)) {
        throw new Exception('角色名称和代码不能为空');
    }
    
    $permissionsJson = json_encode($permissions);
    $now = time();
    
    if ($id > 0) {
        // 更新角色
        Db::execute('UPDATE roles SET name = :name, description = :description, 
                     permissions = :permissions, update_time = :now WHERE id = :id', [
            'name' => $name,
            'description' => $description,
            'permissions' => $permissionsJson,
            'now' => $now,
            'id' => $id
        ]);
        
        echo json_encode(['success' => true, 'message' => '角色更新成功']);
    } else {
        // 新增角色
        $existing = Db::queryOne('SELECT id FROM roles WHERE code = :code', ['code' => $code]);
        if ($existing) {
            throw new Exception('角色代码已存在');
        }
        
        Db::execute('INSERT INTO roles (name, code, description, permissions, create_time, update_time) 
                     VALUES (:name, :code, :description, :permissions, :now, :now)', [
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'permissions' => $permissionsJson,
            'now' => $now
        ]);
        
        echo json_encode(['success' => true, 'message' => '角色添加成功']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
