<?php
/**
 * WebSocket 服务启动脚本
 * 
 * 启动方式：
 *   前台运行: php scripts/websocket_server.php
 *   后台运行: nohup php scripts/websocket_server.php > /var/log/websocket.log 2>&1 &
 * 
 * Windows 启动:
 *   C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe scripts\websocket_server.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../core/services/WebSocketServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// 从配置文件读取
$config = require __DIR__ . '/../config.php';
$wsConfig = $config['websocket'] ?? [];

// 配置（优先使用环境变量，其次使用配置文件）
$port = getenv('WS_PORT') ?: ($wsConfig['port'] ?? 8300);
$host = getenv('WS_HOST') ?: ($wsConfig['host'] ?? '0.0.0.0');

echo "========================================\n";
echo "  WebSocket 实时通知服务\n";
echo "========================================\n";
echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
echo "监听地址: ws://{$host}:{$port}\n";
echo "----------------------------------------\n";

try {
    $wsServer = new WebSocketServer();
    
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($wsServer)
        ),
        $port,
        $host
    );
    
    echo "服务已启动，等待连接...\n";
    echo "按 Ctrl+C 停止服务\n";
    echo "----------------------------------------\n";
    
    $server->run();
    
} catch (Exception $e) {
    echo "启动失败: " . $e->getMessage() . "\n";
    exit(1);
}
