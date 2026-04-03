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
    
    // 更新提成
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
 * 获取详细报表（支持筛选、分组、展开明细）
 */
function handleReportDetailed($pdo, $user) {
    // 权限检查
    if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 筛选参数
    $departmentId = intval($_GET['department_id'] ?? 0);
    $techUserId = intval($_GET['tech_user_id'] ?? 0);
    $dateStart = trim($_GET['date_start'] ?? '');
    $dateEnd = trim($_GET['date_end'] ?? '');
    $projectStatus = trim($_GET['project_status'] ?? '');
    $keyword = trim($_GET['keyword'] ?? '');
    
    // 构建 WHERE 条件
    $where = '1=1';
    $params = [];
    
    // 部门筛选（非管理员只能看本部门）
    if (!isAdmin($user)) {
        $where .= ' AND u.department_id = ?';
        $params[] = $user['department_id'];
    } elseif ($departmentId > 0) {
        $where .= ' AND u.department_id = ?';
        $params[] = $departmentId;
    }
    
    // 技术人员筛选
    if ($techUserId > 0) {
        $where .= ' AND pta.tech_user_id = ?';
        $params[] = $techUserId;
    }
    
    // 时间范围筛选（按提成设置时间）
    if ($dateStart) {
        $where .= ' AND DATE(FROM_UNIXTIME(pta.commission_set_at)) >= ?';
        $params[] = $dateStart;
    }
    if ($dateEnd) {
        $where .= ' AND DATE(FROM_UNIXTIME(pta.commission_set_at)) <= ?';
        $params[] = $dateEnd;
    }
    
    // 项目状态筛选
    if ($projectStatus) {
        $where .= ' AND p.current_status = ?';
        $params[] = $projectStatus;
    }
    
    // 关键词搜索
    if ($keyword) {
        $where .= ' AND (p.project_name LIKE ? OR p.project_code LIKE ? OR c.name LIKE ? OR u.realname LIKE ?)';
        $kw = "%{$keyword}%";
        $params = array_merge($params, [$kw, $kw, $kw, $kw]);
    }
    
    // 获取所有符合条件的分配记录（按人员分组）
    $sql = "
        SELECT 
            u.id as user_id,
            COALESCE(u.realname, u.username) as realname,
            u.username,
            d.id as department_id,
            d.name as department_name,
            COUNT(*) as project_count,
            SUM(COALESCE(pta.commission_amount, 0)) as total_commission,
            SUM(CASE WHEN pta.commission_amount > 0 THEN 1 ELSE 0 END) as set_count,
            SUM(CASE WHEN pta.commission_amount IS NULL OR pta.commission_amount = 0 THEN 1 ELSE 0 END) as unset_count
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE $where
        GROUP BY u.id
        ORDER BY total_commission DESC, project_count DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取每个人的项目明细
    $detailSql = "
        SELECT 
            pta.id as assignment_id,
            pta.tech_user_id,
            pta.commission_amount,
            pta.commission_note,
            pta.assigned_at,
            p.id as project_id,
            p.project_code,
            p.project_name,
            p.current_status,
            c.name as customer_name
        FROM project_tech_assignments pta
        JOIN projects p ON pta.project_id = p.id AND p.deleted_at IS NULL
        JOIN users u ON pta.tech_user_id = u.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE $where
        ORDER BY pta.tech_user_id, pta.assigned_at DESC
    ";
    
    $stmt = $pdo->prepare($detailSql);
    $stmt->execute($params);
    $allDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按用户分组明细
    $detailsByUser = [];
    foreach ($allDetails as $detail) {
        $uid = $detail['tech_user_id'];
        if (!isset($detailsByUser[$uid])) {
            $detailsByUser[$uid] = [];
        }
        $detailsByUser[$uid][] = $detail;
    }
    
    // 合并数据
    foreach ($byUser as &$userRow) {
        $userRow['projects'] = $detailsByUser[$userRow['user_id']] ?? [];
    }
    unset($userRow);
    
    // 计算汇总统计
    $totalCommission = 0;
    $totalProjects = 0;
    $totalSetCount = 0;
    $totalUnsetCount = 0;
    
    foreach ($byUser as $row) {
        $totalCommission += floatval($row['total_commission']);
        $totalProjects += intval($row['project_count']);
        $totalSetCount += intval($row['set_count']);
        $totalUnsetCount += intval($row['unset_count']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $byUser,
            'summary' => [
                'total_commission' => $totalCommission,
                'total_projects' => $totalProjects,
                'user_count' => count($byUser),
                'set_count' => $totalSetCount,
                'unset_count' => $totalUnsetCount,
                'avg_commission' => $totalProjects > 0 ? round($totalCommission / $totalProjects, 2) : 0
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
}
