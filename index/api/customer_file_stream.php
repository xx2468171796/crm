<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';
require_once __DIR__ . '/../services/FileLinkService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = current_user();
$fileId = (int)($_GET['id'] ?? 0);
$token = trim($_GET['token'] ?? '');

if ($fileId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '文件ID无效']);
    exit;
}

$mode = $_GET['mode'] ?? 'download'; // download / preview
$service = new CustomerFileService();

// 如果提供了token，检查文件分享链接权限
if ($token) {
    $link = FileLinkService::getByToken($token);
    if (!$link || !$link['enabled'] || $link['file_id'] != $fileId) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '分享链接无效或已停用']);
        exit;
    }
    
    // 检查权限
    $password = $_SESSION['file_share_password_' . $link['id']] ?? null;
    $permission = FileLinkService::checkPermission($link, $user, $password);
    
    if ($permission === 'none') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '无权限下载此文件']);
        exit;
    }
    
    // 通过分享链接访问，使用guest用户但通过权限检查
    // 创建一个临时的guest用户对象用于下载
    $guestUser = ['id' => 0, 'role' => 'guest'];
    
    try {
        // 直接获取文件信息，不通过权限检查（因为已经检查了分享链接权限）
        $file = Db::queryOne('SELECT * FROM customer_files WHERE id = :id AND deleted_at IS NULL', ['id' => $fileId]);
        if (!$file) {
            throw new RuntimeException('文件不存在');
        }
        
        // 获取storage provider
        require_once __DIR__ . '/../core/storage/storage_provider.php';
        $storage = storage_provider();
        $stream = $storage->readStream($file['storage_key']);
        
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        $disposition = ($mode === 'preview' && $file['preview_supported']) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file['filename']) . '"');
        header('Content-Length: ' . $file['filesize']);

        ignore_user_abort(true);
        set_time_limit(0);
        fpassthru($stream);
        fclose($stream);
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 正常登录或分享访客访问
if (!$user) {
    $fileOwner = Db::queryOne('SELECT customer_id FROM customer_files WHERE id = :id AND deleted_at IS NULL', ['id' => $fileId]);
    if (!$fileOwner) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '文件不存在或已删除']);
        exit;
    }

    $shareActor = resolveShareActor((int)$fileOwner['customer_id']);
    if ($shareActor) {
        $user = $shareActor;
    } else {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
}

try {
    $result = $service->streamFile($fileId, $user);
    $file = $result['file'];
    $stream = $result['stream'];

    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    $disposition = ($mode === 'preview' && $file['preview_supported']) ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file['filename']) . '"');
    header('Content-Length: ' . $file['filesize']);

    ignore_user_abort(true);
    set_time_limit(0);
    fpassthru($stream);
    fclose($stream);
} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

