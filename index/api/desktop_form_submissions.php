<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端表单提交 API
 * GET - 获取表单实例详情（含提交数据、状态变更记录）
 * POST - 保存编辑 / 变更状态
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($user);
            break;
        case 'POST':
            handlePost($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_form_submissions 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $instanceId = intval($_GET['instance_id'] ?? 0);
    
    if ($instanceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少实例ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取表单实例详情
    $instance = Db::queryOne("
        SELECT fi.*, fi.purpose, ft.name as template_name, ft.form_type,
               ftv.schema_json, ftv.version_number,
               p.project_name, p.id as project_id,
               c.name as customer_name
        FROM form_instances fi
        JOIN form_templates ft ON fi.template_id = ft.id
        JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE fi.id = ?
    ", [$instanceId]);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取提交记录
    $submissions = Db::query("
        SELECT fs.*
        FROM form_submissions fs
        WHERE fs.instance_id = ?
        ORDER BY fs.submitted_at DESC
    ", [$instanceId]);
    
    // 格式化提交记录
    foreach ($submissions as &$sub) {
        $sub['submitted_at_formatted'] = date('Y-m-d H:i:s', $sub['submitted_at']);
        $sub['submission_data'] = json_decode($sub['submission_data_json'] ?? '{}', true);
        unset($sub['submission_data_json']);
    }
    
    // 获取状态变更记录
    $statusLogs = Db::query("
        SELECT te.*, u.realname as operator_name
        FROM timeline_events te
        LEFT JOIN users u ON te.operator_user_id = u.id
        WHERE te.entity_type = 'form_instance' 
        AND te.entity_id = ?
        AND te.event_type = 'requirement_status_change'
        ORDER BY te.create_time DESC
    ", [$instanceId]);
    
    // 格式化状态变更记录
    foreach ($statusLogs as &$log) {
        $log['create_time_formatted'] = date('Y-m-d H:i', $log['create_time']);
        $log['event_data'] = json_decode($log['event_data_json'] ?? '{}', true);
        unset($log['event_data_json']);
    }
    
    // 解析 schema
    $schema = json_decode($instance['schema_json'] ?? '[]', true);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'instance' => [
                'id' => (int)$instance['id'],
                'instance_name' => $instance['instance_name'],
                'template_name' => $instance['template_name'],
                'form_type' => $instance['form_type'],
                'purpose' => $instance['purpose'] ?: 'requirement', // requirement | evaluation
                'version_number' => $instance['version_number'],
                'fill_token' => $instance['fill_token'],
                'status' => $instance['status'],
                'requirement_status' => $instance['requirement_status'] ?: 'pending',
                'project_id' => (int)$instance['project_id'],
                'project_name' => $instance['project_name'],
                'customer_name' => $instance['customer_name'],
                'create_time' => $instance['create_time'] ? date('Y-m-d H:i', $instance['create_time']) : null,
                'update_time' => $instance['update_time'] ? date('Y-m-d H:i', $instance['update_time']) : null,
            ],
            'schema' => $schema,
            'submissions' => $submissions,
            'status_logs' => $statusLogs,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handlePost($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'save_edit':
            saveEdit($user, $data);
            break;
        case 'change_status':
            changeStatus($user, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
}

function saveEdit($user, $data) {
    $instanceId = intval($data['instance_id'] ?? 0);
    $formData = $data['form_data'] ?? [];
    
    if ($instanceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少实例ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $formDataJson = json_encode($formData, JSON_UNESCAPED_UNICODE);
    
    // 获取最新提交记录
    $latestSubmission = Db::queryOne("
        SELECT id FROM form_submissions WHERE instance_id = ? ORDER BY submitted_at DESC LIMIT 1
    ", [$instanceId]);
    
    if ($latestSubmission) {
        // 更新现有提交数据
        Db::execute("
            UPDATE form_submissions SET submission_data_json = ? WHERE id = ?
        ", [$formDataJson, $latestSubmission['id']]);
    } else {
        // 新增提交记录（管理员/技术人员直接填写）
        Db::execute("
            INSERT INTO form_submissions (instance_id, submission_data_json, submitted_by_type, submitted_by_id, submitted_by_name, submitted_at, ip_address)
            VALUES (?, ?, 'internal', ?, ?, ?, ?)
        ", [$instanceId, $formDataJson, $user['id'] ?? 0, $user['name'] ?? '内部人员', $now, $_SERVER['REMOTE_ADDR'] ?? '']);
        
        // 更新实例状态为已提交
        Db::execute("UPDATE form_instances SET status = 'submitted' WHERE id = ?", [$instanceId]);
    }
    
    // 更新实例更新时间
    Db::execute("UPDATE form_instances SET update_time = ? WHERE id = ?", [$now, $instanceId]);
    
    echo json_encode([
        'success' => true,
        'message' => '保存成功'
    ], JSON_UNESCAPED_UNICODE);
}

function changeStatus($user, $data) {
    $instanceId = intval($data['instance_id'] ?? 0);
    $newStatus = trim($data['status'] ?? '');
    
    $validStatuses = ['pending', 'communicating', 'confirmed', 'modifying'];
    
    if ($instanceId <= 0 || !in_array($newStatus, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取当前状态
    $instance = Db::queryOne("SELECT requirement_status FROM form_instances WHERE id = ?", [$instanceId]);
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '实例不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $oldStatus = $instance['requirement_status'] ?: 'pending';
    if ($oldStatus === $newStatus) {
        echo json_encode(['success' => true, 'message' => '状态未变更'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    
    // 更新状态
    Db::execute("
        UPDATE form_instances SET requirement_status = ?, update_time = ? WHERE id = ?
    ", [$newStatus, $now, $instanceId]);
    
    // 记录状态变更
    $eventData = json_encode([
        'from_status' => $oldStatus,
        'to_status' => $newStatus,
    ], JSON_UNESCAPED_UNICODE);
    
    Db::execute("
        INSERT INTO timeline_events (entity_type, entity_id, event_type, event_data_json, operator_user_id, create_time)
        VALUES ('form_instance', ?, 'requirement_status_change', ?, ?, ?)
    ", [$instanceId, $eventData, $user['id'], $now]);
    
    echo json_encode([
        'success' => true,
        'message' => '状态已更新'
    ], JSON_UNESCAPED_UNICODE);
}
