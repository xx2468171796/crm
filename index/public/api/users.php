<?php
/**
 * 用户 API
 */
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

$pdo = Db::pdo();
$role = $_GET['role'] ?? '';
$departmentId = intval($_GET['department_id'] ?? 0);

try {
    $where = '1=1';
    $params = [];
    
    if ($role) {
        $where .= ' AND role = ?';
        $params[] = $role;
    }
    
    if ($departmentId > 0) {
        $where .= ' AND department_id = ?';
        $params[] = $departmentId;
    }
    
    $sql = "SELECT id, username, realname, role, department_id FROM users WHERE $where ORDER BY realname ASC, username ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $users], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '查询失败'], JSON_UNESCAPED_UNICODE);
}
