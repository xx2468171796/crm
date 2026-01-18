<?php
/**
 * 状态配置
 * @description 集中管理项目和需求的状态定义
 */

// 项目状态列表
define('PROJECT_STATUSES', [
    '待沟通',
    '需求确认',
    '设计中',
    '设计核对',
    '设计完工',
    '设计评价'
]);

// 需求状态映射
define('REQUIREMENT_STATUSES', [
    'pending' => '待填写',
    'communicating' => '需求沟通',
    'confirmed' => '需求确认',
    'modifying' => '需求修改'
]);

// 状态颜色（Bootstrap风格）
define('STATUS_COLORS', [
    // 项目状态
    '待沟通' => 'secondary',
    '需求确认' => 'info',
    '设计中' => 'primary',
    '设计核对' => 'warning',
    '设计完工' => 'success',
    '设计评价' => 'dark',
    // 需求状态
    'pending' => 'secondary',
    'communicating' => 'warning',
    'confirmed' => 'success',
    'modifying' => 'danger'
]);

// 状态进度百分比
define('STATUS_PROGRESS', [
    '待沟通' => 10,
    '需求确认' => 25,
    '设计中' => 50,
    '设计核对' => 75,
    '设计完工' => 90,
    '设计评价' => 100
]);

/**
 * 获取状态标签
 * @param string $status 状态值
 * @param string $type 类型：'project'|'requirement'
 * @return string
 */
function getStatusLabel($status, $type = 'project') {
    if ($type === 'requirement') {
        return REQUIREMENT_STATUSES[$status] ?? $status;
    }
    return $status;
}

/**
 * 获取状态颜色
 * @param string $status 状态值
 * @return string Bootstrap颜色类名
 */
function getStatusColor($status) {
    return STATUS_COLORS[$status] ?? 'secondary';
}

/**
 * 获取状态进度
 * @param string $status 状态值
 * @return int 百分比
 */
function getStatusProgress($status) {
    return STATUS_PROGRESS[$status] ?? 0;
}

/**
 * 渲染状态徽章HTML
 * @param string $status 状态值
 * @param string $type 类型
 * @return string
 */
function renderStatusBadge($status, $type = 'project') {
    $label = getStatusLabel($status, $type);
    $color = getStatusColor($status);
    return sprintf(
        '<span class="badge bg-%s">%s</span>',
        htmlspecialchars($color),
        htmlspecialchars($label)
    );
}
