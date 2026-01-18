<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 通用搜索 API
 * 
 * GET ?type=project&q=xxx - 搜索项目
 * GET ?type=user&q=xxx&role=tech - 搜索用户
 * GET ?type=customer&q=xxx - 搜索客户
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = desktop_auth_require();

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

$type = $_GET['type'] ?? '';
$query = $_GET['q'] ?? '';
$limit = min((int)($_GET['limit'] ?? 50), 100);
$role = $_GET['role'] ?? '';

try {
    switch ($type) {
        case 'project':
            $result = searchProjects($query, $limit, $user, $isManager);
            break;
        case 'user':
            $result = searchUsers($query, $role, $limit);
            break;
        case 'customer':
            $result = searchCustomers($query, $limit);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid type'], JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[API] desktop_search 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

function searchProjects($query, $limit, $user, $isManager) {
    $conditions = ["p.deleted_at IS NULL"];
    $params = [];
    
    // 非管理员只能搜索自己分配的项目
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    }
    
    if ($query) {
        $conditions[] = "(p.project_code LIKE ? OR p.project_name LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$query}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $sql = "
        SELECT 
            p.id,
            p.project_code,
            p.project_name,
            p.current_status,
            c.name as customer_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id AND c.deleted_at IS NULL
        WHERE {$whereClause}
        ORDER BY p.update_time DESC
        LIMIT {$limit}
    ";
    
    $rows = Db::query($sql, $params);
    
    return array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'project_code' => $row['project_code'],
            'project_name' => $row['project_name'],
            'customer_name' => $row['customer_name'] ?? '',
            'current_status' => $row['current_status'],
        ];
    }, $rows);
}

function searchUsers($query, $role, $limit) {
    $conditions = ["u.status = 1"];
    $params = [];
    
    if ($query) {
        $conditions[] = "(u.username LIKE ? OR u.realname LIKE ?)";
        $searchTerm = "%{$query}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($role) {
        $conditions[] = "u.role = ?";
        $params[] = $role;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $sql = "
        SELECT 
            u.id,
            u.username,
            u.realname,
            u.role
        FROM users u
        WHERE {$whereClause}
        ORDER BY u.realname ASC
        LIMIT {$limit}
    ";
    
    $rows = Db::query($sql, $params);
    
    return array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'realname' => $row['realname'] ?: $row['username'],
            'role' => $row['role'],
            'avatar' => null,
        ];
    }, $rows);
}

function searchCustomers($query, $limit) {
    $conditions = ["c.deleted_at IS NULL"];
    $params = [];
    
    if ($query) {
        $conditions[] = "(c.name LIKE ? OR c.group_code LIKE ? OR c.mobile LIKE ? OR c.customer_code LIKE ? OR EXISTS (SELECT 1 FROM projects p WHERE p.customer_id = c.id AND p.deleted_at IS NULL AND (p.project_code LIKE ? OR p.project_name LIKE ?)))";
        $searchTerm = "%{$query}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.group_code,
            c.mobile,
            u.realname as sales_name
        FROM customers c
        LEFT JOIN users u ON c.sales_id = u.id
        WHERE {$whereClause}
        ORDER BY c.update_time DESC
        LIMIT {$limit}
    ";
    
    $rows = Db::query($sql, $params);
    
    return array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'group_code' => $row['group_code'] ?? '',
            'phone' => $row['mobile'] ?? '',
            'sales_name' => $row['sales_name'] ?? '',
        ];
    }, $rows);
}
