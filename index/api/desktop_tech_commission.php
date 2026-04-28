<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 技术人员提成 API
 * 
 * 统一 API，支持桌面端认证，复用 tech_commission.php 的逻辑
 * 
 * GET ?action=my_projects - 获取我的项目提成
 * GET ?action=team_summary - 获取团队提成汇总（技术主管）
 * GET ?action=report - 获取财务报表（管理层）
 * POST action=set_commission - 设置项目提成（技术主管）
 * POST action=delete_commission - 删除项目提成（技术主管）
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/migrations.php';

ensureCustomerTypeField();

// 认证
$user = desktop_auth_require();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isManager = in_array($user['role'] ?? '', ['super_admin', 'admin', 'dept_leader', 'manager', 'tech_manager']);

try {
    switch ($action) {
        case 'my_projects':
            handleMyProjects($user);
            break;
        case 'team_summary':
            handleTeamSummary($user, $isManager);
            break;
        case 'set_commission':
            handleSetCommission($user, $isManager);
            break;
        case 'delete_commission':
            handleDeleteCommission($user, $isManager);
            break;
        case 'report':
            handleReport($user, $isManager);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_tech_commission 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取我的项目提成（技术人员）
 */
function handleMyProjects($user) {
    $userId = $user['id'];
    
    $projects = Db::query("
        SELECT
            pta.id as assignment_id,
            pta.commission_amount,
            pta.commission_note,
            pta.commission_set_at,
            pta.commission_type_id,
            pta.assigned_at,
            tct.name as commission_type_name,
            p.id as project_id,
            p.project_code,
            p.project_name,
            p.current_status as project_status,
            c.id as customer_id,
            c.name as customer_name,
            setter.realname as set_by_name
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users setter ON pta.commission_set_by = setter.id
        LEFT JOIN tech_commission_types tct ON tct.id = pta.commission_type_id
        WHERE pta.tech_user_id = ?
        ORDER BY pta.assigned_at DESC
    ", [$userId]);
    
    // 计算汇总
    $totalCommission = 0;
    $projectCount = count($projects);
    foreach ($projects as $p) {
        $totalCommission += floatval($p['commission_amount'] ?? 0);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'projects' => $projects,
            'summary' => [
                'total_commission' => $totalCommission,
                'project_count' => $projectCount,
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取团队提成汇总（技术主管）
 */
function handleTeamSummary($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限查看团队汇总'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;

    $where = "p.deleted_at IS NULL";
    $params = [];

    // 按提成日期(commission_set_at, INT时间戳)筛选
    if ($startDate) {
        $startTs = strtotime($startDate);
        if ($startTs !== false) {
            $where .= " AND pta.commission_set_at >= ?";
            $params[] = $startTs;
        }
    }
    if ($endDate) {
        $endTs = strtotime($endDate . ' 23:59:59');
        if ($endTs !== false) {
            $where .= " AND pta.commission_set_at <= ?";
            $params[] = $endTs;
        }
    }

    $assignments = Db::query("
        SELECT
            pta.id as assignment_id,
            pta.commission_amount,
            pta.commission_note,
            pta.commission_set_at,
            pta.commission_type_id,
            pta.assigned_at,
            tct.name as commission_type_name,
            p.id as project_id,
            p.project_code,
            p.project_name,
            p.current_status,
            c.name as customer_name,
            c.customer_type,
            u.id as tech_user_id,
            u.realname as tech_username
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN tech_commission_types tct ON tct.id = pta.commission_type_id
        WHERE {$where}
        ORDER BY u.id, pta.commission_set_at DESC
    ", $params);
    
    // 按技术人员分组汇总 + 按提成类型汇总
    $byUser = [];
    $byType = []; // type_id => ['type_id', 'type_name', 'total_commission', 'project_count']
    $totalAmount = 0;
    $totalProjects = 0;

    foreach ($assignments as $a) {
        $uid = $a['tech_user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'tech_user_id' => $uid,
                'tech_username' => $a['tech_username'],
                'total_commission' => 0,
                'project_count' => 0,
                'projects' => []
            ];
        }
        $commission = floatval($a['commission_amount'] ?? 0);
        $byUser[$uid]['total_commission'] += $commission;
        $byUser[$uid]['project_count']++;
        $byUser[$uid]['projects'][] = $a;

        // 按类型汇总
        $tid = $a['commission_type_id'] !== null ? (int)$a['commission_type_id'] : 0;
        $tname = $a['commission_type_name'] ?? '';
        $key = $tid;
        if (!isset($byType[$key])) {
            $byType[$key] = [
                'type_id' => $tid > 0 ? $tid : null,
                'type_name' => $tid > 0 ? $tname : '未分类',
                'total_commission' => 0,
                'project_count' => 0,
            ];
        }
        $byType[$key]['total_commission'] += $commission;
        $byType[$key]['project_count']++;

        $totalAmount += $commission;
        $totalProjects++;
    }

    // 按合计金额倒序
    usort($byType, function ($a, $b) {
        return ($b['total_commission'] <=> $a['total_commission']);
    });

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => [
                'total_amount' => $totalAmount,
                'total_projects' => $totalProjects,
                'total_users' => count($byUser),
            ],
            'by_user' => array_values($byUser),
            'by_type' => array_values($byType),
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 设置项目提成（技术主管/管理员）
 */
function handleSetCommission($user, $isManager) {
    error_log('[set_commission] 开始处理设置提成请求');
    error_log('[set_commission] user=' . json_encode($user, JSON_UNESCAPED_UNICODE));
    error_log('[set_commission] isManager=' . ($isManager ? 'true' : 'false'));
    
    if (!$isManager) {
        error_log('[set_commission] 错误: 用户无管理权限');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限设置提成'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error_log('[set_commission] 错误: 请求方法不是 POST');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $rawInput = file_get_contents('php://input');
    error_log('[set_commission] 原始输入: ' . $rawInput);
    $input = json_decode($rawInput, true);
    error_log('[set_commission] 解析后输入: ' . json_encode($input, JSON_UNESCAPED_UNICODE));
    
    $assignmentId = (int)($input['assignment_id'] ?? 0);
    $commissionAmount = (float)($input['commission_amount'] ?? 0);
    $commissionNote = trim($input['commission_note'] ?? '');
    $commissionDate = trim($input['commission_date'] ?? '');
    $commissionTypeId = isset($input['commission_type_id']) && $input['commission_type_id'] !== '' && $input['commission_type_id'] !== null
        ? (int)$input['commission_type_id']
        : null;

    if ($assignmentId <= 0) {
        error_log('[set_commission] 错误: 参数错误');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($commissionTypeId !== null && $commissionTypeId > 0) {
        $typeRow = Db::queryOne("SELECT id FROM tech_commission_types WHERE id = ? AND status = 1", [$commissionTypeId]);
        if (!$typeRow) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '所选提成类型不存在或已停用'], JSON_UNESCAPED_UNICODE);
            return;
        }
    } else {
        $commissionTypeId = null;
    }

    // 检查分配记录是否存在
    $assignment = Db::queryOne("SELECT * FROM project_tech_assignments WHERE id = ?", [$assignmentId]);

    if (!$assignment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '分配记录不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 更新提成（commission_set_at 是 INT 类型存储时间戳）
    // 如果前端传了日期，使用该日期的时间戳；否则使用当前时间
    if ($commissionDate) {
        $setAt = strtotime($commissionDate);
        if ($setAt === false) $setAt = time();
    } else {
        $setAt = time();
    }
    Db::execute("
        UPDATE project_tech_assignments
        SET commission_amount = ?, commission_note = ?, commission_set_by = ?, commission_set_at = ?, commission_type_id = ?
        WHERE id = ?
    ", [$commissionAmount, $commissionNote, $user['id'], $setAt, $commissionTypeId, $assignmentId]);
    error_log('[set_commission] 提成设置成功');
    
    echo json_encode([
        'success' => true,
        'message' => '提成设置成功',
        'data' => [
            'assignment_id' => $assignmentId,
            'commission_amount' => $commissionAmount,
            'commission_note' => $commissionNote,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 删除项目提成（技术主管/管理员）
 */
function handleDeleteCommission($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限删除提成'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $assignmentId = (int)($input['assignment_id'] ?? 0);

    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $assignment = Db::queryOne("SELECT * FROM project_tech_assignments WHERE id = ?", [$assignmentId]);
    if (!$assignment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '分配记录不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 清空提成字段（含 type_id）
    Db::execute("
        UPDATE project_tech_assignments
        SET commission_amount = NULL, commission_note = NULL, commission_set_by = NULL, commission_set_at = NULL, commission_type_id = NULL
        WHERE id = ?
    ", [$assignmentId]);

    echo json_encode([
        'success' => true,
        'message' => '提成已删除',
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取财务报表（管理层）
 */
function handleReport($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限查看报表'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $techUserId = $_GET['tech_user_id'] ?? null;
    
    $where = "p.deleted_at IS NULL";
    $params = [];
    
    if ($startDate) {
        $where .= " AND pta.commission_set_at >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $where .= " AND pta.commission_set_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }
    if ($techUserId) {
        $where .= " AND pta.tech_user_id = ?";
        $params[] = (int)$techUserId;
    }
    
    // 按技术人员汇总
    $byUser = Db::query("
        SELECT 
            u.id as tech_user_id,
            u.realname as tech_username,
            COUNT(DISTINCT pta.project_id) as project_count,
            SUM(pta.commission_amount) as total_commission
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id
        JOIN users u ON pta.tech_user_id = u.id
        WHERE {$where} AND pta.commission_amount > 0
        GROUP BY u.id, u.realname
        ORDER BY total_commission DESC
    ", $params);
    
    // 计算总计
    $totalAmount = 0;
    $totalProjects = 0;
    foreach ($byUser as $row) {
        $totalAmount += floatval($row['total_commission']);
        $totalProjects += (int)$row['project_count'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => [
                'total_amount' => $totalAmount,
                'total_projects' => $totalProjects,
                'total_users' => count($byUser),
            ],
            'by_user' => $byUser
        ]
    ], JSON_UNESCAPED_UNICODE);
}
