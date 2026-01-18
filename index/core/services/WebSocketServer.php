<?php
/**
 * WebSocket 服务端
 * 实现实时通知推送功能
 */

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServer implements MessageComponentInterface {
    protected $connections;  // 所有连接
    protected $userMap;      // userId => connection 映射
    protected $connUserMap;  // resourceId => userId 映射
    protected $lastActivity; // 连接最后活动时间
    
    public function __construct() {
        $this->connections = new \SplObjectStorage;
        $this->userMap = [];
        $this->connUserMap = [];
        $this->lastActivity = [];
        
        echo "[" . date('Y-m-d H:i:s') . "] WebSocket 服务初始化完成\n";
    }
    
    /**
     * 新连接建立
     */
    public function onOpen(ConnectionInterface $conn) {
        $this->connections->attach($conn);
        $this->lastActivity[$conn->resourceId] = time();
        
        echo "[" . date('Y-m-d H:i:s') . "] 新连接: {$conn->resourceId}\n";
        
        // 发送欢迎消息，提示需要认证
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => '连接成功，请发送认证信息'
        ]));
    }
    
    /**
     * 接收消息
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $this->lastActivity[$from->resourceId] = time();
        
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => '无效的消息格式'
            ]));
            return;
        }
        
        switch ($data['type']) {
            case 'auth':
                $this->handleAuth($from, $data);
                break;
                
            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;
                
            default:
                echo "[" . date('Y-m-d H:i:s') . "] 未知消息类型: {$data['type']}\n";
        }
    }
    
    /**
     * 连接关闭
     */
    public function onClose(ConnectionInterface $conn) {
        // 清理用户映射
        if (isset($this->connUserMap[$conn->resourceId])) {
            $userId = $this->connUserMap[$conn->resourceId];
            unset($this->userMap[$userId]);
            unset($this->connUserMap[$conn->resourceId]);
            echo "[" . date('Y-m-d H:i:s') . "] 用户 {$userId} 断开连接\n";
        }
        
        unset($this->lastActivity[$conn->resourceId]);
        $this->connections->detach($conn);
        
        echo "[" . date('Y-m-d H:i:s') . "] 连接关闭: {$conn->resourceId}\n";
    }
    
    /**
     * 连接错误
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] 错误: {$e->getMessage()}\n";
        $conn->close();
    }
    
    /**
     * 处理认证
     */
    protected function handleAuth(ConnectionInterface $conn, array $data) {
        if (!isset($data['token'])) {
            $conn->send(json_encode([
                'type' => 'auth_result',
                'success' => false,
                'message' => '缺少 token'
            ]));
            return;
        }
        
        $token = $data['token'];
        // 移除 Bearer 前缀
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }
        
        // 验证 token
        $user = $this->verifyToken($token);
        
        if ($user) {
            // 如果用户已有连接，关闭旧连接
            if (isset($this->userMap[$user['id']])) {
                $oldConn = $this->userMap[$user['id']];
                $oldConn->send(json_encode([
                    'type' => 'kicked',
                    'message' => '您已在其他地方登录'
                ]));
                $oldConn->close();
            }
            
            // 建立新映射
            $this->userMap[$user['id']] = $conn;
            $this->connUserMap[$conn->resourceId] = $user['id'];
            
            $conn->send(json_encode([
                'type' => 'auth_result',
                'success' => true,
                'user_id' => $user['id'],
                'message' => '认证成功'
            ]));
            
            echo "[" . date('Y-m-d H:i:s') . "] 用户 {$user['id']} ({$user['realname']}) 认证成功\n";
        } else {
            $conn->send(json_encode([
                'type' => 'auth_result',
                'success' => false,
                'message' => 'Token 无效或已过期'
            ]));
        }
    }
    
    /**
     * 验证 Token
     */
    protected function verifyToken(string $token): ?array {
        // 加载数据库配置
        $configFile = __DIR__ . '/../../config.php';
        if (!file_exists($configFile)) {
            echo "[" . date('Y-m-d H:i:s') . "] 配置文件不存在\n";
            return null;
        }
        
        $config = require $configFile;
        
        try {
            $pdo = new PDO(
                "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
                $config['db']['username'],
                $config['db']['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // 查询 token
            $stmt = $pdo->prepare("
                SELECT u.id, u.realname, u.role 
                FROM desktop_tokens t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.token = ? AND t.expire_at > UNIX_TIMESTAMP()
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $user ?: null;
            
        } catch (PDOException $e) {
            echo "[" . date('Y-m-d H:i:s') . "] 数据库错误: {$e->getMessage()}\n";
            return null;
        }
    }
    
    /**
     * 向指定用户发送消息
     */
    public function sendToUser(int $userId, array $message): bool {
        if (!isset($this->userMap[$userId])) {
            return false;
        }
        
        $conn = $this->userMap[$userId];
        $conn->send(json_encode($message));
        
        echo "[" . date('Y-m-d H:i:s') . "] 向用户 {$userId} 发送消息: {$message['type']}\n";
        return true;
    }
    
    /**
     * 向多个用户发送消息
     */
    public function sendToUsers(array $userIds, array $message): int {
        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->sendToUser($userId, $message)) {
                $sent++;
            }
        }
        return $sent;
    }
    
    /**
     * 广播消息给所有已认证用户
     */
    public function broadcast(array $message): int {
        $sent = 0;
        foreach ($this->userMap as $userId => $conn) {
            $conn->send(json_encode($message));
            $sent++;
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 广播消息给 {$sent} 个用户\n";
        return $sent;
    }
    
    /**
     * 获取在线用户列表
     */
    public function getOnlineUsers(): array {
        return array_keys($this->userMap);
    }
    
    /**
     * 获取连接数
     */
    public function getConnectionCount(): int {
        return count($this->connections);
    }
    
    /**
     * 获取已认证用户数
     */
    public function getAuthenticatedCount(): int {
        return count($this->userMap);
    }
}
