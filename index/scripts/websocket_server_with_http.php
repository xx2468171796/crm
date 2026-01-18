<?php
/**
 * WebSocket 服务 + HTTP 推送接口
 * 
 * 功能：
 * 1. WebSocket 服务 (ws://0.0.0.0:8080) - 客户端连接
 * 2. HTTP 推送接口 (http://127.0.0.1:8081/push) - 业务系统调用
 * 
 * 启动方式：
 *   php scripts/websocket_server_with_http.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../core/services/WebSocketServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

// 配置
$wsPort = getenv('WS_PORT') ?: 8080;
$httpPort = getenv('HTTP_PORT') ?: 8081;
$host = '0.0.0.0';

echo "========================================\n";
echo "  WebSocket 实时通知服务\n";
echo "========================================\n";
echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
echo "WebSocket: ws://{$host}:{$wsPort}\n";
echo "HTTP Push: http://127.0.0.1:{$httpPort}/push\n";
echo "----------------------------------------\n";

try {
    $loop = Loop::get();
    
    // 创建 WebSocket 服务
    $wsServer = new WebSocketServer();
    
    $webSocket = new SocketServer("{$host}:{$wsPort}", [], $loop);
    $wsIoServer = new IoServer(
        new HttpServer(
            new WsServer($wsServer)
        ),
        $webSocket,
        $loop
    );
    
    // 创建 HTTP 推送接口
    $httpServer = new ReactHttpServer(function (ServerRequestInterface $request) use ($wsServer) {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // 健康检查
        if ($path === '/health') {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'status' => 'ok',
                    'connections' => $wsServer->getConnectionCount(),
                    'authenticated' => $wsServer->getAuthenticatedCount()
                ])
            );
        }
        
        // 推送接口
        if ($path === '/push' && $method === 'POST') {
            $body = (string) $request->getBody();
            $data = json_decode($body, true);
            
            if (!$data || !isset($data['user_ids']) || !isset($data['message'])) {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => '无效的请求格式，需要 user_ids 和 message'])
                );
            }
            
            $userIds = $data['user_ids'];
            $message = $data['message'];
            
            $sent = $wsServer->sendToUsers($userIds, $message);
            
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'success' => true,
                    'sent' => $sent,
                    'total' => count($userIds)
                ])
            );
        }
        
        // 广播接口
        if ($path === '/broadcast' && $method === 'POST') {
            $body = (string) $request->getBody();
            $data = json_decode($body, true);
            
            if (!$data || !isset($data['message'])) {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => '无效的请求格式，需要 message'])
                );
            }
            
            $sent = $wsServer->broadcast($data['message']);
            
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['success' => true, 'sent' => $sent])
            );
        }
        
        // 在线用户
        if ($path === '/online') {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'online_users' => $wsServer->getOnlineUsers(),
                    'count' => $wsServer->getAuthenticatedCount()
                ])
            );
        }
        
        return new Response(404, [], 'Not Found');
    });
    
    $httpSocket = new SocketServer("127.0.0.1:{$httpPort}", [], $loop);
    $httpServer->listen($httpSocket);
    
    echo "服务已启动，等待连接...\n";
    echo "按 Ctrl+C 停止服务\n";
    echo "----------------------------------------\n";
    
    $loop->run();
    
} catch (Exception $e) {
    echo "启动失败: " . $e->getMessage() . "\n";
    exit(1);
}
