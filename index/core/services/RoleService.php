<?php
/**
 * 角色服务类
 * 封装角色 CRUD 操作
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rbac.php';

class RoleService {
    
    /**
     * 获取所有角色列表
     */
    public static function getAll(bool $includeDisabled = false): array {
        $pdo = Db::pdo();
        $sql = "SELECT * FROM roles";
        if (!$includeDisabled) {
            $sql .= " WHERE status = 1";
        }
        $sql .= " ORDER BY id";
        return Db::query($sql);
    }
    
    /**
     * 根据 ID 获取角色
     */
    public static function getById(int $id): ?array {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        return $role ?: null;
    }
    
    /**
     * 根据代码获取角色
     */
    public static function getByCode(string $code): ?array {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE code = ?");
        $stmt->execute([$code]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        return $role ?: null;
    }
    
    /**
     * 创建角色
     */
    public static function create(array $data): int {
        $pdo = Db::pdo();
        
        $name = trim($data['name'] ?? '');
        $code = trim($data['code'] ?? '');
        $description = trim($data['description'] ?? '');
        $permissions = $data['permissions'] ?? [];
        $isSystem = intval($data['is_system'] ?? 0);
        
        if (empty($name) || empty($code)) {
            throw new Exception('角色名称和代码不能为空');
        }
        
        // 检查代码是否已存在
        $existing = self::getByCode($code);
        if ($existing) {
            throw new Exception('角色代码已存在');
        }
        
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        $now = time();
        
        $stmt = $pdo->prepare("
            INSERT INTO roles (name, code, description, permissions, is_system, status, create_time, update_time)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$name, $code, $description, $permissionsJson, $isSystem, $now, $now]);
        
        return (int)$pdo->lastInsertId();
    }
    
    /**
     * 更新角色
     */
    public static function update(int $id, array $data): bool {
        $pdo = Db::pdo();
        
        $role = self::getById($id);
        if (!$role) {
            throw new Exception('角色不存在');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = trim($data['name']);
        }
        
        // 系统角色不允许修改代码
        if (isset($data['code']) && !$role['is_system']) {
            $code = trim($data['code']);
            // 检查新代码是否与其他角色冲突
            $existing = self::getByCode($code);
            if ($existing && $existing['id'] != $id) {
                throw new Exception('角色代码已存在');
            }
            $updates[] = 'code = ?';
            $params[] = $code;
        }
        
        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = trim($data['description']);
        }
        
        if (isset($data['permissions'])) {
            $updates[] = 'permissions = ?';
            $params[] = json_encode($data['permissions'], JSON_UNESCAPED_UNICODE);
        }
        
        if (isset($data['status'])) {
            $updates[] = 'status = ?';
            $params[] = intval($data['status']);
        }
        
        if (empty($updates)) {
            return true;
        }
        
        $updates[] = 'update_time = ?';
        $params[] = time();
        $params[] = $id;
        
        $sql = "UPDATE roles SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // 清除所有用户的权限缓存（角色权限变更会影响所有拥有该角色的用户）
        Permission::clearCache();
        
        return true;
    }
    
    /**
     * 删除角色
     */
    public static function delete(int $id): bool {
        $pdo = Db::pdo();
        
        $role = self::getById($id);
        if (!$role) {
            throw new Exception('角色不存在');
        }
        
        if ($role['is_system']) {
            throw new Exception('系统角色不可删除');
        }
        
        // 检查是否有用户使用该角色
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            throw new Exception("该角色下有 {$count} 个用户，请先移除用户角色");
        }
        
        // 删除数据权限规则
        $stmt = $pdo->prepare("DELETE FROM data_permissions WHERE role_id = ?");
        $stmt->execute([$id]);
        
        // 删除角色
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        
        return true;
    }
    
    /**
     * 获取角色的数据权限规则
     */
    public static function getDataPermissions(int $roleId): array {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM data_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 设置角色的数据权限规则
     */
    public static function setDataPermissions(int $roleId, array $permissions): bool {
        $pdo = Db::pdo();
        
        // 删除旧规则
        $stmt = $pdo->prepare("DELETE FROM data_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        // 插入新规则
        $now = time();
        $stmt = $pdo->prepare("
            INSERT INTO data_permissions (role_id, module, scope, create_time, update_time)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($permissions as $module => $scope) {
            if (in_array($scope, [DataScope::ALL, DataScope::DEPT_TREE, DataScope::DEPT, DataScope::SELF])) {
                $stmt->execute([$roleId, $module, $scope, $now, $now]);
            }
        }
        
        // 清除权限缓存
        Permission::clearCache();
        
        return true;
    }
    
    /**
     * 获取所有权限定义
     */
    public static function getAllPermissions(): array {
        return Db::query("SELECT * FROM permissions ORDER BY module, sort_order");
    }
    
    /**
     * 获取权限定义（按模块分组）
     */
    public static function getPermissionsByModule(): array {
        $permissions = self::getAllPermissions();
        $grouped = [];
        
        foreach ($permissions as $perm) {
            $module = $perm['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }
        
        return $grouped;
    }
    
    /**
     * 获取角色下的用户数量
     */
    public static function getUserCount(int $roleId): int {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
        $stmt->execute([$roleId]);
        return (int)$stmt->fetchColumn();
    }
}
