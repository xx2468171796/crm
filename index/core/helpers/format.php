<?php
/**
 * 格式化辅助函数
 * @description 提供通用的格式化功能
 */

/**
 * 格式化时间戳为日期字符串
 * @param int $timestamp Unix时间戳
 * @param string $format 格式类型：'date'|'datetime'|'time'|'relative'
 * @return string
 */
function formatTime($timestamp, $format = 'date') {
    if (empty($timestamp)) return '-';
    
    switch ($format) {
        case 'datetime':
            return date('Y-m-d H:i:s', $timestamp);
        case 'time':
            return date('H:i:s', $timestamp);
        case 'relative':
            return formatRelativeTime($timestamp);
        case 'date':
        default:
            return date('Y-m-d', $timestamp);
    }
}

/**
 * 格式化相对时间
 * @param int $timestamp
 * @return string
 */
function formatRelativeTime($timestamp) {
    if (empty($timestamp)) return '-';
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 2592000) return floor($diff / 86400) . '天前';
    
    return date('Y-m-d', $timestamp);
}

/**
 * 格式化文件大小
 * @param int $bytes
 * @return string
 */
function formatFileSize($bytes) {
    if ($bytes <= 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * 格式化金额
 * @param float $amount
 * @param string $currency
 * @return string
 */
function formatCurrency($amount, $currency = '¥') {
    return $currency . number_format($amount, 2);
}

/**
 * 格式化百分比
 * @param float $value
 * @param int $decimals
 * @return string
 */
function formatPercent($value, $decimals = 0) {
    return number_format($value, $decimals) . '%';
}

/**
 * 安全HTML输出
 * @param string $text
 * @return string
 */
function safeHtml($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 截断字符串
 * @param string $text
 * @param int $length
 * @param string $suffix
 * @return string
 */
function truncate($text, $length = 50, $suffix = '...') {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . $suffix;
}

/**
 * 生成随机字符串
 * @param int $length
 * @return string
 */
function randomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}
