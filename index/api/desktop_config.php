<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面客户端配置 API
 * 返回客户端需要的配置信息，包括 WebSocket 地址
 */

header('Content-Type: application/json; charset=utf-8');
// 获取配置
$config = require __DIR__ . '/../config.php';

// 构建 WebSocket URL
$wsConfig = $config['websocket'] ?? [];
$wsEnabled = $wsConfig['enabled'] ?? false;
$wsPort = $wsConfig['port'] ?? 8300;
$wsClientUrl = $wsConfig['client_url'] ?? '';

// 如果没有指定客户端 URL，自动检测
if (empty($wsClientUrl) && $wsEnabled) {
    // 获取当前请求的主机名
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // 移除端口号（如果有）
    $host = preg_replace('/:\d+$/', '', $host);
    
    // 判断协议
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    $wsProtocol = $isHttps ? 'wss' : 'ws';
    $wsClientUrl = "{$wsProtocol}://{$host}:{$wsPort}";
}

// 返回配置
echo json_encode([
    'success' => true,
    'data' => [
        'websocket' => [
            'enabled' => $wsEnabled,
            'url' => $wsClientUrl,
            'port' => $wsPort,
        ],
        'app' => [
            'name' => '技术资源同步',
            'version' => '1.5.3',
        ],
    ],
], JSON_UNESCAPED_UNICODE);
