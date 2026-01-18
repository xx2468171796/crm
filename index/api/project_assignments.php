<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目技术人员分配 API
 * 
 * POST - 添加分配
 * DELETE - 移除分配
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();
$pdo = Db::pdo();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            handleAdd($pdo, $user);
            break;
        case 'DELETE':
            handleRemove($pdo, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 添加技术人员分配
 */
function handleAdd($pdo, $user) {
    // 权限检查
    if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限分配技术人员'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $projectId = intval($data['project_id'] ?? 0);
    $techUserId = intval($data['tech_user_id'] ?? 0);
    $notes = trim($data['notes'] ?? '');
    
    if ($projectId <= 0 || $techUserId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查项目是否存在
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$projectId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查是否已分配
    $stmt = $pdo->prepare("SELECT id FROM project_tech_assignments WHERE project_id = ? AND tech_user_id = ?");
    $stmt->execute([$projectId, $techUserId]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '该技术人员已分配到此项目'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 添加分配
    $stmt = $pdo->prepare("
        INSERT INTO project_tech_assignments (project_id, tech_user_id, assigned_by, assigned_at, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$projectId, $techUserId, $user['id'], time(), $notes]);
    
    echo json_encode(['success' => true, 'message' => '分配成功', 'data' => ['id' => $pdo->lastInsertId()]], JSON_UNESCAPED_UNICODE);
}

/**
 * 移除技术人员分配
 */
function handleRemove($pdo, $user) {
    // 权限检查
    if (!isAdmin($user) && $user['role'] !== 'dept_leader') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限移除分配'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $assignmentId = intval($_GET['id'] ?? 0);
    
    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查是否存在
    $stmt = $pdo->prepare("SELECT id FROM project_tech_assignments WHERE id = ?");
    $stmt->execute([$assignmentId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '分配记录不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 删除分配
    $stmt = $pdo->prepare("DELETE FROM project_tech_assignments WHERE id = ?");
    $stmt->execute([$assignmentId]);
    
    echo json_encode(['success' => true, 'message' => '移除成功'], JSON_UNESCAPED_UNICODE);
}
