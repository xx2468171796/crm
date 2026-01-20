<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 项目管理 API
 * 
 * GET ?action=kanban - 获取看板数据（按状态分组）
 * GET ?action=list - 获取项目列表
 * GET ?action=filters - 获取筛选选项
 * POST action=change_status - 变更项目状态
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/constants.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

// 认证
$user = desktop_auth_require();

// 项目状态列表（从常量生成）
$STATUS_COLORS = ['#6366f1', '#8b5cf6', '#ec4899', '#f97316', '#14b8a6', '#10b981'];
$STATUS_LIST = array_map(function($status, $i) use ($STATUS_COLORS) {
    return ['key' => $status, 'label' => $status, 'color' => $STATUS_COLORS[$i] ?? '#6366f1'];
}, PROJECT_STATUSES, array_keys(PROJECT_STATUSES));

$action = $_GET['action'] ?? $_POST['action'] ?? 'kanban';

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    switch ($action) {
        case 'kanban':
            handleKanban($user, $isManager, $STATUS_LIST);
            break;
        case 'list':
            handleList($user, $isManager);
            break;
        case 'filters':
            handleFilters($user, $isManager);
            break;
        case 'change_status':
            handleChangeStatus($user, $isManager);
            break;
        case 'update_status_time':
            handleUpdateStatusTime($user, $isManager);
            break;
        case 'update_dates':
            handleUpdateDates($user, $isManager);
            break;
        case 'statuses':
            echo json_encode(['success' => true, 'data' => $STATUS_LIST], JSON_UNESCAPED_UNICODE);
            break;
        case 'assign_tech':
            handleAssignTech($user, $isManager);
            break;
        case 'customers':
            handleCustomers($user, $isManager);
            break;
        case 'create_project':
            handleCreateProject($user, $isManager);
            break;
        case 'tech_users':
            handleTechUsers($user, $isManager);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_projects 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取看板数据（按状态分组）
 */
function handleKanban($user, $isManager, $statusList) {
    // 筛选参数
    $filterUserId = $_GET['user_id'] ?? null;
    $filterStatus = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    $userType = $_GET['user_type'] ?? 'tech'; // tech 或 sales
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    // 构建查询条件
    $conditions = ["p.deleted_at IS NULL"];
    $params = [];
    
    // 非管理员只能看自己的项目
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    } elseif ($filterUserId) {
        // 管理员筛选特定用户
        if ($userType === 'tech') {
            $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        } else {
            $conditions[] = "p.sales_id = ?";
        }
        $params[] = (int)$filterUserId;
    }
    
    // 状态筛选
    if ($filterStatus) {
        $conditions[] = "p.current_status = ?";
        $params[] = $filterStatus;
    }
    
    // 时间筛选（按创建时间）
    if ($startDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(p.create_time)) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(p.create_time)) <= ?";
        $params[] = $endDate;
    }
    
    // 搜索
    if ($search) {
        $conditions[] = "(p.project_name LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 查询项目
    $sql = "
        SELECT 
            p.id,
            p.project_code,
            p.project_name,
            p.current_status,
            p.create_time,
            p.update_time,
            c.id as customer_id,
            c.name as customer_name,
            c.group_code as customer_group_code,
            c.customer_group as customer_group_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        WHERE {$whereClause}
        ORDER BY p.update_time DESC
        LIMIT 200
    ";
    
    $projects = Db::query($sql, $params);
    
    // 获取每个项目的技术负责人（包含提成信息）
    $projectIds = array_column($projects, 'id');
    $techAssignments = [];
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $techSql = "
            SELECT pta.id as assignment_id, pta.project_id, pta.commission_amount, pta.commission_note,
                   u.id as user_id, u.realname, u.username
            FROM project_tech_assignments pta
            LEFT JOIN users u ON pta.tech_user_id = u.id
            WHERE pta.project_id IN ({$placeholders})
        ";
        $techRows = Db::query($techSql, $projectIds);
        foreach ($techRows as $row) {
            if (!isset($techAssignments[$row['project_id']])) {
                $techAssignments[$row['project_id']] = [];
            }
            $techAssignments[$row['project_id']][] = [
                'id' => (int)$row['user_id'],
                'name' => $row['realname'] ?: $row['username'],
                'assignment_id' => (int)$row['assignment_id'],
                'commission' => $row['commission_amount'] !== null ? (float)$row['commission_amount'] : null,
                'commission_note' => $row['commission_note'],
            ];
        }
    }
    
    // 按状态分组
    $kanban = [];
    foreach ($statusList as $status) {
        $kanban[$status['key']] = [
            'status' => $status,
            'projects' => [],
        ];
    }
    
    foreach ($projects as $project) {
        $status = $project['current_status'] ?: '待沟通';
        if (!isset($kanban[$status])) {
            // 未知状态放入待沟通
            $status = '待沟通';
        }
        
        $kanban[$status]['projects'][] = [
            'id' => (int)$project['id'],
            'project_code' => $project['project_code'],
            'project_name' => $project['project_name'],
            'current_status' => $project['current_status'],
            'customer_id' => (int)$project['customer_id'],
            'customer_name' => $project['customer_name'],
            'customer_group_code' => $project['customer_group_code'],
            'customer_group_name' => $project['customer_group_name'] ?? null,
            'tech_users' => $techAssignments[$project['id']] ?? [],
            'update_time' => $project['update_time'] ? date('Y-m-d H:i', $project['update_time']) : null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'statuses' => $statusList,
            'kanban' => $kanban,
            'total' => count($projects),
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取项目列表
 */
function handleList($user, $isManager) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
    $filterStatus = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $conditions = ["p.deleted_at IS NULL"];
    $params = [];
    
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    }
    
    if ($filterStatus) {
        $conditions[] = "p.current_status = ?";
        $params[] = $filterStatus;
    }
    
    if ($search) {
        $conditions[] = "(p.project_name LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 总数
    $countSql = "SELECT COUNT(*) as total FROM projects p LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL WHERE {$whereClause}";
    $countResult = Db::queryOne($countSql, $params);
    $total = (int)($countResult['total'] ?? 0);
    
    // 数据
    $offset = ($page - 1) * $perPage;
    $sql = "
        SELECT 
            p.id, p.project_code, p.project_name, p.current_status, p.create_time, p.update_time,
            c.id as customer_id, c.name as customer_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        WHERE {$whereClause}
        ORDER BY p.update_time DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    
    $projects = Db::query($sql, $params);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $projects,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取筛选选项
 */
function handleFilters($user, $isManager) {
    $userType = $_GET['user_type'] ?? 'tech';
    
    // 获取人员列表
    $users = [];
    if ($isManager) {
        if ($userType === 'tech') {
            $users = Db::query("SELECT id, username, realname FROM users WHERE role IN ('tech', 'tech_manager') AND status = 1 ORDER BY realname");
        } else {
            $users = Db::query("SELECT id, username, realname FROM users WHERE role IN ('sales', 'sales_manager') AND status = 1 ORDER BY realname");
        }
    }
    
    $userList = [];
    foreach ($users as $u) {
        $userList[] = [
            'id' => (int)$u['id'],
            'name' => $u['realname'] ?: $u['username'],
        ];
    }
    
    // 状态列表（使用常量）
    $statuses = PROJECT_STATUSES;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $userList,
            'statuses' => $statuses,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 变更项目状态
 * 使用统一的 ProjectService 确保数据一致性
 */
function handleChangeStatus($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = (int)($input['project_id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    
    if ($projectId <= 0 || empty($newStatus)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证权限：管理员或技术人员角色都可以修改
    $isTechRole = ($user['role'] ?? '') === 'tech';
    if (!$isManager && !$isTechRole) {
        $check = Db::queryOne(
            "SELECT 1 FROM project_tech_assignments WHERE project_id = ? AND tech_user_id = ?",
            [$projectId, $user['id']]
        );
        if (!$check) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '无权限修改此项目'], JSON_UNESCAPED_UNICODE);
            return;
        }
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
        echo json_encode(['success' => false, 'error' => $result['message']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => '状态已更新',
        'data' => [
            'project_id' => $projectId,
            'new_status' => $newStatus,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 更新状态时间
 */
function handleUpdateStatusTime($user, $isManager) {
    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = (int)($input['project_id'] ?? 0);
    $status = $input['status'] ?? '';
    $newTime = $input['new_time'] ?? '';
    
    if (!$projectId || !$status || !$newTime) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 转换时间
    $timestamp = strtotime($newTime);
    if (!$timestamp) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '无效的时间格式'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 更新状态日志中的时间
    Db::execute(
        "UPDATE project_status_log SET changed_at = ? WHERE project_id = ? AND to_status = ?",
        [$timestamp, $projectId, $status]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '时间已更新',
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 更新项目日期
 */
function handleUpdateDates($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = (int)($input['project_id'] ?? 0);
    $startDate = $input['start_date'] ?? null;
    $deadline = $input['deadline'] ?? null;
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证权限：管理员或技术人员角色都可以修改
    // 技术人员只能看到分配给他们的项目，所以允许修改
    $isTechRole = ($user['role'] ?? '') === 'tech';
    if (!$isManager && !$isTechRole) {
        $check = Db::queryOne(
            "SELECT 1 FROM project_tech_assignments WHERE project_id = ? AND tech_user_id = ?",
            [$projectId, $user['id']]
        );
        if (!$check) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '无权限修改此项目'], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    
    // 更新日期
    $now = time();
    Db::execute(
        "UPDATE projects SET start_date = ?, deadline = ?, update_time = ? WHERE id = ?",
        [$startDate ?: null, $deadline ?: null, $now, $projectId]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '日期已更新',
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 分配技术人员
 */
function handleAssignTech($user, $isManager) {
    error_log('[assign_tech] 开始处理分配技术人员请求');
    error_log('[assign_tech] user=' . json_encode($user, JSON_UNESCAPED_UNICODE));
    error_log('[assign_tech] isManager=' . ($isManager ? 'true' : 'false'));
    error_log('[assign_tech] method=' . $_SERVER['REQUEST_METHOD']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log('[assign_tech] 错误: 请求方法不是 POST');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!$isManager) {
        error_log('[assign_tech] 错误: 用户无管理权限');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限分配技术人员'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $rawInput = file_get_contents('php://input');
    error_log('[assign_tech] 原始输入: ' . $rawInput);
    $input = json_decode($rawInput, true);
    error_log('[assign_tech] 解析后输入: ' . json_encode($input, JSON_UNESCAPED_UNICODE));
    $projectId = (int)($input['project_id'] ?? 0);
    $techUserIds = $input['tech_user_ids'] ?? [];
    error_log('[assign_tech] projectId=' . $projectId . ', techUserIds=' . json_encode($techUserIds));
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!is_array($techUserIds)) {
        $techUserIds = [];
    }
    
    // 检查项目是否存在
    $project = Db::queryOne("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL", [$projectId]);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取当前分配的技术人员
    $currentAssignments = Db::query(
        "SELECT tech_user_id FROM project_tech_assignments WHERE project_id = ?",
        [$projectId]
    );
    $currentIds = array_column($currentAssignments, 'tech_user_id');
    
    // 计算需要添加和删除的
    $toAdd = array_diff($techUserIds, $currentIds);
    $toRemove = array_diff($currentIds, $techUserIds);
    
    $now = time();
    
    // 使用事务确保原子性
    try {
        Db::beginTransaction();
        
        // 删除不再分配的
        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            Db::execute(
                "DELETE FROM project_tech_assignments WHERE project_id = ? AND tech_user_id IN ({$placeholders})",
                array_merge([$projectId], array_values($toRemove))
            );
        }
        
        // 添加新分配的
        foreach ($toAdd as $techUserId) {
            Db::execute(
                "INSERT INTO project_tech_assignments (project_id, tech_user_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?)",
                [$projectId, $techUserId, $user['id'], $now]
            );
        }
        
        Db::commit();
        error_log('[assign_tech] 事务提交成功');
    } catch (Exception $e) {
        Db::rollBack();
        error_log('[assign_tech] 事务失败: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '分配失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 发送项目分配通知（事务外）
    if (!empty($toAdd)) {
        try {
            $projectInfo = Db::queryOne("SELECT project_code, project_name FROM projects WHERE id = ?", [$projectId]);
            $assignerName = $user['realname'] ?? $user['username'];
            foreach ($toAdd as $techUserId) {
                createProjectAssignNotification($techUserId, $projectId, $projectInfo['project_code'], $projectInfo['project_name'], $assignerName);
            }
        } catch (Exception $e) {
            error_log('[assign_tech] 发送通知失败: ' . $e->getMessage());
        }
    }
    
    // 获取更新后的技术人员列表
    $techUsers = Db::query(
        "SELECT pta.id as assignment_id, u.id, u.realname as name, u.username, pta.commission_amount as commission, pta.commission_note
         FROM project_tech_assignments pta
         LEFT JOIN users u ON pta.tech_user_id = u.id
         WHERE pta.project_id = ?",
        [$projectId]
    );
    
    $result = [];
    foreach ($techUsers as $tech) {
        $result[] = [
            'id' => (int)$tech['id'],
            'name' => $tech['name'] ?: $tech['username'],
            'assignment_id' => (int)$tech['assignment_id'],
            'commission' => $tech['commission'] !== null ? (float)$tech['commission'] : null,
            'commission_note' => $tech['commission_note'],
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => '技术人员分配成功',
        'data' => [
            'tech_users' => $result,
            'added' => count($toAdd),
            'removed' => count($toRemove),
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 创建项目分配通知
 */
function createProjectAssignNotification($userId, $projectId, $projectCode, $projectName, $assignerName) {
    $now = time();
    $content = "{$assignerName} 将项目 [{$projectCode}] {$projectName} 分配给你";
    
    Db::execute("
        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
        VALUES (?, 'project', '项目分配', ?, 'project', ?, 0, ?)
    ", [
        $userId,
        $content,
        $projectId,
        $now
    ]);
}

/**
 * 获取客户列表（含项目统计）
 */
function handleCustomers($user, $isManager) {
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(1000, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    $conditions = ["c.deleted_at IS NULL"];
    $params = [];
    
    if ($search) {
        $conditions[] = "(c.name LIKE ? OR c.group_code LIKE ? OR c.customer_group LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 总数
    $countSql = "SELECT COUNT(*) as total FROM customers c WHERE {$whereClause}";
    $countResult = Db::queryOne($countSql, $params);
    $total = (int)($countResult['total'] ?? 0);
    
    // 客户列表（含项目统计）
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.group_code,
            c.customer_group,
            c.alias,
            c.mobile,
            c.create_time,
            (SELECT COUNT(*) FROM projects p WHERE p.customer_id = c.id AND p.deleted_at IS NULL) as project_count,
            (SELECT MAX(p.update_time) FROM projects p WHERE p.customer_id = c.id AND p.deleted_at IS NULL) as last_project_time
        FROM customers c
        WHERE {$whereClause}
        ORDER BY last_project_time DESC, c.create_time DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    
    $customers = Db::query($sql, $params);
    
    $result = [];
    foreach ($customers as $c) {
        $result[] = [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'group_code' => $c['group_code'],
            'group_name' => $c['customer_group'] ?: $c['alias'],
            'phone' => $c['mobile'],
            'project_count' => (int)$c['project_count'],
            'create_time' => $c['create_time'] ? date('Y-m-d', $c['create_time']) : null,
            'last_activity' => $c['last_project_time'] ? date('Y-m-d H:i', $c['last_project_time']) : null,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $result,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 创建项目
 */
function handleCreateProject($user, $isManager) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限创建项目'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $customerId = (int)($input['customer_id'] ?? 0);
    $projectName = trim($input['project_name'] ?? '');
    $techUserIds = $input['tech_user_ids'] ?? [];
    
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($projectName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '项目名称不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查客户是否存在
    $customer = Db::queryOne("SELECT id, name, group_code FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    
    // 生成项目编号
    $datePrefix = date('Ymd');
    $lastProject = Db::queryOne(
        "SELECT project_code FROM projects WHERE project_code LIKE ? ORDER BY id DESC LIMIT 1",
        ["P{$datePrefix}%"]
    );
    if ($lastProject && preg_match('/P\d{8}(\d{3})/', $lastProject['project_code'], $m)) {
        $seq = (int)$m[1] + 1;
    } else {
        $seq = 1;
    }
    $projectCode = "P{$datePrefix}" . str_pad($seq, 3, '0', STR_PAD_LEFT);
    
    try {
        Db::beginTransaction();
        
        // 创建项目
        Db::execute(
            "INSERT INTO projects (project_code, project_name, customer_id, current_status, create_time, update_time, created_by) VALUES (?, ?, ?, '待沟通', ?, ?, ?)",
            [$projectCode, $projectName, $customerId, $now, $now, $user['id']]
        );
        $projectId = Db::lastInsertId();
        
        // 分配技术人员
        if (is_array($techUserIds) && !empty($techUserIds)) {
            foreach ($techUserIds as $techUserId) {
                Db::execute(
                    "INSERT INTO project_tech_assignments (project_id, tech_user_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?)",
                    [$projectId, (int)$techUserId, $user['id'], $now]
                );
            }
        }
        
        // 记录状态日志
        Db::execute(
            "INSERT INTO project_status_log (project_id, from_status, to_status, changed_by, changed_at) VALUES (?, '', '待沟通', ?, ?)",
            [$projectId, $user['id'], $now]
        );
        
        Db::commit();
        
        // 发送分配通知
        if (!empty($techUserIds)) {
            $assignerName = $user['realname'] ?? $user['username'];
            foreach ($techUserIds as $techUserId) {
                createProjectAssignNotification((int)$techUserId, $projectId, $projectCode, $projectName, $assignerName);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => '项目创建成功',
            'data' => [
                'id' => (int)$projectId,
                'project_code' => $projectCode,
                'project_name' => $projectName,
                'customer_id' => $customerId,
                'customer_name' => $customer['name'],
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        Db::rollBack();
        error_log('[create_project] 创建项目失败: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '创建项目失败'], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取技术人员列表
 */
function handleTechUsers($user, $isManager) {
    $techUsers = Db::query(
        "SELECT id, username, realname FROM users WHERE role IN ('tech', 'tech_manager') AND status = 1 ORDER BY realname, username"
    );
    
    $result = [];
    foreach ($techUsers as $u) {
        $result[] = [
            'id' => (int)$u['id'],
            'name' => $u['realname'] ?: $u['username'],
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);
}

