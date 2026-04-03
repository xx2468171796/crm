<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST/DELETE']);
    exit;
}

$payload = $_POST;
parse_str(file_get_contents('php://input'), $bodyInput);
if (!empty($bodyInput)) {
    $payload = array_merge($payload, $bodyInput);
}

$fileId = (int)($payload['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '文件ID无效']);
    exit;
}

$user = current_user();
if (!$user) {
    $fileOwner = Db::queryOne('SELECT customer_id FROM customer_files WHERE id = :id AND deleted_at IS NULL', ['id' => $fileId]);
    if (!$fileOwner) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '文件不存在或已删除']);
        exit;
    }

    $shareActor = resolveShareActor((int)$fileOwner['customer_id']);
    if ($shareActor) {
        $user = $shareActor;
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
}

$service = new CustomerFileService();

try {
    $file = $service->deleteFile($fileId, $user);
    echo json_encode(['success' => true, 'file' => $file]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

