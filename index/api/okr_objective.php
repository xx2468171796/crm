<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * OKR 目标和 KR 管理 API
 * 提供目标（Objective）和关键结果（Key Result）的增删改查功能
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
        case 'save':
            saveObjective();
            break;
            
        case 'delete':
            deleteObjective();
            break;
            
        case 'kr_save':
            saveKeyResult();
            break;
            
        case 'kr_delete':
            deleteKeyResult();
            break;
            
        case 'kr_update_progress':
            updateKrProgress();
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
 * 保存目标（新增或更新）
 */
function saveObjective() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $containerId = intval($_POST['container_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $parentId = intval($_POST['parent_id'] ?? 0); // 对齐的上级目标ID
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if ($containerId <= 0) {
        throw new Exception('容器ID无效');
    }
    
    if (empty($title)) {
        throw new Exception('目标标题不能为空');
    }
    
    // 检查容器权限
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $containerId]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限操作此容器');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $id]);
        if (!$objective) {
            throw new Exception('目标不存在');
        }
        
        if ($objective['container_id'] != $containerId) {
            throw new Exception('容器ID不匹配');
        }
        
        $oldValue = $objective;
        
        Db::execute(
            'UPDATE okr_objectives SET title = :title, parent_id = :parent_id, sort_order = :sort_order, update_time = :update_time WHERE id = :id',
            [
                'id' => $id,
                'title' => $title,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'sort_order' => $sortOrder,
                'update_time' => $now
            ]
        );
        
        // 记录操作日志
        logOkrAction('objective', $id, 'update', $oldValue, ['title' => $title, 'parent_id' => $parentId]);
    } else {
        // 新增
        // 如果没有指定排序，自动计算
        if ($sortOrder == 0) {
            $maxOrder = Db::queryOne(
                'SELECT MAX(sort_order) as max_order FROM okr_objectives WHERE container_id = :container_id',
                ['container_id' => $containerId]
            );
            $sortOrder = ($maxOrder && $maxOrder['max_order'] !== null) ? intval($maxOrder['max_order']) + 1 : 1;
        }
        
        Db::execute(
            'INSERT INTO okr_objectives (container_id, title, sort_order, parent_id, progress, status, create_user_id, create_time, update_time) VALUES (:container_id, :title, :sort_order, :parent_id, 0, :status, :create_user_id, :create_time, :update_time)',
            [
                'container_id' => $containerId,
                'title' => $title,
                'sort_order' => $sortOrder,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'status' => 'normal',
                'create_user_id' => $user['id'],
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
        
        // 记录操作日志
        logOkrAction('objective', $id, 'create', null, ['title' => $title, 'container_id' => $containerId]);
    }
    
    // 重新计算容器和目标进度
    recalculateObjectiveProgress($id);
    recalculateContainerProgress($containerId);
    
    $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $id]);
    
    echo json_encode([
        'success' => true,
        'data' => $objective,
        'message' => $id > 0 ? '目标保存成功' : '目标创建成功'
    ]);
}

/**
 * 删除目标
 */
function deleteObjective() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('目标ID无效');
    }
    
    $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $id]);
    if (!$objective) {
        throw new Exception('目标不存在');
    }
    
    // 检查容器权限
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $objective['container_id']]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限操作此容器');
    }
    
    $containerId = $objective['container_id'];
    
    // 级联删除（由外键约束处理，会删除所有 KR）
    Db::execute('DELETE FROM okr_objectives WHERE id = :id', ['id' => $id]);
    
    // 记录操作日志
    logOkrAction('objective', $id, 'delete', $objective, null);
    
    // 重新计算容器进度
    recalculateContainerProgress($containerId);
    
    echo json_encode([
        'success' => true,
        'message' => '目标删除成功'
    ]);
}

/**
 * 保存关键结果（新增或更新）
 */
function saveKeyResult() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $objectiveId = intval($_POST['objective_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $targetValue = floatval($_POST['target_value'] ?? 100);
    $currentValue = floatval($_POST['current_value'] ?? 0);
    $startValue = floatval($_POST['start_value'] ?? 0);
    $unit = trim($_POST['unit'] ?? '%');
    $weight = floatval($_POST['weight'] ?? 0);
    $confidence = intval($_POST['confidence'] ?? 5);
    $progressMode = trim($_POST['progress_mode'] ?? 'value');
    $ownerUserIds = $_POST['owner_user_ids'] ?? [];
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if ($objectiveId <= 0) {
        throw new Exception('目标ID无效');
    }
    
    if (empty($title)) {
        throw new Exception('KR标题不能为空');
    }
    
    if (!in_array($progressMode, ['value', 'task'])) {
        throw new Exception('进度模式无效');
    }
    
    // 检查目标权限
    $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $objectiveId]);
    if (!$objective) {
        throw new Exception('目标不存在');
    }
    
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $objective['container_id']]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限操作此容器');
    }
    
    // 处理负责人 IDs
    if (is_string($ownerUserIds)) {
        $ownerUserIds = json_decode($ownerUserIds, true) ?: [];
    }
    if (!is_array($ownerUserIds)) {
        $ownerUserIds = [];
    }
    
    $now = time();
    
    // 计算进度
    $progress = calculateKrProgress($progressMode, $currentValue, $startValue, $targetValue);
    
    if ($id > 0) {
        // 更新
        $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $id]);
        if (!$kr) {
            throw new Exception('KR不存在');
        }
        
        if ($kr['objective_id'] != $objectiveId) {
            throw new Exception('目标ID不匹配');
        }
        
        $oldValue = $kr;
        
        Db::execute(
            'UPDATE okr_key_results SET title = :title, target_value = :target_value, current_value = :current_value, start_value = :start_value, unit = :unit, weight = :weight, confidence = :confidence, progress_mode = :progress_mode, progress = :progress, owner_user_ids = :owner_user_ids, sort_order = :sort_order, update_time = :update_time WHERE id = :id',
            [
                'id' => $id,
                'title' => $title,
                'target_value' => $targetValue,
                'current_value' => $currentValue,
                'start_value' => $startValue,
                'unit' => $unit,
                'weight' => $weight,
                'confidence' => $confidence,
                'progress_mode' => $progressMode,
                'progress' => $progress,
                'owner_user_ids' => json_encode($ownerUserIds, JSON_UNESCAPED_UNICODE),
                'sort_order' => $sortOrder,
                'update_time' => $now
            ]
        );
        
        // 记录操作日志
        logOkrAction('kr', $id, 'update', $oldValue, ['title' => $title, 'progress' => $progress]);
    } else {
        // 新增
        // 如果没有指定排序，自动计算
        if ($sortOrder == 0) {
            $maxOrder = Db::queryOne(
                'SELECT MAX(sort_order) as max_order FROM okr_key_results WHERE objective_id = :objective_id',
                ['objective_id' => $objectiveId]
            );
            $sortOrder = ($maxOrder && $maxOrder['max_order'] !== null) ? intval($maxOrder['max_order']) + 1 : 1;
        }
        
        Db::execute(
            'INSERT INTO okr_key_results (objective_id, title, target_value, current_value, start_value, unit, weight, confidence, progress_mode, progress, owner_user_ids, sort_order, status, create_user_id, create_time, update_time) VALUES (:objective_id, :title, :target_value, :current_value, :start_value, :unit, :weight, :confidence, :progress_mode, :progress, :owner_user_ids, :sort_order, :status, :create_user_id, :create_time, :update_time)',
            [
                'objective_id' => $objectiveId,
                'title' => $title,
                'target_value' => $targetValue,
                'current_value' => $currentValue,
                'start_value' => $startValue,
                'unit' => $unit,
                'weight' => $weight,
                'confidence' => $confidence,
                'progress_mode' => $progressMode,
                'progress' => $progress,
                'owner_user_ids' => json_encode($ownerUserIds, JSON_UNESCAPED_UNICODE),
                'sort_order' => $sortOrder,
                'status' => 'normal',
                'create_user_id' => $user['id'],
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
        
        // 记录操作日志
        logOkrAction('kr', $id, 'create', null, ['title' => $title, 'objective_id' => $objectiveId]);
    }
    
    // 重新计算目标和容器进度
    recalculateObjectiveProgress($objectiveId);
    recalculateContainerProgress($container['id']);
    
    $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $id]);
    $kr['owner_user_ids'] = json_decode($kr['owner_user_ids'] ?? '[]', true) ?: [];
    
    echo json_encode([
        'success' => true,
        'data' => $kr,
        'message' => $id > 0 ? 'KR保存成功' : 'KR创建成功'
    ]);
}

/**
 * 删除关键结果
 */
function deleteKeyResult() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('KR ID无效');
    }
    
    $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $id]);
    if (!$kr) {
        throw new Exception('KR不存在');
    }
    
    // 检查目标权限
    $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $kr['objective_id']]);
    if (!$objective) {
        throw new Exception('目标不存在');
    }
    
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $objective['container_id']]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限操作此容器');
    }
    
    $objectiveId = $kr['objective_id'];
    $containerId = $objective['container_id'];
    
    Db::execute('DELETE FROM okr_key_results WHERE id = :id', ['id' => $id]);
    
    // 记录操作日志
    logOkrAction('kr', $id, 'delete', $kr, null);
    
    // 重新计算目标和容器进度
    recalculateObjectiveProgress($objectiveId);
    recalculateContainerProgress($containerId);
    
    echo json_encode([
        'success' => true,
        'message' => 'KR删除成功'
    ]);
}

/**
 * 更新 KR 进度
 */
function updateKrProgress() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $currentValue = floatval($_POST['current_value'] ?? 0);
    $confidence = intval($_POST['confidence'] ?? null);
    
    if ($id <= 0) {
        throw new Exception('KR ID无效');
    }
    
    $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $id]);
    if (!$kr) {
        throw new Exception('KR不存在');
    }
    
    // 检查目标权限
    $objective = Db::queryOne('SELECT * FROM okr_objectives WHERE id = :id', ['id' => $kr['objective_id']]);
    if (!$objective) {
        throw new Exception('目标不存在');
    }
    
    $container = Db::queryOne('SELECT * FROM okr_containers WHERE id = :id', ['id' => $objective['container_id']]);
    if (!$container) {
        throw new Exception('容器不存在');
    }
    
    if (!checkOkrContainerPermission($user, $container)) {
        throw new Exception('无权限操作此容器');
    }
    
    $oldValue = $kr;
    
    // 计算进度
    $progress = calculateKrProgress($kr['progress_mode'], $currentValue, $kr['start_value'], $kr['target_value']);
    
    $updateFields = ['current_value' => $currentValue, 'progress' => $progress, 'update_time' => time()];
    $updateSql = 'UPDATE okr_key_results SET current_value = :current_value, progress = :progress, update_time = :update_time';
    $updateParams = ['id' => $id, 'current_value' => $currentValue, 'progress' => $progress, 'update_time' => time()];
    
    if ($confidence !== null) {
        $updateSql .= ', confidence = :confidence';
        $updateParams['confidence'] = $confidence;
        $updateFields['confidence'] = $confidence;
    }
    
    $updateSql .= ' WHERE id = :id';
    $updateParams['id'] = $id;
    
    Db::execute($updateSql, $updateParams);
    
    // 记录操作日志
    logOkrAction('kr', $id, 'update_progress', $oldValue, $updateFields);
    
    // 重新计算目标和容器进度
    recalculateObjectiveProgress($objective['id']);
    recalculateContainerProgress($container['id']);
    
    $kr = Db::queryOne('SELECT * FROM okr_key_results WHERE id = :id', ['id' => $id]);
    
    echo json_encode([
        'success' => true,
        'data' => $kr,
        'message' => '进度更新成功'
    ]);
}

/**
 * 计算 KR 进度
 */
function calculateKrProgress($mode, $currentValue, $startValue, $targetValue) {
    if ($mode === 'task') {
        // 任务模式：从关联任务计算（这里简化处理，实际应从任务表统计）
        // TODO: 从 okr_tasks 表统计已完成任务数 / 总任务数
        return 0; // 暂时返回 0，后续从任务表计算
    } else {
        // 数值模式：按当前值/目标值计算
        if ($targetValue == $startValue) {
            return 0;
        }
        $progress = (($currentValue - $startValue) / ($targetValue - $startValue)) * 100;
        return max(0, min(100, round($progress, 2)));
    }
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

