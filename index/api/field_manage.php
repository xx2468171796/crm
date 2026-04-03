<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 维度管理API
 * 提供维度（字段）的增删改查功能
 */

// 清除任何之前的输出缓冲
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 检查登录
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 检查管理员权限
$user = current_user();
if (!canOrAdmin(PermissionCode::FIELD_MANAGE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // 获取维度列表
            getFieldList();
            break;
            
        case 'get':
            // 获取单个维度
            getField();
            break;
            
        case 'add':
            // 添加维度
            addField();
            break;
            
        case 'edit':
            // 编辑维度
            editField();
            break;
            
        case 'delete':
            // 删除维度
            deleteField();
            break;
            
        case 'move':
            // 移动到其他模块
            moveField();
            break;
            
        case 'sort':
            // 更新排序
            updateSort();
            break;
            
        default:
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 获取维度列表
 */
function getFieldList() {
    $moduleId = intval($_GET['module_id'] ?? 0);
    
    $sql = 'SELECT 
                d.*,
                d.dimension_name as field_name,
                d.dimension_code as field_code,
                d.menu_id as module_id,
                m.menu_name as module_name,
                m.menu_code as module_code,
                COUNT(f.id) as option_count
            FROM dimensions d
            LEFT JOIN menus m ON d.menu_id = m.id
            LEFT JOIN fields f ON d.id = f.dimension_id AND f.status = 1
            WHERE 1=1';
    
    $params = [];
    
    if ($moduleId > 0) {
        $sql .= ' AND d.menu_id = ?';
        $params[] = $moduleId;
    }
    
    $sql .= ' GROUP BY d.id
              ORDER BY d.sort_order';
    
    $fields = Db::query($sql, $params);
    
    echo json_encode([
        'success' => true,
        'data' => $fields
    ]);
}

/**
 * 获取单个维度
 */
function getField() {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('维度ID无效');
    }
    
    $field = Db::queryOne('SELECT 
                                d.*,
                                d.dimension_name as field_name,
                                d.dimension_code as field_code,
                                d.menu_id as module_id
                            FROM dimensions d 
                            WHERE d.id = ?', [$id]);
    
    if (!$field) {
        throw new Exception('维度不存在');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $field
    ]);
}

/**
 * 添加维度
 */
function addField() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 验证必填字段
    if (empty($data['field_name'])) {
        throw new Exception('维度名称不能为空');
    }
    
    if (empty($data['field_code'])) {
        throw new Exception('维度代码不能为空');
    }
    
    // 注意：维度（Dimension）不需要 field_type，field_type 是维度下的字段（Field）才需要的
    
    // 验证维度代码格式
    if (!preg_match('/^[a-z_]+$/', $data['field_code'])) {
        throw new Exception('维度代码只能包含小写字母和下划线');
    }
    
    // 检查维度代码是否已存在
    $exists = Db::queryOne('SELECT id FROM dimensions WHERE dimension_code = ?', [$data['field_code']]);
    if ($exists) {
        throw new Exception('维度代码已存在');
    }
    
    // 不再需要module_code字段
    
    // 插入数据
    $sql = 'INSERT INTO dimensions (
                menu_id, dimension_name, dimension_code, description,
                sort_order, status, create_time, update_time
            ) VALUES (?, ?, ?, ?, ?, 1, ?, ?)';
    
    $params = [
        $data['module_id'] ?? null,
        $data['field_name'],
        $data['field_code'],
        $data['description'] ?? null,
        $data['sort_order'] ?? 0,
        time(),
        time()
    ];
    
    Db::execute($sql, $params);
    $id = Db::lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => '添加成功',
        'data' => ['id' => $id]
    ]);
}

/**
 * 编辑维度
 */
function editField() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('维度ID无效');
    }
    
    // 检查维度是否存在
    $field = Db::queryOne('SELECT * FROM dimensions WHERE id = ?', [$id]);
    if (!$field) {
        throw new Exception('维度不存在');
    }
    
    // 验证必填字段
    if (empty($data['field_name'])) {
        throw new Exception('维度名称不能为空');
    }
    
    if (empty($data['field_code'])) {
        throw new Exception('维度代码不能为空');
    }
    
    // 验证维度代码格式
    if (!preg_match('/^[a-z_]+$/', $data['field_code'])) {
        throw new Exception('维度代码只能包含小写字母和下划线');
    }
    
    // 检查维度代码是否与其他维度重复
    $exists = Db::queryOne('SELECT id FROM dimensions WHERE dimension_code = ? AND id != ?', [$data['field_code'], $id]);
    if ($exists) {
        throw new Exception('维度代码已存在');
    }
    
    // 不再需要module_code字段
    
    // 更新数据
    $sql = 'UPDATE dimensions SET
                menu_id = ?,
                dimension_name = ?,
                dimension_code = ?,
                description = ?,
                sort_order = ?,
                update_time = ?
            WHERE id = ?';
    
    $params = [
        $data['module_id'] ?? null,
        $data['field_name'],
        $data['field_code'],
        $data['description'] ?? null,
        $data['sort_order'] ?? 0,
        time(),
        $id
    ];
    
    Db::execute($sql, $params);
    
    echo json_encode([
        'success' => true,
        'message' => '更新成功'
    ]);
}

/**
 * 删除维度
 */
function deleteField() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('维度ID无效');
    }
    
    // 检查维度是否存在
    $field = Db::queryOne('SELECT * FROM dimensions WHERE id = ?', [$id]);
    if (!$field) {
        throw new Exception('维度不存在');
    }
    
    // 级联删除：先删除该维度下的所有字段（由数据库外键级联删除处理）
    $fieldCount = Db::queryOne('SELECT COUNT(*) as count FROM fields WHERE dimension_id = ?', [$id]);
    if ($fieldCount['count'] > 0) {
        // 由于设置了 ON DELETE CASCADE，会自动删除
        // Db::execute('DELETE FROM fields WHERE dimension_id = ?', [$id]);
    }
    
    // 删除维度
    Db::execute('DELETE FROM dimensions WHERE id = ?', [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => '删除成功'
    ]);
}

/**
 * 移动维度到其他模块
 */
function moveField() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    $targetModuleId = intval($data['target_module_id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('维度ID无效');
    }
    
    if ($targetModuleId <= 0) {
        throw new Exception('目标模块ID无效');
    }
    
    // 检查维度是否存在
    $field = Db::queryOne('SELECT * FROM dimensions WHERE id = ?', [$id]);
    if (!$field) {
        throw new Exception('维度不存在');
    }
    
    // 检查目标菜单是否存在
    $module = Db::queryOne('SELECT * FROM menus WHERE id = ?', [$targetModuleId]);
    if (!$module) {
        throw new Exception('目标模块不存在');
    }
    
    // 移动维度
    Db::execute('UPDATE dimensions SET menu_id = ?, update_time = ? WHERE id = ?', [$targetModuleId, time(), $id]);
    
    echo json_encode([
        'success' => true,
        'message' => '移动成功'
    ]);
}

/**
 * 更新排序
 */
function updateSort() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['orders']) || !is_array($data['orders'])) {
        throw new Exception('排序数据无效');
    }
    
    // 开始事务
    Db::beginTransaction();
    
    try {
        foreach ($data['orders'] as $index => $id) {
            $sortOrder = $index + 1;
            
            if ($id > 0) {
                Db::execute(
                    'UPDATE dimensions SET sort_order = ?, update_time = ? WHERE id = ?',
                    [$sortOrder, time(), $id]
                );
            }
        }
        
        Db::commit();
        
        echo json_encode([
            'success' => true,
            'message' => '排序更新成功'
        ]);
    } catch (Exception $e) {
        Db::rollback();
        throw $e;
    }
}
