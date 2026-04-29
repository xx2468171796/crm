<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 技术人员项目提成 API
 * 
 * 功能：
 * - my_projects: 获取我的项目提成
 * - team_summary: 获取团队提成汇总（技术主管）
 * - set_commission: 设置项目提成（技术主管）
 * - report: 获取财务报表（管理层）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();
$pdo = Db::pdo();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'my_projects':
        handleMyProjects($pdo, $user);
        break;
    case 'team_summary':
        handleTeamSummary($pdo, $user);
        break;
    case 'set_commission':
        handleSetCommission($pdo, $user);
        break;
    case 'report':
        handleReport($pdo, $user);
        break;
    case 'report_detailed':
        handleReportDetailed($pdo, $user);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取我的项目提成（技术人员）
 */
function handleMyProjects($pdo, $user) {
    $userId = $user['id'];
    
    // 获取分配给当前用户的项目及提成
    $sql = "
        SELECT 
            pta.id as assignment_id,
            pta.commission_amount,
            pta.commission_note,
            pta.commission_set_at,
            pta.assigned_at,
            p.id as project_id,
            p.project_name,
            p.current_status as project_status,
            c.id as customer_id,
            c.name as customer_name,
            setter.username as set_by_name
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users setter ON pta.commission_set_by = setter.id
        WHERE pta.tech_user_id = ?
        ORDER BY pta.assigned_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
                'project_count' => $projectCount
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取团队提成汇总（技术主管/部门主管）
 */
function handleTeamSummary($pdo, $user) {
    // 权限检查：部门主管或管理员
    if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $departmentId = $_GET['department_id'] ?? null;
    $techUserId = $_GET['tech_user_id'] ?? null;
    
    $where = "1=1";
    $params = [];
    
    // 非管理员只能查看本部门
    if (!isAdmin($user)) {
        $departmentId = $user['department_id'];
    }
    
    // 部门筛选
    if ($departmentId) {
        $where .= " AND u.department_id = ?";
        $params[] = $departmentId;
    }
    
    // 技术人员筛选
    if ($techUserId) {
        $where .= " AND pta.tech_user_id = ?";
        $params[] = $techUserId;
    }
    
    $sql = "
        SELECT 
            pta.id as assignment_id,
            pta.commission_amount,
            pta.commission_note,
            pta.commission_set_at,
            pta.assigned_at,
            p.id as project_id,
            p.project_name,
            p.current_status as project_status,
            c.id as customer_id,
            c.name as customer_name,
            u.id as tech_user_id,
            u.username as tech_username,
            u.department_id,
            d.name as department_name
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE $where
        ORDER BY u.id, pta.assigned_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按技术人员分组汇总
    $byUser = [];
    foreach ($assignments as $a) {
        $uid = $a['tech_user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'tech_user_id' => $uid,
                'tech_username' => $a['tech_username'],
                'department_name' => $a['department_name'],
                'total_commission' => 0,
                'project_count' => 0,
                'projects' => []
            ];
        }
        $byUser[$uid]['total_commission'] += floatval($a['commission_amount'] ?? 0);
        $byUser[$uid]['project_count']++;
        $byUser[$uid]['projects'][] = $a;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'assignments' => $assignments,
            'by_user' => array_values($byUser)
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 设置项目提成（技术主管/管理员）
 * 管理员可以设置所有人的提成
 * 部门主管只能设置本部门的提成
 */
function handleSetCommission($pdo, $user) {
    // 权限检查：管理员、部门主管、经理、技术主管
    $allowedRoles = ['super_admin', 'admin', 'dept_leader', 'manager', 'tech_manager'];
    if (!isAdmin($user) && !in_array($user['role'] ?? '', $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限设置提成'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $assignmentId = intval($data['assignment_id'] ?? 0);
    $commissionAmount = floatval($data['commission_amount'] ?? 0);
    $commissionNote = trim($data['commission_note'] ?? '');

    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查分配记录是否存在
    $stmt = $pdo->prepare("
        SELECT pta.*, u.department_id 
        FROM project_tech_assignments pta 
        JOIN users u ON pta.tech_user_id = u.id 
        WHERE pta.id = ?
    ");
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '分配记录不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 管理员可以设置任何人的提成；非管理员只能设置本部门的
    if (!isAdmin($user) && $assignment['department_id'] != $user['department_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '只能设置本部门成员的提成'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 兼容老接口：直接覆盖 pta 上的单条提成（不再写 entries 表，请使用 tech_commission_entries.php）
    $stmt = $pdo->prepare("
        UPDATE project_tech_assignments
        SET commission_amount = ?, commission_set_by = ?, commission_set_at = ?, commission_note = ?
        WHERE id = ?
    ");
    $stmt->execute([$commissionAmount, $user['id'], time(), $commissionNote, $assignmentId]);
    
    echo json_encode(['success' => true, 'message' => '提成设置成功'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取财务报表（管理层）
 */
function handleReport($pdo, $user) {
    // 权限检查：仅管理员
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限查看报表'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $techUserId = $_GET['tech_user_id'] ?? null;
    $departmentId = $_GET['department_id'] ?? null;
    
    $where = "1=1";
    $params = [];
    
    // 时间筛选（按提成设置时间）
    if ($startDate) {
        $where .= " AND pta.commission_set_at >= ?";
        $params[] = strtotime($startDate);
    }
    if ($endDate) {
        $where .= " AND pta.commission_set_at <= ?";
        $params[] = strtotime($endDate) + 86399;
    }
    
    // 技术人员筛选
    if ($techUserId) {
        $where .= " AND pta.tech_user_id = ?";
        $params[] = $techUserId;
    }
    
    // 部门筛选
    if ($departmentId) {
        $where .= " AND u.department_id = ?";
        $params[] = $departmentId;
    }
    
    // 按技术人员汇总
    $sql = "
        SELECT 
            u.id as tech_user_id,
            u.username as tech_username,
            d.name as department_name,
            COUNT(pta.id) as project_count,
            SUM(IFNULL(pta.commission_amount, 0)) as total_commission
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE $where AND pta.commission_amount IS NOT NULL
        GROUP BY u.id
        ORDER BY total_commission DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 总计
    $totalCommission = 0;
    $totalProjects = 0;
    foreach ($byUser as $row) {
        $totalCommission += floatval($row['total_commission']);
        $totalProjects += intval($row['project_count']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'by_user' => $byUser,
            'summary' => [
                'total_commission' => $totalCommission,
                'total_projects' => $totalProjects,
                'user_count' => count($byUser)
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取详细报表 —— 时间范围按 entries.entry_at 逐条聚合
 *
 * 时段内合计 = 该时段内 entries.amount 的 SUM
 * set_count: 时段内有 ≥1 条 entry 的项目数（或无时段筛选时 = 累计有提成的项目数）
 * unset_count: 没有时段内 entry 的项目数
 */
function handleReportDetailed($pdo, $user) {
    if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $departmentId = intval($_GET['department_id'] ?? 0);
    $techUserId = intval($_GET['tech_user_id'] ?? 0);
    $dateStart = trim($_GET['date_start'] ?? '');
    $dateEnd = trim($_GET['date_end'] ?? '');
    $projectStatus = trim($_GET['project_status'] ?? '');
    $keyword = trim($_GET['keyword'] ?? '');

    // assignment 级筛选条件（与时间无关）
    $assignWhere = 'p.deleted_at IS NULL';
    $assignParams = [];
    if (!isAdmin($user)) {
        $assignWhere .= ' AND u.department_id = ?';
        $assignParams[] = $user['department_id'];
    } elseif ($departmentId > 0) {
        $assignWhere .= ' AND u.department_id = ?';
        $assignParams[] = $departmentId;
    }
    if ($techUserId > 0) {
        $assignWhere .= ' AND pta.tech_user_id = ?';
        $assignParams[] = $techUserId;
    }
    if ($projectStatus) {
        $assignWhere .= ' AND p.current_status = ?';
        $assignParams[] = $projectStatus;
    }
    if ($keyword) {
        $assignWhere .= ' AND (p.project_name LIKE ? OR p.project_code LIKE ? OR c.name LIKE ? OR u.realname LIKE ?)';
        $kw = "%{$keyword}%";
        $assignParams = array_merge($assignParams, [$kw, $kw, $kw, $kw]);
    }

    // entry 级时间筛选（仅作用于 entries.entry_at）
    $entryDateClause = '';
    $entryDateParams = [];
    if ($dateStart) {
        $startTs = strtotime($dateStart);
        if ($startTs !== false) { $entryDateClause .= ' AND e.entry_at >= ?'; $entryDateParams[] = $startTs; }
    }
    if ($dateEnd) {
        $endTs = strtotime($dateEnd . ' 23:59:59');
        if ($endTs !== false) { $entryDateClause .= ' AND e.entry_at <= ?'; $entryDateParams[] = $endTs; }
    }
    $hasDateFilter = !empty($entryDateClause);

    // 1) 取所有 assignment + 项目 + 设计师 + 部门
    $assignSql = "
        SELECT
            pta.id as assignment_id,
            pta.tech_user_id,
            pta.assigned_at,
            u.id as user_id,
            COALESCE(u.realname, u.username) as realname,
            u.username,
            d.id as department_id,
            d.name as department_name,
            p.id as project_id,
            p.project_code,
            p.project_name,
            p.current_status,
            c.name as customer_name
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE $assignWhere
        ORDER BY pta.tech_user_id, pta.assigned_at DESC
    ";
    $stmt = $pdo->prepare($assignSql);
    $stmt->execute($assignParams);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) 拉取这些 assignment 在时段内（或全部）的 entries
    $entriesByAssignment = [];
    $assignmentIds = array_column($assignments, 'assignment_id');
    if (!empty($assignmentIds)) {
        $ph = implode(',', array_fill(0, count($assignmentIds), '?'));
        $entriesSql = "SELECT e.id, e.assignment_id, e.amount, e.note, e.entry_at
                       FROM tech_commission_entries e
                       WHERE e.assignment_id IN ($ph)" . $entryDateClause . "
                       ORDER BY e.assignment_id, e.entry_at DESC, e.id DESC";
        $stmt2 = $pdo->prepare($entriesSql);
        $stmt2->execute(array_merge(array_values($assignmentIds), $entryDateParams));
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aid = (int)$r['assignment_id'];
            if (!isset($entriesByAssignment[$aid])) $entriesByAssignment[$aid] = [];
            $entriesByAssignment[$aid][] = $r;
        }
    }

    // 3) 按 user 聚合
    $byUser = [];
    foreach ($assignments as $a) {
        $uid = (int)$a['user_id'];
        $entries = $entriesByAssignment[(int)$a['assignment_id']] ?? [];
        $sumAmount = 0.0;
        foreach ($entries as $e) { $sumAmount += (float)$e['amount']; }
        $entryCount = count($entries);
        $latest = $entries[0] ?? null; // 已按 entry_at DESC 排序

        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id' => $uid,
                'realname' => $a['realname'],
                'username' => $a['username'],
                'department_id' => $a['department_id'],
                'department_name' => $a['department_name'],
                'project_count' => 0,
                'total_commission' => 0.0,
                'set_count' => 0,
                'unset_count' => 0,
                'projects' => [],
            ];
        }
        // 时段筛选打开时只列有命中 entry 的项目，未命中的不展示（避免误导）
        if ($hasDateFilter && $entryCount === 0) {
            continue;
        }
        $byUser[$uid]['project_count']++;
        $byUser[$uid]['total_commission'] += $sumAmount;
        if ($entryCount > 0) {
            $byUser[$uid]['set_count']++;
        } else {
            $byUser[$uid]['unset_count']++;
        }
        $byUser[$uid]['projects'][] = [
            'assignment_id' => (int)$a['assignment_id'],
            'tech_user_id' => $uid,
            'project_id' => (int)$a['project_id'],
            'project_code' => $a['project_code'],
            'project_name' => $a['project_name'],
            'current_status' => $a['current_status'],
            'customer_name' => $a['customer_name'],
            'assigned_at' => $a['assigned_at'],
            'commission_amount' => $sumAmount,
            'commission_note' => $latest['note'] ?? null,
            'commission_set_at' => $latest ? (int)$latest['entry_at'] : null,
            'entry_count_in_range' => $entryCount,
            'entries' => array_map(function ($e) {
                return [
                    'id' => (int)$e['id'],
                    'amount' => (float)$e['amount'],
                    'note' => $e['note'],
                    'entry_at' => (int)$e['entry_at'],
                ];
            }, $entries),
        ];
    }

    // 排序：按 total_commission DESC, project_count DESC
    $byUserList = array_values($byUser);
    usort($byUserList, function ($a, $b) {
        if ($b['total_commission'] != $a['total_commission']) return $b['total_commission'] <=> $a['total_commission'];
        return $b['project_count'] <=> $a['project_count'];
    });

    $totalCommission = 0;
    $totalProjects = 0;
    $totalSetCount = 0;
    $totalUnsetCount = 0;
    foreach ($byUserList as $row) {
        $totalCommission += $row['total_commission'];
        $totalProjects += $row['project_count'];
        $totalSetCount += $row['set_count'];
        $totalUnsetCount += $row['unset_count'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $byUserList,
            'summary' => [
                'total_commission' => $totalCommission,
                'total_projects' => $totalProjects,
                'user_count' => count($byUserList),
                'set_count' => $totalSetCount,
                'unset_count' => $totalUnsetCount,
                'avg_commission' => $totalProjects > 0 ? round($totalCommission / $totalProjects, 2) : 0,
            ],
            'date_range' => [
                'start' => $dateStart ?: null,
                'end' => $dateEnd ?: null,
                'has_filter' => $hasDateFilter,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}
