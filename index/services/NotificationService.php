<?php
/**
 * NotificationService - 通知服务类
 * 
 * 统一封装 notifications 表的操作，确保字段名和类型正确
 * 
 * 表结构：
 * - user_id: INT
 * - type: VARCHAR(50)
 * - title: VARCHAR(255)
 * - content: TEXT
 * - related_type: VARCHAR(50)  -- 不是 data
 * - related_id: INT            -- 不是 data
 * - is_read: TINYINT(1)
 * - create_time: INT           -- 不是 created_at，使用 time() 而非 NOW()
 */

require_once __DIR__ . '/../core/db.php';

class NotificationService
{
    const TABLE = 'notifications';
    
    // 通知类型常量
    const TYPE_PROJECT = 'project';
    const TYPE_TASK = 'task';
    const TYPE_FILE_APPROVAL = 'file_approval';
    const TYPE_FILE_APPROVAL_RESULT = 'file_approval_result';
    const TYPE_SYSTEM = 'system';
    
    /**
     * 创建通知
     * 
     * @param int $userId 接收用户ID
     * @param string $type 通知类型
     * @param string $title 标题
     * @param string $content 内容
     * @param string|null $relatedType 关联类型 (project, task, file, batch 等)
     * @param int|null $relatedId 关联ID
     * @return int 新创建的通知ID
     */
    public static function create(
        int $userId,
        string $type,
        string $title,
        string $content,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): int {
        $now = time();  // 使用 INT 时间戳，不是 NOW()
        
        Db::execute("
            INSERT INTO notifications 
            (user_id, type, title, content, related_type, related_id, is_read, create_time)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ", [$userId, $type, $title, $content, $relatedType, $relatedId, $now]);
        
        return (int) Db::pdo()->lastInsertId();
    }
    
    /**
     * 批量创建通知（发送给多个用户）
     * 
     * @param array $userIds 用户ID数组
     * @param string $type 通知类型
     * @param string $title 标题
     * @param string $content 内容
     * @param string|null $relatedType 关联类型
     * @param int|null $relatedId 关联ID
     * @return int 创建的通知数量
     */
    public static function createBatch(
        array $userIds,
        string $type,
        string $title,
        string $content,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): int {
        $now = time();
        $count = 0;
        
        foreach ($userIds as $userId) {
            Db::execute("
                INSERT INTO notifications 
                (user_id, type, title, content, related_type, related_id, is_read, create_time)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ", [(int)$userId, $type, $title, $content, $relatedType, $relatedId, $now]);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * 标记单条通知为已读
     * 
     * @param int $notificationId 通知ID
     * @return bool 是否成功
     */
    public static function markAsRead(int $notificationId): bool {
        return Db::execute("UPDATE notifications SET is_read = 1 WHERE id = ?", [$notificationId]) > 0;
    }
    
    /**
     * 标记用户所有通知为已读
     * 
     * @param int $userId 用户ID
     * @return int 更新的记录数
     */
    public static function markAllAsRead(int $userId): int {
        return Db::execute("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$userId]);
    }
    
    /**
     * 获取用户未读通知数量
     * 
     * @param int $userId 用户ID
     * @return int 未读数量
     */
    public static function getUnreadCount(int $userId): int {
        $result = Db::queryOne("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
        return (int) ($result['cnt'] ?? 0);
    }
    
    /**
     * 获取用户通知列表
     * 
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param bool $unreadOnly 仅未读
     * @return array 通知列表
     */
    public static function getList(int $userId, int $limit = 20, bool $unreadOnly = false): array {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$userId];
        
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        
        $sql .= " ORDER BY create_time DESC LIMIT ?";
        $params[] = $limit;
        
        return Db::query($sql, $params);
    }
    
    /**
     * 删除通知
     * 
     * @param int $notificationId 通知ID
     * @return bool 是否成功
     */
    public static function delete(int $notificationId): bool {
        return Db::execute("DELETE FROM notifications WHERE id = ?", [$notificationId]) > 0;
    }
}
