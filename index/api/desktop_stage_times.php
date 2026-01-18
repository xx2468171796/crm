<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端项目阶段时间 API
 * GET - 获取项目阶段时间
 * POST - 批量调整阶段时间
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/stage_time_calc.php';
require_once __DIR__ . '/../core/desktop_auth.php';

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
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[DESKTOP_STAGE_TIME_API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败'], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $projectId = intval($_GET['project_id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '项目ID不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取项目阶段时间（从 project_stage_times 表）
    $stageTimes = Db::query("
        SELECT pst.*, 
               DATEDIFF(pst.planned_end_date, CURDATE()) as remaining_days
        FROM project_stage_times pst
        WHERE pst.project_id = ?
        ORDER BY pst.stage_order ASC
    ", [$projectId]);
    
    // 获取项目当前状态和起始日期
    $project = Db::queryOne("
        SELECT current_status, start_date, deadline, timeline_enabled, timeline_start_date, completed_at
        FROM projects WHERE id = ?
    ", [$projectId]);
    
    $isCompleted = !empty($project['completed_at']);
    
    // 使用公共函数计算摘要
    $summary = calculateStageTimeSummary($project, $stageTimes);
    
    // 构建阶段数据（桌面端需要特定格式）
    $stages = [];
    foreach ($stageTimes as $st) {
        $stages[] = [
            'id' => (int)$st['id'],
            'stage_from' => $st['stage_from'],
            'stage_to' => $st['stage_to'],
            'stage_order' => (int)$st['stage_order'],
            'planned_days' => (int)$st['planned_days'],
            'planned_start_date' => $st['planned_start_date'],
            'planned_end_date' => $st['planned_end_date'],
            'status' => $isCompleted ? 'completed' : $st['status'],
            'remaining_days' => $isCompleted ? 0 : ($st['remaining_days'] ? (int)$st['remaining_days'] : null),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stages' => $stages,
            'current_status' => $project['current_status'],
            'timeline_enabled' => (bool)$project['timeline_enabled'],
            'start_date' => $project['start_date'] ?? $project['timeline_start_date'] ?? null,
            'summary' => $summary
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handlePost($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $projectId = intval($data['project_id'] ?? 0);
    $changes = $data['changes'] ?? [];
    $startDate = $data['start_date'] ?? null;
    
    if ($projectId <= 0 || empty($changes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $updated = 0;
    
    // 计算总天数
    $totalDays = 0;
    foreach ($changes as $change) {
        $totalDays += intval($change['planned_days'] ?? 0);
    }
    
    // 如果提供了开始日期，更新项目的 start_date 和 deadline
    if ($startDate) {
        $deadlineDate = (new DateTime($startDate))->modify('+' . $totalDays . ' days')->format('Y-m-d');
        Db::execute("UPDATE projects SET start_date = ?, deadline = ?, update_time = ? WHERE id = ?", 
            [$startDate, $deadlineDate, time(), $projectId]);
    } else {
        // 即使没有提供开始日期，也根据现有开始日期更新 deadline
        $project = Db::queryOne("SELECT start_date FROM projects WHERE id = ?", [$projectId]);
        if ($project['start_date']) {
            $deadlineDate = (new DateTime($project['start_date']))->modify('+' . $totalDays . ' days')->format('Y-m-d');
            Db::execute("UPDATE projects SET deadline = ?, update_time = ? WHERE id = ?", 
                [$deadlineDate, time(), $projectId]);
        }
    }
    
    // 获取项目时间线起始日期（优先使用新设置的开始日期）
    $project = Db::queryOne("SELECT start_date, timeline_start_date FROM projects WHERE id = ?", [$projectId]);
    $timelineStart = $startDate 
        ? new DateTime($startDate) 
        : ($project['start_date'] 
            ? new DateTime($project['start_date']) 
            : ($project['timeline_start_date'] 
                ? new DateTime($project['timeline_start_date']) 
                : new DateTime()));
    
    // 按阶段顺序排序
    usort($changes, function($a, $b) {
        return ($a['stage_order'] ?? 0) - ($b['stage_order'] ?? 0);
    });
    
    // 计算日期并更新
    $currentDate = clone $timelineStart;
    
    foreach ($changes as $change) {
        $stageId = intval($change['id'] ?? 0);
        $plannedDays = intval($change['planned_days'] ?? 0);
        
        if ($stageId <= 0 || $plannedDays <= 0) continue;
        
        $startDate = $currentDate->format('Y-m-d');
        $endDate = (clone $currentDate)->modify('+' . ($plannedDays - 1) . ' days')->format('Y-m-d');
        
        // 更新阶段时间
        $result = Db::execute("
            UPDATE project_stage_times 
            SET planned_days = ?, planned_start_date = ?, planned_end_date = ?, updated_at = NOW()
            WHERE id = ? AND project_id = ?
        ", [$plannedDays, $startDate, $endDate, $stageId, $projectId]);
        
        if ($result !== false) {
            $updated++;
        }
        
        // 下一阶段从结束日期后一天开始
        $currentDate->modify('+' . $plannedDays . ' days');
    }
    
    echo json_encode([
        'success' => true,
        'message' => "已更新 {$updated} 个阶段时间",
        'updated' => $updated
    ], JSON_UNESCAPED_UNICODE);
}
