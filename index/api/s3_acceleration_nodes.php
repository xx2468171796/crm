<?php
/**
 * S3 加速节点管理 API
 * 
 * 用于管理多区域加速代理节点，供桌面端选择使用
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 确保表存在
ensureTableExists();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// list 操作不需要登录（桌面端调用）
if ($action === 'list') {
    handleList();
    exit;
}

// 其他操作需要管理员权限
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

if (!in_array($user['role'], ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

switch ($action) {
    case 'list_all':
        handleListAll();
        break;
    case 'get':
        handleGet();
        break;
    case 'save':
        handleSave();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'toggle':
        handleToggle();
        break;
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
}

/**
 * 确保数据库表存在
 */
function ensureTableExists() {
    $tableExists = Db::queryOne("SHOW TABLES LIKE 's3_acceleration_nodes'");
    if (!$tableExists) {
        $sql = "CREATE TABLE IF NOT EXISTS s3_acceleration_nodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            node_name VARCHAR(100) NOT NULL COMMENT '节点名称',
            endpoint_url VARCHAR(500) NOT NULL COMMENT '加速端点URL',
            region_code VARCHAR(50) DEFAULT NULL COMMENT '区域代码',
            status TINYINT DEFAULT 1 COMMENT '1=启用 0=禁用',
            is_default TINYINT DEFAULT 0 COMMENT '是否默认节点',
            sort_order INT DEFAULT 0 COMMENT '排序',
            description TEXT COMMENT '备注说明',
            created_at INT,
            updated_at INT,
            INDEX idx_status (status),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Db::exec($sql);
    }
}

/**
 * 获取启用的节点列表（桌面端用，不需要登录）
 */
function handleList() {
    $nodes = Db::query(
        "SELECT id, node_name, endpoint_url, region_code, is_default 
         FROM s3_acceleration_nodes 
         WHERE status = 1 
         ORDER BY sort_order ASC, id ASC"
    );
    echo json_encode(['success' => true, 'data' => $nodes ?: []], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取所有节点列表（后台管理用）
 */
function handleListAll() {
    $nodes = Db::query(
        "SELECT * FROM s3_acceleration_nodes ORDER BY sort_order ASC, id ASC"
    );
    echo json_encode(['success' => true, 'data' => $nodes ?: []], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取单个节点
 */
function handleGet() {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID无效']);
        return;
    }
    
    $node = Db::queryOne("SELECT * FROM s3_acceleration_nodes WHERE id = ?", [$id]);
    if (!$node) {
        echo json_encode(['success' => false, 'message' => '节点不存在']);
        return;
    }
    
    echo json_encode(['success' => true, 'data' => $node], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存节点（新增或更新）
 */
function handleSave() {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $id = (int)($input['id'] ?? 0);
    $nodeName = trim($input['node_name'] ?? '');
    $endpointUrl = trim($input['endpoint_url'] ?? '');
    $regionCode = trim($input['region_code'] ?? '');
    $status = (int)($input['status'] ?? 1);
    $isDefault = (int)($input['is_default'] ?? 0);
    $sortOrder = (int)($input['sort_order'] ?? 0);
    $description = trim($input['description'] ?? '');
    
    if (empty($nodeName)) {
        echo json_encode(['success' => false, 'message' => '节点名称不能为空']);
        return;
    }
    
    if (empty($endpointUrl)) {
        echo json_encode(['success' => false, 'message' => '加速端点URL不能为空']);
        return;
    }
    
    // 验证URL格式
    if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'URL格式无效']);
        return;
    }
    
    // 如果设为默认，先取消其他默认
    if ($isDefault) {
        Db::exec("UPDATE s3_acceleration_nodes SET is_default = 0");
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        Db::exec(
            "UPDATE s3_acceleration_nodes SET 
                node_name = ?, endpoint_url = ?, region_code = ?, 
                status = ?, is_default = ?, sort_order = ?, 
                description = ?, updated_at = ?
             WHERE id = ?",
            [$nodeName, $endpointUrl, $regionCode ?: null, $status, $isDefault, $sortOrder, $description ?: null, $now, $id]
        );
        echo json_encode(['success' => true, 'message' => '更新成功', 'id' => $id], JSON_UNESCAPED_UNICODE);
    } else {
        // 新增
        Db::exec(
            "INSERT INTO s3_acceleration_nodes 
                (node_name, endpoint_url, region_code, status, is_default, sort_order, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$nodeName, $endpointUrl, $regionCode ?: null, $status, $isDefault, $sortOrder, $description ?: null, $now, $now]
        );
        $newId = Db::lastInsertId();
        echo json_encode(['success' => true, 'message' => '添加成功', 'id' => $newId], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 删除节点
 */
function handleDelete() {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID无效']);
        return;
    }
    
    Db::exec("DELETE FROM s3_acceleration_nodes WHERE id = ?", [$id]);
    echo json_encode(['success' => true, 'message' => '删除成功'], JSON_UNESCAPED_UNICODE);
}

/**
 * 切换启用状态
 */
function handleToggle() {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID无效']);
        return;
    }
    
    Db::exec(
        "UPDATE s3_acceleration_nodes SET status = 1 - status, updated_at = ? WHERE id = ?",
        [time(), $id]
    );
    echo json_encode(['success' => true, 'message' => '状态已切换'], JSON_UNESCAPED_UNICODE);
}
