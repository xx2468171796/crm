<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端表单模板 API
 * GET - 获取已发布的表单模板列表
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

try {
    // 获取已发布的表单模板
    $templates = Db::query("
        SELECT ft.id, ft.name, ft.form_type, ft.description, ft.status,
               ftv.version_number
        FROM form_templates ft
        LEFT JOIN form_template_versions ftv ON ft.current_version_id = ftv.id
        WHERE ft.deleted_at IS NULL AND ft.status = 'published'
        ORDER BY ft.name ASC
    ");
    
    echo json_encode(['success' => true, 'data' => $templates], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_form_templates 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}
