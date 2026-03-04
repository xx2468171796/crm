<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 设计问卷看板 API
 * 
 * GET ?action=list          - 获取问卷列表（带权限过滤）
 * GET ?action=stats         - 获取统计数据
 * GET ?action=users         - 获取可分配的用户列表
 * POST ?action=assign       - 分配问卷给指定人员
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'list';
$isAdmin = in_array($user['role'], ['admin', 'superadmin']);

try {
    switch ($action) {
        case 'list':
            handleList($user, $isAdmin);
            break;
        case 'stats':
            handleStats($user, $isAdmin);
            break;
        case 'users':
            handleUsers();
            break;
        case 'assign':
            handleAssign($user, $isAdmin);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] design_questionnaire_list 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

function handleList($user, $isAdmin) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 20)));
    $search = trim($_GET['search'] ?? '');
    $houseStatus = trim($_GET['house_status'] ?? '');
    $budgetType = trim($_GET['budget_type'] ?? '');
    $styleMat = trim($_GET['style_maturity'] ?? '');
    $assignedTo = (int)($_GET['assigned_to'] ?? 0);
    $sortBy = $_GET['sort_by'] ?? 'update_time';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $serviceFilter = trim($_GET['service_items'] ?? '');

    $where = ['dq.status = 1'];
    $params = [];

    // 权限过滤：非管理员只能看自己创建的客户的问卷
    if (!$isAdmin) {
        $where[] = 'c.create_user_id = :user_id';
        $params['user_id'] = $user['id'];
    } elseif ($assignedTo > 0) {
        $where[] = 'c.create_user_id = :assigned_to';
        $params['assigned_to'] = $assignedTo;
    }

    // 搜索
    if ($search !== '') {
        $where[] = '(dq.client_name LIKE :search OR c.name LIKE :search2 OR c.customer_group LIKE :search3 OR c.alias LIKE :search4)';
        $params['search'] = "%{$search}%";
        $params['search2'] = "%{$search}%";
        $params['search3'] = "%{$search}%";
        $params['search4'] = "%{$search}%";
    }

    // 筛选
    if ($houseStatus !== '') {
        $where[] = 'dq.house_status = :house_status';
        $params['house_status'] = $houseStatus;
    }
    if ($budgetType !== '') {
        $where[] = 'dq.budget_type = :budget_type';
        $params['budget_type'] = $budgetType;
    }
    if ($styleMat !== '') {
        $where[] = 'dq.style_maturity = :style_maturity';
        $params['style_maturity'] = $styleMat;
    }
    if ($serviceFilter !== '') {
        $where[] = 'dq.service_items LIKE :service_filter';
        $params['service_filter'] = "%{$serviceFilter}%";
    }

    $whereClause = implode(' AND ', $where);

    // 允许排序字段
    $allowedSorts = ['update_time', 'create_time', 'client_name', 'total_area', 'budget_type'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'update_time';
    $orderField = $sortBy === 'client_name' ? 'dq.client_name' : "dq.{$sortBy}";

    // 统计总数
    $countSql = "SELECT COUNT(*) as total FROM design_questionnaires dq JOIN customers c ON dq.customer_id = c.id WHERE {$whereClause}";
    $totalRow = Db::queryOne($countSql, $params);
    $total = (int)($totalRow['total'] ?? 0);

    // 查询数据
    $offset = ($page - 1) * $pageSize;
    $sql = "SELECT dq.*, c.name as customer_name, c.alias as customer_alias, c.customer_group, 
                   c.create_user_id as owner_user_id, u_owner.realname as owner_name,
                   u_creator.realname as creator_name, u_updater.realname as updater_name
            FROM design_questionnaires dq
            JOIN customers c ON dq.customer_id = c.id
            LEFT JOIN users u_owner ON c.create_user_id = u_owner.id
            LEFT JOIN users u_creator ON dq.create_user_id = u_creator.id
            LEFT JOIN users u_updater ON dq.update_user_id = u_updater.id
            WHERE {$whereClause}
            ORDER BY {$orderField} {$sortOrder}
            LIMIT {$pageSize} OFFSET {$offset}";

    $rows = Db::query($sql, $params);

    $list = [];
    foreach ($rows as $row) {
        $list[] = formatListItem($row);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize),
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handleStats($user, $isAdmin) {
    $where = ['dq.status = 1'];
    $params = [];

    if (!$isAdmin) {
        $where[] = 'c.create_user_id = :user_id';
        $params['user_id'] = $user['id'];
    }

    $whereClause = implode(' AND ', $where);

    // 总数
    $total = Db::queryOne("SELECT COUNT(*) as cnt FROM design_questionnaires dq JOIN customers c ON dq.customer_id = c.id WHERE {$whereClause}", $params)['cnt'] ?? 0;

    // 按房屋状态分组
    $byHouseStatus = Db::query("SELECT dq.house_status, COUNT(*) as cnt FROM design_questionnaires dq JOIN customers c ON dq.customer_id = c.id WHERE {$whereClause} GROUP BY dq.house_status", $params);

    // 按预算类型分组
    $byBudget = Db::query("SELECT dq.budget_type, COUNT(*) as cnt FROM design_questionnaires dq JOIN customers c ON dq.customer_id = c.id WHERE {$whereClause} GROUP BY dq.budget_type", $params);

    // 按服务项目统计
    $byService = Db::query("SELECT dq.service_items FROM design_questionnaires dq JOIN customers c ON dq.customer_id = c.id WHERE {$whereClause} AND dq.service_items IS NOT NULL", $params);
    $serviceStats = ['floor_plan' => 0, 'rendering' => 0, 'construction' => 0, 'exterior' => 0];
    foreach ($byService as $row) {
        $items = json_decode($row['service_items'], true) ?: [];
        foreach ($items as $item) {
            if (isset($serviceStats[$item])) {
                $serviceStats[$item]++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total' => (int)$total,
            'by_house_status' => $byHouseStatus,
            'by_budget' => $byBudget,
            'by_service' => $serviceStats,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handleUsers() {
    $users = Db::query("SELECT id, username, realname, role FROM users WHERE status = 1 ORDER BY realname");
    echo json_encode(['success' => true, 'data' => $users], JSON_UNESCAPED_UNICODE);
}

function handleAssign($user, $isAdmin) {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '仅管理员可分配'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $questionnaireId = (int)($input['questionnaire_id'] ?? 0);
    $targetUserId = (int)($input['target_user_id'] ?? 0);

    if (!$questionnaireId || !$targetUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = Db::queryOne('SELECT customer_id FROM design_questionnaires WHERE id = ?', [$questionnaireId]);
    if (!$q) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '问卷不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 修改客户的 create_user_id 来实现分配
    Db::execute('UPDATE customers SET create_user_id = ? WHERE id = ?', [$targetUserId, $q['customer_id']]);

    echo json_encode(['success' => true, 'message' => '分配成功'], JSON_UNESCAPED_UNICODE);
}

function formatListItem($row) {
    $jsonFields = ['service_items', 'rendering_type', 'life_focus', 'reference_images'];
    $data = [
        'id' => (int)$row['id'],
        'customer_id' => (int)$row['customer_id'],
        'token' => $row['token'] ?? '',
        'client_name' => $row['client_name'] ?? '',
        'customer_name' => $row['customer_name'] ?? '',
        'customer_alias' => $row['customer_alias'] ?? '',
        'customer_group' => $row['customer_group'] ?? '',
        'owner_name' => $row['owner_name'] ?? '',
        'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
        'house_status' => $row['house_status'] ?? '',
        'budget_type' => $row['budget_type'] ?? '',
        'style_maturity' => $row['style_maturity'] ?? '',
        'style_description' => $row['style_description'] ?? '',
        'total_area' => $row['total_area'] ?? '',
        'area_unit' => $row['area_unit'] ?? 'sqm',
        'household_members' => $row['household_members'] ?? '',
        'delivery_deadline' => $row['delivery_deadline'] ?? '',
        'version' => (int)($row['version'] ?? 1),
        'creator_name' => $row['creator_name'] ?? '',
        'updater_name' => $row['updater_name'] ?? '',
        'create_time' => $row['create_time'] ? date('Y-m-d H:i', $row['create_time']) : null,
        'update_time' => $row['update_time'] ? date('Y-m-d H:i', $row['update_time']) : null,
    ];

    foreach ($jsonFields as $field) {
        $val = $row[$field] ?? null;
        $data[$field] = is_string($val) ? (json_decode($val, true) ?: []) : [];
    }

    return $data;
}
