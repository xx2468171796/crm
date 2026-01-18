<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * OKR 容器管理 API
 * 提供 OKR 容器的增删改查功能
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/okr_permission.php';

// 检查登录
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$user = current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            getContainerList();
            break;
            
        case 'get':
            getContainer();
            break;
            
        case 'save':
            saveContainer();
            break;
            
        case 'delete':
            deleteContainer();
            break;
            
        case 'detail':
            getContainerDetail();
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
 * 获取 OKR 容器列表
 */
function getContainerList() {
    global $user;
    
    $cycleId = intval($_GET['cycle_id'] ?? 0);
    $level = $_GET['level'] ?? ''; // company/department/personal
    $userId = intval($_GET['user_id'] ?? 0);
    
    $sql = "SELECT c.*, 
                   u.realname as user_name,
                   d.name as department_name,
                   cy.name as cycle_name,
                   cy.start_date as cycle_start,
                   cy.end_date as cycle_end
            FROM okr_containers c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN departments d ON c.department_id = d.id
            LEFT JOIN okr_cycles cy ON c.cycle_id = cy.id
            WHERE 1=1";
    $params = [];
    
    if ($cycleId > 0) {
        $sql .= " AND c.cycle_id = :cycle_id";
        $params['cycle_id'] = $cycleId;
    }
    
    if ($level) {
        $sql .= " AND c.level = :level";
        $params['level'] = $level;
    }
    
    if ($userId > 0) {
        $sql .= " AND c.user_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    // 权限过滤
    $whereClause = buildOkrContainerWhereClause($user, 'c');
    $sql .= $whereClause;
    
    $sql .= " ORDER BY c.level DESC, c.create_time DESC";
    
    $containers = Db::query($sql, $params);
    
    // 计算每个容器的进度（从目标计算）
    foreach ($containers as &$container) {
        $objectives = Db::query(
            'SELECT * FROM okr_objectives WHERE container_id = :container_id ORDER BY sort_order ASC',
            ['container_id' => $container['id']]
        );
        
        if (count($objectives) > 0) {
            $totalProgress = 0;
            foreach ($objectives as $obj) {
                $totalProgress += floatval($obj['progress']);
            }
            $container['progress'] = round($totalProgress / count($objectives), 2);
        } else {
            $container['progress'] = 0;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $containers
    ]);
}

/**
 * 获取单个 OKR 容器
 */
function getContainer() {
    global $user;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('容器ID无效');
    }
    
    $container = Db::queryOne(
        'SELECT c.*, 
                u.realname as user_name,
                d.name as department_name,
                cy.name as cycle_name
         FROM okr_containers c
         LEFT JOIN users u ON c.user_id = u.id
         LEFT JOIN departments d ON c.department_id = d.id
         LEFT JOIN okr_cycles cy ON c.cycle_id = cy.id
         WHERE c.id = :id',
        ['id' => $id]
    );
    
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    // 权限检查
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限访问此容器');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $container
    ]);
}

/**
 * 获取 OKR 容器详情（包含目标和 KR）
 */
function getContainerDetail() {
    global $user;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('容器ID无效');
    }
    
    $container = Db::queryOne(
        'SELECT c.*, 
                u.realname as user_name,
                d.name as department_name,
                cy.name as cycle_name
         FROM okr_containers c
         LEFT JOIN users u ON c.user_id = u.id
         LEFT JOIN departments d ON c.department_id = d.id
         LEFT JOIN okr_cycles cy ON c.cycle_id = cy.id
         WHERE c.id = :id',
        ['id' => $id]
    );
    
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    // 权限检查
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限访问此容器');
    }
    
    // 获取目标列表
    $objectives = Db::query(
        'SELECT * FROM okr_objectives WHERE container_id = :container_id ORDER BY sort_order ASC',
        ['container_id' => $id]
    );
    
    // 获取每个目标的 KR
    foreach ($objectives as &$obj) {
        $krs = Db::query(
            'SELECT * FROM okr_key_results WHERE objective_id = :objective_id ORDER BY sort_order ASC',
            ['objective_id' => $obj['id']]
        );
        
        // 解析负责人 IDs
        foreach ($krs as &$kr) {
            $kr['owner_user_ids'] = json_decode($kr['owner_user_ids'] ?? '[]', true) ?: [];
        }
        
        $obj['key_results'] = $krs;
    }
    
    $container['objectives'] = $objectives;
    
    echo json_encode([
        'success' => true,
        'data' => $container
    ]);
}

/**
 * 保存 OKR 容器（新增或更新）
 */
function saveContainer() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $cycleId = intval($_POST['cycle_id'] ?? 0);
    $level = trim($_POST['level'] ?? 'personal');
    $userId = intval($_POST['user_id'] ?? $user['id']);
    $departmentId = intval($_POST['department_id'] ?? 0);
    
    if ($cycleId <= 0) {
        throw new Exception('周期ID无效');
    }
    
    if (!in_array($level, ['company', 'department', 'personal'])) {
        throw new Exception('层级无效');
    }
    
    // 权限检查
    if (!checkOkrContainerOperationPermission($user, null, $level, $departmentId > 0 ? $departmentId : null)) {
        throw new Exception('无权限创建此层级的 OKR');
    }
    
    // 验证周期存在
    $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $cycleId]);
    if (!$cycle) {
        throw new Exception('周期不存在');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $id]);
        if (!$container) {
            throw new Exception('容器不存在');
        }
        
        // 权限检查
        if (!checkOkrContainerOperationPermission($user, $container)) {
            throw new Exception('无权限编辑此容器');
        }
        
        Db::execute(
            'UPDATE okr_containers SET user_id = :user_id, level = :level, department_id = :department_id, update_time = :update_time WHERE id = :id',
            [
                'id' => $id,
                'user_id' => $userId,
                'level' => $level,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'update_time' => $now
            ]
        );
    } else {
        // 新增
        Db::execute(
            'INSERT INTO okr_containers (cycle_id, user_id, level, department_id, progress, status, create_user_id, create_time, update_time) VALUES (:cycle_id, :user_id, :level, :department_id, 0, 1, :create_user_id, :create_time, :update_time)',
            [
                'cycle_id' => $cycleId,
                'user_id' => $userId,
                'level' => $level,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'create_user_id' => $user['id'],
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
        
        // 记录操作日志
        logOkrAction('okr', $id, 'create', null, ['level' => $level, 'user_id' => $userId]);
    }
    
    $container = Db::queryOne(
        'SELECT c.*, 
                u.realname as user_name,
                d.name as department_name
         FROM okr_containers c
         LEFT JOIN users u ON c.user_id = u.id
         LEFT JOIN departments d ON c.department_id = d.id
         WHERE c.id = :id',
        ['id' => $id]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $container,
        'message' => $id > 0 ? '容器保存成功' : '容器创建成功'
    ]);
}

/**
 * 删除 OKR 容器
 */
function deleteContainer() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('容器ID无效');
    }
    
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $id]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    // 权限检查
    if (!checkOkrContainerOperationPermission($user, $container)) {
        throw new Exception('无权限删除此容器');
    }
    
    // 级联删除（由外键约束处理）
    Db::execute('DELETE FROM okr_containers WHERE id = :id', ['id' => $id]);
    
    // 记录操作日志
    logOkrAction('okr', $id, 'delete', $container, null);
    
    echo json_encode([
        'success' => true,
        'message' => '容器删除成功'
    ]);
}

/**
 * 记录 OKR 操作日志
 */
function logOkrAction($targetType, $targetId, $action, $oldValue = null, $newValue = null, $description = null) {
    global $user;
    
    Db::execute(
        'INSERT INTO okr_logs (target_type, target_id, user_id, action, old_value, new_value, description, create_time) VALUES (:target_type, :target_id, :user_id, :action, :old_value, :new_value, :description, :create_time)',
        [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'user_id' => $user['id'],
            'action' => $action,
            'old_value' => $oldValue ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $newValue ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'description' => $description,
            'create_time' => time()
        ]
    );
}

