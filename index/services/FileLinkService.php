<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

/**
 * 文件分享链接服务类
 * 提供文件分享链接的创建、查询、权限检查等功能
 */
class FileLinkService
{
    /**
     * 检查文件分享链接权限
     * 复用 checkLinkPermission 函数的逻辑
     * 
     * @param array $link 文件分享链接信息
     * @param array|null $user 当前用户（可选）
     * @param string|null $password 输入的密码（可选）
     * @return string 权限级别：'none' | 'view' | 'edit'
     */
    public static function checkPermission(array $link, ?array $user, ?string $password): string
    {
        // 直接复用现有的 checkLinkPermission 函数
        return checkLinkPermission($link, $user, $password);
    }

    /**
     * 清除文件分享链接权限缓存
     * 
     * @param int $linkId 链接ID
     */
    public static function clearPermissionCache(int $linkId): void
    {
        clearLinkPermissionCache($linkId);
    }

    /**
     * 生成唯一的token
     * 
     * @return string
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 创建文件分享链接
     * 
     * @param int $fileId 文件ID
     * @param array $options 选项
     * @return array 创建的链接信息
     */
    public static function create(int $fileId, array $options = []): array
    {
        $token = self::generateToken();
        $enabled = $options['enabled'] ?? 1;
        $password = !empty($options['password']) ? encryptLinkPassword($options['password']) : null;
        $orgPermission = $options['org_permission'] ?? 'edit';
        $passwordPermission = $options['password_permission'] ?? 'editable';
        $allowedViewUsers = !empty($options['allowed_view_users']) ? json_encode($options['allowed_view_users']) : null;
        $allowedEditUsers = !empty($options['allowed_edit_users']) ? json_encode($options['allowed_edit_users']) : null;
        $now = time();

        // 验证权限值
        if (!in_array($orgPermission, ['none', 'view', 'edit'])) {
            $orgPermission = 'edit';
        }
        if (!in_array($passwordPermission, ['readonly', 'editable'])) {
            $passwordPermission = 'editable';
        }

        Db::execute('
            INSERT INTO file_links 
            (file_id, token, enabled, password, org_permission, password_permission, 
             allowed_view_users, allowed_edit_users, created_at, updated_at)
            VALUES 
            (:file_id, :token, :enabled, :password, :org_permission, :password_permission,
             :allowed_view_users, :allowed_edit_users, :created_at, :updated_at)
        ', [
            'file_id' => $fileId,
            'token' => $token,
            'enabled' => $enabled,
            'password' => $password,
            'org_permission' => $orgPermission,
            'password_permission' => $passwordPermission,
            'allowed_view_users' => $allowedViewUsers,
            'allowed_edit_users' => $allowedEditUsers,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $linkId = Db::lastInsertId();
        return self::getById($linkId);
    }

    /**
     * 更新文件分享链接
     * 
     * @param int $linkId 链接ID
     * @param array $options 选项
     * @return array 更新后的链接信息
     */
    public static function update(int $linkId, array $options = []): array
    {
        $updates = [];
        $params = ['id' => $linkId];

        if (isset($options['enabled'])) {
            $updates[] = 'enabled = :enabled';
            $params['enabled'] = intval($options['enabled']);
        }

        if (isset($options['password'])) {
            if ($options['password'] === '') {
                $updates[] = 'password = NULL';
            } else {
                $updates[] = 'password = :password';
                $params['password'] = encryptLinkPassword($options['password']);
            }
        }

        if (isset($options['org_permission'])) {
            $orgPermission = $options['org_permission'];
            if (in_array($orgPermission, ['none', 'view', 'edit'])) {
                $updates[] = 'org_permission = :org_permission';
                $params['org_permission'] = $orgPermission;
            }
        }

        if (isset($options['password_permission'])) {
            $passwordPermission = $options['password_permission'];
            if (in_array($passwordPermission, ['readonly', 'editable'])) {
                $updates[] = 'password_permission = :password_permission';
                $params['password_permission'] = $passwordPermission;
            }
        }

        if (isset($options['allowed_view_users'])) {
            $updates[] = 'allowed_view_users = :allowed_view_users';
            $params['allowed_view_users'] = !empty($options['allowed_view_users']) 
                ? json_encode($options['allowed_view_users']) 
                : null;
        }

        if (isset($options['allowed_edit_users'])) {
            $updates[] = 'allowed_edit_users = :allowed_edit_users';
            $params['allowed_edit_users'] = !empty($options['allowed_edit_users']) 
                ? json_encode($options['allowed_edit_users']) 
                : null;
        }

        if (empty($updates)) {
            return self::getById($linkId);
        }

        $updates[] = 'updated_at = :updated_at';
        $params['updated_at'] = time();

        Db::execute('UPDATE file_links SET ' . implode(', ', $updates) . ' WHERE id = :id', $params);

        // 清除权限缓存
        self::clearPermissionCache($linkId);

        return self::getById($linkId);
    }

    /**
     * 根据ID获取链接信息
     * 
     * @param int $linkId 链接ID
     * @return array|null
     */
    public static function getById(int $linkId): ?array
    {
        return Db::queryOne('SELECT * FROM file_links WHERE id = :id', ['id' => $linkId]);
    }

    /**
     * 根据token获取链接信息
     * 
     * @param string $token token
     * @return array|null
     */
    public static function getByToken(string $token): ?array
    {
        return Db::queryOne('SELECT * FROM file_links WHERE token = :token', ['token' => $token]);
    }

    /**
     * 根据文件ID获取链接信息
     * 
     * @param int $fileId 文件ID
     * @return array|null
     */
    public static function getByFileId(int $fileId): ?array
    {
        return Db::queryOne('SELECT * FROM file_links WHERE file_id = :file_id', ['file_id' => $fileId]);
    }

    /**
     * 删除文件分享链接
     * 
     * @param int $linkId 链接ID
     * @return bool
     */
    public static function delete(int $linkId): bool
    {
        Db::execute('DELETE FROM file_links WHERE id = :id', ['id' => $linkId]);
        return true;
    }

    /**
     * 记录访问日志
     * 
     * @param int $linkId 链接ID
     * @param string $ip 访问IP
     * @return void
     */
    public static function recordAccess(int $linkId, string $ip): void
    {
        Db::execute('
            UPDATE file_links 
            SET last_access_at = :time, 
                last_access_ip = :ip, 
                access_count = access_count + 1 
            WHERE id = :id
        ', [
            'time' => time(),
            'ip' => $ip,
            'id' => $linkId
        ]);
    }
}

