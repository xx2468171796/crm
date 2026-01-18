<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 团队项目进度 API（仅主管）
 * 
 * GET /api/desktop_team_projects.php - 获取团队所有项目进度
 * 参数: group_by (status), sort_by (deadline)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/services/ProjectService.php';

$user = desktop_auth_require();

// 权限检查：仅主管可访问
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
if (!$isManager) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '无权访问团队项目进度'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    handleGet($user);
} catch (Exception $e) {
    error_log('[API] desktop_team_projects 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $groupBy = $_GET['group_by'] ?? 'status';
    $sortBy = $_GET['sort_by'] ?? 'deadline';
    
    // 使用 ProjectService 的状态定义
    $stages = ProjectService::STAGES;
    
    // 获取所有进行中的项目（排除已完工）
    $projects = Db::query("
        SELECT 
            p.id,
            p.project_code,
            p.project_name,
            p.current_status,
            p.update_time,
            c.name as customer_name,
            p.deadline as stage_deadline,
            GROUP_CONCAT(DISTINCT u.realname SEPARATOR ', ') as tech_names
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        LEFT JOIN project_tech_assignments pta ON pta.project_id = p.id
        LEFT JOIN users u ON pta.tech_user_id = u.id
        WHERE p.deleted_at IS NULL AND p.completed_at IS NULL
        GROUP BY p.id
        ORDER BY 
            CASE p.current_status 
                WHEN '待沟通' THEN 1 
                WHEN '需求确认' THEN 2
                WHEN '设计中' THEN 3
                WHEN '设计核对' THEN 4
                WHEN '设计完工' THEN 5
                WHEN '设计评价' THEN 6
                ELSE 99 
            END,
            p.deadline ASC,
            p.update_time DESC
    ");
    
    $now = time();
    $grouped = [];
    
    foreach ($projects as $project) {
        $deadline = $project['stage_deadline'] ? strtotime($project['stage_deadline']) : null;
        $remainingDays = null;
        $status = 'normal';
        
        if ($deadline) {
            $remainingDays = floor(($deadline - $now) / 86400);
            if ($remainingDays < 0) {
                $status = 'overdue';
            } elseif ($remainingDays <= 2) {
                $status = 'urgent';
            }
        }
        
        $stageInfo = $stages[$project['current_status']] ?? ['name' => $project['current_status'], 'color' => '#6B7280', 'order' => 99];
        
        $item = [
            'id' => (int)$project['id'],
            'project_code' => $project['project_code'],
            'project_name' => $project['project_name'],
            'customer_name' => $project['customer_name'],
            'current_status' => $project['current_status'],
            'stage_name' => $stageInfo['name'],
            'stage_color' => $stageInfo['color'],
            'stage_deadline' => $project['stage_deadline'],
            'remaining_days' => $remainingDays,
            'deadline_status' => $status,
            'tech_names' => $project['tech_names'] ?: '未分配',
        ];
        
        // 按状态分组
        $groupKey = $project['current_status'];
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'status' => $groupKey,
                'stage_name' => $stageInfo['name'],
                'stage_color' => $stageInfo['color'],
                'stage_order' => $stageInfo['order'],
                'projects' => [],
            ];
        }
        $grouped[$groupKey]['projects'][] = $item;
    }
    
    // 按阶段顺序排序分组
    usort($grouped, fn($a, $b) => $a['stage_order'] - $b['stage_order']);
    
    // 组内按到期时间排序（紧急的在前）
    foreach ($grouped as &$group) {
        usort($group['projects'], function($a, $b) {
            // 超期 > 即将到期 > 正常
            $statusOrder = ['overdue' => 0, 'urgent' => 1, 'normal' => 2];
            $aOrder = $statusOrder[$a['deadline_status']] ?? 3;
            $bOrder = $statusOrder[$b['deadline_status']] ?? 3;
            
            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }
            
            // 同等级按剩余天数排序
            $aDays = $a['remaining_days'] ?? 999;
            $bDays = $b['remaining_days'] ?? 999;
            return $aDays - $bDays;
        });
        
        $group['count'] = count($group['projects']);
        unset($group['stage_order']);
    }
    
    // 统计
    $urgentCount = 0;
    foreach ($projects as $p) {
        $deadline = strtotime($p['stage_deadline'] ?? '2099-12-31');
        $days = floor(($deadline - $now) / 86400);
        if ($days >= 0 && $days <= 2) {
            $urgentCount++;
        }
    }
    
    $stats = [
        'total' => count($projects),
        'overdue' => count(array_filter($projects, fn($p) => strtotime($p['stage_deadline'] ?? '2099-12-31') < $now)),
        'urgent' => $urgentCount,
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'groups' => array_values($grouped),
            'stats' => $stats,
            'stages' => $stages,
        ]
    ], JSON_UNESCAPED_UNICODE);
}
