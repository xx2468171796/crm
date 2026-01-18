<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 仪表盘 API
 * 
 * GET /api/desktop_dashboard.php
 * 
 * 查询参数：
 * - user_id: 可选，技术主管查看特定成员数据
 * 
 * 响应：
 * {
 *   "success": true,
 *   "data": {
 *     "stats": {...},
 *     "team_members": [...] // 仅技术主管
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
$targetUserId = $_GET['user_id'] ?? null;

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    // 确定查询的用户ID
    $queryUserId = $user['id'];
    if ($isManager && $targetUserId) {
        $queryUserId = (int)$targetUserId;
    }
    
    // 统计数据
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    
    // 今日任务数 (从 tasks 表)
    $todayTasks = 0;
    $completedToday = 0;
    try {
        $taskResult = Db::queryOne(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM tasks 
            WHERE assignee_id = ? AND DATE(FROM_UNIXTIME(create_time)) = ?",
            [$queryUserId, $today]
        );
        $todayTasks = (int)($taskResult['total'] ?? 0);
        $completedToday = (int)($taskResult['completed'] ?? 0);
    } catch (Exception $e) {
        // 表可能不存在，忽略
        error_log('[dashboard] tasks query error: ' . $e->getMessage());
    }
    
    // 进行中项目数
    $activeProjects = 0;
    try {
        if ($isManager && !$targetUserId) {
            // 技术主管看所有项目
            $projectResult = Db::queryOne(
                "SELECT COUNT(*) as total FROM projects WHERE current_status NOT IN ('completed', 'cancelled', '')"
            );
        } else {
            // 个人项目
            $projectResult = Db::queryOne(
                "SELECT COUNT(DISTINCT p.id) as total 
                FROM projects p
                LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
                WHERE (pta.tech_user_id = ? OR p.created_by = ?)
                AND p.current_status NOT IN ('completed', 'cancelled', '')",
                [$queryUserId, $queryUserId]
            );
        }
        $activeProjects = (int)($projectResult['total'] ?? 0);
    } catch (Exception $e) {
        // 忽略
    }
    
    // 待审批作品数
    $pendingApprovals = 0;
    try {
        if ($isManager && !$targetUserId) {
            $approvalResult = Db::queryOne(
                "SELECT COUNT(*) as total FROM work_approvals WHERE status = 'pending'"
            );
        } else {
            $approvalResult = Db::queryOne(
                "SELECT COUNT(*) as total FROM work_approvals WHERE user_id = ? AND status = 'pending'",
                [$queryUserId]
            );
        }
        $pendingApprovals = (int)($approvalResult['total'] ?? 0);
    } catch (Exception $e) {
        // 表可能不存在
    }
    
    // 本月提成 (从 project_tech_assignments 表查询)
    $monthCommission = 0;
    try {
        if ($isManager && !$targetUserId) {
            // 管理员看所有人的提成
            $commissionResult = Db::queryOne(
                "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
                FROM project_tech_assignments pta
                WHERE pta.commission_set_at IS NOT NULL"
            );
        } else {
            // 个人提成
            $commissionResult = Db::queryOne(
                "SELECT COALESCE(SUM(pta.commission_amount), 0) as total 
                FROM project_tech_assignments pta
                WHERE pta.tech_user_id = ?",
                [$queryUserId]
            );
        }
        $monthCommission = (float)($commissionResult['total'] ?? 0);
    } catch (Exception $e) {
        // 表可能不存在
        error_log('[dashboard] commission query error: ' . $e->getMessage());
    }
    
    // 紧急任务数
    $urgentTasks = 0;
    try {
        $urgentResult = Db::queryOne(
            "SELECT COUNT(*) as total FROM daily_tasks 
            WHERE user_id = ? AND priority = 'high' AND status != 'completed'",
            [$queryUserId]
        );
        $urgentTasks = (int)($urgentResult['total'] ?? 0);
    } catch (Exception $e) {
        // 忽略
    }
    
    $stats = [
        'todayTasks' => $todayTasks,
        'completedToday' => $completedToday,
        'activeProjects' => $activeProjects,
        'pendingApprovals' => $pendingApprovals,
        'monthCommission' => $monthCommission,
        'urgentTasks' => $urgentTasks,
    ];
    
    // 技术主管：获取团队成员列表
    $teamMembers = [];
    if ($isManager) {
        try {
            $members = Db::query(
                "SELECT id, username, realname as name FROM users WHERE role IN ('tech', 'tech_manager') AND status = 1"
            );
            foreach ($members as $member) {
                // 获取每个成员的今日任务数
                $memberTasks = Db::queryOne(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM daily_tasks 
                    WHERE user_id = ? AND DATE(FROM_UNIXTIME(create_time)) = ?",
                    [$member['id'], $today]
                );
                $teamMembers[] = [
                    'id' => (int)$member['id'],
                    'name' => $member['name'] ?: $member['username'],
                    'todayTasks' => (int)($memberTasks['total'] ?? 0),
                    'completedTasks' => (int)($memberTasks['completed'] ?? 0),
                ];
            }
        } catch (Exception $e) {
            // 忽略
        }
    }
    
    $response = [
        'success' => true,
        'data' => [
            'stats' => $stats,
        ]
    ];
    
    if ($isManager) {
        $response['data']['team_members'] = $teamMembers;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_dashboard 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}
