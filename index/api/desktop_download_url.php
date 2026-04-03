<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '仅支持POST']]);
    exit;
}

$user = desktop_auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => '未登录或登录已过期']]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$resourceId = intval($input['resource_id'] ?? 0);

if ($resourceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_INPUT', 'message' => '缺少 resource_id']]);
    exit;
}

$pdo = db_connect();

$stmt = $pdo->prepare("
    SELECT tr.*, c.group_code, c.id as customer_id
    FROM tech_resources tr
    JOIN customers c ON tr.group_code = c.group_code
    WHERE tr.id = ?
");
$stmt->execute([$resourceId]);
$resource = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => '资源不存在']]);
    exit;
}

$config = app_config();
$storageConfig = $config['storage'] ?? [];

if (empty($storageConfig['type']) || $storageConfig['type'] !== 's3') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'CONFIG_ERROR', 'message' => '存储配置错误']]);
    exit;
}

try {
    $provider = new S3StorageProvider($storageConfig);
    
    $expiresIn = 3600;
    $presignedUrl = $provider->getPresignedDownloadUrl($resource['storage_key'], $expiresIn);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'presigned_url' => $presignedUrl,
            'filename' => $resource['filename'],
            'filesize' => intval($resource['filesize']),
            'mime_type' => $resource['mime_type'],
            'expires_in' => $expiresIn,
        ]
    ]);
} catch (Exception $e) {
    error_log("获取下载URL失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'DOWNLOAD_ERROR', 'message' => '获取下载链接失败']]);
}
