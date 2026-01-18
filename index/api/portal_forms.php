<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 门户表单列表 API
 * GET - 获取客户项目的表单列表
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim($_GET['token'] ?? '');
$projectId = intval($_GET['project_id'] ?? 0);

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少访问token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 验证门户token
    $portalStmt = $pdo->prepare("SELECT * FROM portal_links WHERE token = ? AND enabled = 1");
    $portalStmt->execute([$token]);
    $portal = $portalStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$portal) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无效的访问链接'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $customerId = $portal['customer_id'];
    
    // 构建查询
    $sql = "
        SELECT fi.*, ft.name as template_name, ft.form_type,
               p.project_name,
               (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
        FROM form_instances fi
        JOIN form_templates ft ON fi.template_id = ft.id
        JOIN projects p ON fi.project_id = p.id
        WHERE p.customer_id = ?
    ";
    $params = [$customerId];
    
    if ($projectId > 0) {
        $sql .= " AND fi.project_id = ?";
        $params[] = $projectId;
    }
    
    $sql .= " ORDER BY fi.create_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 状态标签
    $statusLabels = [
        'pending' => '待填写',
        'communicating' => '需求沟通',
        'confirmed' => '需求确认',
        'modifying' => '需求修改'
    ];
    
    $statusColors = [
        'pending' => '#94a3b8',
        'communicating' => '#f59e0b',
        'confirmed' => '#10b981',
        'modifying' => '#ef4444'
    ];
    
    foreach ($forms as &$form) {
        $reqStatus = $form['requirement_status'] ?? 'pending';
        $form['requirement_status_label'] = $statusLabels[$reqStatus] ?? '未知';
        $form['requirement_status_color'] = $statusColors[$reqStatus] ?? '#94a3b8';
        $form['create_time_formatted'] = date('Y-m-d H:i', $form['create_time']);
        $form['update_time_formatted'] = date('Y-m-d H:i', $form['update_time']);
        
        // 判断可执行的操作
        $form['can_fill'] = ($reqStatus === 'pending' || $reqStatus === 'modifying');
        $form['can_view'] = ($form['submission_count'] > 0);
        $form['can_request_modify'] = ($reqStatus === 'confirmed');
    }
    unset($form);
    
    echo json_encode([
        'success' => true,
        'data' => $forms
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '查询失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
