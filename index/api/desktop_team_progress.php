<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 团队进度 API (主管用)
 * 
 * GET /api/desktop_team_progress.php
 * 
 * 查询参数：
 * - date: 日期 (默认今天)
 * - department_id: 部门ID (可选)
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

// 权限检查：仅主管和管理员可访问
if ($user['role'] !== 'manager' && $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '仅主管可查看团队进度']], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$departmentId = $_GET['department_id'] ?? $user['department_id'];

try {
    // 获取部门成员
    $members = Db::query("
        SELECT id, username, realname, role 
        FROM users 
        WHERE department_id = ? AND status = 1
        ORDER BY role DESC, realname ASC
    ", [$departmentId]);
    
    // 获取每个成员的任务统计
    $result = [];
    foreach ($members as $member) {
        $stats = Db::queryOne("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                AVG(progress) as avg_progress
            FROM daily_tasks 
            WHERE user_id = ? AND task_date = ?
        ", [$member['id'], $date]);
        
        // 获取任务列表
        $tasks = Db::query("
            SELECT id, title, status, progress, priority
            FROM daily_tasks 
            WHERE user_id = ? AND task_date = ?
            ORDER BY priority DESC, created_at ASC
        ", [$member['id'], $date]);
        
        $result[] = [
            'user_id' => (int)$member['id'],
            'username' => $member['username'],
            'realname' => $member['realname'],
            'role' => $member['role'],
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
                'completed' => (int)($stats['completed'] ?? 0),
                'in_progress' => (int)($stats['in_progress'] ?? 0),
                'pending' => (int)($stats['pending'] ?? 0),
                'avg_progress' => round($stats['avg_progress'] ?? 0, 1),
            ],
            'tasks' => $tasks,
        ];
    }
    
    // 部门汇总
    $summary = [
        'total_members' => count($members),
        'total_tasks' => array_sum(array_column(array_column($result, 'stats'), 'total')),
        'completed_tasks' => array_sum(array_column(array_column($result, 'stats'), 'completed')),
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'members' => $result,
            'summary' => $summary,
            'date' => $date,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_team_progress 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}
