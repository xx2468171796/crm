<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 交付物 API
 * 支持：上传、查询、删除
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../services/DeliverableService.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = Db::pdo();

try {
    // 处理特殊action
    if ($action === 'tree') {
        handleGetTree($pdo, $user);
        exit;
    }
    if ($action === 'folder') {
        handleFolder($pdo, $user, $method);
        exit;
    }
    if ($action === 'batch_approve') {
        handleBatchApprove($pdo, $user);
        exit;
    }
    if ($action === 'approve') {
        handleApprove($pdo, $user);
        exit;
    }
    if ($action === 'reset_approval') {
        handleResetApproval($pdo, $user);
        exit;
    }
    if ($action === 'download') {
        handleDownload($pdo, $user);
        exit;
    }
    if ($action === 'rename') {
        handleRename($pdo, $user);
        exit;
    }
    if ($action === 'batch_delete') {
        handleBatchDelete($pdo, $user);
        exit;
    }
    if ($action === 'trash') {
        handleTrash($pdo, $user);
        exit;
    }
    if ($action === 'restore') {
        handleRestore($pdo, $user);
        exit;
    }

    switch ($method) {
        case 'GET':
            handleGet($pdo, $user);
            break;
        case 'POST':
            handlePost($pdo, $user);
            break;
        case 'DELETE':
            handleDelete($pdo, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($pdo, $user) {
    $filters = [
        'project_id'      => $_GET['project_id'] ?? 0,
        'approval_status' => $_GET['approval_status'] ?? '',
        'file_category'   => $_GET['file_category'] ?? '',
        'group_by'        => $_GET['group_by'] ?? '',
        'user_id'         => $_GET['user_id'] ?? 0,
    ];

    // 按父文件夹筛选（保留 null 区分"未传"和"传了0"）
    if (isset($_GET['parent_folder_id'])) {
        $filters['parent_folder_id'] = $_GET['parent_folder_id'];
    }

    $result = DeliverableService::listDeliverables($pdo, $filters);

    if ($result['grouped']) {
        echo json_encode(['success' => true, 'data' => $result['data'], 'grouped' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => true, 'data' => $result['data']], JSON_UNESCAPED_UNICODE);
    }
}

function handlePost($pdo, $user) {
    $projectId      = intval($_POST['project_id'] ?? 0);
    $deliverableName = trim($_POST['title'] ?? $_POST['deliverable_name'] ?? '');
    $deliverableType = trim($_POST['deliverable_type'] ?? '');
    $fileCategory   = trim($_POST['file_category'] ?? 'artwork_file');
    $description    = trim($_POST['description'] ?? '');
    $visibilityLevel = trim($_POST['visibility_level'] ?? 'client');
    $uploadMode     = trim($_POST['upload_mode'] ?? 'files');
    $folderRoot     = trim($_POST['folder_root'] ?? '');
    $folderPaths    = $_POST['folder_paths'] ?? [];
    $filePaths      = $_POST['file_paths'] ?? [];
    $fileHash       = trim($_POST['file_hash'] ?? '');

    // [RC_DEBUG] 调试日志
    error_log("[RC_DEBUG] handlePost: projectId=$projectId, deliverableName=$deliverableName, fileCategory=$fileCategory, uploadMode=$uploadMode");
    error_log("[RC_DEBUG] FILES: " . json_encode(array_keys($_FILES)));
    if (isset($_FILES['file'])) {
        error_log("[RC_DEBUG] file: name={$_FILES['file']['name']}, size={$_FILES['file']['size']}, error={$_FILES['file']['error']}");
    }
    if (isset($_FILES['files'])) {
        error_log("[RC_DEBUG] files count: " . count($_FILES['files']['name']));
    }

    $filePath      = '';
    $fileSize      = 0;
    $parentFolderId = intval($_POST['parent_folder_id'] ?? 0);

    // 处理批量文件上传（文件夹上传）
    if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
        if ($projectId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $projectInfo = DeliverableService::getProjectStorageInfo($projectId);
        if (!$projectInfo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $batchResult = DeliverableService::batchUpload(
            $pdo, $user, $projectId, $fileCategory, $parentFolderId,
            $visibilityLevel, $uploadMode, $folderPaths, $filePaths
        );

        if ($batchResult['uploaded_count'] === 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => '没有文件上传成功',
                'errors'  => $batchResult['errors'],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'success'        => true,
            'message'        => "成功上传 {$batchResult['uploaded_count']} 个文件",
            'uploaded_count' => $batchResult['uploaded_count'],
            'files'          => $batchResult['files'],
            'errors'         => $batchResult['errors'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $projectInfo = DeliverableService::getProjectStorageInfo($projectId);
        if (!$projectInfo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $groupCode   = $projectInfo['group_code'];
        $projectName = $projectInfo['project_name'];

        // 获取文件夹路径（保持层级结构）
        $folderPath = '';
        if ($parentFolderId > 0) {
            $folderPath = DeliverableService::getFolderPath($pdo, $parentFolderId);
        }

        $originalName = $_FILES['file']['name'];
        $storageKey   = DeliverableService::buildStorageKey($groupCode, $projectName, $fileCategory, $originalName, $folderPath);

        try {
            $storage    = storage_provider();
            $tmpPath    = $_FILES['file']['tmp_name'];
            $mimeType   = mime_content_type($tmpPath) ?: 'application/octet-stream';
            $fileSize   = $_FILES['file']['size'];
            $asyncUploadFile = null;

            // 异步上传优化：2GB以下文件使用异步上传
            $useAsyncUpload = $fileSize <= 2 * 1024 * 1024 * 1024;
            error_log("[DELIVERABLES] fileSize=$fileSize, useAsyncUpload=" . ($useAsyncUpload ? 'true' : 'false'));

            if ($useAsyncUpload) {
                $cacheDir = __DIR__ . '/../../storage/upload_cache';
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0777, true);
                }
                error_log("[DELIVERABLES] cacheDir=$cacheDir, exists=" . (is_dir($cacheDir) ? 'true' : 'false'));

                $cacheFile = $cacheDir . '/' . uniqid('upload_') . '_' . basename($storageKey);
                error_log("[DELIVERABLES] copying $tmpPath to $cacheFile");
                if (copy($tmpPath, $cacheFile)) {
                    error_log("[DELIVERABLES] copy success, cacheFile=$cacheFile");
                    file_put_contents($cacheFile . '.json', json_encode([
                        'storage_key' => $storageKey,
                        'mime_type'   => $mimeType,
                        'file_size'   => $fileSize,
                        'create_time' => time()
                    ]));
                    $filePath        = $storageKey;
                    $asyncUploadFile = $cacheFile;
                } else {
                    error_log("[DELIVERABLES] copy FAILED");
                    $useAsyncUpload = false;
                }
            }

            if (!$useAsyncUpload) {
                error_log("[DELIVERABLES] using sync upload");
                $result   = $storage->putObject($storageKey, $tmpPath, ['mime_type' => $mimeType]);
                error_log("[RC_DEBUG] Upload SUCCESS: storageKey=$storageKey");
                $filePath = $storageKey;
                $fileSize = $result['bytes'] ?? $_FILES['file']['size'];
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '文件上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
    } else {
        $asyncUploadFile = null;
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            $projectId       = intval($data['project_id'] ?? $projectId);
            $deliverableName = trim($data['deliverable_name'] ?? $deliverableName);
            $deliverableType = trim($data['deliverable_type'] ?? $deliverableType);
            $filePath        = trim($data['file_path'] ?? '');
            $fileSize        = intval($data['file_size'] ?? 0);
        }
    }

    if ($projectId <= 0 || empty($deliverableName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必填字段（项目ID和名称）'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 检查项目是否存在且有权访问
    $project = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL");
    $project->execute([$projectId]);
    if (!$project->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $now            = time();
    $approvalStatus = DeliverableService::getInitialApprovalStatus($fileCategory);

    $deliverableId = DeliverableService::insertDeliverable($pdo, [
        'project_id'      => $projectId,
        'deliverable_name' => $deliverableName,
        'deliverable_type' => $deliverableType,
        'file_category'   => $fileCategory,
        'file_path'       => $filePath,
        'file_size'       => $fileSize,
        'file_hash'       => $fileHash ?: null,
        'visibility_level' => $visibilityLevel,
        'approval_status' => $approvalStatus,
        'submitted_by'    => $user['id'],
        'now'             => $now,
        'parent_folder_id' => $parentFolderId,
    ]);

    // 如果使用异步上传，先返回响应再执行S3上传
    if (isset($asyncUploadFile) && $asyncUploadFile && file_exists($asyncUploadFile)) {
        error_log("[DELIVERABLES] Async upload mode, returning response immediately");
        $response = json_encode([
            'success' => true,
            'message' => '交付物上传成功',
            'data'    => ['id' => $deliverableId, 'async' => true]
        ], JSON_UNESCAPED_UNICODE);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($response));
        header('Connection: close');
        header('X-Accel-Buffering: no');

        echo $response;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }

        // 请求已结束，后台执行S3上传
        try {
            $storage = storage_provider();
            $meta    = json_decode(file_get_contents($asyncUploadFile . '.json'), true);
            $storage->putObject($meta['storage_key'], $asyncUploadFile, ['mime_type' => $meta['mime_type']]);
            @unlink($asyncUploadFile . '.json');
            error_log("[DELIVERABLES_ASYNC] S3 upload success: {$meta['storage_key']}");
        } catch (Exception $e) {
            error_log("[DELIVERABLES_ASYNC] S3 upload failed: " . $e->getMessage());
        }
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => '交付物上传成功',
        'data'    => ['id' => $deliverableId]
    ], JSON_UNESCAPED_UNICODE);
}

function handleDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        parse_str(file_get_contents('php://input'), $data);
    }
    $deliverableId = intval($data['id'] ?? $_GET['id'] ?? 0);
    $permanent     = !empty($data['permanent']) || !empty($_GET['permanent']);

    if ($deliverableId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少交付物ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, file_path, deleted_at, approval_status FROM deliverables WHERE id = ?");
    $stmt->execute([$deliverableId]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '交付物不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!DeliverableService::canOperateFile($user, $d)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限删除'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($permanent) {
        DeliverableService::permanentDelete($pdo, $deliverableId);
        echo json_encode(['success' => true, 'message' => '永久删除成功'], JSON_UNESCAPED_UNICODE);
        return;
    }

    DeliverableService::softDelete($pdo, $deliverableId, $user['id']);
    echo json_encode(['success' => true, 'message' => '已移入回收站'], JSON_UNESCAPED_UNICODE);
}

// 获取目录树结构
function handleGetTree($pdo, $user) {
    $projectId    = intval($_GET['project_id'] ?? 0);
    $fileCategory = $_GET['file_category'] ?? '';

    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tree = DeliverableService::getTree($pdo, $projectId, $fileCategory);
    echo json_encode(['success' => true, 'data' => $tree], JSON_UNESCAPED_UNICODE);
}

// 文件夹操作（创建、重命名、删除）
function handleFolder($pdo, $user, $method) {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    switch ($method) {
        case 'POST':
            $projectId     = intval($data['project_id'] ?? 0);
            $folderName    = trim($data['folder_name'] ?? '');
            $parentFolderId = !empty($data['parent_folder_id']) ? intval($data['parent_folder_id']) : null;
            $fileCategory  = trim($data['file_category'] ?? 'artwork_file');

            if ($projectId <= 0 || empty($folderName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少必填字段'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $newId = DeliverableService::createFolder($pdo, $projectId, $folderName, $parentFolderId, $fileCategory, $user['id']);
            echo json_encode(['success' => true, 'message' => '文件夹创建成功', 'data' => ['id' => $newId]], JSON_UNESCAPED_UNICODE);
            break;

        case 'PUT':
            $folderId = intval($data['id'] ?? 0);
            $newName  = trim($data['folder_name'] ?? '');

            if ($folderId <= 0 || empty($newName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少必填字段'], JSON_UNESCAPED_UNICODE);
                return;
            }

            DeliverableService::renameFolder($pdo, $folderId, $newName);
            echo json_encode(['success' => true, 'message' => '重命名成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'DELETE':
            $folderId = intval($data['id'] ?? $_GET['id'] ?? 0);
            if ($folderId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少文件夹ID'], JSON_UNESCAPED_UNICODE);
                return;
            }
            DeliverableService::softDelete($pdo, $folderId, $user['id']);
            echo json_encode(['success' => true, 'message' => '文件夹删除成功'], JSON_UNESCAPED_UNICODE);
            break;
    }
}

// 单个审批
function handleApprove($pdo, $user) {
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无审批权限'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $data   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id     = intval($data['id'] ?? 0);
    $action = $data['approve_action'] ?? '';
    $reason = trim($data['reject_reason'] ?? '');

    if ($id <= 0 || !in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }

    DeliverableService::approveDeliverable($pdo, $id, $action, $reason, $user['id']);
    echo json_encode(['success' => true, 'message' => $action === 'approve' ? '审批通过' : '已驳回'], JSON_UNESCAPED_UNICODE);
}

// 重置审批状态（调回待审批）
function handleResetApproval($pdo, $user) {
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id   = intval($data['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }

    DeliverableService::resetApproval($pdo, $id);
    echo json_encode(['success' => true, 'message' => '已重置为待审批'], JSON_UNESCAPED_UNICODE);
}

// 批量审批
function handleBatchApprove($pdo, $user) {
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无审批权限'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $data   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ids    = $data['ids'] ?? [];
    $action = $data['approve_action'] ?? '';
    $reason = trim($data['reject_reason'] ?? '');

    if (empty($ids) || !is_array($ids) || !in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $affected = DeliverableService::batchApprove($pdo, $ids, $action, $reason, $user['id']);
    echo json_encode(['success' => true, 'message' => "已处理 {$affected} 个文件", 'affected' => $affected], JSON_UNESCAPED_UNICODE);
}

// 下载文件（生成临时URL）
function handleDownload($pdo, $user) {
    $fileId = intval($_GET['id'] ?? 0);

    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $urlData = DeliverableService::getDownloadUrl($pdo, $fileId);

        if (!$urlData) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['success' => true, 'data' => $urlData], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '获取下载链接失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

// 重命名文件
function handleRename($pdo, $user) {
    $data    = json_decode(file_get_contents('php://input'), true);
    $fileId  = intval($data['id'] ?? 0);
    $newName = trim($data['new_name'] ?? '');

    if ($fileId <= 0 || empty($newName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必填参数'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 先查出记录以便权限校验
    $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, file_path, deliverable_name, approval_status FROM deliverables WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!DeliverableService::canOperateFile($user, $file)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限重命名'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $renamed = DeliverableService::renameDeliverable($pdo, $fileId, $newName);
    echo json_encode([
        'success' => true,
        'message' => '重命名成功',
        'data'    => $renamed,
    ], JSON_UNESCAPED_UNICODE);
}

// 批量删除文件
function handleBatchDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $ids  = $data['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请提供要删除的文件ID列表'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '文件ID无效'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $result = DeliverableService::batchSoftDelete($pdo, $user, $ids);

    if ($result['deleted_count'] === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '没有文件被删除',
            'errors'  => $result['errors'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'success'         => true,
        'message'         => "已删除 {$result['deleted_count']} 个文件",
        'deleted_count'   => $result['deleted_count'],
        'total_requested' => count($ids),
        'errors'          => $result['errors'],
    ], JSON_UNESCAPED_UNICODE);
}

// 获取回收站列表
function handleTrash($pdo, $user) {
    $projectId    = intval($_GET['project_id'] ?? 0);
    $fileCategory = $_GET['file_category'] ?? '';
    $adminView    = DeliverableService::isAdmin($user);

    $items = DeliverableService::listTrash($pdo, $projectId, $fileCategory, $adminView, $user['id']);
    echo json_encode(['success' => true, 'data' => $items], JSON_UNESCAPED_UNICODE);
}

// 恢复已删除的文件
function handleRestore($pdo, $user) {
    $data   = json_decode(file_get_contents('php://input'), true);
    $fileId = intval($data['id'] ?? 0);

    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, deleted_at FROM deliverables WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (empty($file['deleted_at'])) {
        echo json_encode(['success' => false, 'message' => '文件未被删除'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($file['submitted_by'] != $user['id'] && !DeliverableService::isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限恢复'], JSON_UNESCAPED_UNICODE);
        return;
    }

    DeliverableService::restore($pdo, $fileId);
    echo json_encode(['success' => true, 'message' => '恢复成功'], JSON_UNESCAPED_UNICODE);
}
