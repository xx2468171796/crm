<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 截止日期检查 API
 * 检查即将到期的任务和项目，返回提醒信息
 */

require_once __DIR__ . '/../core/desktop_auth.php';

// 验证桌面端登录
$user = desktop_auth_require();

/**
 * 判断日期的紧急程度
 * @param string|int $date 日期（Y-m-d 格式或时间戳）
 * @param bool $isTimestamp 是否为时间戳
 * @return array|null ['urgency' => string, 'time_text' => string] 或 null（不在提醒范围内）
 */
function determineUrgency($date, bool $isTimestamp = false): ?array {
    $todayDate = date('Y-m-d');
    $tomorrowDate = date('Y-m-d', strtotime('+1 day'));
    $now = time();
    
    if ($isTimestamp) {
        $targetDate = date('Y-m-d', $date);
        $isOverdue = $date < $now;
    } else {
        $targetDate = $date;
        $isOverdue = $date < $todayDate;
    }
    
    if ($isOverdue) {
        return ['urgency' => 'overdue', 'time_text' => '已逾期'];
    } elseif ($targetDate === $todayDate) {
        return ['urgency' => 'today', 'time_text' => '今天到期'];
    } elseif ($targetDate === $tomorrowDate) {
        return ['urgency' => 'tomorrow', 'time_text' => '明天到期'];
    }
    
    return null;
}

try {
    $userId = $user['id'];
    $dayAfterTomorrow = strtotime('+2 days', strtotime('today'));
    $dayAfterTomorrowDate = date('Y-m-d', $dayAfterTomorrow);
    
    $reminders = [];
    
    // 检查即将到期的任务（1天内）
    // deadline 字段是 INT 类型（Unix 时间戳）
    $tasks = Db::query("
        SELECT t.id, t.title, t.deadline, t.status, p.project_code, p.project_name
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.assignee_id = ?
        AND t.status != 'completed'
        AND t.deadline IS NOT NULL
        AND t.deadline > 0
        AND FROM_UNIXTIME(t.deadline) < ?
        ORDER BY t.deadline ASC
        LIMIT 10
    ", [$userId, $dayAfterTomorrowDate]);
    
    foreach ($tasks as $task) {
        $deadlineTs = (int)$task['deadline']; // 确保是整数
        $urgencyInfo = determineUrgency($deadlineTs, true);
        if (!$urgencyInfo) continue;
        
        $reminders[] = [
            'type' => 'task',
            'id' => $task['id'],
            'title' => $task['title'],
            'project_code' => $task['project_code'],
            'project_name' => $task['project_name'],
            'deadline' => date('Y-m-d H:i', $deadlineTs),
            'urgency' => $urgencyInfo['urgency'],
            'time_text' => $urgencyInfo['time_text'],
            'message' => "任务 [{$task['title']}] {$urgencyInfo['time_text']}"
        ];
    }
    
    // 检查即将到期的项目（通过阶段时间）
    // 表：project_stage_times，字段：planned_end_date (DATE 类型)
    $projects = [];
    try {
        $projects = Db::query("
            SELECT p.id, p.project_code, p.project_name, p.current_status,
                   pst.planned_end_date as end_date
            FROM projects p
            INNER JOIN project_tech_assignments pta ON p.id = pta.project_id
            LEFT JOIN project_stage_times pst ON p.id = pst.project_id AND p.current_status = pst.stage_to
            WHERE pta.tech_user_id = ?
            AND p.deleted_at IS NULL
            AND p.current_status NOT IN ('设计完工', '设计评价')
            AND pst.planned_end_date IS NOT NULL
            AND pst.planned_end_date <= ?
            ORDER BY pst.planned_end_date ASC
            LIMIT 10
        ", [$userId, $dayAfterTomorrowDate]);
    } catch (Throwable $e) {
        error_log('[deadline_check] 项目查询错误: ' . $e->getMessage());
    }
    
    foreach ($projects as $project) {
        if (!$project['end_date']) continue;
        
        $urgencyInfo = determineUrgency($project['end_date'], false);
        if (!$urgencyInfo) continue;
        
        $reminders[] = [
            'type' => 'project',
            'id' => $project['id'],
            'project_code' => $project['project_code'],
            'project_name' => $project['project_name'],
            'current_status' => $project['current_status'],
            'deadline' => $project['end_date'],
            'urgency' => $urgencyInfo['urgency'],
            'time_text' => $urgencyInfo['time_text'],
            'message' => "项目 [{$project['project_code']}] {$project['project_name']} 阶段 [{$project['current_status']}] {$urgencyInfo['time_text']}"
        ];
    }
    
    // 按紧急程度排序：overdue > today > tomorrow
    usort($reminders, function($a, $b) {
        $order = ['overdue' => 0, 'today' => 1, 'tomorrow' => 2];
        return ($order[$a['urgency']] ?? 3) - ($order[$b['urgency']] ?? 3);
    });
    
    echo json_encode([
        'success' => true,
        'data' => [
            'reminders' => $reminders,
            'count' => count($reminders),
            'has_overdue' => count(array_filter($reminders, fn($r) => $r['urgency'] === 'overdue')) > 0,
            'has_today' => count(array_filter($reminders, fn($r) => $r['urgency'] === 'today')) > 0,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('[API] desktop_deadline_check 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}
