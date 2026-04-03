<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单提交记录查询 API
 * GET - 查询表单提交详情
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$instanceId = intval($_GET['instance_id'] ?? 0);
$portalToken = trim($_GET['portal_token'] ?? '');

if ($instanceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少表单实例ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单实例
    $stmt = $pdo->prepare("
        SELECT fi.*, ft.name as template_name, ft.form_type,
               ftv.schema_json, ftv.version_number,
               p.project_name, p.id as project_id, p.customer_id
        FROM form_instances fi
        JOIN form_templates ft ON fi.template_id = ft.id
        JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
        LEFT JOIN projects p ON fi.project_id = p.id
        WHERE fi.id = ?
    ");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '表单实例不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 权限检查
    if (!empty($portalToken)) {
        // 门户客户访问
        $portalStmt = $pdo->prepare("SELECT * FROM portal_links WHERE token = ? AND enabled = 1");
        $portalStmt->execute([$portalToken]);
        $portal = $portalStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$portal || $portal['customer_id'] != $instance['customer_id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '无权查看此表单'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // 管理后台访问
        $user = current_user();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 查询提交记录
    $subStmt = $pdo->prepare("
        SELECT * FROM form_submissions 
        WHERE instance_id = ? 
        ORDER BY submitted_at DESC
    ");
    $subStmt->execute([$instanceId]);
    $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 解析schema和提交数据
    $schema = json_decode($instance['schema_json'] ?? '[]', true);
    
    foreach ($submissions as &$sub) {
        $sub['submission_data'] = json_decode($sub['submission_data_json'] ?? '{}', true);
        $sub['submitted_at_formatted'] = date('Y-m-d H:i:s', $sub['submitted_at']);
        unset($sub['submission_data_json']);
    }
    unset($sub);
    
    // 需求状态标签
    $statusLabels = [
        'pending' => '待填写',
        'communicating' => '需求沟通',
        'confirmed' => '需求确认',
        'modifying' => '需求修改'
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'instance' => [
                'id' => $instance['id'],
                'instance_name' => $instance['instance_name'],
                'template_name' => $instance['template_name'],
                'form_type' => $instance['form_type'],
                'status' => $instance['status'],
                'requirement_status' => $instance['requirement_status'] ?? 'pending',
                'requirement_status_label' => $statusLabels[$instance['requirement_status'] ?? 'pending'] ?? '未知',
                'project_name' => $instance['project_name'],
                'create_time' => date('Y-m-d H:i:s', $instance['create_time']),
                'update_time' => date('Y-m-d H:i:s', $instance['update_time'])
            ],
            'schema' => $schema,
            'submissions' => $submissions
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '查询失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
