<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单实例管理 API
 * GET - 查询实例列表
 * POST - 创建实例（为项目绑定表单）
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
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($pdo, $user) {
    $projectId = intval($_GET['project_id'] ?? 0);
    $instanceId = intval($_GET['id'] ?? 0);
    
    if ($instanceId > 0) {
        // 查询单个实例
        $stmt = $pdo->prepare("
            SELECT fi.*, ft.name as template_name, ft.form_type,
                   ftv.schema_json, ftv.version_number,
                   (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
            FROM form_instances fi
            JOIN form_templates ft ON fi.template_id = ft.id
            JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
            WHERE fi.id = ?
        ");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$instance) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        echo json_encode(['success' => true, 'data' => $instance], JSON_UNESCAPED_UNICODE);
        
    } else if ($projectId > 0) {
        // 查询项目的表单实例列表
        $stmt = $pdo->prepare("
            SELECT fi.*, ft.name as template_name, ft.form_type,
                   ftv.version_number,
                   (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
            FROM form_instances fi
            JOIN form_templates ft ON fi.template_id = ft.id
            JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
            WHERE fi.project_id = ?
            ORDER BY fi.create_time DESC
        ");
        $stmt->execute([$projectId]);
        $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $instances], JSON_UNESCAPED_UNICODE);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID或实例ID'], JSON_UNESCAPED_UNICODE);
    }
}

function handlePost($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    $projectId = intval($data['project_id'] ?? 0);
    $templateId = intval($data['template_id'] ?? 0);
    $instanceName = trim($data['instance_name'] ?? '');
    
    if ($projectId <= 0 || $templateId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID或模板ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查项目是否存在
    $projectStmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL");
    $projectStmt->execute([$projectId]);
    if (!$projectStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查模板是否存在且已发布
    $templateStmt = $pdo->prepare("
        SELECT ft.id, ft.name, ft.current_version_id, ft.form_type
        FROM form_templates ft
        WHERE ft.id = ? AND ft.deleted_at IS NULL AND ft.status = 'published'
    ");
    $templateStmt->execute([$templateId]);
    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '模板不存在或未发布'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($instanceName)) {
        $instanceName = $template['name'];
    }
    
    // 获取表单类型作为purpose
    $formType = $template['form_type'] ?? 'custom';
    // 兼容 'requirement' 和 'requirements' 两种写法
    $purpose = (in_array($formType, ['requirement', 'requirements'])) ? 'requirement' : 
               (($formType === 'evaluation') ? 'evaluation' : 'custom');
    
    // 生成填写链接token
    $fillToken = bin2hex(random_bytes(32));
    $now = time();
    
    $stmt = $pdo->prepare("
        INSERT INTO form_instances (project_id, template_id, template_version_id, instance_name, fill_token, status, purpose, requirement_status, created_by, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, 'pending', ?, ?, ?)
    ");
    $stmt->execute([$projectId, $templateId, $template['current_version_id'], $instanceName, $fillToken, $purpose, $user['id'], $now, $now]);
    $instanceId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => '表单实例创建成功',
        'data' => [
            'id' => $instanceId,
            'fill_token' => $fillToken,
            'fill_url' => "/form_fill.php?token={$fillToken}"
        ]
    ], JSON_UNESCAPED_UNICODE);
}
