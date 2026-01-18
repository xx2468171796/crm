<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 字段可见性配置 API
 * GET - 查询配置列表
 * PUT - 批量更新配置
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
        case 'PUT':
            handlePut($pdo, $user);
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
    $entityType = $_GET['entity_type'] ?? '';
    
    $sql = "SELECT * FROM field_visibility_config";
    $params = [];
    
    if (!empty($entityType)) {
        $sql .= " WHERE entity_type = ?";
        $params[] = $entityType;
    }
    
    $sql .= " ORDER BY entity_type, sort_order";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $configs], JSON_UNESCAPED_UNICODE);
}

function handlePut($pdo, $user) {
    // 权限检查：仅管理员可配置
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限配置字段可见性'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $configs = $data['configs'] ?? [];
    
    if (empty($configs)) {
        echo json_encode(['success' => true, 'message' => '无需更新'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    
    $stmt = $pdo->prepare("
        UPDATE field_visibility_config 
        SET visibility_level = ?, tech_visible = ?, client_visible = ?, sort_order = ?, update_time = ?
        WHERE id = ?
    ");
    
    $updated = 0;
    foreach ($configs as $config) {
        $id = intval($config['id'] ?? 0);
        if ($id <= 0) continue;
        
        $visibilityLevel = $config['visibility_level'] ?? 'internal';
        $techVisible = intval($config['tech_visible'] ?? 1);
        $clientVisible = intval($config['client_visible'] ?? 0);
        $sortOrder = intval($config['sort_order'] ?? 0);
        
        $stmt->execute([$visibilityLevel, $techVisible, $clientVisible, $sortOrder, $now, $id]);
        $updated++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "更新了 {$updated} 条配置"
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取实体类型的可见字段列表（供其他API使用）
 */
function getVisibleFields($pdo, $entityType, $viewerRole) {
    $sql = "SELECT field_key FROM field_visibility_config WHERE entity_type = ?";
    $params = [$entityType];
    
    if ($viewerRole === 'tech') {
        $sql .= " AND tech_visible = 1";
    } elseif ($viewerRole === 'client') {
        $sql .= " AND client_visible = 1";
    }
    
    $sql .= " ORDER BY sort_order";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'field_key');
}
