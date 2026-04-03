<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 用户列表 API（带数据权限）
 * 用于筛选器中的人员下拉列表
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

try {
    $pdo = Db::pdo();
    $role = trim($_GET['role'] ?? '');
    
    $sql = "SELECT u.id, u.username, u.realname, u.role, u.department_id 
            FROM users u 
            WHERE u.status = 1";
    $params = [];
    
    // 按角色筛选
    if ($role === 'tech') {
        $sql .= " AND u.role = 'tech'";
    } elseif ($role === 'sales') {
        $sql .= " AND u.role = 'sales'";
    }
    
    // 数据权限过滤：非管理员只能看到与自己数据范围相关的人员
    if (!isAdmin($user)) {
        if ($user['role'] === 'sales') {
            // 销售只能看到自己
            $sql .= " AND u.id = ?";
            $params[] = $user['id'];
        } elseif ($user['role'] === 'tech') {
            // 技术可以看到所有技术人员（用于协作）
            // 但如果筛选销售，只能看自己项目相关的销售
            if ($role === 'sales') {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM projects p 
                    LEFT JOIN customers c ON p.customer_id = c.id
                    WHERE (p.created_by = u.id OR c.owner_user_id = u.id)
                    AND EXISTS (
                        SELECT 1 FROM project_tech_assignments pta 
                        WHERE pta.project_id = p.id AND pta.tech_user_id = ?
                    )
                )";
                $params[] = $user['id'];
            }
        } elseif ($user['role'] === 'dept_leader' || $user['role'] === 'dept_admin') {
            // 部门管理者只能看到本部门及下级部门的人员
            $deptId = $user['department_id'] ?? null;
            if ($deptId) {
                $stmt = $pdo->prepare("SELECT path FROM departments WHERE id = ?");
                $stmt->execute([$deptId]);
                $dept = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dept && $dept['path']) {
                    if ($user['role'] === 'dept_leader') {
                        // 部门主管看部门及下级
                        $sql .= " AND u.department_id IN (SELECT id FROM departments WHERE path LIKE ?)";
                        $params[] = $dept['path'] . '%';
                    } else {
                        // 部门管理员只看本部门
                        $sql .= " AND u.department_id = ?";
                        $params[] = $deptId;
                    }
                } else {
                    // 没有部门，只能看自己
                    $sql .= " AND u.id = ?";
                    $params[] = $user['id'];
                }
            } else {
                // 没有部门，只能看自己
                $sql .= " AND u.id = ?";
                $params[] = $user['id'];
            }
        } else {
            // 其他角色只能看自己
            $sql .= " AND u.id = ?";
            $params[] = $user['id'];
        }
    }
    
    $sql .= " ORDER BY u.realname ASC, u.username ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $users
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
