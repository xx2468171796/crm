<?php
/**
 * 统一上传初始化 API（三端共用）
 * POST /api/upload_init.php
 * 
 * 支持：桌面端、Web端、客户门户
 * 
 * 参数:
 * - group_code: 群码（必填）
 * - project_id: 项目ID（可选）
 * - asset_type: 文件类型 works/models/customer/info/company（必填）
 * - rel_path: 相对路径（必填，包含文件名）
 * - filename: 文件名（必填）
 * - filesize: 文件大小（必填）
 * - mime_type: MIME类型（可选）
 * 
 * 认证方式（自动检测）:
 * - 桌面端: Authorization: Bearer <token>
 * - Web端: Session cookie
 * - 客户门户: X-Portal-Token
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../services/FolderUploadService.php';

// 统一认证：支持多端
$user = null;
$authType = 'unknown';

// 1. 尝试桌面端认证
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    require_once __DIR__ . '/../core/desktop_auth.php';
    $user = desktop_auth_check();
    if ($user) {
        $authType = 'desktop';
    }
}

// 2. 尝试Web端Session认证
if (!$user) {
    require_once __DIR__ . '/../core/auth.php';
    $user = current_user();
    if ($user) {
        $authType = 'web';
    }
}

// 3. 尝试客户门户Token认证
if (!$user) {
    $portalToken = $_SERVER['HTTP_X_PORTAL_TOKEN'] ?? '';
    if ($portalToken) {
        $tokenData = Db::queryOne(
            "SELECT customer_id, expires_at FROM customer_portal_tokens WHERE token = ? AND expires_at > ?",
            [$portalToken, time()]
        );
        if ($tokenData) {
            $customer = Db::queryOne("SELECT id, name, group_code FROM customers WHERE id = ?", [$tokenData['customer_id']]);
            if ($customer) {
                $user = [
                    'id' => $customer['id'],
                    'type' => 'customer',
                    'name' => $customer['name'],
                    'group_code' => $customer['group_code'],
                ];
                $authType = 'portal';
            }
        }
    }
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未授权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$groupCode = $input['group_code'] ?? '';
$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? 'works';
$relPath = $input['rel_path'] ?? '';
$filename = $input['filename'] ?? '';
$filesize = (int)($input['filesize'] ?? 0);
$mimeType = $input['mime_type'] ?? 'application/octet-stream';

// 客户门户使用客户的group_code
if ($authType === 'portal' && empty($groupCode)) {
    $groupCode = $user['group_code'] ?? '';
}

if (!$groupCode || !$filename || !$filesize) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new FolderUploadService();
    
    // 构建存储键
    $keyResult = $service->buildStorageKey($groupCode, $assetType, $relPath, $projectId);
    
    // 初始化分片上传
    $uploadResult = $service->initiateFileUpload($keyResult['storage_key'], $filesize, $mimeType);
    
    echo json_encode([
        'success' => true,
        'data' => $uploadResult
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] upload_init 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
