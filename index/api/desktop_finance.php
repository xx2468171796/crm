<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 财务提成 API
 * 
 * 从 project_tech_assignments 表查询提成数据
 * 
 * GET /api/desktop_finance.php
 * 
 * 查询参数：
 * - range: last_month|this_month|custom
 * - start_date: 自定义开始日期
 * - end_date: 自定义结束日期
 * - user_id: 技术主管查看特定成员
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

// 参数
$range = $_GET['range'] ?? 'this_month';
$targetUserId = $_GET['user_id'] ?? null;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 20)));

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    // 确定查询的用户ID
    $queryUserId = $user['id'];
    if ($isManager && $targetUserId) {
        $queryUserId = (int)$targetUserId;
    }
    
    // 计算日期范围
    $today = date('Y-m-d');
    $thisMonthStart = date('Y-m-01');
    $thisMonthEnd = date('Y-m-t');
    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
    
    switch ($range) {
        case 'last_month':
            $queryStart = $lastMonthStart;
            $queryEnd = $lastMonthEnd;
            break;
        case 'custom':
            $queryStart = $startDate ?: $thisMonthStart;
            $queryEnd = $endDate ?: $thisMonthEnd;
            break;
        default: // this_month
            $queryStart = $thisMonthStart;
            $queryEnd = $thisMonthEnd;
    }
    
    // 构建用户条件 - 从 project_tech_assignments 表查询
    $userCondition = "";
    $userParams = [];
    if (!$isManager || $targetUserId) {
        $userCondition = "AND pta.tech_user_id = ?";
        $userParams[] = $queryUserId;
    }
    
    // 查询统计数据
    $stats = [
        'lastMonth' => 0,
        'thisMonth' => 0,
        'pending' => 0,
        'total' => 0,
        'filteredTotal' => 0,
    ];
    
    // 上月提成 - 根据 commission_set_at 时间筛选
    $lastMonthResult = Db::queryOne(
        "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(pta.commission_set_at)) BETWEEN ? AND ? $userCondition",
        array_merge([$lastMonthStart, $lastMonthEnd], $userParams)
    );
    $stats['lastMonth'] = (float)($lastMonthResult['total'] ?? 0);
    
    // 本月提成
    $thisMonthResult = Db::queryOne(
        "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(pta.commission_set_at)) BETWEEN ? AND ? $userCondition",
        array_merge([$thisMonthStart, $thisMonthEnd], $userParams)
    );
    $stats['thisMonth'] = (float)($thisMonthResult['total'] ?? 0);
    
    // 待发放 - 当前筛选范围内的提成（与filteredTotal相同）
    $pendingResult = Db::queryOne(
        "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(COALESCE(pta.commission_set_at, pta.assigned_at))) BETWEEN ? AND ? $userCondition",
        array_merge([$queryStart, $queryEnd], $userParams)
    );
    $stats['pending'] = (float)($pendingResult['total'] ?? 0);
    
    // 累计总额 - 所有时间的提成总和
    $totalResult = Db::queryOne(
        "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0 $userCondition",
        $userParams
    );
    $stats['total'] = (float)($totalResult['total'] ?? 0);
    
    // 根据筛选条件计算的合计
    $filteredResult = Db::queryOne(
        "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(COALESCE(pta.commission_set_at, pta.assigned_at))) BETWEEN ? AND ? $userCondition",
        array_merge([$queryStart, $queryEnd], $userParams)
    );
    $stats['filteredTotal'] = (float)($filteredResult['total'] ?? 0);
    
    // 查询提成总数（用于分页）
    $countResult = Db::queryOne(
        "SELECT COUNT(*) as total 
        FROM project_tech_assignments pta
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(COALESCE(pta.commission_set_at, pta.assigned_at))) BETWEEN ? AND ? $userCondition",
        array_merge([$queryStart, $queryEnd], $userParams)
    );
    $totalItems = (int)($countResult['total'] ?? 0);
    $totalPages = ceil($totalItems / $pageSize);
    $offset = ($page - 1) * $pageSize;
    
    // 查询提成明细（分页）
    $items = [];
    $sql = "
        SELECT 
            pta.id,
            pta.commission_amount as amount,
            pta.commission_note as note,
            pta.commission_set_at,
            pta.assigned_at,
            p.id as project_id,
            p.project_name,
            c.name as customer_name,
            u.realname as tech_name
        FROM project_tech_assignments pta
        LEFT JOIN projects p ON pta.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON pta.tech_user_id = u.id
        WHERE pta.commission_amount IS NOT NULL 
        AND pta.commission_amount > 0
        AND DATE(FROM_UNIXTIME(COALESCE(pta.commission_set_at, pta.assigned_at))) BETWEEN ? AND ? $userCondition
        ORDER BY COALESCE(pta.commission_set_at, pta.assigned_at) DESC
        LIMIT $pageSize OFFSET $offset
    ";
    
    $commissions = Db::query($sql, array_merge([$queryStart, $queryEnd], $userParams));
    
    foreach ($commissions as $item) {
        $items[] = [
            'id' => (int)$item['id'],
            'project_id' => (int)$item['project_id'],
            'project_name' => $item['project_name'] ?? '未知项目',
            'customer_name' => $item['customer_name'] ?? '未知客户',
            'tech_name' => $item['tech_name'] ?? '',
            'amount' => (float)$item['amount'],
            'note' => $item['note'] ?? '',
            'status' => 'confirmed',
            'created_at' => $item['commission_set_at'] ? date('Y-m-d', $item['commission_set_at']) : date('Y-m-d', $item['assigned_at']),
        ];
    }
    
    // 技术主管：获取团队成员列表及当前筛选范围内的提成
    $teamMembers = [];
    if ($isManager) {
        $members = Db::query(
            "SELECT id, username, realname as name FROM users WHERE role IN ('tech', 'tech_manager') AND status = 1"
        );
        foreach ($members as $member) {
            $memberCommission = Db::queryOne(
                "SELECT COALESCE(SUM(commission_amount), 0) as total 
                FROM project_tech_assignments 
                WHERE tech_user_id = ? 
                AND commission_amount IS NOT NULL 
                AND commission_amount > 0
                AND DATE(FROM_UNIXTIME(COALESCE(commission_set_at, assigned_at))) BETWEEN ? AND ?",
                [$member['id'], $queryStart, $queryEnd]
            );
            $teamMembers[] = [
                'id' => (int)$member['id'],
                'name' => $member['name'] ?: $member['username'],
                'thisMonth' => (float)($memberCommission['total'] ?? 0),
            ];
        }
    }
    
    $response = [
        'success' => true,
        'data' => [
            'stats' => $stats,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ]
    ];
    
    if ($isManager) {
        $response['data']['team_members'] = $teamMembers;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_finance 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}
