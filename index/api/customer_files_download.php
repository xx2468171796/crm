<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '客户ID无效']);
    exit;
}

$user = current_user();
if (!$user) {
    $shareActor = resolveShareActor($customerId);
    if ($shareActor) {
        $user = $shareActor;
    } else {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
}

$service = new CustomerFileService();

$folderPath = array_key_exists('folder_path', $_GET) ? (string)$_GET['folder_path'] : null;
$options = [
    'category' => $_GET['category'] ?? 'client_material',
    'folder_path' => $folderPath,
    'include_children' => $_GET['include_children'] ?? null,
    'selection_type' => $_GET['selection_type'] ?? null,
    'file_ids' => parse_file_ids($_GET['file_ids'] ?? []),
];

try {
    $result = $service->createZipDownload($customerId, $user, $options);
    $zipPath = $result['path'];
    if (!is_file($zipPath)) {
        throw new RuntimeException('压缩包生成失败');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($result['download_name']) . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache');

    readfile($zipPath);
    @unlink($zipPath);
} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function parse_file_ids($raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = explode(',', (string)$raw);
    }
    $ids = [];
    foreach ($items as $item) {
        $id = (int)$item;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

