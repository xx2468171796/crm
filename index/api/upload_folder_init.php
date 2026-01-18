<?php
/**
 * 统一文件夹上传初始化 API（三端共用）
 * POST /api/upload_folder_init.php
 * 
 * 支持：桌面端、Web端、客户门户
 * 
 * 参数:
 * - group_code: 群码（必填）
 * - project_id: 项目ID（可选）
 * - asset_type: 文件类型 works/models/customer/info/company（必填）
 * - files: 文件列表数组，每项包含:
 *   - rel_path: 相对路径（包含文件夹结构和文件名）
 *   - filename: 文件名
 *   - filesize: 文件大小
 *   - mime_type: MIME类型（可选）
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/unified_auth.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

// 统一认证
$authResult = unified_auth();
if (!$authResult['success']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $authResult['error']], JSON_UNESCAPED_UNICODE);
    exit;
}
$user = $authResult['user'];
$authType = $authResult['type'];

$input = json_decode(file_get_contents('php://input'), true);
$groupCode = $input['group_code'] ?? '';
$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? 'works';
$files = $input['files'] ?? [];

// 客户门户使用客户的group_code
if ($authType === 'portal' && empty($groupCode)) {
    $groupCode = $user['group_code'] ?? '';
}

if (!$groupCode || empty($files)) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    error_log('[FOLDER_UPLOAD_DEBUG] 开始初始化，groupCode=' . $groupCode . ', assetType=' . $assetType . ', files=' . count($files) . ', projectId=' . $projectId);
    $service = new FolderUploadService();
    $result = $service->initiateFolderUpload($groupCode, $assetType, $files, $projectId);
    error_log('[FOLDER_UPLOAD_DEBUG] 初始化成功: ' . json_encode($result));
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[FOLDER_UPLOAD_DEBUG] 初始化失败: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
