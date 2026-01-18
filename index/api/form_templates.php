<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单模板管理 API
 * GET - 查询模板列表或单个模板
 * POST - 创建模板
 * PUT - 更新模板
 * DELETE - 删除模板
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 权限检查：管理员可管理表单模板
if (!isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限管理表单模板'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Db::pdo();

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo, $user);
            break;
        case 'POST':
            handlePost($pdo, $user);
            break;
        case 'PUT':
            handlePut($pdo, $user);
            break;
        case 'DELETE':
            handleDelete($pdo, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($pdo, $user) {
    $templateId = intval($_GET['id'] ?? 0);
    
    if ($templateId > 0) {
        // 查询单个模板
        $stmt = $pdo->prepare("
            SELECT ft.*, ftv.schema_json, ftv.version_number,
                   u.realname as created_by_name
            FROM form_templates ft
            LEFT JOIN form_template_versions ftv ON ft.current_version_id = ftv.id
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE ft.id = ? AND ft.deleted_at IS NULL
        ");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '模板不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 获取版本历史
        $versionsStmt = $pdo->prepare("
            SELECT id, version_number, published_at, published_by
            FROM form_template_versions
            WHERE template_id = ?
            ORDER BY version_number DESC
        ");
        $versionsStmt->execute([$templateId]);
        $template['versions'] = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $template], JSON_UNESCAPED_UNICODE);
    } else {
        // 查询模板列表
        $formType = $_GET['form_type'] ?? '';
        $status = $_GET['status'] ?? '';
        
        $sql = "
            SELECT ft.*, ftv.version_number,
                   u.realname as created_by_name,
                   (SELECT COUNT(*) FROM form_instances fi WHERE fi.template_id = ft.id) as instance_count
            FROM form_templates ft
            LEFT JOIN form_template_versions ftv ON ft.current_version_id = ftv.id
            LEFT JOIN users u ON ft.created_by = u.id
            WHERE ft.deleted_at IS NULL
        ";
        $params = [];
        
        if (!empty($formType)) {
            $sql .= " AND ft.form_type = ?";
            $params[] = $formType;
        }
        if (!empty($status)) {
            $sql .= " AND ft.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY ft.update_time DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $templates], JSON_UNESCAPED_UNICODE);
    }
}

function handlePost($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    $name = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $formType = trim($data['form_type'] ?? 'custom');
    $schemaJson = $data['schema_json'] ?? '[]';
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '模板名称不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    
    $pdo->beginTransaction();
    
    try {
        // 创建模板
        $stmt = $pdo->prepare("
            INSERT INTO form_templates (name, description, form_type, status, created_by, create_time, update_time)
            VALUES (?, ?, ?, 'draft', ?, ?, ?)
        ");
        $stmt->execute([$name, $description, $formType, $user['id'], $now, $now]);
        $templateId = $pdo->lastInsertId();
        
        // 创建初始版本
        $versionStmt = $pdo->prepare("
            INSERT INTO form_template_versions (template_id, version_number, schema_json, create_time)
            VALUES (?, 1, ?, ?)
        ");
        $versionStmt->execute([$templateId, is_string($schemaJson) ? $schemaJson : json_encode($schemaJson), $now]);
        $versionId = $pdo->lastInsertId();
        
        // 更新模板的当前版本
        $updateStmt = $pdo->prepare("UPDATE form_templates SET current_version_id = ? WHERE id = ?");
        $updateStmt->execute([$versionId, $templateId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '模板创建成功',
            'data' => ['id' => $templateId]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handlePut($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        parse_str(file_get_contents('php://input'), $data);
    }
    
    $templateId = intval($data['id'] ?? 0);
    
    if ($templateId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少模板ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查模板是否存在
    $checkStmt = $pdo->prepare("SELECT id, current_version_id FROM form_templates WHERE id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$templateId]);
    $template = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '模板不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $updateFields = [];
    $params = [];
    
    if (isset($data['name'])) {
        $updateFields[] = "name = ?";
        $params[] = trim($data['name']);
    }
    if (isset($data['description'])) {
        $updateFields[] = "description = ?";
        $params[] = trim($data['description']);
    }
    if (isset($data['form_type'])) {
        $updateFields[] = "form_type = ?";
        $params[] = trim($data['form_type']);
    }
    
    $now = time();
    
    // 如果有schema_json，更新当前版本
    if (isset($data['schema_json'])) {
        $schemaJson = is_string($data['schema_json']) ? $data['schema_json'] : json_encode($data['schema_json']);
        $versionStmt = $pdo->prepare("UPDATE form_template_versions SET schema_json = ? WHERE id = ?");
        $versionStmt->execute([$schemaJson, $template['current_version_id']]);
    }
    
    if (!empty($updateFields)) {
        $updateFields[] = "update_time = ?";
        $params[] = $now;
        $params[] = $templateId;
        
        $sql = "UPDATE form_templates SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    echo json_encode(['success' => true, 'message' => '更新成功'], JSON_UNESCAPED_UNICODE);
}

function handleDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        parse_str(file_get_contents('php://input'), $data);
    }
    $templateId = intval($data['id'] ?? $_GET['id'] ?? 0);
    
    if ($templateId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少模板ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 软删除
    $stmt = $pdo->prepare("UPDATE form_templates SET deleted_at = ? WHERE id = ?");
    $stmt->execute([time(), $templateId]);
    
    echo json_encode(['success' => true, 'message' => '删除成功'], JSON_UNESCAPED_UNICODE);
}
