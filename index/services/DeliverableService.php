<?php
/**
 * 交付物业务逻辑服务层
 *
 * 封装交付物相关的数据库查询、S3操作和业务规则。
 * API文件负责路由、参数解析、权限校验和返回响应；本服务负责具体业务逻辑。
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

class DeliverableService
{
    // 文件分类目录映射
    const CATEGORY_DIR_MAP = [
        'customer_file' => '客户文件',
        'model_file'    => '模型文件',
        'artwork_file'  => '作品文件',
    ];

    // 扩展名到交付物类型映射
    const EXTENSION_TYPE_MAP = [
        'jpg' => '图片', 'jpeg' => '图片', 'png' => '图片', 'gif' => '图片', 'webp' => '图片', 'svg' => '图片',
        'psd' => '设计稿', 'ai' => '设计稿', 'sketch' => '设计稿', 'fig' => '设计稿', 'xd' => '设计稿',
        'pdf' => '文档', 'doc' => '文档', 'docx' => '文档', 'xls' => '文档', 'xlsx' => '文档',
        'ppt' => '文档', 'pptx' => '文档',
        'mp4' => '视频', 'mov' => '视频', 'avi' => '视频', 'wmv' => '视频',
        'mp3' => '音频', 'wav' => '音频', 'aac' => '音频',
        'zip' => '压缩包', 'rar' => '压缩包', '7z' => '压缩包',
        'obj' => '3D模型', 'fbx' => '3D模型', 'stl' => '3D模型', 'blend' => '3D模型',
    ];

    // -------------------------------------------------------------------------
    // 查询
    // -------------------------------------------------------------------------

    /**
     * 查询交付物列表（支持多种过滤条件和分组方式）
     */
    public static function listDeliverables(PDO $pdo, array $filters): array
    {
        $projectId      = intval($filters['project_id'] ?? 0);
        $approvalStatus = $filters['approval_status'] ?? '';
        $fileCategory   = $filters['file_category'] ?? '';
        $groupBy        = $filters['group_by'] ?? '';
        $userId         = intval($filters['user_id'] ?? 0);
        $parentFolderId = array_key_exists('parent_folder_id', $filters) ? $filters['parent_folder_id'] : null;

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

        if ($projectId > 0) {
            $sql .= " AND d.project_id = ?";
            $params[] = $projectId;
        }
        if (!empty($approvalStatus)) {
            $sql .= " AND d.approval_status = ?";
            $params[] = $approvalStatus;
        }
        if (!empty($fileCategory)) {
            $sql .= " AND d.file_category = ?";
            $params[] = $fileCategory;
        }
        if ($userId > 0) {
            $sql .= " AND d.submitted_by = ?";
            $params[] = $userId;
        }
        if ($parentFolderId !== null) {
            if ($parentFolderId === '' || $parentFolderId === '0' || $parentFolderId === 'null' || $parentFolderId === 0) {
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

        if ($groupBy !== 'hierarchy') {
            return ['grouped' => false, 'data' => $deliverables];
        }

        // 按客户/项目层级分组
        $grouped = [];
        foreach ($deliverables as $d) {
            $cid  = $d['customer_id'] ?? 0;
            $cname = $d['customer_name'] ?? '未知客户';
            $pid  = $d['project_id'];
            $pname = $d['project_name'] ?? '未知项目';

            if (!isset($grouped[$cid])) {
                $grouped[$cid] = ['customer_id' => $cid, 'customer_name' => $cname, 'projects' => []];
            }
            if (!isset($grouped[$cid]['projects'][$pid])) {
                $grouped[$cid]['projects'][$pid] = [
                    'project_id'   => $pid,
                    'project_name' => $pname,
                    'project_code' => $d['project_code'] ?? '',
                    'deliverables' => [],
                ];
            }
            $grouped[$cid]['projects'][$pid]['deliverables'][] = $d;
        }

        $result = [];
        foreach ($grouped as $customer) {
            $customer['projects'] = array_values($customer['projects']);
            $result[] = $customer;
        }

        return ['grouped' => true, 'data' => $result];
    }

    /**
     * 获取目录树（扁平列表转树形结构）
     */
    public static function getTree(PDO $pdo, int $projectId, string $fileCategory): array
    {
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

        return self::buildTree($items, null);
    }

    /**
     * 获取回收站列表
     */
    public static function listTrash(PDO $pdo, int $projectId, string $fileCategory, bool $adminView, int $userId): array
    {
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
        if (!$adminView) {
            $sql .= " AND d.submitted_by = ?";
            $params[] = $userId;
        }
        $sql .= " ORDER BY d.deleted_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // 上传
    // -------------------------------------------------------------------------

    /**
     * 获取项目存储路径信息（groupCode、projectName）
     * 返回 null 表示项目不存在
     */
    public static function getProjectStorageInfo(int $projectId): ?array
    {
        $row = Db::queryOne(
            "SELECT p.project_name, p.project_code, c.group_code, c.group_name, c.name as customer_name
             FROM projects p
             LEFT JOIN customers c ON p.customer_id = c.id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$projectId]
        );
        if (!$row) {
            return null;
        }

        $groupCode   = $row['group_code'] ?: $row['group_name'] ?: $row['customer_name'] ?: ('P' . $projectId);
        $groupCode   = preg_replace('/[\/\\\\:*?"<>|]/', '_', $groupCode);
        $projectName = $row['project_name'] ?: $row['project_code'] ?: ('项目' . $projectId);
        $projectName = preg_replace('/[\/\\:*?"<>|]/', '_', $projectName);

        return ['group_code' => $groupCode, 'project_name' => $projectName];
    }

    /**
     * 构建单文件的 S3 存储键
     */
    public static function buildStorageKey(string $groupCode, string $projectName, string $fileCategory, string $fileName, string $folderPath = ''): string
    {
        $categoryDir = self::CATEGORY_DIR_MAP[$fileCategory] ?? '作品文件';
        $safeFileName = preg_replace('/[\/\\:*?"<>|]/', '_', $fileName);

        $key = "groups/{$groupCode}/{$projectName}/{$categoryDir}";
        if (!empty($folderPath)) {
            $key .= "/{$folderPath}";
        }
        $key .= "/{$safeFileName}";

        return $key;
    }

    /**
     * 获取文件夹完整路径字符串（用于构建 S3 存储路径）
     */
    public static function getFolderPath(PDO $pdo, int $folderId): string
    {
        $path     = [];
        $currentId = $folderId;
        $maxDepth  = 20;

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

    /**
     * 插入交付物记录并写入时间线事件
     */
    public static function insertDeliverable(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO deliverables (
                project_id, deliverable_name, deliverable_type, file_category, file_path, file_size, file_hash,
                visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['project_id'],
            $data['deliverable_name'],
            $data['deliverable_type'],
            $data['file_category'],
            $data['file_path'],
            $data['file_size'],
            $data['file_hash'] ?? null,
            $data['visibility_level'],
            $data['approval_status'],
            $data['submitted_by'],
            $data['now'],
            $data['now'],
            $data['now'],
            $data['parent_folder_id'] ?: null,
        ]);

        $deliverableId = (int)$pdo->lastInsertId();

        // 写入时间线
        $tlStmt = $pdo->prepare("
            INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $tlStmt->execute([
            'deliverable',
            $deliverableId,
            '提交交付物',
            $data['submitted_by'],
            json_encode(['deliverable_name' => $data['deliverable_name'], 'type' => $data['deliverable_type']]),
            $data['now'],
        ]);

        return $deliverableId;
    }

    /**
     * 决定文件的 approval_status（artwork_file 需要审批，其他直接通过）
     */
    public static function getInitialApprovalStatus(string $fileCategory): string
    {
        return $fileCategory === 'artwork_file' ? 'pending' : 'approved';
    }

    /**
     * 根据扩展名返回交付物类型中文名
     */
    public static function getDeliverableType(string $extension): string
    {
        return self::EXTENSION_TYPE_MAP[$extension] ?? '其他';
    }

    /**
     * 确保文件夹层级存在，返回最深层文件夹 ID（不存在则创建）
     */
    public static function ensureFolderExists(PDO $pdo, int $projectId, string $fileCategory, string $folderPath, int $baseParentId, int $userId): int
    {
        $pathParts       = explode('/', $folderPath);
        $currentParentId = $baseParentId;
        $now             = time();

        foreach ($pathParts as $folderName) {
            $folderName = trim($folderName);
            if (empty($folderName)) continue;

            $parentVal = $currentParentId ?: 0;
            $checkStmt = $pdo->prepare("
                SELECT id FROM deliverables
                WHERE project_id = ? AND file_category = ? AND is_folder = 1
                AND deliverable_name = ? AND (parent_folder_id = ? OR (parent_folder_id IS NULL AND ? = 0))
            ");
            $checkStmt->execute([$projectId, $fileCategory, $folderName, $parentVal, $parentVal]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $currentParentId = (int)$existingId;
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO deliverables (
                        project_id, deliverable_name, file_category, is_folder,
                        submitted_by, submitted_at, create_time, update_time, parent_folder_id,
                        approval_status
                    ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 'approved')
                ");
                $insertStmt->execute([
                    $projectId, $folderName, $fileCategory,
                    $userId, $now, $now, $now, $currentParentId ?: null,
                ]);
                $currentParentId = (int)$pdo->lastInsertId();
            }
        }

        return $currentParentId;
    }

    /**
     * 处理批量文件上传（文件夹上传），直接从 $_FILES['files'] 读取
     * 返回 ['uploaded_count' => int, 'files' => array, 'errors' => array]
     */
    public static function batchUpload(
        PDO $pdo,
        array $user,
        int $projectId,
        string $fileCategory,
        int $parentFolderId,
        string $visibilityLevel,
        string $uploadMode,
        array $folderPaths,
        array $filePaths
    ): array {
        $projectInfo = self::getProjectStorageInfo($projectId);
        if (!$projectInfo) {
            return ['error' => '项目不存在'];
        }

        $groupCode    = $projectInfo['group_code'];
        $projectName  = $projectInfo['project_name'];
        $categoryDir  = self::CATEGORY_DIR_MAP[$fileCategory] ?? '作品文件';
        $storage      = storage_provider();
        $now          = time();
        $approvalStatus = self::getInitialApprovalStatus($fileCategory);

        $uploadedCount = 0;
        $errors        = [];
        $uploadedFiles = [];
        $folderIdMap   = [];

        // 预建文件夹映射
        if ($uploadMode === 'folder' && !empty($folderPaths)) {
            foreach ($folderPaths as $fp) {
                $fid = self::ensureFolderExists($pdo, $projectId, $fileCategory, $fp, $parentFolderId, $user['id']);
                if ($fid) {
                    $folderIdMap[$fp] = $fid;
                }
            }
        }

        $files     = $_FILES['files'];
        $fileCount = count($files['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "文件 {$files['name'][$i]} 上传失败: 错误代码 {$files['error'][$i]}";
                continue;
            }

            $originalName = $files['name'][$i];
            $tmpPath      = $files['tmp_name'][$i];
            $fileSize     = $files['size'][$i];
            $relativePath = $filePaths[$i] ?? $originalName;

            // 确定目标文件夹
            $targetFolderId = $parentFolderId;
            $folderPath     = '';
            if ($uploadMode === 'folder' && strpos($relativePath, '/') !== false) {
                $pathParts = explode('/', $relativePath);
                array_pop($pathParts);
                $folderPath = implode('/', $pathParts);

                if (isset($folderIdMap[$folderPath])) {
                    $targetFolderId = $folderIdMap[$folderPath];
                } else {
                    $targetFolderId = self::ensureFolderExists($pdo, $projectId, $fileCategory, $folderPath, $parentFolderId, $user['id']);
                    if ($targetFolderId) {
                        $folderIdMap[$folderPath] = $targetFolderId;
                    }
                }
            }

            $safeFileName = preg_replace('/[\/\\:*?"<>|]/', '_', $originalName);
            $storageKey   = "groups/{$groupCode}/{$projectName}/{$categoryDir}";
            if (!empty($folderPath)) {
                $storageKey .= "/{$folderPath}";
            }
            $storageKey .= "/{$safeFileName}";

            try {
                $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
                $storage->putObject($storageKey, $tmpPath, ['mime_type' => $mimeType]);

                $extension       = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $deliverableType = self::getDeliverableType($extension);

                $stmt = $pdo->prepare("
                    INSERT INTO deliverables (
                        project_id, deliverable_name, deliverable_type, file_category, file_path, file_size,
                        visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projectId, $originalName, $deliverableType, $fileCategory, $storageKey, $fileSize,
                    $visibilityLevel, $approvalStatus, $user['id'], $now, $now, $now, $targetFolderId ?: null,
                ]);

                $uploadedCount++;
                $uploadedFiles[] = [
                    'id'   => (int)$pdo->lastInsertId(),
                    'name' => $originalName,
                    'path' => $storageKey,
                ];
            } catch (Exception $e) {
                $errors[] = "文件 {$originalName} 上传失败: " . $e->getMessage();
            }
        }

        return [
            'uploaded_count' => $uploadedCount,
            'files'          => $uploadedFiles,
            'errors'         => $errors,
        ];
    }

    // -------------------------------------------------------------------------
    // 审批
    // -------------------------------------------------------------------------

    /**
     * 单个文件审批（approve / reject）
     */
    public static function approveDeliverable(PDO $pdo, int $id, string $action, string $reason, int $approverId): void
    {
        $now    = time();
        $status = $action === 'approve' ? 'approved' : 'rejected';

        $stmt = $pdo->prepare("
            UPDATE deliverables SET approval_status = ?, approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $approverId, $now, $action === 'reject' ? $reason : null, $now, $id]);
    }

    /**
     * 批量审批
     * 返回受影响行数
     */
    public static function batchApprove(PDO $pdo, array $ids, string $action, string $reason, int $approverId): int
    {
        $now          = time();
        $status       = $action === 'approve' ? 'approved' : 'rejected';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $params = [$status, $approverId, $now, $action === 'reject' ? $reason : null, $now];
        $params = array_merge($params, array_map('intval', $ids));

        $stmt = $pdo->prepare("
            UPDATE deliverables SET approval_status = ?, approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
            WHERE id IN ($placeholders) AND is_folder = 0
        ");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 重置审批状态为 pending
     */
    public static function resetApproval(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("
            UPDATE deliverables SET approval_status = 'pending', approved_by = NULL, approved_at = NULL, reject_reason = NULL, update_time = ?
            WHERE id = ?
        ");
        $stmt->execute([time(), $id]);
    }

    // -------------------------------------------------------------------------
    // 下载
    // -------------------------------------------------------------------------

    /**
     * 生成临时下载 URL，返回数组；文件不存在返回 null
     */
    public static function getDownloadUrl(PDO $pdo, int $fileId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, deliverable_name, file_path FROM deliverables WHERE id = ? AND is_folder = 0");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            return null;
        }

        $filePath = $file['file_path'];

        // 历史记录：已是完整 URL
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            return ['url' => $filePath, 'filename' => $file['deliverable_name'], 'expires_in' => null];
        }

        $storage = storage_provider();
        $url     = $storage->getTemporaryUrl($filePath, 3600);
        if (!$url) {
            throw new RuntimeException('无法生成下载链接');
        }

        return ['url' => $url, 'filename' => $file['deliverable_name'], 'expires_in' => 3600];
    }

    // -------------------------------------------------------------------------
    // 重命名
    // -------------------------------------------------------------------------

    /**
     * 重命名文件或文件夹（文件会同步 S3 路径）
     * 返回 ['new_name' => string, 'new_path' => string]；记录不存在返回 null
     */
    public static function renameDeliverable(PDO $pdo, int $fileId, string $newName): ?array
    {
        $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, file_path, deliverable_name, approval_status FROM deliverables WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            return null;
        }

        $newFilePath = $file['file_path'];

        // 文件（非文件夹）且有对象存储路径时，S3 中重命名
        if (!$file['is_folder'] && !empty($file['file_path']) && !filter_var($file['file_path'], FILTER_VALIDATE_URL)) {
            $oldPath      = $file['file_path'];
            $pathInfo     = pathinfo($oldPath);
            $oldExtension = $pathInfo['extension'] ?? '';
            $newExtension = pathinfo($newName, PATHINFO_EXTENSION);

            if (empty($newExtension) && !empty($oldExtension)) {
                $newName .= '.' . $oldExtension;
            }

            $newFilePath = $pathInfo['dirname'] . '/' . $newName;

            if ($newFilePath !== $oldPath) {
                try {
                    $storage = storage_provider();
                    if ($storage->copyObject($oldPath, $newFilePath)) {
                        $storage->deleteObject($oldPath);
                    } else {
                        $newFilePath = $oldPath;
                        error_log("[Rename] S3 复制失败，仅更新数据库名称: {$oldPath} -> {$newFilePath}");
                    }
                } catch (Exception $e) {
                    $newFilePath = $oldPath;
                    error_log("[Rename] S3 操作异常: " . $e->getMessage());
                }
            }
        }

        $updateStmt = $pdo->prepare("UPDATE deliverables SET deliverable_name = ?, file_path = ?, update_time = ? WHERE id = ?");
        $updateStmt->execute([$newName, $newFilePath, time(), $fileId]);

        return ['new_name' => $newName, 'new_path' => $newFilePath];
    }

    // -------------------------------------------------------------------------
    // 删除 / 恢复
    // -------------------------------------------------------------------------

    /**
     * 软删除单条记录（文件或文件夹）
     */
    public static function softDelete(PDO $pdo, int $id, int $deletedBy): void
    {
        $stmt = $pdo->prepare("SELECT is_folder FROM deliverables WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $now = time();
        if ($row['is_folder']) {
            self::softDeleteFolder($pdo, $id, $now, $deletedBy);
        } else {
            $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([$now, $deletedBy, $id]);
        }
    }

    /**
     * 永久删除（从回收站彻底清除，含 S3 文件）
     */
    public static function permanentDelete(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("SELECT is_folder, file_path, deleted_at FROM deliverables WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $storage = storage_provider();

        if ($row['is_folder']) {
            self::deleteFolderPermanent($pdo, $storage, $id);
        } else {
            if (!empty($row['file_path']) && !filter_var($row['file_path'], FILTER_VALIDATE_URL)) {
                $storage->deleteObject($row['file_path']);
            }
            $pdo->prepare("DELETE FROM deliverables WHERE id = ?")->execute([$id]);
        }
    }

    /**
     * 批量软删除
     * 返回 ['deleted_count' => int, 'errors' => array]
     */
    public static function batchSoftDelete(PDO $pdo, array $user, array $ids): array
    {
        $deletedCount = 0;
        $errors       = [];
        $now          = time();

        foreach ($ids as $fileId) {
            $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, approval_status FROM deliverables WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$file) {
                $errors[] = "文件ID {$fileId} 不存在";
                continue;
            }

            if (!self::canOperateFile($user, $file)) {
                $errors[] = "文件ID {$fileId} 无权限删除";
                continue;
            }

            if ($file['is_folder']) {
                self::softDeleteFolder($pdo, $fileId, $now, $user['id']);
            } else {
                $pdo->prepare("UPDATE deliverables SET deleted_at = ? WHERE id = ?")->execute([$now, $fileId]);
            }
            $deletedCount++;
        }

        return ['deleted_count' => $deletedCount, 'errors' => $errors];
    }

    /**
     * 恢复已删除的文件或文件夹
     * 返回记录数组；不存在则返回 null
     */
    public static function restore(PDO $pdo, int $fileId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, submitted_by, is_folder, deleted_at FROM deliverables WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) return null;

        if ($file['is_folder']) {
            self::restoreFolder($pdo, $fileId);
        } else {
            $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$fileId]);
        }

        return $file;
    }

    // -------------------------------------------------------------------------
    // 文件夹操作
    // -------------------------------------------------------------------------

    /**
     * 创建文件夹，返回新 ID
     */
    public static function createFolder(PDO $pdo, int $projectId, string $folderName, ?int $parentFolderId, string $fileCategory, int $userId): int
    {
        $now  = time();
        $stmt = $pdo->prepare("
            INSERT INTO deliverables (project_id, parent_folder_id, is_folder, deliverable_name, file_category, submitted_by, submitted_at, approval_status, create_time, update_time)
            VALUES (?, ?, 1, ?, ?, ?, ?, 'approved', ?, ?)
        ");
        $stmt->execute([$projectId, $parentFolderId, $folderName, $fileCategory, $userId, $now, $now, $now]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * 重命名文件夹
     */
    public static function renameFolder(PDO $pdo, int $folderId, string $newName): void
    {
        $stmt = $pdo->prepare("UPDATE deliverables SET deliverable_name = ?, update_time = ? WHERE id = ? AND is_folder = 1");
        $stmt->execute([$newName, time(), $folderId]);
    }

    // -------------------------------------------------------------------------
    // 权限辅助
    // -------------------------------------------------------------------------

    /**
     * 判断用户是否有权操作（删除/重命名）某文件
     */
    public static function canOperateFile(array $user, array $file): bool
    {
        $role          = $user['role'] ?? '';
        $isManagerRole = self::isAdmin($user) || in_array($role, ['manager', 'tech_manager'], true);
        if ($isManagerRole) return true;

        $isUploader      = ($file['submitted_by'] == $user['id']);
        $approvalStatus  = $file['approval_status'] ?? 'pending';
        return $isUploader && in_array($approvalStatus, ['pending', 'rejected'], true);
    }

    /**
     * 判断是否管理员（复用 isAdmin 全局函数）
     */
    public static function isAdmin(array $user): bool
    {
        return function_exists('isAdmin') ? isAdmin($user) : (($user['role'] ?? '') === 'admin');
    }

    // -------------------------------------------------------------------------
    // 内部递归辅助
    // -------------------------------------------------------------------------

    private static function buildTree(array $items, $parentId): array
    {
        $branch = [];
        foreach ($items as $item) {
            if ($item['parent_folder_id'] == $parentId) {
                $children = self::buildTree($items, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $branch[] = $item;
            }
        }
        return $branch;
    }

    private static function softDeleteFolder(PDO $pdo, int $folderId, int $deletedAt, int $deletedBy = 0): void
    {
        $children = $pdo->prepare("SELECT id, is_folder FROM deliverables WHERE parent_folder_id = ? AND deleted_at IS NULL");
        $children->execute([$folderId]);
        while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
            if ($child['is_folder']) {
                self::softDeleteFolder($pdo, $child['id'], $deletedAt, $deletedBy);
            } else {
                $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([$deletedAt, $deletedBy, $child['id']]);
            }
        }
        $pdo->prepare("UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([$deletedAt, $deletedBy, $folderId]);
    }

    private static function deleteFolderPermanent(PDO $pdo, $storage, int $folderId): void
    {
        $children = $pdo->prepare("SELECT id, is_folder, file_path FROM deliverables WHERE parent_folder_id = ?");
        $children->execute([$folderId]);
        while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
            if ($child['is_folder']) {
                self::deleteFolderPermanent($pdo, $storage, $child['id']);
            } else {
                if (!empty($child['file_path']) && !filter_var($child['file_path'], FILTER_VALIDATE_URL)) {
                    $storage->deleteObject($child['file_path']);
                }
                $pdo->prepare("DELETE FROM deliverables WHERE id = ?")->execute([$child['id']]);
            }
        }
        $pdo->prepare("DELETE FROM deliverables WHERE id = ?")->execute([$folderId]);
    }

    private static function restoreFolder(PDO $pdo, int $folderId): void
    {
        $children = $pdo->prepare("SELECT id, is_folder FROM deliverables WHERE parent_folder_id = ? AND deleted_at IS NOT NULL");
        $children->execute([$folderId]);
        while ($child = $children->fetch(PDO::FETCH_ASSOC)) {
            if ($child['is_folder']) {
                self::restoreFolder($pdo, $child['id']);
            } else {
                $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$child['id']]);
            }
        }
        $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$folderId]);
    }
}
