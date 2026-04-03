<?php
/**
 * CORS 跨域处理 - 统一模块
 * 
 * 支持任意来源访问（开发和生产环境）
 * 
 * 使用方法：在 API 文件开头引入
 * require_once __DIR__ . '/../core/cors.php';
 * 
 * 注意：如果 Nginx 已配置 CORS，此文件可以不引入
 * Nginx 配置了 fastcgi_hide_header 会隐藏这些头
 */

// 防止重复引入
if (defined('CORS_HEADERS_SENT')) {
    return;
}
define('CORS_HEADERS_SENT', true);

// 动态获取请求来源，允许任意来源
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

// 设置 CORS 头（无硬编码，接受任意来源）
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
