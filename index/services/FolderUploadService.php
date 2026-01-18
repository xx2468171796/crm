<?php
/**
 * 文件夹上传统一服务层
 * 
 * 三端（桌面端、Web端、客户门户）共用此服务
 * 禁止硬编码，所有配置从常量或配置文件读取
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/MultipartUploadService.php';

class FolderUploadService
{
    private MultipartUploadService $uploadService;
    
    // 文件类型目录映射（统一配置，禁止硬编码）
    const ASSET_TYPE_DIRS = [
        'works' => '作品文件',
        'models' => '模型文件',
        'customer' => '客户文件',
        'info' => '信息文件',
        'company' => '公司文件',
    ];
    
    // 分片大小：50MB
    const PART_SIZE = 52428800;
    
    public function __construct()
    {
        $this->uploadService = new MultipartUploadService();
    }
    
    /**
     * 获取文件类型目录名
     */
    public static function getAssetTypeDir(string $assetType): string
    {
        return self::ASSET_TYPE_DIRS[$assetType] ?? self::ASSET_TYPE_DIRS['works'];
    }
    
    /**
     * 构建存储键（统一逻辑，三端共用）
     * 
     * @param string $groupCode 群码/客户编码
     * @param string $assetType 文件类型
     * @param string $relPath 相对路径（包含文件名）
     * @param int $projectId 项目ID（可选）
     * @return array ['storage_key' => string, 'group_code' => string, 'project_name' => string]
     */
    public function buildStorageKey(string $groupCode, string $assetType, string $relPath, int $projectId = 0): array
    {
        $projectName = '';
        $finalGroupCode = $groupCode;
        
        // 如果有项目ID，获取项目信息
        if ($projectId > 0) {
            $project = Db::queryOne(
                "SELECT p.project_name, p.project_code, c.group_code as customer_group_code, c.group_name, c.name as customer_name
                 FROM projects p
                 LEFT JOIN customers c ON p.customer_id = c.id
                 WHERE p.id = ? AND p.deleted_at IS NULL",
                [$projectId]
            );
            
            if ($project) {
                $projectName = $project['project_name'] ?: $project['project_code'];
                $projectName = $this->sanitizePath($projectName);
                
                // 优化groupCode（如果是临时编码如P123，替换为真实客户信息）
                if (preg_match('/^P\d+$/', $groupCode)) {
                    $betterCode = $project['customer_group_code'] ?: $project['group_name'] ?: $project['customer_name'];
                    if ($betterCode) {
                        $finalGroupCode = $this->sanitizePath($betterCode);
                    }
                }
            }
        }
        
        $assetTypeDir = self::getAssetTypeDir($assetType);
        $cleanRelPath = ltrim($relPath, '/');
        
        // 构建存储键
        if ($projectName && in_array($assetType, ['works', 'models', 'customer'])) {
            $storageKey = "groups/{$finalGroupCode}/{$projectName}/{$assetTypeDir}/{$cleanRelPath}";
        } else {
            $storageKey = "groups/{$finalGroupCode}/{$assetTypeDir}/{$cleanRelPath}";
        }
        
        return [
            'storage_key' => $storageKey,
            'group_code' => $finalGroupCode,
            'project_name' => $projectName,
        ];
    }
    
    /**
     * 初始化单个文件的分片上传
     */
    public function initiateFileUpload(string $storageKey, int $filesize, string $mimeType = 'application/octet-stream'): array
    {
        $result = $this->uploadService->initiate($storageKey, $mimeType);
        $totalParts = (int)ceil($filesize / self::PART_SIZE);
        
        return [
            'upload_id' => $result['upload_id'],
            'storage_key' => $storageKey,
            'part_size' => self::PART_SIZE,
            'total_parts' => $totalParts,
        ];
    }
    
    /**
     * 初始化文件夹上传（批量初始化多个文件）
     * 
     * @param string $groupCode 群码
     * @param string $assetType 文件类型
     * @param array $files 文件列表 [['rel_path' => '', 'filename' => '', 'filesize' => 0, 'mime_type' => '']]
     * @param int $projectId 项目ID（可选）
     * @return array
     */
    public function initiateFolderUpload(string $groupCode, string $assetType, array $files, int $projectId = 0): array
    {
        $uploadSessions = [];
        
        foreach ($files as $index => $file) {
            $relPath = $file['rel_path'] ?? '';
            $filename = $file['filename'] ?? '';
            $filesize = (int)($file['filesize'] ?? 0);
            $mimeType = $file['mime_type'] ?? 'application/octet-stream';
            
            if (empty($filename) || $filesize <= 0) {
                continue;
            }
            
            // 使用统一的存储键构建逻辑
            $keyResult = $this->buildStorageKey($groupCode, $assetType, $relPath, $projectId);
            $storageKey = $keyResult['storage_key'];
            
            // 初始化分片上传
            $uploadResult = $this->initiateFileUpload($storageKey, $filesize, $mimeType);
            
            $uploadSessions[] = [
                'index' => $index,
                'rel_path' => $relPath,
                'filename' => $filename,
                'upload_id' => $uploadResult['upload_id'],
                'storage_key' => $storageKey,
                'part_size' => $uploadResult['part_size'],
                'total_parts' => $uploadResult['total_parts'],
            ];
        }
        
        return [
            'total_files' => count($uploadSessions),
            'upload_sessions' => $uploadSessions,
        ];
    }
    
    /**
     * 完成分片上传
     */
    public function completeUpload(string $storageKey, string $uploadId, array $parts): array
    {
        return $this->uploadService->complete($storageKey, $uploadId, $parts);
    }
    
    /**
     * 获取分片上传URL
     */
    public function getPartUploadUrl(string $storageKey, string $uploadId, int $partNumber): string
    {
        return $this->uploadService->getPartPresignedUrl($storageKey, $uploadId, $partNumber);
    }
    
    /**
     * 记录文件到deliverables表（支持文件夹层级）
     * 
     * @param int $projectId 项目ID
     * @param string $storageKey 存储路径
     * @param string $filename 文件名
     * @param int $filesize 文件大小
     * @param string $assetType 资产类型
     * @param int $userId 用户ID
     * @param string $relPath 相对路径（可选，用于创建文件夹层级）
     * @return int
     */
    public function recordDeliverable(int $projectId, string $storageKey, string $filename, int $filesize, string $assetType, int $userId, string $relPath = ''): int
    {
        if ($projectId <= 0) {
            return 0;
        }
        
        $fileCategory = match($assetType) {
            'customer' => 'customer_file',
            'models' => 'model_file',
            default => 'artwork_file',
        };
        
        $approvalStatus = $fileCategory === 'artwork_file' ? 'pending' : 'approved';
        $deliverableName = basename(str_replace('\\', '/', $filename));
        $now = time();
        
        $pdo = Db::pdo();
        
        // 检查是否已存在
        $stmt = $pdo->prepare('SELECT id FROM deliverables WHERE project_id = ? AND file_path = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$projectId, $storageKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing && isset($existing['id'])) {
            return (int)$existing['id'];
        }
        
        // 从relPath中提取文件夹路径并创建文件夹记录
        $parentFolderId = 0;
        if (!empty($relPath)) {
            $parentFolderId = $this->ensureFolderHierarchy($pdo, $projectId, $relPath, $fileCategory, $userId, $now);
        }
        
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
            $userId,
            $now,
            $now,
            $now,
            $parentFolderId ?: null,
        ]);
        
        return (int)$pdo->lastInsertId();
    }
    
    /**
     * 确保文件夹层级存在，返回最深层文件夹的ID
     * 
     * @param PDO $pdo 数据库连接
     * @param int $projectId 项目ID
     * @param string $relPath 相对路径（如：folder1/folder2/file.txt）
     * @param string $fileCategory 文件分类
     * @param int $userId 用户ID
     * @param int $now 时间戳
     * @return int 最深层文件夹的ID，如果没有文件夹则返回0
     */
    private function ensureFolderHierarchy(PDO $pdo, int $projectId, string $relPath, string $fileCategory, int $userId, int $now): int
    {
        // 规范化路径分隔符
        $relPath = str_replace('\\', '/', $relPath);
        $parts = explode('/', $relPath);
        
        // 移除最后一个元素（文件名）
        array_pop($parts);
        
        // 如果没有文件夹部分，返回0
        if (empty($parts)) {
            return 0;
        }
        
        $parentFolderId = 0;
        
        foreach ($parts as $folderName) {
            $folderName = trim($folderName);
            if (empty($folderName)) {
                continue;
            }
            
            // 查找是否已存在该文件夹
            $stmt = $pdo->prepare(
                'SELECT id FROM deliverables WHERE project_id = ? AND deliverable_name = ? AND is_folder = 1 AND file_category = ? AND (parent_folder_id = ? OR (parent_folder_id IS NULL AND ? = 0)) AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute([$projectId, $folderName, $fileCategory, $parentFolderId, $parentFolderId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing && isset($existing['id'])) {
                $parentFolderId = (int)$existing['id'];
            } else {
                // 创建文件夹记录
                $stmt = $pdo->prepare(
                    'INSERT INTO deliverables (project_id, deliverable_name, deliverable_type, file_category, is_folder, visibility_level, approval_status, submitted_by, submitted_at, create_time, update_time, parent_folder_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $projectId,
                    $folderName,
                    'folder',
                    $fileCategory,
                    1,
                    'client',
                    'approved',
                    $userId,
                    $now,
                    $now,
                    $now,
                    $parentFolderId ?: null,
                ]);
                $parentFolderId = (int)$pdo->lastInsertId();
            }
        }
        
        return $parentFolderId;
    }
    
    /**
     * 记录上传日志
     */
    public function logUpload(int $userId, int $projectId, string $filename, string $assetType, int $filesize, string $status = 'success'): void
    {
        if ($projectId <= 0 || empty($filename)) {
            return;
        }
        
        $folderType = self::getAssetTypeDir($assetType);
        
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO file_sync_logs (user_id, project_id, filename, operation, status, size, folder_type, create_time)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $projectId,
            basename($filename),
            'upload',
            $status,
            $filesize > 0 ? $filesize : 0,
            $folderType,
            time(),
        ]);
    }
    
    /**
     * 清理路径中的特殊字符
     */
    private function sanitizePath(string $path): string
    {
        return preg_replace('/[\/\\\\:*?"<>|]/', '_', $path);
    }
}
