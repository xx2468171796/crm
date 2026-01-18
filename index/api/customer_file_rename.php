<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$service = new CustomerFileService();

try {
    $action = $_POST['action'] ?? '';
    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $oldFolderPath = $_POST['old_folder_path'] ?? '';
    $newName = trim($_POST['new_name'] ?? '');

    if ($action === 'rename_file') {
        if ($fileId <= 0) {
            throw new InvalidArgumentException('文件ID无效');
        }
        if ($newName === '') {
            throw new InvalidArgumentException('新文件名不能为空');
        }

        $result = $service->renameFile($fileId, $newName, $user);
        echo json_encode([
            'success' => true,
            'message' => '重命名成功',
            'data' => $result,
        ]);
    } elseif ($action === 'rename_folder') {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('客户ID无效');
        }
        if ($oldFolderPath === '' && $oldFolderPath !== '0') {
            throw new InvalidArgumentException('文件夹路径无效');
        }
        if ($newName === '') {
            throw new InvalidArgumentException('新文件夹名称不能为空');
        }

        $result = $service->renameFolder($customerId, $oldFolderPath, $newName, $user);
        echo json_encode([
            'success' => true,
            'message' => '重命名成功',
            'data' => $result,
        ]);
    } else {
        throw new InvalidArgumentException('无效的操作类型');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    error_log('重命名文件/文件夹失败: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器错误',
    ]);
}

