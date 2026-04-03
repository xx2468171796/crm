<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 项目任务 API
 * 
 * GET /api/desktop_tasks.php
 * 
 * 查询参数：
 * - range: today|tomorrow|week|month|last_month|custom|all (默认 today)
 * - start_date: 自定义开始日期 (YYYY-MM-DD，仅 range=custom 时有效)
 * - end_date: 自定义结束日期 (YYYY-MM-DD，仅 range=custom 时有效)
 * - sort: remaining|deadline|customer (默认 remaining)
 * - page: 页码
 * - per_page: 每页数量
 * 
 * 响应：
 * {
 *   "success": true,
 *   "data": {
 *     "items": [...],
 *     "summary": { "total": 10, "urgent": 2, "today": 5 }
 *   }
 * }
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

// 参数
$range = $_GET['range'] ?? 'today';
$sort = $_GET['sort'] ?? 'remaining';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
$customStartDate = $_GET['start_date'] ?? '';
$customEndDate = $_GET['end_date'] ?? '';

try {
    // 计算日期范围
    $now = time();
    $todayStart = strtotime('today');
    $todayEnd = strtotime('tomorrow') - 1;
    
    switch ($range) {
        case 'tomorrow':
            $startDate = strtotime('tomorrow');
            $endDate = strtotime('+2 days') - 1;
            break;
        case 'week':
            $startDate = $todayStart;
            $endDate = strtotime('+7 days') - 1;
            break;
        case 'month':
            $startDate = strtotime('first day of this month');
            $endDate = strtotime('last day of this month 23:59:59');
            break;
        case 'last_month':
            $startDate = strtotime('first day of last month');
            $endDate = strtotime('last day of last month 23:59:59');
            break;
        case 'custom':
            $startDate = $customStartDate ? strtotime($customStartDate) : $todayStart;
            $endDate = $customEndDate ? strtotime($customEndDate . ' 23:59:59') : $todayEnd;
            break;
        case 'all':
            $startDate = null;
            $endDate = null;
            break;
        default: // today
            $startDate = $todayStart;
            $endDate = $todayEnd;
    }
    
    // 查询项目 - admin/manager 看所有，普通用户看自己的
    $isAdmin = in_array($user['role'], ['admin', 'super_admin', 'manager']);
    
    if ($isAdmin) {
        $sql = "
            SELECT 
                p.id as project_id,
                p.project_name,
                p.current_status,
                p.deadline,
                c.id as customer_id,
                c.name as customer_name
            FROM projects p
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE 1=1
            ORDER BY p.id DESC
            LIMIT 100
        ";
        $projects = Db::query($sql);
    } else {
        $sql = "
            SELECT 
                p.id as project_id,
                p.project_name,
                p.current_status,
                p.deadline,
                c.id as customer_id,
                c.name as customer_name
            FROM projects p
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
            WHERE (pta.tech_user_id = ? OR pta.tech_user_id IS NULL)
            LIMIT 100
        ";
        $projects = Db::query($sql, [$user['id']]);
    }
    
    // 构建任务列表
    $tasks = [];
    
    foreach ($projects as $project) {
        // 使用项目截止日期
        $deadline = $project['deadline'] ?? null;
        $currentStatus = $project['current_status'] ?? 'requirement';
        
        if (!$deadline) {
            // 如果没有截止时间，默认7天后
            $deadline = date('Y-m-d', strtotime('+7 days'));
        }
        
        $deadlineTs = strtotime($deadline);
        if ($deadlineTs === false) continue;
        
        // 检查日期范围（all 时不过滤）
        if ($startDate !== null && $deadlineTs < $startDate) continue;
        if ($endDate !== null && $deadlineTs > $endDate) continue;
        
        // 计算剩余时间
        $remaining = $deadlineTs - $now;
        $remainingDays = (int)floor($remaining / 86400);
        $remainingHours = round($remaining / 3600, 1);
        
        // 是否紧急
        $isUrgent = $remaining < 86400 * 2; // 2天内为紧急
        
        $tasks[] = [
            'project_id' => (int)$project['project_id'],
            'project_code' => 'P' . $project['project_id'],
            'project_name' => $project['project_name'] ?? '未命名项目',
            'customer_name' => $project['customer_name'] ?? '未知客户',
            'current_status' => $currentStatus,
            'deadline' => $deadline,
            'remaining_days' => $remainingDays,
            'remaining_hours' => $remainingHours,
            'is_urgent' => $isUrgent,
            'updated_at' => date('m-d H:i'),
        ];
    }
    
    // 排序
    usort($tasks, function($a, $b) use ($sort) {
        switch ($sort) {
            case 'deadline':
                return strtotime($a['deadline']) - strtotime($b['deadline']);
            case 'customer':
                return strcmp($a['customer_name'], $b['customer_name']);
            default: // remaining
                return $a['remaining_days'] - $b['remaining_days'];
        }
    });
    
    // 统计
    $summary = [
        'total' => count($tasks),
        'urgent' => count(array_filter($tasks, fn($t) => $t['is_urgent'])),
        'overdue' => count(array_filter($tasks, fn($t) => $t['remaining_days'] < 0)),
        'today' => count(array_filter($tasks, fn($t) => $t['remaining_days'] == 0)),
    ];
    
    // 分页
    $total = count($tasks);
    $offset = ($page - 1) * $perPage;
    $tasks = array_slice($tasks, $offset, $perPage);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $tasks,
            'summary' => $summary,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_tasks 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}

/**
 * 格式化剩余时间
 */
function formatRemaining($seconds) {
    if ($seconds < 0) {
        $seconds = abs($seconds);
        $prefix = '已超';
    } else {
        $prefix = '剩余';
    }
    
    if ($seconds < 3600) {
        return $prefix . round($seconds / 60) . '分钟';
    } elseif ($seconds < 86400) {
        return $prefix . round($seconds / 3600, 1) . '小时';
    } else {
        return $prefix . round($seconds / 86400, 1) . '天';
    }
}
