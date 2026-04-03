<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单模板发布版本 API
 * POST - 发布新版本
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

// 权限检查：管理员可发布表单模板
if (!isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限发布表单模板'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$templateId = intval($input['template_id'] ?? 0);
$schemaJson = $input['schema_json'] ?? null;

if ($templateId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少模板ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询模板
    $stmt = $pdo->prepare("
        SELECT ft.*, ftv.schema_json as current_schema
        FROM form_templates ft
        LEFT JOIN form_template_versions ftv ON ft.current_version_id = ftv.id
        WHERE ft.id = ? AND ft.deleted_at IS NULL
    ");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '模板不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取最新版本号
    $maxVersionStmt = $pdo->prepare("SELECT MAX(version_number) as max_version FROM form_template_versions WHERE template_id = ?");
    $maxVersionStmt->execute([$templateId]);
    $maxVersion = $maxVersionStmt->fetch(PDO::FETCH_ASSOC)['max_version'] ?? 0;
    $newVersion = $maxVersion + 1;
    
    $now = time();
    
    // 使用传入的schema或当前版本的schema
    $schema = $schemaJson ?? $template['current_schema'] ?? '[]';
    if (!is_string($schema)) {
        $schema = json_encode($schema);
    }
    
    $pdo->beginTransaction();
    
    // 创建新版本
    $versionStmt = $pdo->prepare("
        INSERT INTO form_template_versions (template_id, version_number, schema_json, published_by, published_at, create_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $versionStmt->execute([$templateId, $newVersion, $schema, $user['id'], $now, $now]);
    $versionId = $pdo->lastInsertId();
    
    // 更新模板的当前版本和状态
    $updateStmt = $pdo->prepare("
        UPDATE form_templates 
        SET current_version_id = ?, status = 'published', update_time = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$versionId, $now, $templateId]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "版本 v{$newVersion} 发布成功",
        'data' => [
            'version_id' => $versionId,
            'version_number' => $newVersion
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '发布失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
