<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 模块管理API
 * 提供模块的增删改查功能
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
            // 获取模块列表
            getModuleList();
            break;
            
        case 'get':
            // 获取单个模块
            getModule();
            break;
            
        case 'add':
            // 添加模块
            addModule();
            break;
            
        case 'edit':
            // 编辑模块
            editModule();
            break;
            
        case 'delete':
            // 删除模块
            deleteModule();
            break;
            
        case 'sort':
            // 更新排序
            updateSort();
            break;
            
        case 'toggle_status':
            // 切换状态
            toggleStatus();
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
 * 获取模块列表
 */
function getModuleList() {
    try {
        $sql = 'SELECT 
                    m.*,
                    m.menu_name as module_name,
                    m.menu_code as module_code,
                    COUNT(d.id) as field_count
                FROM menus m
                LEFT JOIN dimensions d ON m.id = d.menu_id AND d.status = 1
                GROUP BY m.id
                ORDER BY m.sort_order, m.id';
        
        $modules = Db::query($sql);
        
        // 清除之前的输出
        ob_clean();
        
        echo json_encode([
            'success' => true,
            'data' => $modules,
            'count' => count($modules)
        ]);
        
        exit;
    } catch (Exception $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '查询失败: ' . $e->getMessage()
        ]);
        exit;
    }
}

/**
 * 获取单个模块
 */
function getModule() {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('模块ID无效');
    }
    
    $module = Db::queryOne('SELECT * FROM menus WHERE id = ?', [$id]);
    
    if (!$module) {
        throw new Exception('模块不存在');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $module
    ]);
}

/**
 * 添加模块
 */
function addModule() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 验证必填字段
    if (empty($data['module_name'])) {
        throw new Exception('模块名称不能为空');
    }
    
    if (empty($data['module_code'])) {
        throw new Exception('模块代码不能为空');
    }
    
    // 验证模块代码格式（只允许小写字母和下划线）
    if (!preg_match('/^[a-z_]+$/', $data['module_code'])) {
        throw new Exception('模块代码只能包含小写字母和下划线');
    }
    
    // 检查菜单代码是否已存在
    $exists = Db::queryOne('SELECT id FROM menus WHERE menu_code = ?', [$data['module_code']]);
    if ($exists) {
        throw new Exception('模块代码已存在');
    }
    
    // 获取最大排序号
    $maxSort = Db::queryOne('SELECT MAX(sort_order) as max_sort FROM menus');
    $sortOrder = ($maxSort['max_sort'] ?? 0) + 1;
    
    // 插入数据
    $sql = 'INSERT INTO menus (menu_name, menu_code, menu_icon, description, sort_order, status, create_time, update_time) 
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)';
    
    $params = [
        $data['module_name'],
        $data['module_code'],
        $data['module_icon'] ?? null,
        $data['description'] ?? null,
        $sortOrder,
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
 * 编辑模块
 */
function editModule() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('模块ID无效');
    }
    
    // 检查模块是否存在
    $module = Db::queryOne('SELECT * FROM menus WHERE id = ?', [$id]);
    if (!$module) {
        throw new Exception('模块不存在');
    }
    
    // 验证必填字段
    if (empty($data['module_name'])) {
        throw new Exception('模块名称不能为空');
    }
    
    if (empty($data['module_code'])) {
        throw new Exception('模块代码不能为空');
    }
    
    // 验证模块代码格式
    if (!preg_match('/^[a-z_]+$/', $data['module_code'])) {
        throw new Exception('模块代码只能包含小写字母和下划线');
    }
    
    // 检查菜单代码是否与其他菜单重复
    $exists = Db::queryOne('SELECT id FROM menus WHERE menu_code = ? AND id != ?', [$data['module_code'], $id]);
    if ($exists) {
        throw new Exception('模块代码已存在');
    }
    
    // 更新数据
    $sql = 'UPDATE menus 
            SET menu_name = ?, menu_code = ?, menu_icon = ?, description = ?, update_time = ?
            WHERE id = ?';
    
    $params = [
        $data['module_name'],
        $data['module_code'],
        $data['module_icon'] ?? null,
        $data['description'] ?? null,
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
 * 删除模块
 */
function deleteModule() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('模块ID无效');
    }
    
    // 检查菜单是否存在
    $module = Db::queryOne('SELECT * FROM menus WHERE id = ?', [$id]);
    if (!$module) {
        throw new Exception('菜单不存在');
    }
    
    // 检查菜单下是否有维度
    $fieldCount = Db::queryOne('SELECT COUNT(*) as count FROM dimensions WHERE menu_id = ?', [$id]);
    if ($fieldCount['count'] > 0) {
        throw new Exception('该模块下还有 ' . $fieldCount['count'] . ' 个维度，无法删除。请先删除或移动这些维度。');
    }
    
    // 删除菜单
    Db::execute('DELETE FROM menus WHERE id = ?', [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => '删除成功'
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
            Db::execute('UPDATE menus SET sort_order = ?, update_time = ? WHERE id = ?', [$sortOrder, time(), $id]);
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

/**
 * 切换状态
 */
function toggleStatus() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('模块ID无效');
    }
    
    // 检查菜单是否存在
    $module = Db::queryOne('SELECT * FROM menus WHERE id = ?', [$id]);
    if (!$module) {
        throw new Exception('菜单不存在');
    }
    
    // 切换状态
    $newStatus = $module['status'] == 1 ? 0 : 1;
    Db::execute('UPDATE menus SET status = ?, update_time = ? WHERE id = ?', [$newStatus, time(), $id]);
    
    echo json_encode([
        'success' => true,
        'message' => '状态更新成功',
        'data' => ['status' => $newStatus]
    ]);
}
