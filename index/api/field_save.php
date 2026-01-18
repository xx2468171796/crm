<?php
require_once __DIR__ . '/../core/api_init.php';
// 维度保存API
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json');

auth_require();
$currentUser = current_user();

if (!canOrAdmin(PermissionCode::FIELD_MANAGE)) {
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

try {
    $id = intval($_POST['id'] ?? 0);
    $dimensionName = trim($_POST['field_name'] ?? '');
    $dimensionCode = trim($_POST['field_code'] ?? '');
    $menuId = intval($_POST['module_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $status = intval($_POST['status'] ?? 1);
    
    if (empty($dimensionName) || empty($dimensionCode)) {
        throw new Exception('维度名称和代码不能为空');
    }
    
    if ($menuId <= 0) {
        throw new Exception('菜单ID不能为空');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新维度
        Db::execute('UPDATE dimensions SET dimension_name = :name, dimension_code = :code, 
                     menu_id = :menu_id, description = :desc, sort_order = :sort, 
                     status = :status, update_time = :now WHERE id = :id', [
            'name' => $dimensionName,
            'code' => $dimensionCode,
            'menu_id' => $menuId,
            'desc' => $description,
            'sort' => $sortOrder,
            'status' => $status,
            'now' => $now,
            'id' => $id
        ]);
        
        echo json_encode(['success' => true, 'message' => '维度更新成功']);
    } else {
        // 新增维度
        $existing = Db::queryOne('SELECT id FROM dimensions WHERE dimension_code = :code', ['code' => $dimensionCode]);
        if ($existing) {
            throw new Exception('维度代码已存在');
        }
        
        Db::execute('INSERT INTO dimensions (dimension_name, dimension_code, menu_id, description, 
                     sort_order, status, create_time, update_time) 
                     VALUES (:name, :code, :menu_id, :desc, :sort, :status, :now, :now)', [
            'name' => $dimensionName,
            'code' => $dimensionCode,
            'menu_id' => $menuId,
            'desc' => $description,
            'sort' => $sortOrder,
            'status' => $status,
            'now' => $now
        ]);
        
        echo json_encode(['success' => true, 'message' => '维度添加成功']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
