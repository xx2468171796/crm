<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * OKR 任务管理 API
 * 提供任务的增删改查、状态更新、关联、协助人等功能
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/okr_permission.php';
require_once __DIR__ . '/../core/okr_progress.php';

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
            getTaskList();
            break;
            
        case 'get':
            getTask();
            break;
            
        case 'save':
            saveTask();
            break;
            
        case 'delete':
            deleteTask();
            break;
            
        case 'update_status':
            updateTaskStatus();
            break;
            
        case 'add_assistant':
            addTaskAssistant();
            break;
            
        case 'remove_assistant':
            removeTaskAssistant();
            break;
            
        case 'add_relation':
            addTaskRelation();
            break;
            
        case 'remove_relation':
            removeTaskRelation();
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
 * 获取任务列表
 */
function getTaskList() {
    global $user;
    
    $filter = $_GET['filter'] ?? 'all'; // all/my/my_responsible/my_assigned/my_participate/my_dept
    $status = $_GET['status'] ?? ''; // pending/in_progress/completed/failed
    $priority = $_GET['priority'] ?? ''; // low/medium/high
    $relationType = $_GET['relation_type'] ?? ''; // okr/objective/kr/customer
    $relationId = intval($_GET['relation_id'] ?? 0);
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    
    $sql = "SELECT t.*, 
                   u1.name as executor_name,
                   u2.name as assigner_name,
                   d.name as department_name
            FROM okr_tasks t
            LEFT JOIN users u1 ON t.executor_id = u1.id
            LEFT JOIN users u2 ON t.assigner_id = u2.id
            LEFT JOIN departments d ON t.department_id = d.id
            WHERE 1=1";
    $params = [];
    
    // 权限过滤
    $whereClause = buildOkrTaskWhereClause($user, 't');
    $sql .= $whereClause;
    
    // 筛选条件
    if ($filter === 'my') {
        // 我的任务：我负责的 + 我分配的 + 我参与的
        $sql .= " AND (t.executor_id = :user_id OR t.assigner_id = :user_id OR EXISTS (SELECT 1 FROM okr_task_assistants ta WHERE ta.task_id = t.id AND ta.user_id = :user_id))";
        $params['user_id'] = $user['id'];
    } elseif ($filter === 'my_responsible') {
        $sql .= " AND t.executor_id = :user_id";
        $params['user_id'] = $user['id'];
    } elseif ($filter === 'my_assigned') {
        $sql .= " AND t.assigner_id = :user_id";
        $params['user_id'] = $user['id'];
    } elseif ($filter === 'my_participate') {
        $sql .= " AND EXISTS (SELECT 1 FROM okr_task_assistants ta WHERE ta.task_id = t.id AND ta.user_id = :user_id)";
        $params['user_id'] = $user['id'];
    } elseif ($filter === 'my_dept') {
        if ($user['role'] === 'dept_admin' && !empty($user['department_id'])) {
            $sql .= " AND t.department_id = :dept_id";
            $params['dept_id'] = $user['department_id'];
        } else {
            $sql .= " AND 1=0"; // 无权限
        }
    }
    // 'all' 不添加额外条件
    
    if ($status) {
        $sql .= " AND t.status = :status";
        $params['status'] = $status;
    }
    
    if ($priority) {
        $sql .= " AND t.priority = :priority";
        $params['priority'] = $priority;
    }
    
    if ($relationType && $relationId > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM okr_task_relations tr WHERE tr.task_id = t.id AND tr.relation_type = :relation_type AND tr.relation_id = :relation_id)";
        $params['relation_type'] = $relationType;
        $params['relation_id'] = $relationId;
    }
    
    if ($startDate) {
        $sql .= " AND t.due_date >= :start_date";
        $params['start_date'] = $startDate;
    }
    
    if ($endDate) {
        $sql .= " AND t.due_date <= :end_date";
        $params['end_date'] = $endDate;
    }
    
    $sql .= " ORDER BY t.priority DESC, t.due_date ASC, t.create_time DESC";
    
    $tasks = Db::query($sql, $params);
    
    // 获取每个任务的协助人和关联
    foreach ($tasks as &$task) {
        // 协助人
        $assistants = Db::query(
            'SELECT ta.user_id, u.realname as user_name FROM okr_task_assistants ta LEFT JOIN users u ON ta.user_id = u.id WHERE ta.task_id = :task_id',
            ['task_id' => $task['id']]
        );
        $task['assistants'] = $assistants;
        
        // 关联
        $relations = Db::query(
            'SELECT * FROM okr_task_relations WHERE task_id = :task_id',
            ['task_id' => $task['id']]
        );
        $task['relations'] = $relations;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $tasks
    ]);
}

/**
 * 获取单个任务
 */
function getTask() {
    global $user;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('任务ID无效');
    }
    
    $task = Db::queryOne(
        'SELECT t.*, 
                u1.name as executor_name,
                u2.name as assigner_name,
                d.name as department_name
         FROM okr_tasks t
         LEFT JOIN users u1 ON t.executor_id = u1.id
         LEFT JOIN users u2 ON t.assigner_id = u2.id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.id = :id',
        ['id' => $id]
    );
    
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskPermission($user, $task)) {
        throw new Exception('无权限访问此任务');
    }
    
    // 协助人
    $assistants = Db::query(
        'SELECT ta.user_id, u.realname as user_name FROM okr_task_assistants ta LEFT JOIN users u ON ta.user_id = u.id WHERE ta.task_id = :task_id',
        ['task_id' => $id]
    );
    $task['assistants'] = $assistants;
    
    // 关联
    $relations = Db::query(
        'SELECT * FROM okr_task_relations WHERE task_id = :task_id',
        ['task_id' => $id]
    );
    $task['relations'] = $relations;
    
    echo json_encode([
        'success' => true,
        'data' => $task
    ]);
}

/**
 * 保存任务（新增或更新）
 */
function saveTask() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $level = trim($_POST['level'] ?? 'personal');
    $priority = trim($_POST['priority'] ?? 'medium');
    $status = trim($_POST['status'] ?? 'pending');
    $startDate = trim($_POST['start_date'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');
    $executorId = intval($_POST['executor_id'] ?? $user['id']);
    $assignerId = intval($_POST['assigner_id'] ?? 0);
    $departmentId = intval($_POST['department_id'] ?? 0);
    $assistantIds = $_POST['assistant_ids'] ?? [];
    $relations = $_POST['relations'] ?? [];
    
    if (empty($title)) {
        throw new Exception('任务标题不能为空');
    }
    
    if (!in_array($level, ['company', 'department', 'employee', 'personal'])) {
        throw new Exception('层级无效');
    }
    
    if (!in_array($priority, ['low', 'medium', 'high'])) {
        throw new Exception('优先级无效');
    }
    
    if (!in_array($status, ['pending', 'in_progress', 'completed', 'failed'])) {
        throw new Exception('状态无效');
    }
    
    // 处理协助人 IDs
    if (is_string($assistantIds)) {
        $assistantIds = json_decode($assistantIds, true) ?: [];
    }
    if (!is_array($assistantIds)) {
        $assistantIds = [];
    }
    
    // 处理关联
    if (is_string($relations)) {
        $relations = json_decode($relations, true) ?: [];
    }
    if (!is_array($relations)) {
        $relations = [];
    }
    
    $now = time();
    $sourceType = $assignerId > 0 && $assignerId != $user['id'] ? 'assigned' : 'self';
    
    if ($id > 0) {
        // 更新
        $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $id]);
        if (!$task) {
            throw new Exception('任务不存在');
        }
        
        // 权限检查
        if (!checkOkrTaskOperationPermission($user, $task)) {
            throw new Exception('无权限编辑此任务');
        }
        
        $oldValue = $task;
        
        $completedAt = null;
        if ($status === 'completed' && $task['status'] !== 'completed') {
            $completedAt = $now;
        } elseif ($status !== 'completed' && $task['status'] === 'completed') {
            $completedAt = null;
        } else {
            $completedAt = $task['completed_at'];
        }
        
        Db::execute(
            'UPDATE okr_tasks SET title = :title, description = :description, level = :level, priority = :priority, status = :status, start_date = :start_date, due_date = :due_date, executor_id = :executor_id, assigner_id = :assigner_id, department_id = :department_id, completed_at = :completed_at, update_time = :update_time WHERE id = :id',
            [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'level' => $level,
                'priority' => $priority,
                'status' => $status,
                'start_date' => $startDate ?: null,
                'due_date' => $dueDate ?: null,
                'executor_id' => $executorId,
                'assigner_id' => $assignerId > 0 ? $assignerId : null,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'completed_at' => $completedAt,
                'update_time' => $now
            ]
        );
        
        // 更新协助人
        Db::execute('DELETE FROM okr_task_assistants WHERE task_id = :task_id', ['task_id' => $id]);
        foreach ($assistantIds as $assistantId) {
            Db::execute(
                'INSERT INTO okr_task_assistants (task_id, user_id, create_time) VALUES (:task_id, :user_id, :create_time)',
                ['task_id' => $id, 'user_id' => intval($assistantId), 'create_time' => $now]
            );
        }
        
        // 更新关联
        Db::execute('DELETE FROM okr_task_relations WHERE task_id = :task_id', ['task_id' => $id]);
        foreach ($relations as $relation) {
            if (isset($relation['type']) && isset($relation['id'])) {
                Db::execute(
                    'INSERT INTO okr_task_relations (task_id, relation_type, relation_id, create_time) VALUES (:task_id, :relation_type, :relation_id, :create_time)',
                    [
                        'task_id' => $id,
                        'relation_type' => $relation['type'],
                        'relation_id' => intval($relation['id']),
                        'create_time' => $now
                    ]
                );
            }
        }
        
        // 记录操作日志
        logOkrAction('task', $id, 'update', $oldValue, ['title' => $title, 'status' => $status]);
    } else {
        // 新增
        Db::execute(
            'INSERT INTO okr_tasks (title, description, level, priority, status, start_date, due_date, executor_id, assigner_id, source_type, department_id, create_user_id, create_time, update_time) VALUES (:title, :description, :level, :priority, :status, :start_date, :due_date, :executor_id, :assigner_id, :source_type, :department_id, :create_user_id, :create_time, :update_time)',
            [
                'title' => $title,
                'description' => $description,
                'level' => $level,
                'priority' => $priority,
                'status' => $status,
                'start_date' => $startDate ?: null,
                'due_date' => $dueDate ?: null,
                'executor_id' => $executorId,
                'assigner_id' => $assignerId > 0 ? $assignerId : null,
                'source_type' => $sourceType,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'create_user_id' => $user['id'],
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
        
        // 添加协助人
        foreach ($assistantIds as $assistantId) {
            Db::execute(
                'INSERT INTO okr_task_assistants (task_id, user_id, create_time) VALUES (:task_id, :user_id, :create_time)',
                ['task_id' => $id, 'user_id' => intval($assistantId), 'create_time' => $now]
            );
        }
        
        // 添加关联
        foreach ($relations as $relation) {
            if (isset($relation['type']) && isset($relation['id'])) {
                Db::execute(
                    'INSERT INTO okr_task_relations (task_id, relation_type, relation_id, create_time) VALUES (:task_id, :relation_type, :relation_id, :create_time)',
                    [
                        'task_id' => $id,
                        'relation_type' => $relation['type'],
                        'relation_id' => intval($relation['id']),
                        'create_time' => $now
                    ]
                );
            }
        }
        
        // 记录操作日志
        logOkrAction('task', $id, 'create', null, ['title' => $title]);
    }
    
    $task = Db::queryOne(
        'SELECT t.*, 
                u1.name as executor_name,
                u2.name as assigner_name
         FROM okr_tasks t
         LEFT JOIN users u1 ON t.executor_id = u1.id
         LEFT JOIN users u2 ON t.assigner_id = u2.id
         WHERE t.id = :id',
        ['id' => $id]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $task,
        'message' => $id > 0 ? '任务保存成功' : '任务创建成功'
    ]);
}

/**
 * 删除任务
 */
function deleteTask() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('任务ID无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $id]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限删除此任务');
    }
    
    // 级联删除（由外键约束处理）
    Db::execute('DELETE FROM okr_tasks WHERE id = :id', ['id' => $id]);
    
    // 记录操作日志
    logOkrAction('task', $id, 'delete', $task, null);
    
    echo json_encode([
        'success' => true,
        'message' => '任务删除成功'
    ]);
}

/**
 * 更新任务状态
 */
function updateTaskStatus() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    
    if ($id <= 0) {
        throw new Exception('任务ID无效');
    }
    
    if (!in_array($status, ['pending', 'in_progress', 'completed', 'failed'])) {
        throw new Exception('状态无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $id]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限更新此任务');
    }
    
    $oldValue = $task;
    
    $completedAt = null;
    if ($status === 'completed' && $task['status'] !== 'completed') {
        $completedAt = time();
    } elseif ($status !== 'completed' && $task['status'] === 'completed') {
        $completedAt = null;
    } else {
        $completedAt = $task['completed_at'];
    }
    
    Db::execute(
        'UPDATE okr_tasks SET status = :status, completed_at = :completed_at, update_time = :update_time WHERE id = :id',
        [
            'id' => $id,
            'status' => $status,
            'completed_at' => $completedAt,
            'update_time' => time()
        ]
    );
    
    // 记录操作日志
    logOkrAction('task', $id, 'update_status', $oldValue, ['status' => $status]);
    
    // 如果关联了 KR，重新计算 KR 进度（任务模式）
    $relations = Db::query(
        'SELECT * FROM okr_task_relations WHERE task_id = :task_id AND relation_type = :relation_type',
        ['task_id' => $id, 'relation_type' => 'kr']
    );
    
    foreach ($relations as $relation) {
        $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $relation['relation_id']]);
        if ($kr && $kr['progress_mode'] === 'task') {
            // 重新计算任务模式的 KR 进度
            $completedTasks = Db::queryOne(
                'SELECT COUNT(*) as cnt FROM okr_tasks t INNER JOIN okr_task_relations tr ON t.id = tr.task_id WHERE tr.relation_type = :relation_type AND tr.relation_id = :relation_id AND t.status = :status',
                ['relation_type' => 'kr', 'relation_id' => $kr['id'], 'status' => 'completed']
            );
            $totalTasks = Db::queryOne(
                'SELECT COUNT(*) as cnt FROM okr_tasks t INNER JOIN okr_task_relations tr ON t.id = tr.task_id WHERE tr.relation_type = :relation_type AND tr.relation_id = :relation_id',
                ['relation_type' => 'kr', 'relation_id' => $kr['id']]
            );
            
            if ($totalTasks && $totalTasks['cnt'] > 0) {
                $progress = round(($completedTasks['cnt'] / $totalTasks['cnt']) * 100, 2);
                Db::execute(
                    'UPDATE okr_key_results SET progress = :progress, update_time = :update_time WHERE id = :id',
                    ['progress' => $progress, 'update_time' => time(), 'id' => $kr['id']]
                );
                
                // 重新计算目标进度
                $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $kr['objective_id']]);
                if ($objective) {
                    recalculateObjectiveProgress($objective['id']);
                    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $objective['container_id']]);
                    if ($container) {
                        recalculateContainerProgress($container['id']);
                    }
                }
            }
        }
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $id]);
    
    echo json_encode([
        'success' => true,
        'data' => $task,
        'message' => '状态更新成功'
    ]);
}

/**
 * 添加任务协助人
 */
function addTaskAssistant() {
    global $user;
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $userId = intval($_POST['user_id'] ?? 0);
    
    if ($taskId <= 0 || $userId <= 0) {
        throw new Exception('参数无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $taskId]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限操作此任务');
    }
    
    // 检查是否已存在
    $existing = Db::queryOne(
        'SELECT * FROM okr_task_assistants WHERE task_id = :task_id AND user_id = :user_id',
        ['task_id' => $taskId, 'user_id' => $userId]
    );
    
    if ($existing) {
        throw new Exception('该用户已是协助人');
    }
    
    Db::execute(
        'INSERT INTO okr_task_assistants (task_id, user_id, create_time) VALUES (:task_id, :user_id, :create_time)',
        ['task_id' => $taskId, 'user_id' => $userId, 'create_time' => time()]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '协助人添加成功'
    ]);
}

/**
 * 移除任务协助人
 */
function removeTaskAssistant() {
    global $user;
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $userId = intval($_POST['user_id'] ?? 0);
    
    if ($taskId <= 0 || $userId <= 0) {
        throw new Exception('参数无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $taskId]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限操作此任务');
    }
    
    Db::execute(
        'DELETE FROM okr_task_assistants WHERE task_id = :task_id AND user_id = :user_id',
        ['task_id' => $taskId, 'user_id' => $userId]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '协助人移除成功'
    ]);
}

/**
 * 添加任务关联
 */
function addTaskRelation() {
    global $user;
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $relationType = trim($_POST['relation_type'] ?? '');
    $relationId = intval($_POST['relation_id'] ?? 0);
    
    if ($taskId <= 0 || empty($relationType) || $relationId <= 0) {
        throw new Exception('参数无效');
    }
    
    if (!in_array($relationType, ['okr', 'objective', 'kr', 'kpi', 'customer'])) {
        throw new Exception('关联类型无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $taskId]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限操作此任务');
    }
    
    // 检查是否已存在
    $existing = Db::queryOne(
        'SELECT * FROM okr_task_relations WHERE task_id = :task_id AND relation_type = :relation_type AND relation_id = :relation_id',
        ['task_id' => $taskId, 'relation_type' => $relationType, 'relation_id' => $relationId]
    );
    
    if ($existing) {
        throw new Exception('该关联已存在');
    }
    
    Db::execute(
        'INSERT INTO okr_task_relations (task_id, relation_type, relation_id, create_time) VALUES (:task_id, :relation_type, :relation_id, :create_time)',
        ['task_id' => $taskId, 'relation_type' => $relationType, 'relation_id' => $relationId, 'create_time' => time()]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '关联添加成功'
    ]);
}

/**
 * 移除任务关联
 */
function removeTaskRelation() {
    global $user;
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $relationType = trim($_POST['relation_type'] ?? '');
    $relationId = intval($_POST['relation_id'] ?? 0);
    
    if ($taskId <= 0 || empty($relationType) || $relationId <= 0) {
        throw new Exception('参数无效');
    }
    
    $task = Db::queryOne('SELECT * FROM okr_tasks WHERE id = :id', ['id' => $taskId]);
    if (!$task) {
        throw new Exception('任务不存在');
    }
    
    // 权限检查
    if (!checkOkrTaskOperationPermission($user, $task)) {
        throw new Exception('无权限操作此任务');
    }
    
    Db::execute(
        'DELETE FROM okr_task_relations WHERE task_id = :task_id AND relation_type = :relation_type AND relation_id = :relation_id',
        ['task_id' => $taskId, 'relation_type' => $relationType, 'relation_id' => $relationId]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '关联移除成功'
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

