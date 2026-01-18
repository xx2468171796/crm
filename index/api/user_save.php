<?php
require_once __DIR__ . '/../core/api_init.php';
// 员工保存API
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$currentUser = current_user();

if (!canOrAdmin(PermissionCode::USER_MANAGE)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $id = intval($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $realname = trim($_POST['realname'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'sales');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = intval($_POST['status'] ?? 1);

    if (!empty($password)) {
        if (preg_match('/^\$2[aby]\$/', $password) || strpos($password, '$argon2') === 0) {
            throw new Exception('密码格式异常，请输入明文新密码（不要提交哈希值）');
        }
    }
    
    // 验证必填字段
    if (empty($username) || empty($realname)) {
        throw new Exception('用户名和姓名不能为空');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新员工
        // 检查用户名是否被其他人使用
        $existing = Db::queryOne('SELECT id FROM users WHERE username = :username AND id != :id', [
            'username' => $username,
            'id' => $id
        ]);
        
        if ($existing) {
            throw new Exception('用户名已被使用');
        }
        
        // 获取部门ID
        $departmentId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        
        $sql = 'UPDATE users SET username = :username, realname = :realname, role = :role, 
                mobile = :mobile, email = :email, department_id = :department_id, status = :status, update_time = :now';
        $params = [
            'username' => $username,
            'realname' => $realname,
            'role' => $role,
            'mobile' => $mobile,
            'email' => $email,
            'department_id' => $departmentId,
            'status' => $status,
            'now' => $now,
            'id' => $id
        ];
        
        // 如果提供了密码，则更新密码
        if (!empty($password)) {
            $sql .= ', password = :password';
            $params['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $sql .= ' WHERE id = :id';
        
        Db::execute($sql, $params);
        
        echo json_encode(['success' => true, 'message' => '员工更新成功'], JSON_UNESCAPED_UNICODE);
    } else {
        // 新增员工
        if (empty($password)) {
            throw new Exception('密码不能为空');
        }
        
        // 检查用户名是否已存在
        $existing = Db::queryOne('SELECT id FROM users WHERE username = :username', [
            'username' => $username
        ]);
        
        if ($existing) {
            throw new Exception('用户名已存在');
        }
        
        // 获取部门ID
        $departmentId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        
        $insertParams = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'realname' => $realname,
            'role' => $role,
            'mobile' => $mobile,
            'email' => $email,
            'department_id' => $departmentId,
            'status' => $status,
            'create_time' => $now,
            'update_time' => $now
        ];
        
        Db::execute('INSERT INTO users (username, password, realname, role, mobile, email, department_id, status, create_time, update_time) 
                     VALUES (:username, :password, :realname, :role, :mobile, :email, :department_id, :status, :create_time, :update_time)', 
                     $insertParams);
        
        echo json_encode(['success' => true, 'message' => '员工添加成功'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('User save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
