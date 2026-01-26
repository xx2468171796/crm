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
    $projectId = intval($_GET['project_id'] ?? 0);
    $approvalStatus = $_GET['approval_status'] ?? '';
    $fileCategory = $_GET['file_category'] ?? '';
    $groupBy = $_GET['group_by'] ?? ''; // 支持 customer, project
    $userId = intval($_GET['user_id'] ?? 0);
    
    $sql = "
        SELECT d.*, u.realname as submitted_by_name, au.realname as approved_by_name,
               p.project_name, p.project_code, p.customer_id,
               c.name as customer_name
        FROM deliverables d
        LEFT JOIN users u ON d.submitted_by = u.id
        LEFT JOIN users au ON d.approved_by = au.id
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE d.deleted_at IS NULL
    ";
    
    $params = [];
    
    // 按项目筛选
    if ($projectId > 0) {
        $sql .= " AND d.project_id = ?";
        $params[] = $projectId;
    }
    
    // 按审批状态筛选（用于审批工作台）
    if (!empty($approvalStatus)) {
        $sql .= " AND d.approval_status = ?";
        $params[] = $approvalStatus;
    }
    
    // 按文件分类筛选
    if (!empty($fileCategory)) {
        $sql .= " AND d.file_category = ?";
        $params[] = $fileCategory;
    }
    
    // 按用户筛选
    if ($userId > 0) {
        $sql .= " AND d.submitted_by = ?";
        $params[] = $userId;
    }
    
    // 按父文件夹筛选
    $parentFolderId = $_GET['parent_folder_id'] ?? null;
    if ($parentFolderId !== null) {
        if ($parentFolderId === '' || $parentFolderId === '0' || $parentFolderId === 'null') {
            $sql .= " AND (d.parent_folder_id IS NULL OR d.parent_folder_id = 0)";
        } else {
            $sql .= " AND d.parent_folder_id = ?";
            $params[] = intval($parentFolderId);
        }
    }
    
    $sql .= " ORDER BY c.name ASC, p.project_name ASC, d.create_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deliverables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 如果需要按层级分组
    if ($groupBy === 'hierarchy') {
        $grouped = [];
        foreach ($deliverables as $d) {
            $customerId = $d['customer_id'] ?? 0;
            $customerName = $d['customer_name'] ?? '未知客户';
            $projectId = $d['project_id'];
            $projectName = $d['project_name'] ?? '未知项目';
            
            if (!isset($grouped[$customerId])) {
                $grouped[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'projects' => []
                ];
            }
            
            if (!isset($grouped[$customerId]['projects'][$projectId])) {
                $grouped[$customerId]['projects'][$projectId] = [
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                    'project_code' => $d['project_code'] ?? '',
                    'deliverables' => []
                ];
            }
            
            $grouped[$customerId]['projects'][$projectId]['deliverables'][] = $d;
        }
        
        // 转换为数组格式
        $result = [];
        foreach ($grouped as $customer) {
            $customer['projects'] = array_values($customer['projects']);
            $result[] = $customer;
        }
        
        echo json_encode(['success' => true, 'data' => $result, 'grouped' => true], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode(['success' => true, 'data' => $deliverables], JSON_UNESCAPED_UNICODE);
}

function handlePost($pdo, $user) {
    $projectId = intval($_POST['project_id'] ?? 0);
    $deliverableName = trim($_POST['title'] ?? $_POST['deliverable_name'] ?? '');
    $deliverableType = trim($_POST['deliverable_type'] ?? '');
    $fileCategory = trim($_POST['file_category'] ?? 'artwork_file'); // customer_file, artwork_file, model_file
    $description = trim($_POST['description'] ?? '');
    $visibilityLevel = trim($_POST['visibility_level'] ?? 'client');
    $uploadMode = trim($_POST['upload_mode'] ?? 'files');
    $folderRoot = trim($_POST['folder_root'] ?? '');
    $folderPaths = $_POST['folder_paths'] ?? [];
    $filePaths = $_POST['file_paths'] ?? []; // webkitRelativePath
    $fileHash = trim($_POST['file_hash'] ?? '');
    
    // [RC_DEBUG] 调试日志
    error_log("[RC_DEBUG] handlePost: projectId=$projectId, deliverableName=$deliverableName, fileCategory=$fileCategory, uploadMode=$uploadMode");
    error_log("[RC_DEBUG] FILES: " . json_encode(array_keys($_FILES)));
    if (isset($_FILES['file'])) {
        error_log("[RC_DEBUG] file: name={$_FILES['file']['name']}, size={$_FILES['file']['size']}, error={$_FILES['file']['error']}");
    }
    if (isset($_FILES['files'])) {
        error_log("[RC_DEBUG] files count: " . count($_FILES['files']['name']));
    }
    
    // 处理文件上传
    $filePath = '';
    $fileSize = 0;
    $parentFolderId = intval($_POST['parent_folder_id'] ?? 0);
    
    // 处理批量文件上传（文件夹上传）
    if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
        handleBatchUpload($pdo, $user, $projectId, $fileCategory, $parentFolderId, $visibilityLevel, $uploadMode, $folderRoot, $folderPaths, $filePaths);
        return;
    }
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // 获取项目与客户信息用于构建存储路径
        $projectRow = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code, c.group_name, c.name as customer_name
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );
        if (!$projectRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // 优先使用 group_code，其次用 group_name，最后用客户名称
        $groupCode = $projectRow['group_code'] ?: $projectRow['group_name'] ?: $projectRow['customer_name'] ?: ('P' . $projectId);
        $groupCode = preg_replace('/[\/\\\\:*?"<>|]/', '_', $groupCode);
        $projectName = $projectRow['project_name'] ?: $projectRow['project_code'] ?: ('项目' . $projectId);
        $projectName = preg_replace('/[\/\\:*?"<>|]/', '_', $projectName);
        
        // 获取文件夹路径（保持层级结构）
        $folderPath = '';
        if ($parentFolderId > 0) {
            $folderPath = getFolderPath($pdo, $parentFolderId);
        }
        
        $originalName = $_FILES['file']['name'];
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        
        // 构建存储路径（项目级统一走 groups/）
        // groups/{groupCode}/{项目名称}/{客户文件|作品文件|模型文件}/{文件夹路径}/{文件名}
        switch ($fileCategory) {
            case 'customer_file':
                $categoryDir = '客户文件';
                break;
            case 'model_file':
                $categoryDir = '模型文件';
                break;
            default:
                $categoryDir = '作品文件';
                break;
        }
        $storageKey = "groups/{$groupCode}/{$projectName}/{$categoryDir}";
        if (!empty($folderPath)) {
            $storageKey .= "/{$folderPath}";
        }
        $finalFileName = preg_replace('/[\/\\:*?"<>|]/', '_', $originalName);
        $storageKey .= "/{$finalFileName}";
        
        try {
            $storage = storage_provider();
            $tmpPath = $_FILES['file']['tmp_name'];
            $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
            $fileSize = $_FILES['file']['size'];
            $asyncUploadFile = null; // 初始化异步上传文件变量
            
            // 异步上传优化：2GB以下文件使用异步上传
            $useAsyncUpload = $fileSize <= 2 * 1024 * 1024 * 1024;
            error_log("[DELIVERABLES] fileSize=$fileSize, useAsyncUpload=" . ($useAsyncUpload ? 'true' : 'false'));
            
            if ($useAsyncUpload) {
                // 先复制文件到SSD缓存目录
                $cacheDir = __DIR__ . '/../../storage/upload_cache';
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0777, true);
                }
                error_log("[DELIVERABLES] cacheDir=$cacheDir, exists=" . (is_dir($cacheDir) ? 'true' : 'false'));
                
                $cacheFile = $cacheDir . '/' . uniqid('upload_') . '_' . basename($storageKey);
                error_log("[DELIVERABLES] copying $tmpPath to $cacheFile");
                if (copy($tmpPath, $cacheFile)) {
                    error_log("[DELIVERABLES] copy success, cacheFile=$cacheFile");
                    // 保存元数据
                    file_put_contents($cacheFile . '.json', json_encode([
                        'storage_key' => $storageKey,
                        'mime_type' => $mimeType,
                        'file_size' => $fileSize,
                        'create_time' => time()
                    ]));
                    
                    // DB 中只保存 storage_key
                    $filePath = $storageKey;
                    
                    // 标记使用异步上传，后面会先返回响应再执行S3上传
                    $asyncUploadFile = $cacheFile;
                } else {
                    // 复制失败，回退到同步上传
                    error_log("[DELIVERABLES] copy FAILED");
                    $useAsyncUpload = false;
                }
            }
            
            if (!$useAsyncUpload) {
                error_log("[DELIVERABLES] using sync upload");
                // 同步上传
                $result = $storage->putObject($storageKey, $tmpPath, ['mime_type' => $mimeType]);
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
        // 兼容旧的JSON方式
        $asyncUploadFile = null; // 初始化异步上传文件变量
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            $projectId = intval($data['project_id'] ?? $projectId);
            $deliverableName = trim($data['deliverable_name'] ?? $deliverableName);
            $deliverableType = trim($data['deliverable_type'] ?? $deliverableType);
            $filePath = trim($data['file_path'] ?? '');
            $fileSize = intval($data['file_size'] ?? 0);
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
    
    $now = time();
    
    // 根据文件分类确定审批状态
    // artwork_file 需要审批，其他类型直接通过
    $approvalStatus = ($fileCategory === 'artwork_file') ? 'pending' : 'approved';
    
    // 插入交付物
    $stmt = $pdo->prepare("
        INSERT INTO deliverables (
            project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, file_hash,
            visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $projectId, $deliverableName, $deliverableType, $fileCategory, $filePath, $fileSize, $fileHash ?: null,
        $visibilityLevel, $approvalStatus, $user['id'], $now, $now, $now, $parentFolderId ?: null
    ]);
    
    $deliverableId = $pdo->lastInsertId();
    
    // 写入时间线
    $timelineStmt = $pdo->prepare("
        INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $timelineStmt->execute([
        'deliverable',
        $deliverableId,
        '提交交付物',
        $user['id'],
        json_encode(['deliverable_name' => $deliverableName, 'type' => $deliverableType]),
        $now
    ]);
    
    // 如果使用异步上传，先返回响应再执行S3上传
    if (isset($asyncUploadFile) && $asyncUploadFile && file_exists($asyncUploadFile)) {
        error_log("[DELIVERABLES] Async upload mode, returning response immediately");
        $response = json_encode([
            'success' => true,
            'message' => '交付物上传成功',
            'data' => ['id' => $deliverableId, 'async' => true]
        ], JSON_UNESCAPED_UNICODE);
        
        // 清除所有输出缓冲
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($response));
        header('Connection: close');
        header('X-Accel-Buffering: no'); // 禁用Nginx缓冲
        
        echo $response;
        
        // 立即刷新输出缓冲区并结束请求
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
        
        // 请求已结束，用户不用等待，现在执行S3上传
        try {
            $storage = storage_provider();
            $meta = json_decode(file_get_contents($asyncUploadFile . '.json'), true);
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
        'data' => ['id' => $deliverableId]
    ], JSON_UNESCAPED_UNICODE);
}

function handleDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        parse_str(file_get_contents('php://input'), $data);
    }
    $deliverableId = intval($data['id'] ?? $_GET['id'] ?? 0);
    $permanent = !empty($data['permanent']) || !empty($_GET['permanent']); // 是否永久删除（支持 URL 参数）
    
    if ($deliverableId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少交付物ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查是否有权删除
    $deliverable = $pdo->prepare("SELECT id, submitted_by, is_folder, file_path, deleted_at, approval_status FROM deliverables WHERE id = ?");
    $deliverable->execute([$deliverableId]);
    $d = $deliverable->fetch(PDO::FETCH_ASSOC);
    
    if (!$d) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '交付物不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $role = $user['role'] ?? '';
    $isManagerRole = isAdmin($user) || in_array($role, ['manager', 'tech_manager'], true);
    $isUploader = ($d['submitted_by'] == $user['id']);
    $approvalStatus = $d['approval_status'] ?? 'pending';
    $canOperate = false;
    if ($isManagerRole) {
        $canOperate = true;
    } elseif ($isUploader && in_array($approvalStatus, ['pending', 'rejected'], true)) {
        $canOperate = true;
    }

    if (!$canOperate) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限删除'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 永久删除（从回收站删除）
    if ($permanent) {
        $storage = storage_provider();
        if ($d['is_folder']) {
            deleteFolderPermanent($pdo, $storage, $deliverableId);
        } else {
            // 删除 S3 文件
            if (!empty($d['file_path']) && !filter_var($d['file_path'], FILTER_VALIDATE_URL)) {
                $storage->deleteObject($d['file_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
            $stmt->execute([$deliverableId]);
        }
        echo json_encode(['success' => true, 'message' => '永久删除成功'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 软删除（移入回收站）
    $now = time();
    $userId = $user['id'] ?? 0;
    if ($d['is_folder']) {
        softDeleteFolder($pdo, $deliverableId, $now, $userId);
    } else {
        $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?");
        $stmt->execute([$now, $userId, $deliverableId]);
    }
    
    echo json_encode(['success' => true, 'message' => '已移入回收站'], JSON_UNESCAPED_UNICODE);
}

// 获取文件夹完整路径（用于MinIO存储结构）
function getFolderPath($pdo, $folderId) {
    $path = [];
    $currentId = $folderId;
    $maxDepth = 20; // 防止无限循环
    
    while ($currentId > 0 && $maxDepth-- > 0) {
        $stmt = $pdo->prepare("SELECT id, deliverable_name, parent_folder_id FROM deliverables WHERE id = ? AND is_folder = 1");
        $stmt->execute([$currentId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$folder) break;
        
        array_unshift($path, $folder['deliverable_name']);
        $currentId = intval($folder['parent_folder_id'] ?? 0);
    }
    
    return implode('/', $path);
}

// 递归软删除文件夹及其子项
function softDeleteFolder($pdo, $folderId, $deletedAt, $deletedBy = 0) {
    // 先软删除子项
    $children = $pdo->prepare("SELECT id, is_folder FROM deliverables WHERE parent_folder_id = ? AND deleted_at IS NULL");
    $children->execute([$folderId]);
    while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
        if ($child['is_folder']) {
            softDeleteFolder($pdo, $child['id'], $deletedAt, $deletedBy);
        } else {
            $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([$deletedAt, $deletedBy, $child['id']]);
        }
    }
    // 软删除文件夹本身
    $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([$deletedAt, $deletedBy, $folderId]);
}

// 递归永久删除文件夹及其子项（包括 S3 文件）
function deleteFolderPermanent($pdo, $storage, $folderId) {
    // 先删除子项
    $children = $pdo->prepare("SELECT id, is_folder, file_path FROM deliverables WHERE parent_folder_id = ?");
    $children->execute([$folderId]);
    while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
        if ($child['is_folder']) {
            deleteFolderPermanent($pdo, $storage, $child['id']);
        } else {
            // 删除 S3 文件
            if (!empty($child['file_path']) && !filter_var($child['file_path'], FILTER_VALIDATE_URL)) {
                $storage->deleteObject($child['file_path']);
            }
            $pdo->prepare("DELETE FROM deliverables WHERE id = ?")->execute([$child['id']]);
        }
    }
    // 删除文件夹本身
    $pdo->prepare("DELETE FROM deliverables WHERE id = ?")->execute([$folderId]);
}

// 获取目录树结构
function handleGetTree($pdo, $user) {
    $projectId = intval($_GET['project_id'] ?? 0);
    $fileCategory = $_GET['file_category'] ?? '';
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $sql = "SELECT d.*, u.realname as submitted_by_name FROM deliverables d
            LEFT JOIN users u ON d.submitted_by = u.id
            WHERE d.project_id = ? AND d.deleted_at IS NULL";
    $params = [$projectId];
    
    if (!empty($fileCategory)) {
        $sql .= " AND d.file_category = ?";
        $params[] = $fileCategory;
    }
    
    $sql .= " ORDER BY d.is_folder DESC, d.deliverable_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 构建树形结构
    $tree = buildTree($items, null);
    
    echo json_encode(['success' => true, 'data' => $tree], JSON_UNESCAPED_UNICODE);
}

// 递归构建树形结构
function buildTree($items, $parentId) {
    $branch = [];
    foreach ($items as $item) {
        if ($item['parent_folder_id'] == $parentId) {
            $children = buildTree($items, $item['id']);
            if ($children) {
                $item['children'] = $children;
            }
            $branch[] = $item;
        }
    }
    return $branch;
}

// 文件夹操作（创建、重命名、删除）
function handleFolder($pdo, $user, $method) {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    switch ($method) {
        case 'POST':
            // 创建文件夹
            $projectId = intval($data['project_id'] ?? 0);
            $folderName = trim($data['folder_name'] ?? '');
            $parentFolderId = !empty($data['parent_folder_id']) ? intval($data['parent_folder_id']) : null;
            $fileCategory = trim($data['file_category'] ?? 'artwork_file');
            
            if ($projectId <= 0 || empty($folderName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少必填字段'], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            $now = time();
            $stmt = $pdo->prepare("
                INSERT INTO deliverables (project_id, parent_folder_id, is_folder, deliverable_name, file_category, submitted_by, submitted_at, approval_status, create_time, update_time)
                VALUES (?, ?, 1, ?, ?, ?, ?, 'approved', ?, ?)
            ");
            $stmt->execute([$projectId, $parentFolderId, $folderName, $fileCategory, $user['id'], $now, $now, $now]);
            
            echo json_encode(['success' => true, 'message' => '文件夹创建成功', 'data' => ['id' => $pdo->lastInsertId()]], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'PUT':
            // 重命名文件夹
            $folderId = intval($data['id'] ?? 0);
            $newName = trim($data['folder_name'] ?? '');
            
            if ($folderId <= 0 || empty($newName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少必填字段'], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            $stmt = $pdo->prepare("UPDATE deliverables SET deliverable_name = ?, update_time = ? WHERE id = ? AND is_folder = 1");
            $stmt->execute([$newName, time(), $folderId]);
            
            echo json_encode(['success' => true, 'message' => '重命名成功'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'DELETE':
            $folderId = intval($data['id'] ?? $_GET['id'] ?? 0);
            if ($folderId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少文件夹ID'], JSON_UNESCAPED_UNICODE);
                return;
            }
            softDeleteFolder($pdo, $folderId, time());
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
    
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = intval($data['id'] ?? 0);
    $action = $data['approve_action'] ?? ''; // approve 或 reject
    $reason = trim($data['reject_reason'] ?? '');
    
    if ($id <= 0 || !in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    $stmt = $pdo->prepare("
        UPDATE deliverables SET approval_status = ?, approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $user['id'], $now, $action === 'reject' ? $reason : null, $now, $id]);
    
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
    $id = intval($data['id'] ?? 0);
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $stmt = $pdo->prepare("
        UPDATE deliverables SET approval_status = 'pending', approved_by = NULL, approved_at = NULL, reject_reason = NULL, update_time = ?
        WHERE id = ?
    ");
    $stmt->execute([time(), $id]);
    
    echo json_encode(['success' => true, 'message' => '已重置为待审批'], JSON_UNESCAPED_UNICODE);
}

// 批量审批
function handleBatchApprove($pdo, $user) {
    if (!isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无审批权限'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ids = $data['ids'] ?? [];
    $action = $data['approve_action'] ?? ''; // approve 或 reject
    $reason = trim($data['reject_reason'] ?? '');
    
    if (empty($ids) || !is_array($ids) || !in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $params = [$status, $user['id'], $now, $action === 'reject' ? $reason : null, $now];
    $params = array_merge($params, array_map('intval', $ids));
    
    $stmt = $pdo->prepare("
        UPDATE deliverables SET approval_status = ?, approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
        WHERE id IN ($placeholders) AND is_folder = 0
    ");
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
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
    
    $stmt = $pdo->prepare("SELECT id, deliverable_name, file_path FROM deliverables WHERE id = ? AND is_folder = 0");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 生成临时下载URL
    try {
        $storage = storage_provider();
        $filePath = $file['file_path'];

        // 兼容历史记录：如果已是 URL，直接返回
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'url' => $filePath,
                    'filename' => $file['deliverable_name'],
                    'expires_in' => null
                ]
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $url = $storage->getTemporaryUrl($filePath, 3600);
        if (!$url) {
            throw new RuntimeException('无法生成下载链接');
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'url' => $url,
                'filename' => $file['deliverable_name'],
                'expires_in' => 3600
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '获取下载链接失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

// 重命名文件
function handleRename($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $fileId = intval($data['id'] ?? 0);
    $newName = trim($data['new_name'] ?? '');
    
    if ($fileId <= 0 || empty($newName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必填参数'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查文件是否存在及权限
    $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, file_path, deliverable_name, approval_status FROM deliverables WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $role = $user['role'] ?? '';
    $isManagerRole = isAdmin($user) || in_array($role, ['manager', 'tech_manager'], true);
    $isUploader = ($file['submitted_by'] == $user['id']);
    $approvalStatus = $file['approval_status'] ?? 'pending';
    $canOperate = false;
    if ($isManagerRole) {
        $canOperate = true;
    } elseif ($isUploader && in_array($approvalStatus, ['pending', 'rejected'], true)) {
        $canOperate = true;
    }

    // 检查权限
    if (!$canOperate) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限重命名'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $newFilePath = $file['file_path']; // 默认保持不变
    
    // 如果是文件（不是文件夹）且有存储路径，需要在 S3 中重命名
    // 兼容历史记录：file_path 可能是 URL，这种情况下只更新名称，不做对象存储操作
    if (!$file['is_folder'] && !empty($file['file_path']) && !filter_var($file['file_path'], FILTER_VALIDATE_URL)) {
        $oldPath = $file['file_path'];
        $oldName = $file['deliverable_name'];
        
        // 构建新的存储路径（替换文件名部分）
        $pathInfo = pathinfo($oldPath);
        $oldExtension = $pathInfo['extension'] ?? '';
        $newExtension = pathinfo($newName, PATHINFO_EXTENSION);
        
        // 如果新名称没有扩展名，保留原扩展名
        if (empty($newExtension) && !empty($oldExtension)) {
            $newName .= '.' . $oldExtension;
        }
        
        $newFilePath = $pathInfo['dirname'] . '/' . $newName;
        
        // 只有路径真正改变时才需要在 S3 中操作
        if ($newFilePath !== $oldPath) {
            try {
                $storage = storage_provider();
                
                // 复制到新位置
                if ($storage->copyObject($oldPath, $newFilePath)) {
                    // 删除旧文件
                    $storage->deleteObject($oldPath);
                } else {
                    // 复制失败，只更新数据库名称，不更新路径
                    $newFilePath = $oldPath;
                    error_log("[Rename] S3 复制失败，仅更新数据库名称: {$oldPath} -> {$newFilePath}");
                }
            } catch (Exception $e) {
                // S3 操作失败，只更新数据库名称
                $newFilePath = $oldPath;
                error_log("[Rename] S3 操作异常: " . $e->getMessage());
            }
        }
    }
    
    // 更新数据库
    $updateStmt = $pdo->prepare("UPDATE deliverables SET deliverable_name = ?, file_path = ?, update_time = ? WHERE id = ?");
    $updateStmt->execute([$newName, $newFilePath, time(), $fileId]);
    
    echo json_encode([
        'success' => true, 
        'message' => '重命名成功',
        'data' => [
            'new_name' => $newName,
            'new_path' => $newFilePath
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// 批量删除文件
function handleBatchDelete($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    
    if (empty($ids) || !is_array($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请提供要删除的文件ID列表'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 验证ID都是整数
    $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '文件ID无效'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $deletedCount = 0;
    $errors = [];
    
    foreach ($ids as $fileId) {
        // 检查文件是否存在及权限
        $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, approval_status FROM deliverables WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            $errors[] = "文件ID {$fileId} 不存在";
            continue;
        }
        
        $role = $user['role'] ?? '';
        $isManagerRole = isAdmin($user) || in_array($role, ['manager', 'tech_manager'], true);
        $isUploader = ($file['submitted_by'] == $user['id']);
        $approvalStatus = $file['approval_status'] ?? 'pending';
        $canOperate = false;
        if ($isManagerRole) {
            $canOperate = true;
        } elseif ($isUploader && in_array($approvalStatus, ['pending', 'rejected'], true)) {
            $canOperate = true;
        }

        // 检查权限
        if (!$canOperate) {
            $errors[] = "文件ID {$fileId} 无权限删除";
            continue;
        }
        
        // 如果是文件夹，递归软删除
        if ($file['is_folder']) {
            softDeleteFolder($pdo, $fileId, time());
        } else {
            $deleteStmt = $pdo->prepare("UPDATE deliverables SET deleted_at = ? WHERE id = ?");
            $deleteStmt->execute([time(), $fileId]);
        }
        $deletedCount++;
    }
    
    if ($deletedCount === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '没有文件被删除',
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "已删除 {$deletedCount} 个文件",
        'deleted_count' => $deletedCount,
        'total_requested' => count($ids),
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
}

// 批量上传文件（文件夹上传）
function handleBatchUpload($pdo, $user, $projectId, $fileCategory, $parentFolderId, $visibilityLevel, $uploadMode, $folderRoot, $folderPaths, $filePaths) {
    // [RC_DEBUG] 批量上传日志
    error_log("[RC_DEBUG] handleBatchUpload: projectId=$projectId, fileCategory=$fileCategory, uploadMode=$uploadMode, folderRoot=$folderRoot");
    error_log("[RC_DEBUG] files count: " . count($_FILES['files']['name']) . ", folderPaths count: " . count($folderPaths));
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少项目ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 获取项目与客户信息用于构建存储路径
    $projectRow = Db::queryOne(
        "SELECT p.project_name, p.project_code, c.group_code, c.group_name, c.name as customer_name
         FROM projects p
         LEFT JOIN customers c ON p.customer_id = c.id
         WHERE p.id = ? AND p.deleted_at IS NULL",
        [$projectId]
    );
    if (!$projectRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '项目不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    // 优先使用 group_code，其次用 group_name，最后用客户名称
    $groupCode = $projectRow['group_code'] ?: $projectRow['group_name'] ?: $projectRow['customer_name'] ?: ('P' . $projectId);
    $groupCode = preg_replace('/[\/\\\\:*?"<>|]/', '_', $groupCode);
    $projectName = $projectRow['project_name'] ?: $projectRow['project_code'] ?: ('项目' . $projectId);
    $projectName = preg_replace('/[\/\\:*?"<>|]/', '_', $projectName);

    switch ($fileCategory) {
        case 'customer_file':
            $categoryDir = '客户文件';
            break;
        case 'model_file':
            $categoryDir = '模型文件';
            break;
        default:
            $categoryDir = '作品文件';
            break;
    }
    
    $storage = storage_provider();
    $now = time();
    $approvalStatus = ($fileCategory === 'artwork_file') ? 'pending' : 'approved';
    
    $uploadedCount = 0;
    $errors = [];
    $uploadedFiles = [];
    
    // 创建文件夹映射（根据folderPaths创建文件夹记录）
    $folderIdMap = []; // path => folder_id
    if ($uploadMode === 'folder' && !empty($folderPaths)) {
        foreach ($folderPaths as $folderPath) {
            $folderId = ensureFolderExists($pdo, $projectId, $fileCategory, $folderPath, $parentFolderId, $user['id']);
            if ($folderId) {
                $folderIdMap[$folderPath] = $folderId;
            }
        }
    }
    
    // 处理每个文件
    $files = $_FILES['files'];
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "文件 {$files['name'][$i]} 上传失败: 错误代码 {$files['error'][$i]}";
            continue;
        }
        
        $originalName = $files['name'][$i];
        $tmpPath = $files['tmp_name'][$i];
        $fileSize = $files['size'][$i];
        
        // 获取相对路径
        $relativePath = isset($filePaths[$i]) ? $filePaths[$i] : $originalName;
        
        // 确定文件所属文件夹
        $targetFolderId = $parentFolderId;
        $folderPath = '';
        if ($uploadMode === 'folder' && strpos($relativePath, '/') !== false) {
            $pathParts = explode('/', $relativePath);
            array_pop($pathParts); // 移除文件名
            $folderPath = implode('/', $pathParts);
            
            // 查找或创建对应文件夹
            if (isset($folderIdMap[$folderPath])) {
                $targetFolderId = $folderIdMap[$folderPath];
            } else {
                $targetFolderId = ensureFolderExists($pdo, $projectId, $fileCategory, $folderPath, $parentFolderId, $user['id']);
                if ($targetFolderId) {
                    $folderIdMap[$folderPath] = $targetFolderId;
                }
            }
        }
        
        // 构建存储路径（项目级统一走 groups/）
        $safeOriginalName = preg_replace('/[\/\\:*?"<>|]/', '_', $originalName);
        $storageKey = "groups/{$groupCode}/{$projectName}/{$categoryDir}";
        if (!empty($folderPath)) {
            $storageKey .= "/{$folderPath}";
        }
        $storageKey .= "/{$safeOriginalName}";
        
        try {
            $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
            $storage->putObject($storageKey, $tmpPath, ['mime_type' => $mimeType]);
            
            // 插入数据库
            $stmt = $pdo->prepare("
                INSERT INTO deliverables (
                    project_id, deliverable_name, deliverable_type, file_category, file_path, file_size,
                    visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $deliverableType = getDeliverableType($extension);
            
            $stmt->execute([
                $projectId, $originalName, $deliverableType, $fileCategory, $storageKey, $fileSize,
                $visibilityLevel, $approvalStatus, $user['id'], $now, $now, $now, $targetFolderId ?: null
            ]);
            
            $uploadedCount++;
            $uploadedFiles[] = [
                'id' => $pdo->lastInsertId(),
                'name' => $originalName,
                'path' => $storageKey,
            ];
        } catch (Exception $e) {
            $errors[] = "文件 {$originalName} 上传失败: " . $e->getMessage();
        }
    }
    
    if ($uploadedCount === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '没有文件上传成功',
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "成功上传 {$uploadedCount} 个文件",
        'uploaded_count' => $uploadedCount,
        'files' => $uploadedFiles,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
}

// 确保文件夹存在，返回文件夹ID
function ensureFolderExists($pdo, $projectId, $fileCategory, $folderPath, $baseParentId, $userId) {
    $pathParts = explode('/', $folderPath);
    $currentParentId = $baseParentId;
    
    foreach ($pathParts as $folderName) {
        if (empty(trim($folderName))) continue;
        
        // 检查文件夹是否已存在
        $checkStmt = $pdo->prepare("
            SELECT id FROM deliverables 
            WHERE project_id = ? AND file_category = ? AND is_folder = 1 
            AND deliverable_name = ? AND (parent_folder_id = ? OR (parent_folder_id IS NULL AND ? = 0))
        ");
        $parentVal = $currentParentId ?: 0;
        $checkStmt->execute([$projectId, $fileCategory, $folderName, $parentVal, $parentVal]);
        $existingFolder = $checkStmt->fetchColumn();
        
        if ($existingFolder) {
            $currentParentId = $existingFolder;
        } else {
            // 创建文件夹
            $now = time();
            $insertStmt = $pdo->prepare("
                INSERT INTO deliverables (
                    project_id, deliverable_name, file_category, is_folder,
                    submitted_by, submitted_at, create_time, update_time, parent_folder_id,
                    approval_status
                ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 'approved')
            ");
            $insertStmt->execute([
                $projectId, $folderName, $fileCategory,
                $userId, $now, $now, $now, $currentParentId ?: null
            ]);
            $currentParentId = $pdo->lastInsertId();
        }
    }
    
    return $currentParentId;
}

// 根据扩展名获取交付物类型
function getDeliverableType($extension) {
    $typeMap = [
        'jpg' => '图片', 'jpeg' => '图片', 'png' => '图片', 'gif' => '图片', 'webp' => '图片', 'svg' => '图片',
        'psd' => '设计稿', 'ai' => '设计稿', 'sketch' => '设计稿', 'fig' => '设计稿', 'xd' => '设计稿',
        'pdf' => '文档', 'doc' => '文档', 'docx' => '文档', 'xls' => '文档', 'xlsx' => '文档', 'ppt' => '文档', 'pptx' => '文档',
        'mp4' => '视频', 'mov' => '视频', 'avi' => '视频', 'wmv' => '视频',
        'mp3' => '音频', 'wav' => '音频', 'aac' => '音频',
        'zip' => '压缩包', 'rar' => '压缩包', '7z' => '压缩包',
        'obj' => '3D模型', 'fbx' => '3D模型', 'stl' => '3D模型', 'blend' => '3D模型',
    ];
    return $typeMap[$extension] ?? '其他';
}

// 获取回收站列表
function handleTrash($pdo, $user) {
    $projectId = intval($_GET['project_id'] ?? 0);
    $fileCategory = $_GET['file_category'] ?? '';
    
    $sql = "SELECT d.*, u.realname as submitted_by_name
            FROM deliverables d
            LEFT JOIN users u ON d.submitted_by = u.id
            WHERE d.deleted_at IS NOT NULL";
    $params = [];
    
    if ($projectId > 0) {
        $sql .= " AND d.project_id = ?";
        $params[] = $projectId;
    }
    if (!empty($fileCategory)) {
        $sql .= " AND d.file_category = ?";
        $params[] = $fileCategory;
    }
    
    // 非管理员只能看自己删除的
    if (!isAdmin($user)) {
        $sql .= " AND d.submitted_by = ?";
        $params[] = $user['id'];
    }
    
    $sql .= " ORDER BY d.deleted_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $items], JSON_UNESCAPED_UNICODE);
}

// 恢复已删除的文件
function handleRestore($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    $fileId = intval($data['id'] ?? 0);
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 检查文件是否存在
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
    
    // 检查权限
    if ($file['submitted_by'] != $user['id'] && !isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限恢复'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 恢复文件
    if ($file['is_folder']) {
        restoreFolder($pdo, $fileId);
    } else {
        $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?");
        $stmt->execute([$fileId]);
    }
    
    echo json_encode(['success' => true, 'message' => '恢复成功'], JSON_UNESCAPED_UNICODE);
}

// 递归恢复文件夹及其子项
function restoreFolder($pdo, $folderId) {
    // 先恢复子项
    $children = $pdo->prepare("SELECT id, is_folder FROM deliverables WHERE parent_folder_id = ? AND deleted_at IS NOT NULL");
    $children->execute([$folderId]);
    while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
        if ($child['is_folder']) {
            restoreFolder($pdo, $child['id']);
        } else {
            $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$child['id']]);
        }
    }
    // 恢复文件夹本身
    $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$folderId]);
}
