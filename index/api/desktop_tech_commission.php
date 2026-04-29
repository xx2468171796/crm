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
 * 获取团队提成汇总（技术主管）—— 按 entries.entry_at 逐条聚合
 * 时间范围筛选作用在每条 entry 的 entry_at 上，每个设计师的 total
 * = 该范围内 entries.amount 之和；只统计有命中 entries 的合同
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

    if ($startDate) {
        $startTs = strtotime($startDate);
        if ($startTs !== false) {
            $where .= " AND e.entry_at >= ?";
            $params[] = $startTs;
        }
    }
    if ($endDate) {
        $endTs = strtotime($endDate . ' 23:59:59');
        if ($endTs !== false) {
            $where .= " AND e.entry_at <= ?";
            $params[] = $endTs;
        }
    }

    // 逐条 entry 拉取，连带 assignment / project / 设计师 / 客户信息
    $rows = Db::query("
        SELECT
            e.id as entry_id,
            e.amount as entry_amount,
            e.note as entry_note,
            e.entry_at,
            pta.id as assignment_id,
            pta.assigned_at,
            p.id as project_id,
            p.project_code,
            p.project_name,
            p.current_status,
            c.name as customer_name,
            c.customer_type,
            u.id as tech_user_id,
            u.realname as tech_username
        FROM tech_commission_entries e
        JOIN project_tech_assignments pta ON pta.id = e.assignment_id
        JOIN projects p ON p.id = pta.project_id
        JOIN users u ON u.id = pta.tech_user_id
        LEFT JOIN customers c ON c.id = p.customer_id
        WHERE {$where}
        ORDER BY u.id, p.id, e.entry_at DESC, e.id DESC
    ", $params);

    // 按设计师 → 项目两级聚合
    $byUser = [];
    $totalAmount = 0;
    foreach ($rows as $r) {
        $uid = (int)$r['tech_user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'tech_user_id' => $uid,
                'tech_username' => $r['tech_username'],
                'total_commission' => 0.0,
                'project_count' => 0,
                'projects' => [],
                '_project_index' => [], // pid => index in projects[]
            ];
        }
        $u =& $byUser[$uid];
        $pid = (int)$r['project_id'];
        $amt = (float)$r['entry_amount'];

        if (!isset($u['_project_index'][$pid])) {
            $u['_project_index'][$pid] = count($u['projects']);
            $u['projects'][] = [
                'assignment_id' => (int)$r['assignment_id'],
                'project_id' => $pid,
                'project_code' => $r['project_code'],
                'project_name' => $r['project_name'],
                'current_status' => $r['current_status'],
                'customer_name' => $r['customer_name'],
                'customer_type' => $r['customer_type'],
                'commission_amount' => 0.0,         // 时段内本项目累计
                'commission_note' => $r['entry_note'], // 最近一条备注（行已按时间倒序，所以第一次见到的是最新的）
                'commission_set_at' => (int)$r['entry_at'], // 最近一条 entry_at
                'entry_count_in_range' => 0,
                'entries' => [],                     // 本项目在时段内的全部 entries（每条独立一行）
                'assigned_at' => $r['assigned_at'],
            ];
            $u['project_count']++;
        }
        $idx = $u['_project_index'][$pid];
        $u['projects'][$idx]['commission_amount'] += $amt;
        $u['projects'][$idx]['entry_count_in_range']++;
        $u['projects'][$idx]['entries'][] = [
            'id' => (int)$r['entry_id'],
            'amount' => $amt,
            'note' => $r['entry_note'],
            'entry_at' => (int)$r['entry_at'],
        ];

        $u['total_commission'] += $amt;
        $totalAmount += $amt;
        unset($u);
    }
    // 清理内部索引
    foreach ($byUser as &$u) {
        unset($u['_project_index']);
    }
    unset($u);

    // 按 total 倒序排
    $byUserList = array_values($byUser);
    usort($byUserList, function ($a, $b) { return $b['total_commission'] <=> $a['total_commission']; });

    $totalProjects = 0;
    foreach ($byUserList as $u) { $totalProjects += $u['project_count']; }

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => [
                'total_amount' => $totalAmount,
                'total_projects' => $totalProjects,
                'total_users' => count($byUserList),
            ],
            'by_user' => $byUserList,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
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

    // 提成日期：前端传则用，否则当前时间
    if ($commissionDate) {
        $setAt = strtotime($commissionDate);
        if ($setAt === false) $setAt = time();
    } else {
        $setAt = time();
    }

    // 老接口语义是"覆盖式"：清空原有 entries + 新插一条，再同步 pta 缓存。
    // 这样保证旧版桌面调这个 API 时也走 entries 表，不会产生孤儿数据。
    try {
        Db::beginTransaction();
        Db::execute("DELETE FROM tech_commission_entries WHERE assignment_id = ?", [$assignmentId]);
        if ($commissionAmount > 0) {
            $now = time();
            Db::execute(
                "INSERT INTO tech_commission_entries (assignment_id, amount, note, entry_at, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$assignmentId, $commissionAmount, $commissionNote !== '' ? $commissionNote : null, $setAt, $user['id'], $now, $now]
            );
        }
        // 同步 pta 缓存
        Db::execute(
            "UPDATE project_tech_assignments
             SET commission_amount = ?, commission_note = ?, commission_set_by = ?, commission_set_at = ?
             WHERE id = ?",
            [$commissionAmount > 0 ? $commissionAmount : null, $commissionNote !== '' ? $commissionNote : null, $user['id'], $commissionAmount > 0 ? $setAt : null, $assignmentId]
        );
        Db::commit();
    } catch (Exception $e) {
        Db::rollBack();
        error_log('[set_commission] 失败: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    error_log('[set_commission] 提成设置成功（已写入 entries）');
    
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
