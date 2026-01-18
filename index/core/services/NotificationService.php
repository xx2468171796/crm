<?php
/**
 * 通知服务
 * 处理系统通知的创建和查询
 */

class NotificationService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 创建通知
     */
    public function create($userId, $type, $title, $content = '', $relatedType = null, $relatedId = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$userId, $type, $title, $content, $relatedType, $relatedId, time()]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 批量创建通知（发送给多个用户）
     */
    public function createBatch($userIds, $type, $title, $content = '', $relatedType = null, $relatedId = null) {
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ");
        
        foreach ($userIds as $userId) {
            $stmt->execute([$userId, $type, $title, $content, $relatedType, $relatedId, $now]);
        }
    }
    
    /**
     * 获取用户未读通知数量
     */
    public function getUnreadCount($userId) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }
    
    /**
     * 获取用户通知列表
     */
    public function getList($userId, $limit = 20, $onlyUnread = false) {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY create_time DESC LIMIT ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 标记通知为已读
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * 标记所有通知为已读
     */
    public function markAllAsRead($userId) {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
    }
    
    /**
     * 获取项目技术人员ID列表
     */
    public static function getProjectTechUserIds($pdo, $projectId) {
        $stmt = $pdo->prepare("SELECT tech_user_id FROM project_tech_assignments WHERE project_id = ?");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取技术主管ID列表（从部门结构）
     */
    public static function getTechLeaderIds($pdo) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id 
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.code IN ('dept_leader', 'tech_leader', 'admin')
            AND u.status = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 发送表单提交通知
     */
    public function sendFormSubmitNotification($projectId, $formInstanceId, $formName, $projectName) {
        $techUserIds = self::getProjectTechUserIds($this->pdo, $projectId);
        
        if (empty($techUserIds)) {
            return;
        }
        
        $title = "新需求表单提交";
        $content = "项目【{$projectName}】有新的需求表单【{$formName}】已提交，请及时查看确认。";
        
        $this->createBatch($techUserIds, 'form_submit', $title, $content, 'form_instance', $formInstanceId);
    }
    
    /**
     * 发送需求修改申请通知
     */
    public function sendModifyRequestNotification($projectId, $formInstanceId, $formName, $projectName) {
        $techUserIds = self::getProjectTechUserIds($this->pdo, $projectId);
        
        if (empty($techUserIds)) {
            return;
        }
        
        $title = "客户申请修改需求";
        $content = "项目【{$projectName}】的需求表单【{$formName}】，客户申请修改需求，请关注。";
        
        $this->createBatch($techUserIds, 'form_modify_request', $title, $content, 'form_instance', $formInstanceId);
    }
    
    /**
     * 发送项目分配通知（含实时推送）
     */
    public function sendProjectAssignNotification($projectId, $projectName, $techUserIds, $assignedBy) {
        if (empty($techUserIds)) {
            return;
        }
        
        $title = "新项目分配";
        $content = "您被分配到项目【{$projectName}】，请及时查看项目详情。";
        
        // 创建数据库通知记录
        $notificationIds = [];
        foreach ($techUserIds as $userId) {
            $notificationIds[] = $this->create($userId, 'project_assign', $title, $content, 'project', $projectId);
        }
        
        // 推送实时通知到 WebSocket
        $this->pushRealtimeNotification($techUserIds, [
            'type' => 'notification',
            'title' => $title,
            'content' => $content,
            'urgency' => 'high',
            'data' => [
                'project_id' => $projectId,
                'project_name' => $projectName
            ],
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $notificationIds;
    }
    
    /**
     * 推送实时通知到 WebSocket 服务
     * 通过 HTTP 接口触发 WebSocket 推送
     */
    public function pushRealtimeNotification(array $userIds, array $message) {
        // 从配置文件读取推送端口
        $config = require __DIR__ . '/../../config.php';
        $pushPort = $config['websocket']['push_port'] ?? 8301;
        
        // WebSocket 推送接口地址
        $pushUrl = "http://127.0.0.1:{$pushPort}/push";
        
        $payload = json_encode([
            'user_ids' => $userIds,
            'message' => $message
        ]);
        
        // 使用 cURL 发送推送请求
        $ch = curl_init($pushUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2秒超时，不阻塞主流程
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("WebSocket 推送失败: HTTP {$httpCode}, Response: {$response}");
            return false;
        }
        
        return true;
    }
}
