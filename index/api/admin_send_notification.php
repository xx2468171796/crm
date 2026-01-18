<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 管理员发送通知 API
 * 
 * POST - 发送系统通知给指定用户
 * GET  - 获取已发送的通知列表
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

// 仅管理员可发送通知
$managerRoles = ['admin', 'super_admin', 'manager', 'tech_manager'];
if (!in_array($user['role'] ?? '', $managerRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '无权发送通知'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGetSentNotifications($user);
            break;
        case 'POST':
            handleSendNotification($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => '不支持的方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[admin_send_notification] 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 发送通知
 */
function handleSendNotification($sender) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    $type = $input['type'] ?? 'system';
    $recipients = $input['recipients'] ?? 'all'; // 'all' 或用户ID数组
    $priority = $input['priority'] ?? 'normal';
    
    // 验证优先级
    $validPriorities = ['low', 'normal', 'high', 'urgent'];
    if (!in_array($priority, $validPriorities)) {
        $priority = 'normal';
    }
    
    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => '通知标题不能为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => '通知内容不能为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 验证类型
    $validTypes = ['system', 'task', 'project'];
    if (!in_array($type, $validTypes)) {
        $type = 'system';
    }
    
    $pdo = Db::pdo();
    $now = time();
    $senderId = $sender['id'];
    
    // 获取接收人列表
    $userIds = [];
    if ($recipients === 'all') {
        // 发送给所有活跃用户
        $users = Db::query("SELECT id FROM users WHERE status = 1 AND deleted_at IS NULL");
        $userIds = array_column($users, 'id');
    } elseif (is_array($recipients)) {
        $userIds = array_map('intval', $recipients);
    } else {
        echo json_encode(['success' => false, 'error' => '接收人参数无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($userIds)) {
        echo json_encode(['success' => false, 'error' => '没有有效的接收人'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 批量插入通知
    $insertCount = 0;
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, content, priority, related_type, related_id, is_read, create_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
    ");
    
    foreach ($userIds as $userId) {
        try {
            $stmt->execute([
                $userId,
                $type,
                $title,
                $content,
                $priority,
                'admin_notification',
                $senderId,
                $now
            ]);
            $insertCount++;
        } catch (Exception $e) {
            error_log("[admin_send_notification] 插入通知失败 user_id={$userId}: " . $e->getMessage());
        }
    }
    
    // 记录发送日志
    try {
        Db::execute("
            INSERT INTO notification_send_logs (sender_id, title, content, type, recipient_count, send_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$senderId, $title, $content, $type, $insertCount, $now]);
    } catch (Exception $e) {
        // 表可能不存在，创建它
        try {
            Db::execute("
                CREATE TABLE IF NOT EXISTS notification_send_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    content TEXT,
                    type VARCHAR(50) DEFAULT 'system',
                    recipient_count INT DEFAULT 0,
                    send_time INT NOT NULL,
                    INDEX idx_sender (sender_id),
                    INDEX idx_send_time (send_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            Db::execute("
                INSERT INTO notification_send_logs (sender_id, title, content, type, recipient_count, send_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$senderId, $title, $content, $type, $insertCount, $now]);
        } catch (Exception $e2) {
            error_log("[admin_send_notification] 记录发送日志失败: " . $e2->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "通知已发送给 {$insertCount} 人",
        'data' => [
            'sent_count' => $insertCount,
            'title' => $title,
            'type' => $type,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取已发送的通知列表
 */
function handleGetSentNotifications($user) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(50, max(10, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    
    // 检查表是否存在
    try {
        $logs = Db::query("
            SELECT l.*, u.realname as sender_name
            FROM notification_send_logs l
            LEFT JOIN users u ON l.sender_id = u.id
            ORDER BY l.send_time DESC
            LIMIT {$offset}, {$pageSize}
        ");
        
        $total = (int)Db::queryOne("SELECT COUNT(*) as cnt FROM notification_send_logs")['cnt'];
        
        $result = [];
        foreach ($logs as $log) {
            $result[] = [
                'id' => (int)$log['id'],
                'sender_id' => (int)$log['sender_id'],
                'sender_name' => $log['sender_name'],
                'title' => $log['title'],
                'content' => $log['content'],
                'type' => $log['type'],
                'recipient_count' => (int)$log['recipient_count'],
                'send_time' => date('Y-m-d H:i', $log['send_time']),
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'logs' => $result,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => true,
            'data' => [
                'logs' => [],
                'pagination' => ['page' => 1, 'page_size' => $pageSize, 'total' => 0]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取可选的接收人列表
 */
function getAvailableRecipients() {
    $users = Db::query("
        SELECT id, realname, role, department_id
        FROM users 
        WHERE status = 1 AND deleted_at IS NULL
        ORDER BY realname
    ");
    
    return array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'name' => $u['realname'],
            'role' => $u['role'],
        ];
    }, $users);
}
