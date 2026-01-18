<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 默认表单模板配置 API
 * 
 * GET /api/form_default_template.php?type=requirement
 * 获取指定类型的默认模板ID
 * 
 * POST /api/form_default_template.php
 * 设置默认模板
 * {
 *   "type": "requirement",
 *   "template_id": 123
 * }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

// 配置键映射
$configKeyMap = [
    'requirement' => 'default_requirement_template_id',
    'evaluation' => 'default_evaluation_template_id'
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 获取默认模板ID
    $type = $_GET['type'] ?? 'requirement';
    $configKey = $configKeyMap[$type] ?? null;
    
    if (!$configKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->execute([$configKey]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $templateId = intval($config['config_value'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'type' => $type,
            'template_id' => $templateId
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 设置默认模板
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = $input['type'] ?? 'requirement';
    $templateId = intval($input['template_id'] ?? 0);
    $configKey = $configKeyMap[$type] ?? null;
    
    if (!$configKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($templateId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的模板ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 验证模板存在且已发布
    $stmt = $pdo->prepare("SELECT id, name, status FROM form_templates WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '模板不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($template['status'] !== 'published') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '只能将已发布的模板设为默认'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 更新或插入配置
    $checkStmt = $pdo->prepare("SELECT id FROM system_config WHERE config_key = ?");
    $checkStmt->execute([$configKey]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
        $updateStmt->execute([$templateId, $configKey]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, ?)");
        $description = $type === 'requirement' ? '默认需求表单模板' : '默认评价表单模板';
        $insertStmt->execute([$configKey, $templateId, $description]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => '设置成功',
        'data' => [
            'type' => $type,
            'template_id' => $templateId,
            'template_name' => $template['name']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
}
