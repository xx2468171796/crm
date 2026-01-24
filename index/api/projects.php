<?php
require_once __DIR__ . '/../core/api_init.php';
// 项目 CRUD API
// 支持：GET（查询）、POST（创建）、PUT（更新）、DELETE（软删除）

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/constants.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

// 支持 session 和 Bearer token 两种认证方式
$user = current_user();
if (!$user) {
    // 尝试桌面端 token 认证
    $token = desktop_get_token();
    if ($token) {
        $user = desktop_verify_token($token);
    }
}
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Db::pdo();

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo, $user);
            break;
        case 'POST':
            handlePost($pdo, $user);
            break;
        case 'PUT':
            handlePut($pdo, $user);
            break;
        case 'DELETE':
            handleDelete($pdo, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// GET - 查询项目列表或单个项目
function handleGet($pdo, $user) {
    $action = $_GET['action'] ?? '';
    
    // 特殊 action 处理
    if ($action === 'assignees') {
        handleGetAssignees($pdo, $user);
        return;
    }
    if ($action === 'batch_assignees') {
        handleBatchAssignees($pdo, $user);
        return;
    }
    
    $projectId = intval($_GET['id'] ?? 0);
    $customerId = intval($_GET['customer_id'] ?? 0);
    
    if ($projectId > 0) {
        // 查询单个项目
        $project = getProject($pdo, $projectId, $user);
        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '项目不存在或无权访问']);
            return;
        }
        echo json_encode(['success' => true, 'data' => $project]);
    } else {
        // 查询项目列表
        $projects = getProjects($pdo, $user, $customerId);
        echo json_encode(['success' => true, 'data' => $projects]);
    }
}

/**
 * 获取单个项目的负责人列表（含提成信息）
 */
function handleGetAssignees($pdo, $user) {
    $projectId = intval($_GET['project_id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            pta.id as assignment_id,
            pta.tech_user_id as user_id,
            u.username,
            u.realname,
            d.name as department_name,
            pta.commission_amount,
            pta.commission_note,
            pta.assigned_at,
            assigner.realname as assigned_by_name
        FROM project_tech_assignments pta
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN users assigner ON pta.assigned_by = assigner.id
        WHERE pta.project_id = ?
        ORDER BY pta.assigned_at ASC
    ");
    $stmt->execute([$projectId]);
    $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $assignees], JSON_UNESCAPED_UNICODE);
}

/**
 * 批量获取多个项目的负责人（看板用，优化性能）
 */
function handleBatchAssignees($pdo, $user) {
    $projectIds = $_GET['project_ids'] ?? '';
    
    if (empty($projectIds)) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 解析项目ID列表
    $ids = array_filter(array_map('intval', explode(',', $projectIds)));
    
    if (empty($ids)) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $pdo->prepare("
        SELECT 
            pta.project_id,
            pta.tech_user_id as user_id,
            u.username,
            u.realname,
            pta.commission_amount
        FROM project_tech_assignments pta
        JOIN users u ON pta.tech_user_id = u.id
        WHERE pta.project_id IN ({$placeholders})
        ORDER BY pta.project_id, pta.assigned_at ASC
    ");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按项目ID分组
    $result = [];
    foreach ($rows as $row) {
        $pid = $row['project_id'];
        if (!isset($result[$pid])) {
            $result[$pid] = [];
        }
        $result[$pid][] = [
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'realname' => $row['realname'],
            'commission_amount' => $row['commission_amount']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
}

// POST - 创建项目 或 特殊操作
function handlePost($pdo, $user) {
    $action = $_GET['action'] ?? '';
    
    // 处理状态变更
    if ($action === 'change_status') {
        handleChangeStatus($pdo, $user);
        return;
    }
    
    // 权限检查：需要project_create权限
    if (!canCreateProject($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限创建项目'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    $customerId = intval($data['customer_id'] ?? 0);
    $projectName = trim($data['project_name'] ?? '');
    $groupCode = trim($data['group_code'] ?? '');
    $startDate = $data['start_date'] ?? null;
    $deadline = $data['deadline'] ?? null;
    
    if ($customerId <= 0 || empty($projectName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '客户ID和项目名称不能为空']);
        return;
    }
    
    // 检查客户是否存在且有权访问
    $customerStmt = $pdo->prepare("
        SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL
    ");
    $customerStmt->execute([$customerId]);
    if (!$customerStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '客户不存在']);
        return;
    }
    
    // 生成项目编号（基于当年已有项目数量）
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE project_code LIKE ?");
    $stmt->execute(["PRJ-{$year}-%"]);
    $count = $stmt->fetchColumn() ?: 0;
    
    // 循环确保唯一性
    $projectCode = '';
    for ($i = 0; $i < 100; $i++) {
        $seq = $count + $i + 1;
        $projectCode = "PRJ-{$year}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE project_code = ?");
        $checkStmt->execute([$projectCode]);
        if ($checkStmt->fetchColumn() == 0) {
            break;
        }
    }
    
    // 创建项目
    $now = time();
    $insertStmt = $pdo->prepare("
        INSERT INTO projects (
            customer_id, project_name, project_code, group_code,
            current_status, created_by, create_time, update_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insertStmt->execute([
        $customerId,
        $projectName,
        $projectCode,
        $groupCode,
        '待沟通',
        $user['id'],
        $now,
        $now
    ]);
    
    $projectId = $pdo->lastInsertId();
    
    // 初始化项目阶段时间（加载默认模板）
    initProjectStageTimes($pdo, $projectId, $startDate);
    
    // 自动创建需求表单实例（如果配置了默认需求模板）
    createRequirementFormInstance($pdo, $projectId, $projectName);
    
    // 写入时间线
    $timelineStmt = $pdo->prepare("
        INSERT INTO timeline_events (
            entity_type, entity_id, event_type, operator_user_id, 
            event_data_json, create_time
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $timelineStmt->execute([
        'project',
        $projectId,
        '创建项目',
        $user['id'],
        json_encode(['project_name' => $projectName, 'project_code' => $projectCode]),
        $now
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => '项目创建成功',
        'data' => ['id' => $projectId, 'project_code' => $projectCode]
    ]);
}

// PUT - 更新项目
function handlePut($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $projectId = intval($data['id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '项目ID不能为空']);
        return;
    }
    
    // 检查项目是否存在且有权访问
    $project = getProject($pdo, $projectId, $user);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在或无权访问']);
        return;
    }
    
    // 权限检查：使用RBAC细粒度权限
    $onlyStatus = isset($data['current_status']) && count(array_diff(array_keys($data), ['id', 'current_status'])) === 0;
    
    if ($onlyStatus) {
        // 只更新状态：需要project_status_edit权限
        if (!canEditProjectStatus($user)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '无权限修改项目状态'], JSON_UNESCAPED_UNICODE);
            return;
        }
    } else {
        // 更新其他字段：需要project_edit权限
        if (!canEditProject($user)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '无权限编辑项目'], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    
    $updateFields = [];
    $params = [];
    
    if (isset($data['project_name'])) {
        $updateFields[] = "project_name = ?";
        $params[] = trim($data['project_name']);
    }
    if (isset($data['group_code'])) {
        $updateFields[] = "group_code = ?";
        $params[] = trim($data['group_code']);
    }
    if (isset($data['start_date'])) {
        $updateFields[] = "start_date = ?";
        $params[] = $data['start_date'];
    }
    if (isset($data['deadline'])) {
        $updateFields[] = "deadline = ?";
        $params[] = $data['deadline'];
    }
    if (isset($data['requirements_locked'])) {
        $updateFields[] = "requirements_locked = ?";
        $params[] = intval($data['requirements_locked']);
    }
    if (isset($data['current_status'])) {
        $updateFields[] = "current_status = ?";
        $params[] = trim($data['current_status']);
    }
    if (isset($data['show_model_files'])) {
        $updateFields[] = "show_model_files = ?";
        $params[] = intval($data['show_model_files']);
    }
    
    if (empty($updateFields)) {
        echo json_encode(['success' => true, 'message' => '无需更新']);
        return;
    }
    
    $updateFields[] = "update_time = ?";
    $params[] = time();
    $params[] = $projectId;
    
    $sql = "UPDATE projects SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode(['success' => true, 'message' => '项目更新成功']);
}

// DELETE - 软删除项目
function handleDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        parse_str(file_get_contents('php://input'), $data);
    }
    $projectId = intval($data['id'] ?? $_GET['id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '项目ID不能为空']);
        return;
    }
    
    // 检查项目是否存在且有权访问
    $project = getProject($pdo, $projectId, $user);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在或无权访问']);
        return;
    }
    
    // 权限检查：需要project_delete权限（仅管理员和主管）
    if (!canDeleteProject($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限删除项目'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    
    // 开启事务
    $pdo->beginTransaction();
    try {
        // 1. 软删除项目
        $stmt = $pdo->prepare("UPDATE projects SET deleted_at = ?, deleted_by = ? WHERE id = ?");
        $stmt->execute([$now, $user['id'], $projectId]);
        
        // 2. 级联软删除交付物（deliverables）
        $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE project_id = ? AND deleted_at IS NULL");
        $stmt->execute([$now, $user['id'], $projectId]);
        $deletedDeliverables = $stmt->rowCount();
        
        // 3. 记录到timeline_events
        $timelineStmt = $pdo->prepare("
            INSERT INTO timeline_events (
                entity_type, entity_id, event_type, operator_user_id, 
                event_data_json, create_time
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $timelineStmt->execute([
            'project',
            $projectId,
            '删除项目',
            $user['id'],
            json_encode([
                'project_name' => $project['project_name'],
                'project_code' => $project['project_code'],
                'deleted_deliverables' => $deletedDeliverables,
                'operator' => $user['realname'] ?? $user['username']
            ], JSON_UNESCAPED_UNICODE),
            $now
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => '项目删除成功',
            'data' => [
                'deleted_deliverables' => $deletedDeliverables
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 获取单个项目
function getProject($pdo, $projectId, $user) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as customer_name, u.realname as creator_name,
               c.group_code as customer_group_code, c.group_name as customer_group_name, c.customer_group
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        return null;
    }
    
    // 数据权限过滤
    if (!canAccessProject($project, $user)) {
        return null;
    }
    
    // 获取分配的技术人员
    $techStmt = $pdo->prepare("
        SELECT u.id, u.username, u.realname
        FROM project_tech_assignments pta
        JOIN users u ON pta.tech_user_id = u.id
        WHERE pta.project_id = ?
    ");
    $techStmt->execute([$projectId]);
    $project['tech_users'] = $techStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $project;
}

// 获取项目列表
function getProjects($pdo, $user, $customerId = 0) {
    // 获取筛选参数
    $techUserId = intval($_GET['tech_user_id'] ?? 0);
    $salesUserId = intval($_GET['sales_user_id'] ?? 0);
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $createdFrom = $_GET['created_from'] ?? '';
    $createdTo = $_GET['created_to'] ?? '';
    $sort = $_GET['sort'] ?? 'update_time';
    $order = strtoupper($_GET['order'] ?? 'DESC');
    
    // 验证排序参数
    $allowedSorts = ['update_time', 'create_time', 'project_code', 'current_status'];
    if (!in_array($sort, $allowedSorts)) {
        $sort = 'update_time';
    }
    if (!in_array($order, ['ASC', 'DESC'])) {
        $order = 'DESC';
    }
    
    $sql = "
        SELECT p.*, c.name as customer_name, u.realname as creator_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.deleted_at IS NULL
    ";
    
    $params = [];
    
    // 客户筛选
    if ($customerId > 0) {
        $sql .= " AND p.customer_id = ?";
        $params[] = $customerId;
    }
    
    // 搜索（项目名称、项目编号、客户名称、客户群）
    if (!empty($search)) {
        $sql .= " AND (p.project_name LIKE ? OR p.project_code LIKE ? OR c.name LIKE ? OR c.customer_group LIKE ?)";
        $searchPattern = '%' . $search . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    // 技术人员筛选
    if ($techUserId > 0) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM project_tech_assignments pta 
            WHERE pta.project_id = p.id AND pta.tech_user_id = ?
        )";
        $params[] = $techUserId;
    }
    
    // 销售人员筛选（创建人或客户负责人）
    if ($salesUserId > 0) {
        $sql .= " AND (p.created_by = ? OR c.owner_user_id = ?)";
        $params[] = $salesUserId;
        $params[] = $salesUserId;
    }
    
    // 状态筛选（支持多选，逗号分隔）
    if (!empty($status)) {
        $statuses = array_filter(array_map('trim', explode(',', $status)));
        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND p.current_status IN ({$placeholders})";
            $params = array_merge($params, $statuses);
        }
    }
    
    // 创建时间范围筛选
    if (!empty($createdFrom)) {
        $sql .= " AND p.create_time >= ?";
        $params[] = strtotime($createdFrom . ' 00:00:00');
    }
    if (!empty($createdTo)) {
        $sql .= " AND p.create_time <= ?";
        $params[] = strtotime($createdTo . ' 23:59:59');
    }
    
    // 数据权限过滤
    if (!isAdmin($user)) {
        if ($user['role'] === 'sales') {
            // 销售只看自己创建的或客户归属自己的项目
            $sql .= " AND (p.created_by = ? OR c.owner_user_id = ?)";
            $params[] = $user['id'];
            $params[] = $user['id'];
        } elseif ($user['role'] === 'tech') {
            // 技术看分配给自己的项目 或 自己创建的项目
            $sql .= " AND (
                p.created_by = ? OR EXISTS (
                    SELECT 1 FROM project_tech_assignments pta 
                    WHERE pta.project_id = p.id AND pta.tech_user_id = ?
                )
            )";
            $params[] = $user['id'];
            $params[] = $user['id'];
        } elseif ($user['role'] === 'dept_leader') {
            // 部门主管看部门及下级项目
            $deptFilter = getDeptTreeFilter($pdo, $user);
            if ($deptFilter['where']) {
                $sql .= $deptFilter['where'];
                $params = array_merge($params, $deptFilter['params']);
            }
        } elseif ($user['role'] === 'dept_admin') {
            // 部门管理员看本部门项目
            $deptFilter = getDeptFilter($pdo, $user);
            if ($deptFilter['where']) {
                $sql .= $deptFilter['where'];
                $params = array_merge($params, $deptFilter['params']);
            }
        }
    }
    
    // 排序
    $sql .= " ORDER BY p.{$sort} {$order}";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取每个项目的表单需求状态统计
    if (!empty($projects)) {
        $projectIds = array_column($projects, 'id');
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        
        $statsStmt = $pdo->prepare("
            SELECT project_id, requirement_status, COUNT(*) as cnt
            FROM form_instances
            WHERE project_id IN ({$placeholders})
            GROUP BY project_id, requirement_status
        ");
        $statsStmt->execute($projectIds);
        $statsRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按项目ID分组统计
        $statsMap = [];
        foreach ($statsRows as $row) {
            $pid = $row['project_id'];
            if (!isset($statsMap[$pid])) {
                $statsMap[$pid] = ['total' => 0, 'pending' => 0, 'communicating' => 0, 'confirmed' => 0, 'modifying' => 0];
            }
            $status = $row['requirement_status'] ?? 'pending';
            $statsMap[$pid][$status] = intval($row['cnt']);
            $statsMap[$pid]['total'] += intval($row['cnt']);
        }
        
        // 合并到项目数据
        foreach ($projects as &$p) {
            $p['form_stats'] = $statsMap[$p['id']] ?? null;
        }
        unset($p);
        
        // 获取当前阶段剩余时间
        $stageStmt = $pdo->prepare("
            SELECT pst.project_id, 
                   DATEDIFF(pst.planned_end_date, CURDATE()) as remaining_days,
                   pst.planned_days
            FROM project_stage_times pst
            WHERE pst.project_id IN ({$placeholders}) 
              AND pst.status = 'in_progress'
        ");
        $stageStmt->execute($projectIds);
        $stageRows = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stageMap = [];
        foreach ($stageRows as $row) {
            $stageMap[$row['project_id']] = [
                'remaining_days' => intval($row['remaining_days']),
                'planned_days' => intval($row['planned_days'])
            ];
        }
        
        foreach ($projects as &$p) {
            $p['stage_time'] = $stageMap[$p['id']] ?? null;
        }
        unset($p);
    }
    
    return $projects;
}

// 获取部门树过滤条件（部门主管）
function getDeptTreeFilter($pdo, $user) {
    $deptId = $user['department_id'] ?? null;
    if (!$deptId) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    // 获取部门路径
    $stmt = $pdo->prepare("SELECT path FROM departments WHERE id = ?");
    $stmt->execute([$deptId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dept || !$dept['path']) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    // 获取所有下级部门ID
    $stmt = $pdo->prepare("SELECT id FROM departments WHERE path LIKE ?");
    $stmt->execute([$dept['path'] . '%']);
    $deptIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($deptIds)) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    // 获取这些部门的所有用户
    $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE department_id IN ({$placeholders})");
    $stmt->execute($deptIds);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userIds)) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
    return [
        'where' => " AND (p.created_by IN ({$userPlaceholders}) OR c.owner_user_id IN ({$userPlaceholders}))",
        'params' => array_merge($userIds, $userIds)
    ];
}

// 获取本部门过滤条件（部门管理员）
function getDeptFilter($pdo, $user) {
    $deptId = $user['department_id'] ?? null;
    if (!$deptId) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    // 获取本部门的所有用户
    $stmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ?");
    $stmt->execute([$deptId]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userIds)) {
        return ['where' => ' AND p.created_by = ?', 'params' => [$user['id']]];
    }
    
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    return [
        'where' => " AND (p.created_by IN ({$placeholders}) OR c.owner_user_id IN ({$placeholders}))",
        'params' => array_merge($userIds, $userIds)
    ];
}

// 检查是否可访问项目
function canAccessProject($project, $user) {
    if (isAdmin($user)) {
        return true;
    }
    
    if ($user['role'] === 'sales') {
        // 销售可访问自己创建的项目
        if ($project['created_by'] == $user['id']) {
            return true;
        }
        // 或客户归属自己的项目
        // 需要查询客户归属
        return true; // 简化处理，实际应查询
    }
    
    if ($user['role'] === 'tech') {
        // 技术需要检查是否分配给自己
        // 这里简化处理，实际应查询 project_tech_assignments
        return true;
    }
    
    return false;
}

// 处理项目状态变更
function handleChangeStatus($pdo, $user) {
    // 权限检查：需要project_status_edit权限
    if (!canEditProjectStatus($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限修改项目状态'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    $projectId = intval($data['project_id'] ?? 0);
    $newStatus = trim($data['status'] ?? '');
    
    if ($projectId <= 0 || empty($newStatus)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 使用统一的 ProjectService 更新状态
    $projectService = ProjectService::getInstance();
    $result = $projectService->updateStatus(
        $projectId,
        $newStatus,
        $user['id'],
        $user['realname'] ?? $user['username']
    );
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 同步阶段时间表状态
    syncStageTimesStatus($pdo, $projectId, $newStatus);
    
    echo json_encode([
        'success' => true,
        'message' => '项目状态已更新',
        'data' => $result['data']
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 初始化项目阶段时间（从默认模板加载）
 */
function initProjectStageTimes($pdo, $projectId, $startDate = null) {
    try {
        // 获取默认模板
        $stmt = $pdo->prepare("
            SELECT * FROM project_stage_templates 
            WHERE is_active = 1 
            ORDER BY stage_order ASC
        ");
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($templates)) {
            return; // 没有模板则跳过
        }
        
        // 计算起始日期
        $baseDate = $startDate ? new DateTime($startDate) : new DateTime();
        $currentDate = clone $baseDate;
        
        // 为每个阶段创建时间记录
        $insertStmt = $pdo->prepare("
            INSERT INTO project_stage_times 
            (project_id, stage_from, stage_to, stage_order, planned_days, 
             planned_start_date, planned_end_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        foreach ($templates as $idx => $t) {
            $plannedDays = intval($t['default_days']);
            $plannedStart = $currentDate->format('Y-m-d');
            
            // 计算结束日期（开始日期 + 天数 - 1）
            $endDate = clone $currentDate;
            $endDate->modify('+' . ($plannedDays - 1) . ' days');
            $plannedEnd = $endDate->format('Y-m-d');
            
            $insertStmt->execute([
                $projectId,
                $t['stage_from'],
                $t['stage_to'],
                $t['stage_order'],
                $plannedDays,
                $plannedStart,
                $plannedEnd
            ]);
            
            // 下一阶段从当前阶段结束后一天开始
            $currentDate = clone $endDate;
            $currentDate->modify('+1 day');
        }
        
        // 更新项目的 timeline_enabled 和 timeline_start_date
        $updateStmt = $pdo->prepare("
            UPDATE projects SET timeline_enabled = 1, timeline_start_date = ? WHERE id = ?
        ");
        $updateStmt->execute([$baseDate->format('Y-m-d'), $projectId]);
        
    } catch (Exception $e) {
        error_log('[PROJECT_STAGE_TIME_DEBUG] Init failed: ' . $e->getMessage());
    }
}

/**
 * 自动创建需求表单实例
 */
function createRequirementFormInstance($pdo, $projectId, $projectName) {
    try {
        // 获取默认需求模板配置
        $configStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'default_requirement_template_id'");
        $configStmt->execute();
        $config = $configStmt->fetch(PDO::FETCH_ASSOC);
        
        $templateId = intval($config['config_value'] ?? 0);
        if ($templateId <= 0) {
            return; // 未配置默认需求模板
        }
        
        // 检查模板是否存在且已发布
        $tplStmt = $pdo->prepare("SELECT id, name, current_version_id FROM form_templates WHERE id = ? AND status = 'published' AND deleted_at IS NULL");
        $tplStmt->execute([$templateId]);
        $template = $tplStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            return; // 模板不存在或未发布
        }
        
        // 创建需求表单实例
        $now = time();
        $instanceName = $template['name'] . ' - ' . $projectName;
        $fillToken = bin2hex(random_bytes(32));
        
        $insertStmt = $pdo->prepare("
            INSERT INTO form_instances (template_id, template_version_id, project_id, instance_name, status, purpose, requirement_status, fill_token, created_by, create_time, update_time)
            VALUES (?, ?, ?, ?, 'pending', 'requirement', 'pending', ?, 0, ?, ?)
        ");
        $insertStmt->execute([$templateId, $template['current_version_id'], $projectId, $instanceName, $fillToken, $now, $now]);
        
    } catch (Exception $e) {
        error_log('[PROJECT_REQUIREMENT_FORM] Create failed: ' . $e->getMessage());
    }
}

/**
 * 同步阶段时间表状态
 * 根据项目当前状态更新 project_stage_times 表的 status 字段
 */
function syncStageTimesStatus($pdo, $projectId, $currentStatus) {
    try {
        // 状态顺序映射
        $statusOrder = [
            '待沟通' => 0,
            '需求确认' => 1,
            '设计中' => 2,
            '设计核对' => 3,
            '设计完工' => 4,
            '设计评价' => 5,
        ];
        
        $currentIndex = $statusOrder[$currentStatus] ?? 0;
        
        // 获取项目的阶段时间记录
        $stmt = $pdo->prepare("SELECT id, stage_from, stage_to FROM project_stage_times WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($stages)) {
            return;
        }
        
        $updateStmt = $pdo->prepare("UPDATE project_stage_times SET status = ? WHERE id = ?");
        
        foreach ($stages as $stage) {
            $stageFromIndex = $statusOrder[$stage['stage_from']] ?? -1;
            $stageToIndex = $statusOrder[$stage['stage_to']] ?? -1;
            
            // 计算应该的状态
            $shouldStatus = 'pending';
            if ($stageToIndex <= $currentIndex) {
                // 已完成：目标状态索引 <= 当前状态索引
                $shouldStatus = 'completed';
            } elseif ($stageFromIndex <= $currentIndex && $stageToIndex > $currentIndex) {
                // 进行中：起始状态索引 <= 当前状态索引 < 目标状态索引
                $shouldStatus = 'in_progress';
            }
            
            $updateStmt->execute([$shouldStatus, $stage['id']]);
        }
    } catch (Exception $e) {
        error_log('[SYNC_STAGE_TIMES] Error: ' . $e->getMessage());
    }
}

/**
 * 创建评价表单实例（进入设计评价阶段时调用）
 */
function createEvaluationFormInstance($pdo, $projectId, $customerId) {
    try {
        // 获取默认评价模板配置
        $configStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'default_evaluation_template_id'");
        $configStmt->execute();
        $config = $configStmt->fetch(PDO::FETCH_ASSOC);
        
        $templateId = intval($config['config_value'] ?? 0);
        if ($templateId <= 0) {
            return; // 未配置默认模板，使用简单评分
        }
        
        // 检查模板是否存在且已发布
        $tplStmt = $pdo->prepare("SELECT id, name, current_version_id FROM form_templates WHERE id = ? AND status = 'published'");
        $tplStmt->execute([$templateId]);
        $template = $tplStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            return; // 模板不存在或未发布
        }
        
        // 检查是否已存在评价表单实例
        $existStmt = $pdo->prepare("SELECT id FROM form_instances WHERE project_id = ? AND purpose = 'evaluation'");
        $existStmt->execute([$projectId]);
        if ($existStmt->fetch()) {
            return; // 已存在评价表单实例
        }
        
        // 创建表单实例
        $now = time();
        $instanceName = $template['name'] . ' - 项目评价';
        $fillToken = bin2hex(random_bytes(32));
        $insertStmt = $pdo->prepare("
            INSERT INTO form_instances (template_id, template_version_id, project_id, instance_name, status, purpose, fill_token, created_by, create_time, update_time)
            VALUES (?, ?, ?, ?, 'pending', 'evaluation', ?, 0, ?, ?)
        ");
        $insertStmt->execute([$templateId, $template['current_version_id'], $projectId, $instanceName, $fillToken, $now, $now]);
        
        error_log("[EVALUATION_FORM] Created evaluation form for project $projectId");
    } catch (Exception $e) {
        error_log('[EVALUATION_FORM] Error: ' . $e->getMessage());
    }
}
