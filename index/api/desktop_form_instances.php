<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端表单实例 API
 * GET - 获取项目表单实例列表
 * POST - 创建表单实例 / 确认需求
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = desktop_auth_require();

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($user, $isManager);
            break;
        case 'POST':
            handlePost($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_form_instances 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

function handleGet($user, $isManager) {
    $projectId = intval($_GET['project_id'] ?? 0);
    $instanceId = intval($_GET['id'] ?? 0);
    
    if ($instanceId > 0) {
        // 查询单个实例详情（含提交记录）
        $instance = Db::queryOne("
            SELECT fi.*, fi.purpose, ft.name as template_name, ft.form_type,
                   ftv.schema_json, ftv.version_number
            FROM form_instances fi
            JOIN form_templates ft ON fi.template_id = ft.id
            JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
            WHERE fi.id = ?
        ", [$instanceId]);
        
        if (!$instance) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // 非管理员权限检查
        if (!$isManager) {
            $hasAccess = Db::queryOne("
                SELECT 1 FROM project_tech_assignments 
                WHERE project_id = ? AND tech_user_id = ?
            ", [$instance['project_id'], $user['id']]);
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '无权访问此表单'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        // 获取提交记录
        $submissions = Db::query("
            SELECT fs.*, u.realname as submitter_name
            FROM form_submissions fs
            LEFT JOIN users u ON fs.submitted_by = u.id
            WHERE fs.instance_id = ?
            ORDER BY fs.submit_time DESC
        ", [$instanceId]);
        
        $instance['submissions'] = $submissions;
        $instance['schema'] = json_decode($instance['schema_json'], true);
        unset($instance['schema_json']);
        
        echo json_encode(['success' => true, 'data' => $instance], JSON_UNESCAPED_UNICODE);
        
    } else if ($projectId > 0) {
        // 非管理员权限检查
        if (!$isManager) {
            $hasAccess = Db::queryOne("
                SELECT 1 FROM project_tech_assignments 
                WHERE project_id = ? AND tech_user_id = ?
            ", [$projectId, $user['id']]);
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '无权访问此项目'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        // 查询项目的表单实例列表
        $instances = Db::query("
            SELECT fi.id, fi.instance_name, fi.fill_token, fi.status, fi.requirement_status,
                   fi.create_time, fi.update_time,
                   ft.name as template_name, ft.form_type,
                   ftv.version_number,
                   (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
            FROM form_instances fi
            JOIN form_templates ft ON fi.template_id = ft.id
            JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
            WHERE fi.project_id = ?
            ORDER BY fi.create_time DESC
        ", [$projectId]);
        
        // 格式化数据
        foreach ($instances as &$inst) {
            $inst['create_time'] = $inst['create_time'] ? date('Y-m-d H:i', $inst['create_time']) : null;
            $inst['update_time'] = $inst['update_time'] ? date('Y-m-d H:i', $inst['update_time']) : null;
            $inst['submission_count'] = (int)$inst['submission_count'];
        }
        
        echo json_encode(['success' => true, 'data' => $instances], JSON_UNESCAPED_UNICODE);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID或实例ID'], JSON_UNESCAPED_UNICODE);
    }
}

function handlePost($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'create';
    
    switch ($action) {
        case 'create':
            createInstance($user, $data);
            break;
        case 'start_communication':
            startCommunication($user, $data);
            break;
        case 'confirm_requirement':
            confirmRequirement($user, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
}

function createInstance($user, $data) {
    $projectId = intval($data['project_id'] ?? 0);
    $templateId = intval($data['template_id'] ?? 0);
    $instanceName = trim($data['instance_name'] ?? '');
    
    if ($projectId <= 0 || $templateId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID或模板ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查项目是否存在
    $project = Db::queryOne("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL", [$projectId]);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查模板是否存在且已发布
    $template = Db::queryOne("
        SELECT id, name, current_version_id
        FROM form_templates
        WHERE id = ? AND deleted_at IS NULL AND status = 'published'
    ", [$templateId]);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '模板不存在或未发布'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if (empty($instanceName)) {
        $instanceName = $template['name'];
    }
    
    // 生成填写链接token
    $fillToken = bin2hex(random_bytes(32));
    $now = time();
    
    Db::execute("
        INSERT INTO form_instances (project_id, template_id, template_version_id, instance_name, fill_token, status, requirement_status, created_by, create_time, update_time)
        VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?)
    ", [$projectId, $templateId, $template['current_version_id'], $instanceName, $fillToken, $user['id'], $now, $now]);
    
    $instanceId = Db::lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => '表单实例创建成功',
        'data' => [
            'id' => $instanceId,
            'fill_token' => $fillToken
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function startCommunication($user, $data) {
    $instanceId = intval($data['instance_id'] ?? 0);
    
    if ($instanceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少实例ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查实例状态
    $instance = Db::queryOne("SELECT id, requirement_status FROM form_instances WHERE id = ?", [$instanceId]);
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($instance['requirement_status'] !== 'pending' && $instance['requirement_status'] !== null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '只有待沟通的需求可以开始沟通'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("
        UPDATE form_instances 
        SET requirement_status = 'communicating', update_time = ?
        WHERE id = ?
    ", [$now, $instanceId]);
    
    echo json_encode([
        'success' => true,
        'message' => '已开始沟通'
    ], JSON_UNESCAPED_UNICODE);
}

function confirmRequirement($user, $data) {
    $instanceId = intval($data['instance_id'] ?? 0);
    
    if ($instanceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少实例ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查实例状态
    $instance = Db::queryOne("SELECT id, requirement_status FROM form_instances WHERE id = ?", [$instanceId]);
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    if ($instance['requirement_status'] !== 'communicating') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '只有沟通中的需求可以确认'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("
        UPDATE form_instances 
        SET requirement_status = 'confirmed', update_time = ?
        WHERE id = ?
    ", [$now, $instanceId]);
    
    echo json_encode([
        'success' => true,
        'message' => '需求已确认'
    ], JSON_UNESCAPED_UNICODE);
}
