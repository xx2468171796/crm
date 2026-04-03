<?php
/**
 * 用户角色服务类
 * 封装用户角色分配操作
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rbac.php';

class UserRoleService {
    
    /**
     * 获取用户的所有角色ID
     */
    public static function getUserRoleIds(int $userId): array {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取用户的所有角色详情
     */
    public static function getUserRoles(int $userId): array {
        return Permission::getUserRoles($userId);
    }
    
    /**
     * 设置用户的角色（替换所有现有角色）
     */
    public static function setUserRoles(int $userId, array $roleIds): bool {
        $pdo = Db::pdo();
        
        // 过滤有效的角色ID
        $validRoleIds = [];
        if (!empty($roleIds)) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE id IN ({$placeholders}) AND status = 1");
            $stmt->execute($roleIds);
            $validRoleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        try {
            // 删除现有角色
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // 插入新角色
            if (!empty($validRoleIds)) {
                $now = time();
                $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, create_time) VALUES (?, ?, ?)");
                foreach ($validRoleIds as $roleId) {
                    $stmt->execute([$userId, $roleId, $now]);
                }
                
                // 同步更新 users.role 为第一个角色的代码
                $stmt = $pdo->prepare("SELECT code FROM roles WHERE id = ?");
                $stmt->execute([$validRoleIds[0]]);
                $roleCode = $stmt->fetchColumn();
                
                if ($roleCode) {
                    $stmt = $pdo->prepare("UPDATE users SET role = ?, update_time = ? WHERE id = ?");
                    $stmt->execute([$roleCode, $now, $userId]);
                }
            }
            
            $pdo->commit();
            
            // 清除用户权限缓存
            Permission::clearCache($userId);
            
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * 为用户添加角色
     */
    public static function addRole(int $userId, int $roleId): bool {
        $pdo = Db::pdo();
        
        // 检查角色是否存在且启用
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ? AND status = 1");
        $stmt->execute([$roleId]);
        if (!$stmt->fetch()) {
            throw new Exception('角色不存在或已禁用');
        }
        
        // 检查是否已有该角色
        $stmt = $pdo->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->execute([$userId, $roleId]);
        if ($stmt->fetch()) {
            return true; // 已有该角色，无需重复添加
        }
        
        // 添加角色
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, create_time) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $roleId, time()]);
        
        // 清除用户权限缓存
        Permission::clearCache($userId);
        
        return true;
    }
    
    /**
     * 移除用户的角色
     */
    public static function removeRole(int $userId, int $roleId): bool {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->execute([$userId, $roleId]);
        
        // 清除用户权限缓存
        Permission::clearCache($userId);
        
        return true;
    }
    
    /**
     * 获取拥有指定角色的所有用户
     */
    public static function getUsersByRole(int $roleId): array {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.realname, u.department_id, d.name as department_name
            FROM user_roles ur
            JOIN users u ON ur.user_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE ur.role_id = ? AND u.status = 1
            ORDER BY u.id
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 批量设置角色给多个用户
     */
    public static function batchSetRole(array $userIds, int $roleId): int {
        if (empty($userIds)) {
            return 0;
        }
        
        $pdo = Db::pdo();
        
        // 检查角色是否存在
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ? AND status = 1");
        $stmt->execute([$roleId]);
        if (!$stmt->fetch()) {
            throw new Exception('角色不存在或已禁用');
        }
        
        $count = 0;
        $now = time();
        
        foreach ($userIds as $userId) {
            // 检查是否已有该角色
            $stmt = $pdo->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?");
            $stmt->execute([$userId, $roleId]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, create_time) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $roleId, $now]);
                $count++;
            }
        }
        
        // 清除权限缓存
        Permission::clearCache();
        
        return $count;
    }
    
    /**
     * 同步用户的 users.role 字段（从 user_roles 表）
     */
    public static function syncPrimaryRole(int $userId): bool {
        $pdo = Db::pdo();
        
        // 获取用户的第一个角色
        $stmt = $pdo->prepare("
            SELECT r.code 
            FROM user_roles ur 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = ? AND r.status = 1 
            ORDER BY r.id 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $roleCode = $stmt->fetchColumn();
        
        if ($roleCode) {
            $stmt = $pdo->prepare("UPDATE users SET role = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$roleCode, time(), $userId]);
        }
        
        return true;
    }
    
    /**
     * 从 users.role 初始化 user_roles 表
     * 用于迁移旧数据
     */
    public static function migrateFromUsersRole(): int {
        $pdo = Db::pdo();
        
        // 获取所有用户
        $users = Db::query("SELECT id, role FROM users WHERE status = 1");
        $count = 0;
        $now = time();
        
        foreach ($users as $user) {
            if (empty($user['role'])) {
                continue;
            }
            
            // 检查是否已有 user_roles 记录
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            if ($stmt->fetchColumn() > 0) {
                continue; // 已有记录，跳过
            }
            
            // 获取角色ID
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE code = ?");
            $stmt->execute([$user['role']]);
            $roleId = $stmt->fetchColumn();
            
            if ($roleId) {
                $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, create_time) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $roleId, $now]);
                $count++;
            }
        }
        
        return $count;
    }
}
