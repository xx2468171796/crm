<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $payload = $_POST;
}

$ids = $payload['ids'] ?? [];
if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请提供要删除的文件ID列表']);
    exit;
}

// 验证ID都是整数
$ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '文件ID无效']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$service = new CustomerFileService();
$deletedCount = 0;
$errors = [];

foreach ($ids as $fileId) {
    try {
        $file = $service->deleteFile($fileId, $user);
        $deletedCount++;
    } catch (Exception $e) {
        $errors[] = "文件ID {$fileId}: " . $e->getMessage();
    }
}

if ($deletedCount === 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '没有文件被删除',
        'errors' => $errors,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'deleted_count' => $deletedCount,
    'total_requested' => count($ids),
    'errors' => $errors,
]);

