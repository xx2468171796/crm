<?php
/**
 * 获取可选收款人列表API
 * 返回同公司的用户列表，供收款时选择收款人
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $companyId = (int)($user['company_id'] ?? 0);
    
    // 获取所有活跃用户作为可选收款人
    $sql = 'SELECT u.id, u.username, u.realname, u.role, d.name AS department_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.status = 1
            ORDER BY u.realname ASC, u.username ASC';
    
    $users = Db::query($sql);
    
    $list = [];
    $currentUserId = (int)($user['id'] ?? 0);
    
    foreach ($users as $u) {
        $deptName = $u['department_name'] ?? '';
        $displayName = ($u['realname'] ?: $u['username']) . ($deptName ? " ({$deptName})" : '');
        $list[] = [
            'id' => (int)$u['id'],
            'name' => $displayName,
            'role' => $u['role'] ?? '',
            'is_current' => (int)$u['id'] === $currentUserId,
        ];
    }
    
    // 确保当前用户在列表最前面
    usort($list, function($a, $b) {
        if ($a['is_current'] && !$b['is_current']) return -1;
        if (!$a['is_current'] && $b['is_current']) return 1;
        return strcmp($a['name'], $b['name']);
    });
    
    echo json_encode([
        'success' => true,
        'data' => [
            'collectors' => $list,
            'current_user_id' => $currentUserId,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
