<?php
/**
 * 获取提成规则适用范围选项（部门和人员列表）
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
    $departments = Db::query(
        'SELECT id, name FROM departments WHERE status = 1 ORDER BY sort ASC, id ASC'
    );
    
    $users = Db::query(
        'SELECT id, realname, username, department_id FROM users WHERE status = 1 ORDER BY realname ASC, id ASC'
    );
    
    $deptList = [];
    foreach ($departments as $d) {
        $deptList[] = [
            'id' => (int)($d['id'] ?? 0),
            'name' => (string)($d['name'] ?? ''),
        ];
    }
    
    $userList = [];
    foreach ($users as $u) {
        $userList[] = [
            'id' => (int)($u['id'] ?? 0),
            'name' => (string)($u['realname'] ?: $u['username'] ?? ''),
            'department_id' => (int)($u['department_id'] ?? 0),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'departments' => $deptList,
            'users' => $userList,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
