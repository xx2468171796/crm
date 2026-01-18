<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 项目阶段快速切换 API
 * 
 * GET /api/desktop_project_stage.php - 获取用户负责的项目及当前阶段
 * POST /api/desktop_project_stage.php - 快速切换项目阶段
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($user);
            break;
        case 'POST':
            handlePost($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => '不支持的方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('[API] desktop_project_stage 错误: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => '服务器错误: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取用户负责的项目及当前阶段
 */
function handleGet($user) {
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    
    $conditions = ["p.deleted_at IS NULL"];
    $params = [];
    
    // 非管理员只能看自己分配的项目
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 使用 ProjectService 的状态定义
    require_once __DIR__ . '/../core/services/ProjectService.php';
    $stages = ProjectService::STAGES;
    
    $projects = Db::query("
        SELECT 
            p.id,
            p.project_code,
            p.project_name,
            p.current_status,
            p.update_time,
            p.start_date,
            c.name as customer_name,
            c.customer_group as customer_group,
            c.group_code as group_code,
            c.customer_group as group_name,
            p.deadline as stage_deadline
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        WHERE {$whereClause}
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
    ", $params);
    
    // 获取所有项目的阶段时间数据
    $projectIds = array_column($projects, 'id');
    $stageTimes = [];
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stageData = Db::query("
            SELECT project_id, stage_to, planned_days, status
            FROM project_stage_times
            WHERE project_id IN ({$placeholders})
            ORDER BY stage_order ASC
        ", $projectIds);
        
        foreach ($stageData as $st) {
            $pid = $st['project_id'];
            if (!isset($stageTimes[$pid])) {
                $stageTimes[$pid] = [];
            }
            $stageTimes[$pid][$st['stage_to']] = [
                'planned_days' => (int)$st['planned_days'],
                'status' => $st['status']
            ];
        }
    }
    
    $now = time();
    $today = new DateTime();
    $result = [];
    
    foreach ($projects as $project) {
        $projectId = (int)$project['id'];
        $deadline = $project['stage_deadline'] ? strtotime($project['stage_deadline']) : null;
        $remainingDays = null;
        $status = 'normal';
        
        // 优先使用 deadline 计算
        if ($deadline) {
            $remainingDays = floor(($deadline - $now) / 86400);
        } 
        // 如果没有 deadline，使用阶段时间计算
        elseif (!empty($project['start_date']) && isset($stageTimes[$projectId])) {
            $startDate = new DateTime($project['start_date']);
            $currentStatus = $project['current_status'];
            $projectStages = $stageTimes[$projectId];
            
            // 计算当前阶段的截止日期
            $totalDaysBefore = 0;
            $currentStageDays = 0;
            $stageOrder = ['待沟通', '需求确认', '设计中', '设计核对', '设计完工', '设计评价'];
            
            foreach ($stageOrder as $stageName) {
                $stageDays = $projectStages[$stageName]['planned_days'] ?? 0;
                if ($stageName === $currentStatus) {
                    $currentStageDays = $stageDays;
                    break;
                }
                $totalDaysBefore += $stageDays;
            }
            
            // 当前阶段截止日期 = 开始日期 + 之前阶段天数 + 当前阶段天数
            $stageDeadline = clone $startDate;
            $stageDeadline->modify('+' . ($totalDaysBefore + $currentStageDays) . ' days');
            // 使用时间戳计算剩余天数，避免时区问题
            $todayTimestamp = strtotime(date('Y-m-d'));
            $deadlineTimestamp = $stageDeadline->getTimestamp();
            $remainingDays = (int)floor(($deadlineTimestamp - $todayTimestamp) / 86400);
        }
        
        // 设置状态
        if ($remainingDays !== null) {
            if ($remainingDays < 0) {
                $status = 'overdue';
            } elseif ($remainingDays <= 2) {
                $status = 'urgent';
            }
        }
        
        $stageInfo = $stages[$project['current_status']] ?? ['order' => 99, 'color' => '#6B7280'];
        
        // 计算总天数
        $totalDays = 0;
        if (isset($stageTimes[$projectId])) {
            foreach ($stageTimes[$projectId] as $stageData) {
                $totalDays += $stageData['planned_days'];
            }
        }
        
        $result[] = [
            'id' => $projectId,
            'project_code' => $project['project_code'],
            'project_name' => $project['project_name'],
            'customer_name' => $project['customer_name'],
            'customer_group' => $project['customer_group'] ?? null,
            'group_code' => $project['group_code'] ?? null,
            'group_name' => $project['group_name'] ?? null,
            'current_status' => $project['current_status'],
            'stage_name' => $project['current_status'], // 中文状态名就是显示名
            'stage_color' => $stageInfo['color'],
            'stage_deadline' => $project['stage_deadline'],
            'remaining_days' => $remainingDays,
            'total_days' => $totalDays > 0 ? $totalDays : null,
            'deadline_status' => $status, // normal/urgent/overdue
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'projects' => $result,
            'stages' => $stages,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 快速切换项目阶段
 * 使用统一的 ProjectService 确保数据一致性
 */
function handlePost($user) {
    require_once __DIR__ . '/../core/services/ProjectService.php';
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $projectId = (int)($input['project_id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    
    if (!$projectId || !$newStatus) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "缺少必要参数: project_id={$projectId}, status={$newStatus}"], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    error_log("[desktop_project_stage] 收到请求: project_id={$projectId}, status={$newStatus}");
    
    // 检查权限
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    if (!$isManager) {
        $hasAccess = Db::queryOne("
            SELECT 1 FROM project_tech_assignments 
            WHERE project_id = ? AND tech_user_id = ?
        ", [$projectId, $user['id']]);
        
        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => '无权修改此项目'], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    
    // 调试日志
    error_log("[desktop_project_stage] POST: projectId={$projectId}, newStatus={$newStatus}");
    
    // 使用统一的 ProjectService 更新状态
    $projectService = ProjectService::getInstance();
    $result = $projectService->updateStatus(
        $projectId,
        $newStatus,
        $user['id'],
        $user['realname'] ?? $user['username']
    );
    
    error_log("[desktop_project_stage] Result: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $result['message']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

