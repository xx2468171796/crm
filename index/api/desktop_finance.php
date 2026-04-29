<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 财务提成 API（基于 tech_commission_entries 时间线）
 *
 * 数据源：tech_commission_entries（每条提成 = 金额/备注/时间）
 * 时间筛选：作用在 e.entry_at 上，逐条聚合，不用 pta 上的累计缓存
 *
 * GET /api/desktop_finance.php
 *   - range: last_month|this_month|custom
 *   - start_date / end_date: 自定义
 *   - user_id: 技术主管查看特定成员
 *   - page / page_size: 分页（明细列表）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$range = $_GET['range'] ?? 'this_month';
$targetUserId = $_GET['user_id'] ?? null;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 20)));

$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    // 当前查询的用户范围
    $queryUserId = $user['id'];
    if ($isManager && $targetUserId) {
        $queryUserId = (int)$targetUserId;
    }

    // 月份范围（用 PHP Asia/Shanghai 时区，与前端 GMT+8 一致）
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

    // 把 YYYY-MM-DD 转成 GMT+8 边界的 unix 时间戳，对 e.entry_at 比较
    $tsRangeStart = strtotime($queryStart . ' 00:00:00');
    $tsRangeEnd   = strtotime($queryEnd . ' 23:59:59');
    $tsThisMonthStart = strtotime($thisMonthStart . ' 00:00:00');
    $tsThisMonthEnd   = strtotime($thisMonthEnd . ' 23:59:59');
    $tsLastMonthStart = strtotime($lastMonthStart . ' 00:00:00');
    $tsLastMonthEnd   = strtotime($lastMonthEnd . ' 23:59:59');

    // 共用的用户 / 软删过滤
    $userClause = '';
    $userParams = [];
    if (!$isManager || $targetUserId) {
        $userClause = ' AND pta.tech_user_id = ?';
        $userParams[] = $queryUserId;
    }
    $baseFromWhere = "
        FROM tech_commission_entries e
        JOIN project_tech_assignments pta ON pta.id = e.assignment_id
        JOIN projects p ON p.id = pta.project_id AND p.deleted_at IS NULL
        WHERE 1=1" . $userClause;

    // 1. 上月提成
    $row = Db::queryOne(
        "SELECT COALESCE(SUM(e.amount), 0) AS total" . $baseFromWhere . " AND e.entry_at BETWEEN ? AND ?",
        array_merge($userParams, [$tsLastMonthStart, $tsLastMonthEnd])
    );
    $stats = ['lastMonth' => (float)($row['total'] ?? 0)];

    // 2. 本月提成
    $row = Db::queryOne(
        "SELECT COALESCE(SUM(e.amount), 0) AS total" . $baseFromWhere . " AND e.entry_at BETWEEN ? AND ?",
        array_merge($userParams, [$tsThisMonthStart, $tsThisMonthEnd])
    );
    $stats['thisMonth'] = (float)($row['total'] ?? 0);

    // 3. 待发放（= filteredTotal，根据当前筛选范围）
    $row = Db::queryOne(
        "SELECT COALESCE(SUM(e.amount), 0) AS total" . $baseFromWhere . " AND e.entry_at BETWEEN ? AND ?",
        array_merge($userParams, [$tsRangeStart, $tsRangeEnd])
    );
    $stats['pending'] = (float)($row['total'] ?? 0);
    $stats['filteredTotal'] = $stats['pending'];

    // 4. 累计总额（不限时间）
    $row = Db::queryOne(
        "SELECT COALESCE(SUM(e.amount), 0) AS total" . $baseFromWhere,
        $userParams
    );
    $stats['total'] = (float)($row['total'] ?? 0);

    // 5. 当前筛选范围内的明细总数（用于分页）
    $row = Db::queryOne(
        "SELECT COUNT(*) AS total" . $baseFromWhere . " AND e.entry_at BETWEEN ? AND ?",
        array_merge($userParams, [$tsRangeStart, $tsRangeEnd])
    );
    $totalItems = (int)($row['total'] ?? 0);
    $totalPages = $totalItems > 0 ? (int)ceil($totalItems / $pageSize) : 0;
    $offset = ($page - 1) * $pageSize;

    // 6. 明细列表（每条 entry 一行）
    $items = [];
    $detailSql = "
        SELECT
            e.id AS entry_id,
            e.amount,
            e.note,
            e.entry_at,
            pta.id AS assignment_id,
            p.id AS project_id,
            p.project_name,
            c.name AS customer_name,
            u.realname AS tech_name
        FROM tech_commission_entries e
        JOIN project_tech_assignments pta ON pta.id = e.assignment_id
        JOIN projects p ON p.id = pta.project_id AND p.deleted_at IS NULL
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN users u ON u.id = pta.tech_user_id
        WHERE 1=1" . $userClause . "
          AND e.entry_at BETWEEN ? AND ?
        ORDER BY e.entry_at DESC, e.id DESC
        LIMIT $pageSize OFFSET $offset
    ";
    $rows = Db::query($detailSql, array_merge($userParams, [$tsRangeStart, $tsRangeEnd]));
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int)$r['entry_id'],
            'project_id' => (int)$r['project_id'],
            'project_name' => $r['project_name'] ?? '未知项目',
            'customer_name' => $r['customer_name'] ?? '未知客户',
            'tech_name' => $r['tech_name'] ?? '',
            'amount' => (float)$r['amount'],
            'note' => $r['note'] ?? '',
            'status' => 'confirmed',
            'created_at' => date('Y-m-d', (int)$r['entry_at']),
        ];
    }

    // 7. 团队成员（仅 manager）—— 每个人在筛选范围内的提成 SUM
    $teamMembers = [];
    if ($isManager) {
        $members = Db::query(
            "SELECT id, username, realname AS name
             FROM users
             WHERE role IN ('tech', 'tech_manager') AND status = 1
             ORDER BY id"
        );
        foreach ($members as $m) {
            $row = Db::queryOne(
                "SELECT COALESCE(SUM(e.amount), 0) AS total
                 FROM tech_commission_entries e
                 JOIN project_tech_assignments pta ON pta.id = e.assignment_id
                 JOIN projects p ON p.id = pta.project_id AND p.deleted_at IS NULL
                 WHERE pta.tech_user_id = ?
                   AND e.entry_at BETWEEN ? AND ?",
                [(int)$m['id'], $tsRangeStart, $tsRangeEnd]
            );
            $teamMembers[] = [
                'id' => (int)$m['id'],
                'name' => $m['name'] ?: $m['username'],
                'thisMonth' => (float)($row['total'] ?? 0),
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
            'date_range' => [
                'start' => $queryStart,
                'end' => $queryEnd,
            ],
        ],
    ];
    if ($isManager) {
        $response['data']['team_members'] = $teamMembers;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_finance 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']
    ], JSON_UNESCAPED_UNICODE);
}
