<?php
/**
 * 阶段时间计算公共函数
 * 用于 Web后台、客户门户、桌面端 三端统一计算逻辑
 */

/**
 * 获取项目起始日期
 * @param array $project 项目数据（需包含 timeline_start_date）
 * @param array $stages 阶段数据（需包含 planned_start_date）
 * @return DateTime
 */
function getProjectStartDate($project, $stages) {
    if (!empty($project['timeline_start_date'])) {
        return new DateTime($project['timeline_start_date']);
    } elseif (!empty($stages) && !empty($stages[0]['planned_start_date'])) {
        return new DateTime($stages[0]['planned_start_date']);
    }
    return new DateTime();
}

/**
 * 计算已进行天数（基于真实日历天数）
 * @param DateTime $startDate 起始日期
 * @param DateTime|null $endDate 结束日期（默认为今天）
 * @return int 已进行天数（最小为1）
 */
function calculateElapsedDays($startDate, $endDate = null) {
    $end = $endDate ?: new DateTime();
    return max(1, $end->diff($startDate)->days + 1);
}

/**
 * 计算剩余天数
 * @param int $totalDays 总天数
 * @param int $elapsedDays 已进行天数
 * @return int 剩余天数（最小为0）
 */
function calculateRemainingDays($totalDays, $elapsedDays) {
    return max(0, $totalDays - $elapsedDays);
}

/**
 * 计算进度百分比
 * @param int $elapsedDays 已进行天数
 * @param int $totalDays 总天数
 * @param bool $isCompleted 是否已完工
 * @param string|null $currentStatus 当前状态（用于无阶段时间数据时的回退计算）
 * @return int 进度百分比（0-100）
 */
function calculateOverallProgress($elapsedDays, $totalDays, $isCompleted, $currentStatus = null) {
    if ($isCompleted) return 100;
    if ($totalDays <= 0) {
        // 没有阶段时间数据时，基于当前阶段索引计算进度
        if ($currentStatus) {
            $statuses = ['待沟通', '需求确认', '设计中', '设计核对', '设计完工', '设计评价'];
            $currentIndex = array_search($currentStatus, $statuses);
            if ($currentIndex !== false && $currentIndex > 0) {
                return round($currentIndex / (count($statuses) - 1) * 100);
            }
        }
        return 0;
    }
    return min(100, round($elapsedDays * 100 / $totalDays));
}

/**
 * 解析完工时间戳
 * @param mixed $completedAt 完工时间（时间戳或日期字符串）
 * @return int|null 时间戳
 */
function parseCompletedAt($completedAt) {
    if (empty($completedAt)) return null;
    return is_numeric($completedAt) ? (int)$completedAt : strtotime($completedAt);
}

/**
 * 计算阶段时间摘要
 * @param array $project 项目数据
 * @param array $stages 阶段数据
 * @return array 摘要数据
 */
function calculateStageTimeSummary($project, $stages) {
    $isCompleted = !empty($project['completed_at']);
    $projectStartDate = getProjectStartDate($project, $stages);
    
    // 计算总天数
    $totalDays = 0;
    $currentStage = null;
    foreach ($stages as $st) {
        $totalDays += intval($st['planned_days'] ?? 0);
        if (isset($st['status']) && $st['status'] === 'in_progress') {
            $currentStage = $st;
        }
    }
    
    // 计算已进行天数
    $elapsedDays = 0;
    $actualDays = 0;
    $completedAtTimestamp = null;
    
    if ($isCompleted) {
        $completedAtTimestamp = parseCompletedAt($project['completed_at']);
        $completedDate = new DateTime(date('Y-m-d', $completedAtTimestamp));
        $actualDays = calculateElapsedDays($projectStartDate, $completedDate);
        $elapsedDays = $actualDays;
    } else {
        $elapsedDays = calculateElapsedDays($projectStartDate);
    }
    
    // 计算剩余天数和进度
    $remainingDays = calculateRemainingDays($totalDays, $elapsedDays);
    $currentStatus = $project['current_status'] ?? null;
    $overallProgress = calculateOverallProgress($elapsedDays, $totalDays, $isCompleted, $currentStatus);
    
    return [
        'total_days' => $totalDays,
        'elapsed_days' => $elapsedDays,
        'remaining_days' => $remainingDays,
        'overall_progress' => $overallProgress,
        'current_stage' => $isCompleted ? null : $currentStage,
        'is_completed' => $isCompleted,
        'actual_days' => $actualDays,
        'completed_at' => $completedAtTimestamp ? date('Y-m-d H:i', $completedAtTimestamp) : null,
        'start_date' => $projectStartDate->format('Y-m-d')
    ];
}

/**
 * 处理完工后的阶段状态
 * @param array $stages 阶段数据（引用传递）
 * @param bool $isCompleted 是否已完工
 * @return array 处理后的阶段数据
 */
function processStagesForCompletion($stages, $isCompleted) {
    if (!$isCompleted) return $stages;
    
    foreach ($stages as &$st) {
        $st['status'] = 'completed';
        $st['remaining_days'] = 0;
    }
    unset($st);
    
    return $stages;
}
