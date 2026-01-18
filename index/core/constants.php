<?php
/**
 * 项目常量定义
 * 用于统一管理项目状态、阶段状态等常量
 */

/**
 * 项目状态列表（按顺序）
 */
const PROJECT_STATUSES = [
    '待沟通',
    '需求确认', 
    '设计中',
    '设计核对',
    '设计完工',
    '设计评价'
];

/**
 * 阶段状态
 */
const STAGE_STATUSES = [
    'pending' => '待开始',
    'in_progress' => '进行中',
    'completed' => '已完成'
];

/**
 * 允许手动完工的状态列表
 */
const MANUAL_COMPLETE_ALLOWED_STATUSES = [
    '设计中',
    '设计核对',
    '设计完工',
    '设计评价'
];

/**
 * 评价截止时间（天数）
 */
const EVALUATION_DEADLINE_DAYS = 7;

/**
 * 获取状态索引
 * @param string $status 状态名称
 * @return int 状态索引（从0开始，-1表示未找到）
 */
function getStatusIndex($status) {
    $index = array_search($status, PROJECT_STATUSES);
    return $index === false ? -1 : $index;
}

/**
 * 检查是否允许手动完工
 * @param string $status 当前状态
 * @return bool
 */
function canManualComplete($status) {
    return in_array($status, MANUAL_COMPLETE_ALLOWED_STATUSES);
}

/**
 * 获取状态显示颜色
 * @param string $status 状态名称
 * @return array ['bg' => 背景色, 'color' => 文字色]
 */
function getStatusColor($status) {
    $colors = [
        '待沟通' => ['bg' => 'rgba(100, 116, 139, 0.1)', 'color' => '#64748b'],
        '需求确认' => ['bg' => 'rgba(59, 130, 246, 0.1)', 'color' => '#3b82f6'],
        '设计中' => ['bg' => 'rgba(99, 102, 241, 0.1)', 'color' => '#6366f1'],
        '设计核对' => ['bg' => 'rgba(245, 158, 11, 0.1)', 'color' => '#f59e0b'],
        '设计完工' => ['bg' => 'rgba(16, 185, 129, 0.1)', 'color' => '#10b981'],
        '设计评价' => ['bg' => 'rgba(16, 185, 129, 0.15)', 'color' => '#059669'],
    ];
    return $colors[$status] ?? $colors['待沟通'];
}
