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
            pta.assigned_at,
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
        WHERE pta.tech_user_id = ?
        ORDER BY pta.assigned_at DESC
    ", [$userId]);
    
    // 计算汇总
    $totalCommission = 0;
    $projectCount = count($projects);
    foreach ($projects as &$p) {
        $totalCommission += floatval($p['commission_amount'] ?? 0);
        // 强制金额/时间为数字，避免前端字符串拼接 bug
        $p['commission_amount'] = $p['commission_amount'] !== null ? (float)$p['commission_amount'] : null;
        $p['commission_set_at'] = $p['commission_set_at'] !== null ? (int)$p['commission_set_at'] : null;
    }
    unset($p);
    
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
            pta.assigned_at,
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
        WHERE {$where}
        ORDER BY u.id, pta.commission_set_at DESC
    ", $params);
    
    // 按技术人员分组汇总
    $byUser = [];
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
        // 强制金额字段为数字（避免 MySQL DECIMAL 经 json_encode 输出为字符串）
        $a['commission_amount'] = $a['commission_amount'] !== null ? (float)$a['commission_amount'] : null;
        $a['commission_set_at'] = $a['commission_set_at'] !== null ? (int)$a['commission_set_at'] : null;
        $byUser[$uid]['total_commission'] += $commission;
        $byUser[$uid]['project_count']++;
        $byUser[$uid]['projects'][] = $a;

        $totalAmount += $commission;
        $totalProjects++;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => [
                'total_amount' => $totalAmount,
                'total_projects' => $totalProjects,
                'total_users' => count($byUser),
            ],
            'by_user' => array_values($byUser),
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

    if ($assignmentId <= 0) {
        error_log('[set_commission] 错误: 参数错误');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
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
    // 兼容老接口：直接覆盖 pta 上的单条提成（不再写 entries 表，请使用 tech_commission_entries.php）
    Db::execute("
        UPDATE project_tech_assignments
        SET commission_amount = ?, commission_note = ?, commission_set_by = ?, commission_set_at = ?
        WHERE id = ?
    ", [$commissionAmount, $commissionNote, $user['id'], $setAt, $assignmentId]);
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

    // 清空所有提成（entries 时间线 + pta 缓存字段）
    Db::execute("DELETE FROM tech_commission_entries WHERE assignment_id = ?", [$assignmentId]);
    Db::execute("
        UPDATE project_tech_assignments
        SET commission_amount = NULL, commission_note = NULL, commission_set_by = NULL, commission_set_at = NULL
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
