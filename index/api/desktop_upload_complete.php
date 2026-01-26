<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 完成分片上传
 * POST /api/desktop_upload_complete.php
 */

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/MultipartUploadService.php';

$user = desktop_auth_require();

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = $input['upload_id'] ?? '';
$storageKey = $input['storage_key'] ?? '';
$parts = $input['parts'] ?? [];

$projectId = (int)($input['project_id'] ?? 0);
$assetType = $input['asset_type'] ?? '';
$filename = $input['filename'] ?? '';
$filesize = (int)($input['filesize'] ?? 0);

// 调试日志
error_log("[DESKTOP_UPLOAD] input: projectId=$projectId, assetType=$assetType, filename=$filename, filesize=$filesize");

// 从 storage_key 解析信息（兼容旧版本桌面端）
// 格式: groups/{group_code}/{project_name}/{category}/{filename}
if ($storageKey && ($projectId <= 0 || !$assetType || !$filename)) {
    $keyParts = explode('/', $storageKey);
    if (count($keyParts) >= 5 && $keyParts[0] === 'groups') {
        $groupCode = $keyParts[1] ?? '';
        $projectName = $keyParts[2] ?? '';
        $categoryFolder = $keyParts[3] ?? '';
        $parsedFilename = $keyParts[count($keyParts) - 1] ?? '';
        
        // 解析文件分类
        if (!$assetType) {
            if ($categoryFolder === '客户文件') {
                $assetType = 'customer';
            } elseif ($categoryFolder === '模型文件') {
                $assetType = 'models';
            } else {
                $assetType = 'works';
            }
        }
        
        // 解析文件名
        if (!$filename) {
            $filename = $parsedFilename;
        }
        
        // 通过 group_code 查找 project_id
        if ($projectId <= 0 && $groupCode) {
            try {
                $pdo = Db::pdo();
                // 先通过 group_code 查找客户
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE group_code = ? LIMIT 1');
                $stmt->execute([$groupCode]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    // 通过客户ID和项目名查找项目
                    $stmt = $pdo->prepare('SELECT id FROM projects WHERE customer_id = ? AND project_name = ? AND deleted_at IS NULL LIMIT 1');
                    $stmt->execute([$customer['id'], $projectName]);
                    $project = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($project) {
                        $projectId = (int)$project['id'];
                    }
                }
            } catch (Exception $e) {
                // 忽略查询错误，继续使用默认值
            }
        }
    }
}

// [APPROVAL_DEBUG] 调试日志
$debugLog = "[APPROVAL_DEBUG] " . date('Y-m-d H:i:s') . " PARSED: assetType='$assetType', projectId=$projectId, filename='$filename'\n";
file_put_contents(__DIR__ . '/../approval_debug.log', $debugLog, FILE_APPEND);

if (!$uploadId || !$storageKey || empty($parts)) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $startTime = microtime(true);
    $uploadService = new MultipartUploadService();
    $result = $uploadService->complete($storageKey, $uploadId, $parts);
    $completeTime = microtime(true) - $startTime;
    error_log("[DESKTOP_UPLOAD] complete() took {$completeTime}s for $storageKey");

    // 尝试落库到 deliverables（便于项目详情页 CRUD 管理）
    $deliverableId = 0;
    try {
        if ($projectId > 0) {
            $fileCategory = 'artwork_file';
            if ($assetType === 'customer') {
                $fileCategory = 'customer_file';
            } elseif ($assetType === 'models') {
                $fileCategory = 'model_file';
            } elseif ($assetType === 'works') {
                $fileCategory = 'artwork_file';
            }

            $approvalStatus = $fileCategory === 'artwork_file' ? 'pending' : 'approved';
            // [APPROVAL_DEBUG] 日志
            error_log("[APPROVAL_DEBUG] fileCategory='$fileCategory', approvalStatus='$approvalStatus'");
            // 确保只使用文件名，去除任何路径前缀
            $rawFilename = $filename ?: basename($storageKey);
            $deliverableName = basename(str_replace('\\', '/', $rawFilename));
            $now = time();

            $pdo = Db::pdo();

            // 避免重复插入
            $stmt = $pdo->prepare('SELECT id FROM deliverables WHERE project_id = ? AND file_path = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$projectId, $storageKey]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && isset($existing['id'])) {
                $deliverableId = (int)$existing['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO deliverables (project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $projectId,
                    $deliverableName,
                    'desktop_upload',
                    $fileCategory,
                    $storageKey,
                    $filesize > 0 ? $filesize : null,
                    'client',
                    $approvalStatus,
                    $user['id'],
                    $now,
                    $now,
                    $now,
                    0,
                ]);
                $deliverableId = (int)$pdo->lastInsertId();
            }
        }
    } catch (Exception $e) {
        error_log('[API] desktop_upload_complete 落库 deliverables 失败: ' . $e->getMessage());
        $deliverableId = 0;
    }

    // 写入上传日志到 file_sync_logs
    try {
        if ($projectId > 0 && $filename) {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO file_sync_logs (user_id, project_id, filename, operation, status, size, folder_type, create_time)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $folderType = $assetType === 'customer' ? '客户文件' : ($assetType === 'models' ? '模型文件' : '作品文件');
            $stmt->execute([
                $user['id'],
                $projectId,
                basename($filename),
                'upload',
                'success',
                $filesize > 0 ? $filesize : 0,
                $folderType,
                time(),
            ]);
        }
    } catch (Exception $e) {
        error_log('[API] desktop_upload_complete 写入日志失败: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'etag' => $result['etag'] ?? '',
            'location' => $result['location'] ?? '',
            'storage_key' => $storageKey,
            'deliverable_id' => $deliverableId,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_upload_complete 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
