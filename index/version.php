<?php
/**
 * 应用版本号配置
 * 用于清除浏览器缓存
 * 
 * 使用方式：
 * 1. 自动模式（默认）：每次请求都使用当前时间作为版本号，开发时使用
 * 2. 手动模式：设置固定版本号，生产环境使用
 * 
 * 开发环境建议：使用自动模式（MODE = 'auto'）
 * 生产环境建议：使用手动模式（MODE = 'manual'），每次部署后更新VERSION
 * 
 * 环境检测：
 * - 如果在本地开发（localhost），自动使用 auto 模式
 * - 如果在生产服务器，使用 manual 模式
 */

// 自动检测环境
$isLocalhost = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1']) 
            || strpos($_SERVER['SERVER_NAME'] ?? '', '.local') !== false;

// 版本模式：'auto' 或 'manual'
// 本地开发自动使用 auto 模式，生产环境使用 manual 模式
define('VERSION_MODE', $isLocalhost ? 'auto' : 'manual');

// 手动版本号（仅在 MODE = 'manual' 时生效）
// 格式建议：YYYYMMDD.序号 例如：20251119.01
// 每次部署到生产环境时，更新这个版本号
define('MANUAL_VERSION', '20251119.01');

/**
 * 获取当前版本号
 */
function get_app_version() {
    if (VERSION_MODE === 'manual') {
        return MANUAL_VERSION;
    }
    
    // 自动模式：使用当前时间
    // 格式：YmdHi（年月日时分）
    return date('YmdHi');
}
