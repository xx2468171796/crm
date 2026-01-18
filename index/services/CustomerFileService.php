<?php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permission.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../core/metrics.php';
require_once __DIR__ . '/CustomerFilePolicy.php';
require_once __DIR__ . '/CustomerFileLogger.php';
require_once __DIR__ . '/ZipArchiveService.php';

class CustomerFileService
{
    private const CATEGORY_DIR_MAP = [
        'client_material' => '客户文件',
        'internal_solution' => '公司文件',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'heic', 'heif', 'tif', 'tiff'];
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', 'mpeg', 'mpg', 'ts'];
    private const DEFAULT_MAX_FOLDER_DEPTH = 5;
    private const DEFAULT_MAX_FOLDER_SEGMENT = 40;
    private const DEFAULT_MAX_BATCH_FILES = 500;
    private const DEFAULT_MAX_BATCH_BYTES = 2147483648; // 2GB
    private const FIRST_CONTACT_ATTACHMENT_FOLDER = '首通附件';
    private const OBJECTION_ATTACHMENT_FOLDER = '异议附件';

    private StorageProviderInterface $storage;
    private CustomerFileLogger $logger;
    private array $limits;
    private array $folderLimits = [];
    private array $zipLimits = [];
    private array $customerFolderCache = [];

    public function __construct(?StorageProviderInterface $storage = null, ?CustomerFileLogger $logger = null)
    {
        $this->storage = $storage ?? storage_provider();
        $this->logger = $logger ?? new CustomerFileLogger();
        $config = storage_config();
        $this->limits = $config['limits'] ?? [];
        $this->folderLimits = $this->limits['folder_upload'] ?? [];
        $this->zipLimits = $this->limits['zip_download'] ?? [];
    }

    public function listFiles(int $customerId, array $filters, array $actor): array
    {
        $customer = $this->getCustomer($customerId);
        $link = $this->getLinkIfExists($customerId);
        CustomerFilePolicy::authorize($actor, $customer, 'view', $link);

        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int)($filters['page_size'] ?? 50)));
        $offset = ($page - 1) * $pageSize;

        $where = ['cf.customer_id = :customer_id', 'cf.deleted_at IS NULL'];
        $params = ['customer_id' => $customerId];

        if (!empty($filters['category']) && in_array($filters['category'], ['client_material', 'internal_solution'], true)) {
            $where[] = 'cf.category = :category';
            $params['category'] = $filters['category'];
        }

        $rawFolderPath = array_key_exists('folder_path', $filters) ? (string)$filters['folder_path'] : null;
        $normalizedFolderPath = $rawFolderPath !== null ? $this->sanitizeFilterFolderPath($rawFolderPath) : null;
        $includeChildren = $this->normalizeIncludeChildren($filters['include_children'] ?? null);
        $folderClause = $this->buildFolderWhereClause($normalizedFolderPath, $includeChildren);
        if ($folderClause) {
            $where[] = $folderClause['sql'];
            $params = array_merge($params, $folderClause['params']);
        }

        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = 'cf.filename LIKE :keyword';
            $params['keyword'] = '%' . $keyword . '%';
        }

        if (!empty($filters['uploader_id'])) {
            $where[] = 'cf.uploaded_by = :uploader_id';
            $params['uploader_id'] = (int)$filters['uploader_id'];
        }

        if (!empty($filters['start_at'])) {
            $startAt = strtotime($filters['start_at']);
            if ($startAt !== false) {
                $where[] = 'cf.uploaded_at >= :start_at';
                $params['start_at'] = $startAt;
            }
        }

        if (!empty($filters['end_at'])) {
            $endAt = strtotime($filters['end_at'] . ' 23:59:59');
            if ($endAt !== false) {
                $where[] = 'cf.uploaded_at <= :end_at';
                $params['end_at'] = $endAt;
            }
        }

        $whereSql = implode(' AND ', $where);
        $total = (int)Db::queryOne(
            "SELECT COUNT(*) AS cnt FROM customer_files cf WHERE $whereSql",
            $params
        )['cnt'];

        $items = Db::query(
            "SELECT cf.*, u.realname AS uploader_name 
             FROM customer_files cf
             LEFT JOIN users u ON u.id = cf.uploaded_by
             WHERE $whereSql
             ORDER BY cf.folder_path ASC, cf.uploaded_at DESC
             LIMIT $offset, $pageSize",
            $params
        );

        $uploaders = Db::query(
            'SELECT DISTINCT cf.uploaded_by AS id, u.realname AS name
             FROM customer_files cf
             LEFT JOIN users u ON u.id = cf.uploaded_by
             WHERE cf.customer_id = :customer_id AND cf.deleted_at IS NULL
             ORDER BY name ASC',
            ['customer_id' => $customerId]
        );

        // 转换文件行数据，并为支持预览的文件生成缩略图URL
        $transformedItems = array_map([$this, 'transformFileRow'], $items);
        
        // 为支持预览的图片和视频生成缩略图URL
        foreach ($transformedItems as &$item) {
            if ($item['preview_supported'] && ($this->isImageMimeType($item['mime_type']) || $this->isVideoMimeType($item['mime_type']))) {
                try {
                    $thumbnailUrl = $this->getPreviewUrl($item['id'], $actor, 3600); // 1小时有效期
                    if ($thumbnailUrl) {
                        $item['thumbnail_url'] = $thumbnailUrl;
                    }
                } catch (Exception $e) {
                    // 如果生成缩略图URL失败，忽略错误，不设置thumbnail_url
                    $item['thumbnail_url'] = null;
                }
            } else {
                $item['thumbnail_url'] = null;
            }
        }
        unset($item);
        
        return [
            'items' => $transformedItems,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
            'filters' => [
                'uploaders' => array_values(array_filter($uploaders, static fn($item) => !empty($item['id']))),
                'folder_path' => $normalizedFolderPath,
                'include_children' => $includeChildren,
                'keyword' => $keyword,
            ],
        ];
    }

    /**
     * 查询已删除客户的文件列表（仅管理员）
     * 
     * @param array $filters 筛选条件
     *   - page: 页码
     *   - page_size: 每页数量
     *   - customer_id: 客户ID
     *   - category: 文件类型
     *   - keyword: 文件名关键词
     *   - deleted_start_at: 删除开始时间（时间戳或日期字符串）
     *   - deleted_end_at: 删除结束时间（时间戳或日期字符串）
     * @param array $actor 操作人（必须是管理员）
     * @return array
     */
    public function listDeletedCustomerFiles(array $filters, array $actor): array
    {
        // 权限检查：仅系统管理员可访问
        if ($actor['role'] !== 'admin' && $actor['role'] !== 'system_admin') {
            throw new RuntimeException('无权限访问已删除客户文件');
        }

        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int)($filters['page_size'] ?? 50)));
        $offset = ($page - 1) * $pageSize;

        $where = ['cf.deleted_at IS NOT NULL'];
        $params = [];

        // 客户ID筛选
        if (!empty($filters['customer_id'])) {
            $where[] = 'cf.customer_id = :customer_id';
            $params['customer_id'] = (int)$filters['customer_id'];
        }

        // 文件类型筛选
        if (!empty($filters['category']) && in_array($filters['category'], ['client_material', 'internal_solution'], true)) {
            $where[] = 'cf.category = :category';
            $params['category'] = $filters['category'];
        }

        // 文件名关键词筛选
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = 'cf.filename LIKE :keyword';
            $params['keyword'] = '%' . $keyword . '%';
        }

        // 删除时间范围筛选
        if (!empty($filters['deleted_start_at'])) {
            $startAt = is_numeric($filters['deleted_start_at']) 
                ? (int)$filters['deleted_start_at'] 
                : strtotime($filters['deleted_start_at']);
            if ($startAt !== false) {
                $where[] = 'cf.deleted_at >= :deleted_start_at';
                $params['deleted_start_at'] = $startAt;
            }
        }

        if (!empty($filters['deleted_end_at'])) {
            $endAt = is_numeric($filters['deleted_end_at']) 
                ? (int)$filters['deleted_end_at'] 
                : strtotime($filters['deleted_end_at'] . ' 23:59:59');
            if ($endAt !== false) {
                $where[] = 'cf.deleted_at <= :deleted_end_at';
                $params['deleted_end_at'] = $endAt;
            }
        }

        $whereSql = implode(' AND ', $where);
        $total = (int)Db::queryOne(
            "SELECT COUNT(*) AS cnt FROM customer_files cf WHERE $whereSql",
            $params
        )['cnt'];

        $items = Db::query(
            "SELECT cf.*, 
                    u.realname AS uploader_name,
                    du.realname AS deleter_name,
                    c.name AS customer_name,
                    c.customer_code AS customer_code
             FROM customer_files cf
             LEFT JOIN users u ON u.id = cf.uploaded_by
             LEFT JOIN users du ON du.id = cf.deleted_by
             LEFT JOIN customers c ON c.id = cf.customer_id
             WHERE $whereSql
             ORDER BY cf.deleted_at DESC
             LIMIT $offset, $pageSize",
            $params
        );

        return [
            'items' => array_map([$this, 'transformFileRow'], $items),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    public function getFolderTree(int $customerId, array $actor, array $options = []): array
    {
        $customer = $this->getCustomer($customerId);
        $link = $this->getLinkIfExists($customerId);
        CustomerFilePolicy::authorize($actor, $customer, 'view', $link);

        $category = $this->normalizeCategory($options['category'] ?? 'client_material');
        $parentPathRaw = array_key_exists('parent_path', $options) ? (string)$options['parent_path'] : '';
        $parentPath = $this->sanitizeFilterFolderPath($parentPathRaw) ?? '';

        $rows = $this->fetchFolderAggregates($customerId, $category, $parentPath);
        return $this->buildTreePayload($category, $parentPath, $rows);
    }

    public function uploadFiles(int $customerId, array $actor, array $filesPayload, array $payload = [], ?callable $progressCallback = null): array
    {
        $customer = $this->getCustomer($customerId);
        $link = $this->getLinkIfExists($customerId);
        CustomerFilePolicy::authorize($actor, $customer, 'edit', $link);

        $maxSize = $this->limits['max_single_size'] ?? (2 * 1024 * 1024 * 1024);
        $maxCustomerTotal = $this->limits['max_customer_total'] ?? null;
        $allowedExtensions = array_map('strtolower', $this->limits['allowed_extensions'] ?? []);

        $normalizedFiles = $this->normalizeFilesArray($filesPayload);
        if (empty($normalizedFiles)) {
            throw new InvalidArgumentException('请选择至少一个文件');
        }

        $batchFileCount = count($normalizedFiles);
        $this->assertBatchFileCount($batchFileCount);
        $folderPaths = $this->prepareFolderPaths($payload['folder_paths'] ?? [], $batchFileCount);
        $batchTotalBytes = array_sum(array_map('intval', array_column($normalizedFiles, 'size')));
        $this->assertBatchTotalBytes($batchTotalBytes);

        if ($maxCustomerTotal) {
            $currentTotal = (int)Db::queryOne(
                'SELECT COALESCE(SUM(filesize),0) AS total FROM customer_files WHERE customer_id = :customer_id AND deleted_at IS NULL',
                ['customer_id' => $customerId]
            )['total'];
            if (($currentTotal + $batchTotalBytes) > $maxCustomerTotal) {
                throw new RuntimeException('文件总容量超过限制，请先清理旧文件');
            }
        }

        $category = $this->normalizeCategory($payload['category'] ?? 'client_material');
        $notes = trim($payload['notes'] ?? '');
        $folderRoot = $this->sanitizeFolderPath(
            $payload['folder_root'] ?? '',
            $this->folderLimit('max_depth', self::DEFAULT_MAX_FOLDER_DEPTH),
            $this->folderLimit('max_segment_length', self::DEFAULT_MAX_FOLDER_SEGMENT)
        );
        $isFolderUpload = $this->isFolderUploadRequest($folderRoot, $folderPaths, $payload['upload_mode'] ?? '');

        $created = [];
        $generatedNames = [];
        $summary = [
            'file_count' => $batchFileCount,
            'total_bytes' => 0,
            'folder_path' => $folderRoot,
            'category' => $category,
            'is_folder_upload' => $isFolderUpload,
        ];
        $startedAt = microtime(true);

        foreach ($normalizedFiles as $index => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('文件上传失败: ' . $this->translateUploadError($file['error']));
            }
            if ($file['size'] > $maxSize) {
                throw new RuntimeException('文件超出大小限制(最大 ' . round($maxSize / 1024 / 1024) . ' MB)');
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions, true)) {
                throw new RuntimeException('文件类型不允许: ' . $ext);
            }

            $uploadSource = $payload['upload_source'] ?? '';
            $folderPath = $folderPaths[$index] ?? '';
            
            // 如果是首通附件或异议附件，设置特殊的文件夹路径
            if ($uploadSource === 'first_contact') {
                $folderPath = self::FIRST_CONTACT_ATTACHMENT_FOLDER;
            } elseif ($uploadSource === 'objection') {
                $folderPath = self::OBJECTION_ATTACHMENT_FOLDER;
            }
            
            $finalFilename = $this->generateFinalFilename($file['name'], $category, $ext, $isFolderUpload, $uploadSource);
            $finalFilename = $this->ensureUniqueFilename($customerId, $category, $folderPath, $finalFilename, $generatedNames);
            $storageKey = $this->buildStorageKey($customer, $category, $finalFilename, $folderPath);
            
            // 对于大文件（>50MB），使用更快的 MIME 检测方式，并延迟 MD5 计算
            $fileSize = $file['size'];
            $isLargeFile = $fileSize > 50 * 1024 * 1024; // 50MB
            
            // MIME 类型检测：大文件优先使用文件扩展名，避免读取文件内容
            if ($isLargeFile && !empty($ext)) {
                $mime = $this->getMimeFromExtension($ext) ?: ($file['type'] ?: 'application/octet-stream');
            } else {
                $mime = mime_content_type($file['tmp_name']) ?: $file['type'] ?: 'application/octet-stream';
            }
            
            // 特殊处理：如果是录音文件（upload_source为first_contact且文件名为录音开头），且是webm格式
            // 确保MIME类型为audio/webm而不是video/webm
            $isRecordingFile = false;
            if ($uploadSource === 'first_contact' && !empty($file['name']) && 
                (strpos($file['name'], '录音') === 0 || strpos($file['name'], '首通附件-录音') === 0) &&
                ($ext === 'webm' || $mime === 'audio/webm' || $mime === 'video/webm')) {
                $mime = 'audio/webm';
                $isRecordingFile = true;
                error_log('[CustomerFileService] 检测到录音文件: ' . $file['name']);
            }
            
            // 如果是录音文件，统一转换为mp3格式（Windows可直接播放）
            $convertedFile = null;
            $convertedExt = $ext;
            $convertedMime = $mime;
            $convertedFilename = $finalFilename;
            
            // 如果是录音文件，且不是mp3格式，则转换为mp3
            if ($isRecordingFile && in_array($ext, ['webm', 'wav', 'ogg', 'm4a', 'aac'])) {
                try {
                    $convertedFile = $this->convertAudioToMp3($file['tmp_name'], $ext);
                    if ($convertedFile) {
                        // 更新文件扩展名和MIME类型
                        $convertedExt = 'mp3';
                        $convertedMime = 'audio/mpeg';
                        // 更新文件名（替换原始扩展名为.mp3）
                        $convertedFilename = preg_replace('/\.(webm|wav|ogg|m4a|aac)$/i', '.mp3', $finalFilename);
                        // 使用转换后的文件进行后续处理
                        $file['tmp_name'] = $convertedFile;
                        $file['size'] = filesize($convertedFile);
                        error_log('[CustomerFileService] 录音文件已转换为MP3: ' . $finalFilename . ' -> ' . $convertedFilename . ' (原始格式: ' . $ext . ')');
                    }
                } catch (Exception $e) {
                    error_log('[CustomerFileService] 录音文件转换失败: ' . $e->getMessage());
                    // 转换失败时继续使用原始文件
                }
            }
            
            // 在移动文件之前计算 MD5（更可靠，因为临时文件还在）
            // 对于大文件，MD5 计算可能很慢，但为了数据完整性仍然计算
            $md5StartTime = microtime(true);
            $md5 = md5_file($file['tmp_name']);
            $md5Duration = microtime(true) - $md5StartTime;
            
            if ($md5Duration > 2) {
                error_log(sprintf(
                    '[CustomerFileService] MD5 计算耗时: %.2f秒 (文件: %s, 大小: %s)',
                    $md5Duration,
                    $convertedFilename,
                    $this->formatBytes($fileSize)
                ));
            }
            
            // 如果文件已转换，更新storageKey以反映新的文件名
            if ($convertedFile && $convertedFilename !== $finalFilename) {
                $storageKey = $this->buildStorageKey($customer, $category, $convertedFilename, $folderPath);
            }
            
            // 移动文件到存储
            $fileStartTime = microtime(true);
            $storageMeta = $this->storage->putObject($storageKey, $file['tmp_name'], [
                'mime_type' => $convertedMime,
            ]);
            $bytes = (int)($storageMeta['bytes'] ?? 0);
            $summary['total_bytes'] += $bytes;
            $storageDuration = microtime(true) - $fileStartTime;
            
            // 清理转换后的临时文件
            if ($convertedFile && file_exists($convertedFile)) {
                register_shutdown_function(function() use ($convertedFile) {
                    if (file_exists($convertedFile)) {
                        @unlink($convertedFile);
                    }
                });
            }

            Db::execute(
                'INSERT INTO customer_files
                 (customer_id, category, folder_path, filename, storage_disk, storage_key, filesize, mime_type, file_ext,
                  checksum_md5, preview_supported, uploaded_by, uploaded_at, notes, extra)
                 VALUES
                 (:customer_id, :category, :folder_path, :filename, :storage_disk, :storage_key, :filesize, :mime_type, :file_ext,
                  :checksum_md5, :preview_supported, :uploaded_by, :uploaded_at, :notes, :extra)',
                [
                    'customer_id' => $customerId,
                    'category' => $category,
                    'folder_path' => $folderPath,
                    'filename' => $convertedFilename,
                    'storage_disk' => $storageMeta['disk'],
                    'storage_key' => $storageMeta['storage_key'],
                    'filesize' => $bytes,
                    'mime_type' => $convertedMime,
                    'file_ext' => $convertedExt,
                    'checksum_md5' => $md5,
                    'preview_supported' => $this->storage->supportsPreview($convertedMime) ? 1 : 0,
                    'uploaded_by' => $actor['id'],
                    'uploaded_at' => time(),
                    'notes' => $notes,
                    'extra' => $storageMeta['extra'] ? json_encode($storageMeta['extra']) : null,
                ]
            );

            $fileId = (int)Db::lastInsertId();
            $row = Db::queryOne('SELECT cf.*, u.realname AS uploader_name
                                 FROM customer_files cf
                                 LEFT JOIN users u ON u.id = cf.uploaded_by
                                 WHERE cf.id = :id', ['id' => $fileId]);
            $created[] = $this->transformFileRow($row);

            $this->logger->log($customerId, $fileId, 'file_uploaded', $actor, [
                'storage_key' => $storageMeta['storage_key'],
                'filename' => $convertedFilename,
                'filesize' => $bytes,
                'mime_type' => $convertedMime,
                'folder_path' => $folderPath,
                'converted_from_webm' => $isRecordingFile && $convertedFile ? true : null,
            ]);
            
            // 调用进度回调
            if ($progressCallback) {
                $progressCallback($index, $batchFileCount, $finalFilename);
            }
        }

        record_metric('customer_files.upload.count', count($created), [
            'customer_id' => $customerId,
        ]);

        if ($summary['is_folder_upload'] && $summary['file_count'] > 0) {
            $summary['duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
            if ($summary['folder_path'] === '') {
                $summary['folder_path'] = $this->resolveFolderSummaryPath($folderPaths);
            }
            $this->logger->folderUpload($customerId, $actor, [
                'folder_path' => $summary['folder_path'],
                'file_count' => $summary['file_count'],
                'total_bytes' => $summary['total_bytes'],
                'duration_ms' => $summary['duration_ms'],
                'category' => $summary['category'],
            ]);
        }

        return $created;
    }

    public function deleteFile(int $fileId, array $actor): array
    {
        $file = $this->getFileOrFail($fileId);
        $customer = $this->getCustomer((int)$file['customer_id']);
        $link = $this->getLinkIfExists((int)$file['customer_id']);
        CustomerFilePolicy::authorize($actor, $customer, 'edit', $link);

        if ($file['deleted_at']) {
            return $this->transformFileRow($file);
        }

        // 软删除：只标记删除，不删除物理文件
        // 物理文件将在清理脚本（cleanup_deleted_customer_files.php）中，超过保留期（15天）后自动删除
        // 这样可以在保留期内通过恢复功能恢复文件
        Db::execute(
            'UPDATE customer_files SET deleted_at = :deleted_at, deleted_by = :deleted_by WHERE id = :id',
            [
                'deleted_at' => time(),
                'deleted_by' => $actor['id'],
                'id' => $fileId,
            ]
        );

        $this->logger->log((int)$file['customer_id'], $fileId, 'file_deleted', $actor, [
            'storage_key' => $file['storage_key'],
            'filename' => $file['filename'],
            'folder_path' => $file['folder_path'] ?? '',
        ]);

        return $this->transformFileRow(array_merge($file, [
            'deleted_at' => time(),
            'deleted_by' => $actor['id'],
        ]));
    }

    public function getFileOrFail(int $fileId): array
    {
        $file = Db::queryOne(
            'SELECT cf.*, u.realname AS uploader_name
             FROM customer_files cf
             LEFT JOIN users u ON u.id = cf.uploaded_by
             WHERE cf.id = :id',
            ['id' => $fileId]
        );
        if (!$file) {
            throw new RuntimeException('文件不存在');
        }
        return $file;
    }

    public function streamFile(int $fileId, array $actor): array
    {
        $file = $this->getFileOrFail($fileId);
        if ($file['deleted_at']) {
            throw new RuntimeException('文件已删除');
        }
        $customer = $this->getCustomer((int)$file['customer_id']);
        
        // 如果客户已删除，仅管理员可访问
        if (!empty($customer['deleted_at'])) {
            if ($actor['role'] !== 'admin' && $actor['role'] !== 'system_admin') {
                throw new RuntimeException('该文件所属客户已删除，仅管理员可访问');
            }
        }
        
        $link = $this->getLinkIfExists((int)$file['customer_id']);
        CustomerFilePolicy::authorize($actor, $customer, 'view', $link);

        $stream = $this->storage->readStream($file['storage_key']);
        $this->logger->log((int)$file['customer_id'], $fileId, 'file_downloaded', $actor, [
            'storage_key' => $file['storage_key'],
            'filename' => $file['filename'],
            'folder_path' => $file['folder_path'] ?? '',
        ]);
        record_metric('customer_files.download.count', 1, [
            'customer_id' => $file['customer_id'],
        ]);

        return [
            'file' => $file,
            'stream' => $stream,
        ];
    }

    public function createZipDownload(int $customerId, array $actor, array $options = []): array
    {
        $customer = $this->getCustomer($customerId);
        $link = $this->getLinkIfExists($customerId);
        CustomerFilePolicy::authorize($actor, $customer, 'view', $link);

        $category = $this->normalizeCategory($options['category'] ?? 'client_material');
        $fileIds = $this->normalizeIdList($options['file_ids'] ?? []);
        $includeChildren = $this->normalizeIncludeChildren($options['include_children'] ?? null);
        $selectionType = $options['selection_type'] ?? (empty($fileIds) ? 'tree_node' : 'selection');

        if (empty($fileIds)) {
            $rawFolderPath = array_key_exists('folder_path', $options) ? (string)$options['folder_path'] : '';
            $folderPath = $this->sanitizeFilterFolderPath($rawFolderPath) ?? '';
            $files = $this->fetchFilesByFolder($customerId, $category, $folderPath, $includeChildren);
        } else {
            $folderPath = null;
            $files = $this->fetchFilesByIds($customerId, $category, $fileIds);
        }

        if (empty($files)) {
            throw new RuntimeException('所选范围暂无可下载文件');
        }

        $this->enforceZipLimits($files);

        $zipMeta = $this->buildZipArchive($customer, $category, $files, [
            'folder_path' => $folderPath,
            'include_children' => $includeChildren,
            'selection_type' => $selectionType,
        ]);

        $this->logger->folderDownload((int)$customer['id'], $actor, [
            'category' => $category,
            'folder_path' => $folderPath,
            'include_children' => $includeChildren,
            'selection_type' => $selectionType,
            'file_count' => count($files),
            'file_ids' => array_column($files, 'id'),
            'download_name' => $zipMeta['download_name'],
        ]);

        return $zipMeta;
    }

    public function renameFile(int $fileId, string $newFilename, array $actor): array
    {
        $file = $this->getFileOrFail($fileId);
        if ($file['deleted_at']) {
            throw new RuntimeException('文件已删除');
        }

        $customer = $this->getCustomer((int)$file['customer_id']);
        $link = $this->getLinkIfExists((int)$file['customer_id']);
        CustomerFilePolicy::authorize($actor, $customer, 'edit', $link);

        // 验证新文件名格式
        $newFilename = trim($newFilename);
        if ($newFilename === '') {
            throw new InvalidArgumentException('文件名不能为空');
        }

        // 检查非法字符
        if (preg_match('/[\/\\\\<>:"|?*\x00-\x1F\x7F]/u', $newFilename)) {
            throw new InvalidArgumentException('文件名包含非法字符');
        }

        // 检查长度限制
        $newFilename = $this->ensureFilenameLength($newFilename);
        if (mb_strlen($newFilename, 'UTF-8') > 255) {
            throw new InvalidArgumentException('文件名过长（最多255个字符）');
        }

        // 检查文件名冲突（排除当前文件）
        $customerId = (int)$file['customer_id'];
        $category = $file['category'];
        $folderPath = $file['folder_path'] ?? '';
        if ($this->filenameExists($customerId, $category, $folderPath, $newFilename) && $newFilename !== $file['filename']) {
            throw new RuntimeException('文件名已存在，请使用其他名称');
        }

        // 如果文件名相同，直接返回
        if ($newFilename === $file['filename']) {
            return $this->transformFileRow($file);
        }

        // 更新存储路径（如果需要）
        $oldStorageKey = $file['storage_key'];
        $newStorageKey = $this->buildStorageKey($customer, $category, $newFilename, $folderPath);

        // 如果使用本地存储，重命名物理文件
        if ($this->storage->disk() === 'local' && $oldStorageKey !== $newStorageKey) {
            $this->renameStorageObject($oldStorageKey, $newStorageKey);
        } elseif ($oldStorageKey !== $newStorageKey) {
            // 对于对象存储，复制到新位置并删除旧文件
            $this->copyStorageObject($oldStorageKey, $newStorageKey);
            $this->storage->deleteObject($oldStorageKey);
        }

        // 更新数据库
        Db::execute(
            'UPDATE customer_files SET filename = :filename, storage_key = :storage_key WHERE id = :id',
            [
                'filename' => $newFilename,
                'storage_key' => $newStorageKey,
                'id' => $fileId,
            ]
        );

        // 记录操作日志
        $this->logger->log($customerId, $fileId, 'file_renamed', $actor, [
            'old_filename' => $file['filename'],
            'new_filename' => $newFilename,
            'old_storage_key' => $oldStorageKey,
            'new_storage_key' => $newStorageKey,
        ]);

        // 返回更新后的文件信息
        $updatedFile = $this->getFileOrFail($fileId);
        return $this->transformFileRow($updatedFile);
    }

    public function renameFolder(int $customerId, string $oldFolderPath, string $newFolderName, array $actor): array
    {
        $customer = $this->getCustomer($customerId);
        $link = $this->getLinkIfExists($customerId);
        CustomerFilePolicy::authorize($actor, $customer, 'edit', $link);

        // 验证新文件夹名称格式
        $newFolderName = trim($newFolderName);
        if ($newFolderName === '') {
            throw new InvalidArgumentException('文件夹名称不能为空');
        }

        // 检查非法字符
        $sanitized = $this->sanitizePathSegment($newFolderName);
        if ($sanitized !== $newFolderName) {
            throw new InvalidArgumentException('文件夹名称包含非法字符');
        }

        // 构建新路径
        $oldFolderPath = trim((string)$oldFolderPath, '/');
        $parentPath = '';
        if ($oldFolderPath !== '') {
            $segments = explode('/', $oldFolderPath);
            array_pop($segments);
            $parentPath = implode('/', $segments);
        }
        $newFolderPath = $parentPath === '' ? $newFolderName : $parentPath . '/' . $newFolderName;

        // 检查路径冲突（检查新路径及其子路径）
        if ($this->folderPathExists($customerId, $newFolderPath)) {
            throw new RuntimeException('文件夹路径已存在，请使用其他名称');
        }

        // 获取所有需要更新的文件（包括子文件夹中的文件）
        $files = Db::query(
            'SELECT id, filename, folder_path, storage_key, category FROM customer_files 
             WHERE customer_id = :customer_id 
             AND (folder_path = :folder_path OR folder_path LIKE :folder_like)
             AND deleted_at IS NULL',
            [
                'customer_id' => $customerId,
                'folder_path' => $oldFolderPath,
                'folder_like' => $oldFolderPath . '/%',
            ]
        );

        if (empty($files)) {
            throw new RuntimeException('文件夹不存在或为空');
        }

        // 批量更新文件路径和存储键
        $updatedCount = 0;
        foreach ($files as $file) {
            $fileOldPath = $file['folder_path'];
            // 计算新路径：如果是直接子文件，使用新路径；如果是子文件夹中的文件，替换路径前缀
            if ($fileOldPath === $oldFolderPath) {
                $fileNewPath = $newFolderPath;
            } else {
                $fileNewPath = $newFolderPath . substr($fileOldPath, strlen($oldFolderPath));
            }

            $oldStorageKey = $file['storage_key'];
            $newStorageKey = $this->buildStorageKey($customer, $file['category'], $file['filename'], $fileNewPath);

            // 如果使用本地存储，重命名物理目录中的文件
            if ($this->storage->disk() === 'local' && $oldStorageKey !== $newStorageKey) {
                $this->renameStorageObject($oldStorageKey, $newStorageKey);
            } elseif ($oldStorageKey !== $newStorageKey) {
                // 对于对象存储，复制到新位置并删除旧文件
                $this->copyStorageObject($oldStorageKey, $newStorageKey);
                $this->storage->deleteObject($oldStorageKey);
            }

            // 更新数据库
            Db::execute(
                'UPDATE customer_files SET folder_path = :folder_path, storage_key = :storage_key WHERE id = :id',
                [
                    'folder_path' => $fileNewPath,
                    'storage_key' => $newStorageKey,
                    'id' => $file['id'],
                ]
            );

            $updatedCount++;
        }

        // 记录操作日志
        $this->logger->log($customerId, 0, 'folder_renamed', $actor, [
            'old_folder_path' => $oldFolderPath,
            'new_folder_path' => $newFolderPath,
            'file_count' => $updatedCount,
        ]);

        return [
            'old_folder_path' => $oldFolderPath,
            'new_folder_path' => $newFolderPath,
            'file_count' => $updatedCount,
        ];
    }

    public function getPreviewUrl(int $fileId, array $actor, int $expiresIn = 300): ?string
    {
        $file = $this->getFileOrFail($fileId);
        if ($file['deleted_at']) {
            throw new RuntimeException('文件已删除');
        }

        $customer = $this->getCustomer((int)$file['customer_id']);
        
        // 如果客户已删除，仅管理员可访问
        if (!empty($customer['deleted_at'])) {
            if ($actor['role'] !== 'admin' && $actor['role'] !== 'system_admin') {
                throw new RuntimeException('该文件所属客户已删除，仅管理员可访问');
            }
        }
        
        $link = $this->getLinkIfExists((int)$file['customer_id']);
        CustomerFilePolicy::authorize($actor, $customer, 'view', $link);

        // 检查是否支持预览
        $mimeType = $file['mime_type'] ?? '';
        if (!$this->storage->supportsPreview($mimeType)) {
            return null;
        }

        // 使用后端代理URL，避免Mixed Content问题（HTTPS页面加载HTTP资源）
        // 这样前端始终通过HTTPS访问，不会触发浏览器安全策略
        // 后端会代理从存储服务读取文件并流式传输给前端（后端到存储服务使用HTTP，前端到后端使用HTTPS）
        
        // 检测HTTPS协议（支持反向代理和内网穿透场景）
        // 内网穿透通常会在请求头中添加 X-Forwarded-Proto: https
        $isHttps = false;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $isHttps = true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            // 内网穿透/反向代理传递的协议
            $isHttps = true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            $isHttps = true;
        } elseif (isset($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
            $isHttps = true;
        } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            $isHttps = true;
        }
        
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        
        // 生成代理URL（使用与前端访问相同的协议，避免Mixed Content）
        $proxyUrl = $scheme . '://' . $host . '/api/customer_file_stream.php?id=' . $fileId . '&mode=preview';
        
        // 注意：
        // 1. 生成的URL使用HTTPS（如果前端是HTTPS），避免Mixed Content问题
        // 2. 后端代理到存储服务时会使用HTTP（因为存储服务是HTTP），但这对前端是透明的
        // 3. expiresIn参数不再需要，因为代理URL会通过会话认证进行权限检查
        // 4. 每次访问都会经过CustomerFileService的权限验证，比直接URL更安全
        
        return $proxyUrl;
    }

    private function renameStorageObject(string $oldKey, string $newKey): void
    {
        if ($this->storage->disk() !== 'local') {
            return;
        }

        // 对于本地存储，直接重命名文件
        $oldPath = $this->getLocalStoragePath($oldKey);
        $newPath = $this->getLocalStoragePath($newKey);

        $newDir = dirname($newPath);
        if (!is_dir($newDir)) {
            if (!mkdir($newDir, 0755, true) && !is_dir($newDir)) {
                throw new RuntimeException('无法创建目录: ' . $newDir);
            }
        }

        if (!rename($oldPath, $newPath)) {
            throw new RuntimeException('重命名文件失败: ' . $oldKey . ' -> ' . $newKey);
        }
    }

    private function copyStorageObject(string $sourceKey, string $destKey): void
    {
        // 对于对象存储，需要先下载到临时文件，然后上传到新位置
        $sourceStream = $this->storage->readStream($sourceKey);
        $tempPath = tempnam(sys_get_temp_dir(), 'file_copy_');
        if ($tempPath === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        try {
            $destHandle = fopen($tempPath, 'wb');
            if ($destHandle === false) {
                throw new RuntimeException('无法写入临时文件');
            }

            stream_copy_to_stream($sourceStream, $destHandle);
            fclose($destHandle);
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            $this->storage->putObject($destKey, $tempPath);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function getLocalStoragePath(string $storageKey): string
    {
        if ($this->storage->disk() !== 'local') {
            throw new RuntimeException('此方法仅适用于本地存储');
        }
        // 通过反射访问 protected 属性获取 root
        $reflection = new ReflectionClass($this->storage);
        $rootProperty = $reflection->getProperty('root');
        $rootProperty->setAccessible(true);
        $root = $rootProperty->getValue($this->storage);
        return rtrim($root, '/') . '/' . ltrim($storageKey, '/');
    }

    private function folderPathExists(int $customerId, string $folderPath): bool
    {
        $row = Db::queryOne(
            'SELECT COUNT(*) AS cnt FROM customer_files 
             WHERE customer_id = :customer_id AND folder_path = :folder_path AND deleted_at IS NULL 
             LIMIT 1',
            [
                'customer_id' => $customerId,
                'folder_path' => $folderPath,
            ]
        );
        return ((int)($row['cnt'] ?? 0)) > 0;
    }

    private function transformFileRow(array $row): array
    {
        $result = [
            'id' => (int)$row['id'],
            'customer_id' => (int)$row['customer_id'],
            'category' => $row['category'],
            'filename' => $row['filename'],
            'filesize' => (int)$row['filesize'],
            'mime_type' => $row['mime_type'],
            'folder_path' => $row['folder_path'] ?? '',
            'display_folder' => $this->formatDisplayFolder($row['folder_path'] ?? ''),
            'uploaded_at' => (int)$row['uploaded_at'],
            'uploaded_by' => (int)$row['uploaded_by'],
            'uploaded_by_name' => $row['uploader_name'] ?? '',
            'preview_supported' => (bool)$row['preview_supported'],
            'notes' => $row['notes'],
            'storage_disk' => $row['storage_disk'],
            'storage_key' => $row['storage_key'],
            'deleted_at' => $row['deleted_at'] ?? null,
        ];
        
        // 已删除文件的额外字段
        if (isset($row['deleted_by'])) {
            $result['deleted_by'] = (int)$row['deleted_by'];
        }
        if (isset($row['deleter_name'])) {
            $result['deleted_by_name'] = $row['deleter_name'] ?? '';
        }
        if (isset($row['customer_name'])) {
            $result['customer_name'] = $row['customer_name'] ?? '';
        }
        if (isset($row['customer_code'])) {
            $result['customer_code'] = $row['customer_code'] ?? '';
        }
        
        return $result;
    }

    private function formatDisplayFolder(string $folderPath): string
    {
        return $folderPath === '' ? '根目录' : $folderPath;
    }

    private function getCustomer(int $customerId): array
    {
        $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
        if (!$customer) {
            throw new RuntimeException('客户不存在');
        }
        return $customer;
    }

    /**
     * 获取客户链接信息（如果存在）
     * 
     * @param int $customerId 客户ID
     * @return array|null 链接信息，如果不存在则返回null
     */
    private function getLinkIfExists(int $customerId): ?array
    {
        // 先检查文件管理分享上下文
        if (hasFileManagerShareAccess($customerId)) {
            $fileManagerLink = Db::queryOne(
                'SELECT * FROM file_manager_links WHERE customer_id = :id',
                ['id' => $customerId]
            );
            if ($fileManagerLink) {
                $fileManagerLink['__link_type'] = 'file_manager';
                return $fileManagerLink;
            }
        }

        $link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :id', ['id' => $customerId]);
        if ($link) {
            $link['__link_type'] = 'customer';
        }
        return $link ?: null;
    }

    private function normalizeCategory(string $category): string
    {
        $map = [
            'customer' => 'client_material',
            'client_material' => 'client_material',
            'company' => 'internal_solution',
            'internal_solution' => 'internal_solution',
        ];
        return $map[$category] ?? 'client_material';
    }

    private function normalizeFilesArray(array $filesPayload): array
    {
        if (!isset($filesPayload['name'])) {
            return [];
        }

        $normalized = [];
        if (is_array($filesPayload['name'])) {
            $count = count($filesPayload['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name' => $filesPayload['name'][$i],
                    'type' => $filesPayload['type'][$i],
                    'tmp_name' => $filesPayload['tmp_name'][$i],
                    'error' => $filesPayload['error'][$i],
                    'size' => $filesPayload['size'][$i],
                ];
            }
        } else {
            $normalized[] = $filesPayload;
        }

        return $normalized;
    }

    private function folderLimit(string $key, int $default): int
    {
        $value = $this->folderLimits[$key] ?? null;
        if (is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }

    private function assertBatchFileCount(int $count): void
    {
        $maxFiles = $this->folderLimit('max_files', self::DEFAULT_MAX_BATCH_FILES);
        if ($count > $maxFiles) {
            throw new RuntimeException('单次最多上传 ' . $maxFiles . ' 个文件，请拆分后再试');
        }
    }

    private function assertBatchTotalBytes(int $totalBytes): void
    {
        $maxBytes = $this->folderLimit('max_total_bytes', self::DEFAULT_MAX_BATCH_BYTES);
        if ($totalBytes > $maxBytes) {
            throw new RuntimeException('单次上传总大小不可超过 ' . $this->formatBytes($maxBytes));
        }
    }

    private function prepareFolderPaths($folderPaths, int $fileCount): array
    {
        $maxDepth = $this->folderLimit('max_depth', self::DEFAULT_MAX_FOLDER_DEPTH);
        $maxSegment = $this->folderLimit('max_segment_length', self::DEFAULT_MAX_FOLDER_SEGMENT);
        if (!is_array($folderPaths)) {
            $folderPaths = ($folderPaths === null || $folderPaths === '') ? [] : [$folderPaths];
        }
        $folderPaths = array_values($folderPaths);
        $normalized = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $normalized[] = $this->sanitizeFolderPath($folderPaths[$i] ?? '', $maxDepth, $maxSegment);
        }
        return $normalized;
    }

    private function sanitizeFilterFolderPath(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }

        return $this->sanitizeFolderPath(
            $raw,
            $this->folderLimit('max_depth', self::DEFAULT_MAX_FOLDER_DEPTH),
            $this->folderLimit('max_segment_length', self::DEFAULT_MAX_FOLDER_SEGMENT)
        );
    }

    private function normalizeIncludeChildren($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return !in_array($normalized, ['0', 'false', 'current', 'single', 'only', 'none'], true);
    }

    private function buildFolderWhereClause(?string $folderPath, bool $includeChildren, string $columnPrefix = 'cf.', string $paramPrefix = 'filter'): ?array
    {
        if ($folderPath === null) {
            return null;
        }

        $column = $columnPrefix . 'folder_path';
        $pathKey = $paramPrefix . '_folder_path';
        $likeKey = $paramPrefix . '_folder_like';

        if ($folderPath === '' && $includeChildren) {
            return null;
        }

        if ($includeChildren && $folderPath !== '') {
            return [
                'sql' => sprintf('(%s = :%s OR %s LIKE :%s)', $column, $pathKey, $column, $likeKey),
                'params' => [
                    $pathKey => $folderPath,
                    $likeKey => $folderPath . '/%',
                ],
            ];
        }

        return [
            'sql' => sprintf('%s = :%s', $column, $pathKey),
            'params' => [
                $pathKey => $folderPath,
            ],
        ];
    }

    private function sanitizeFolderPath(?string $raw, int $maxDepth, int $maxSegmentLength): string
    {
        $raw = trim((string)$raw);
        $raw = str_replace('\\', '/', $raw);
        $raw = trim($raw, '/');
        if ($raw === '') {
            return '';
        }

        $segments = array_values(array_filter(array_map('trim', explode('/', $raw)), static fn($item) => $item !== ''));
        if (empty($segments)) {
            return '';
        }

        if (count($segments) > $maxDepth) {
            throw new RuntimeException('子目录层级超过限制（最多 ' . $maxDepth . ' 层）：' . $raw);
        }

        $normalized = [];
        foreach ($segments as $segment) {
            $clean = $this->sanitizeFilenameBase($segment);
            $clean = str_replace('..', '-', $clean);
            $clean = trim($clean, '/');
            if ($clean === '') {
                $clean = '-';
            }
            if (mb_strlen($clean, 'UTF-8') > $maxSegmentLength) {
                throw new RuntimeException('子目录“' . $segment . '”长度超过 ' . $maxSegmentLength . ' 个字符');
            }
            $normalized[] = $clean;
        }

        $path = implode('/', $normalized);
        if (mb_strlen($path, 'UTF-8') > 255) {
            throw new RuntimeException('子目录路径过长（最多 255 个字符）');
        }
        return $path;
    }

    private function containsFolderPath(array $folderPaths): bool
    {
        foreach ($folderPaths as $path) {
            if (!empty($path)) {
                return true;
            }
        }
        return false;
    }

    private function resolveFolderSummaryPath(array $folderPaths): string
    {
        foreach ($folderPaths as $path) {
            if (!empty($path)) {
                return $path;
            }
        }
        return '';
    }

    private function isFolderUploadRequest(string $folderRoot, array $folderPaths, ?string $mode): bool
    {
        if ($folderRoot !== '') {
            return true;
        }
        if (($mode ?? '') === 'folder') {
            return true;
        }
        return $this->containsFolderPath($folderPaths);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $idx = 0;
        $value = $bytes;
        while ($value >= 1024 && $idx < count($units) - 1) {
            $value /= 1024;
            $idx++;
        }
        return sprintf('%s %s', $value >= 10 || $idx === 0 ? round($value, 0) : round($value, 1), $units[$idx]);
    }

    private function getMimeFromExtension(string $ext): ?string
    {
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ];
        return $mimeMap[strtolower($ext)] ?? null;
    }


    private function translateUploadError(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '文件超过系统限制';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一部分';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器磁盘不可写';
            case UPLOAD_ERR_EXTENSION:
                return '被扩展中断';
            default:
                return '未知错误';
        }
    }

    private function buildStorageKey(array $customer, string $category, string $finalFilename, string $folderPath = ''): string
    {
        $customerId = (int)$customer['id'];
        $folder = $this->resolveCustomerFolderName($customer);
        $categoryDir = $this->getCategoryDirectory($category);
        $segments = [
            'customer',
            $customerId,
            $folder,
            $categoryDir,
        ];

        if ($folderPath !== '') {
            foreach (explode('/', $folderPath) as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }
                $segments[] = $this->sanitizePathSegment($segment);
            }
        }

        $segments[] = $this->sanitizePathSegment($finalFilename);
        return implode('/', $segments);
    }

    private function generateFinalFilename(string $originalName, string $category, string $ext, bool $isFolderUpload = false, string $uploadSource = ''): string
    {
        $base = $this->sanitizeFilenameBase(pathinfo($originalName, PATHINFO_FILENAME));
        
        // 如果是首通附件或异议附件，添加特殊前缀
        if ($uploadSource === 'first_contact') {
            $base = self::FIRST_CONTACT_ATTACHMENT_FOLDER . '-' . $base;
        } elseif ($uploadSource === 'objection') {
            $base = self::OBJECTION_ATTACHMENT_FOLDER . '-' . $base;
        } elseif ($category === 'client_material' && !$isFolderUpload) {
            // 如果是文件夹上传，不自动分类（不添加图片/视频/文件前缀）
            $prefix = $this->resolveClientPrefix($ext);
            $base = $prefix . '-' . $base;
        }

        if ($base === '') {
            $base = '文件-' . date('His');
        }

        $extPart = $ext ? '.' . $ext : '';
        $filename = $base . $extPart;
        return $this->ensureFilenameLength($filename);
    }

    private function ensureUniqueFilename(int $customerId, string $category, string $folderPath, string $filename, array &$generatedNames): string
    {
        $key = $customerId . ':' . $category . ':' . $folderPath;
        $generatedNames[$key] = $generatedNames[$key] ?? [];

        if (!in_array($filename, $generatedNames[$key], true) && !$this->filenameExists($customerId, $category, $folderPath, $filename)) {
            $generatedNames[$key][] = $filename;
            return $filename;
        }

        $dotPos = mb_strrpos($filename, '.', 0, 'UTF-8');
        if ($dotPos === false) {
            $base = $filename;
            $ext = '';
        } else {
            $base = mb_substr($filename, 0, $dotPos, 'UTF-8');
            $ext = mb_substr($filename, $dotPos, null, 'UTF-8');
        }

        $counter = 2;
        do {
            $candidate = $this->ensureFilenameLength($base . '(' . $counter . ')' . $ext);
            $counter++;
        } while (
            in_array($candidate, $generatedNames[$key], true)
            || $this->filenameExists($customerId, $category, $folderPath, $candidate)
        );

        $generatedNames[$key][] = $candidate;
        return $candidate;
    }

    private function filenameExists(int $customerId, string $category, string $folderPath, string $filename): bool
    {
        $row = Db::queryOne(
            'SELECT COUNT(*) AS cnt FROM customer_files WHERE customer_id = :customer_id AND category = :category AND folder_path = :folder_path AND filename = :filename AND deleted_at IS NULL',
            [
                'customer_id' => $customerId,
                'category' => $category,
                'folder_path' => $folderPath,
                'filename' => $filename,
            ]
        );
        return ((int)($row['cnt'] ?? 0)) > 0;
    }

    private function resolveCustomerFolderName(array $customer): string
    {
        $customerId = (int)$customer['id'];
        $todayKey = $customerId . '-' . date('Ymd');
        if (isset($this->customerFolderCache[$todayKey])) {
            return $this->customerFolderCache[$todayKey];
        }

        $shortName = $this->normalizeCustomerShortName($customer['name'] ?? '', $customerId);
        $folder = date('md') . '-' . $shortName;
        $this->customerFolderCache[$todayKey] = $folder;
        return $folder;
    }

    private function normalizeCustomerShortName(?string $name, int $customerId): string
    {
        $normalized = preg_replace('/\s+/u', '', (string)$name);
        $normalized = preg_replace('/[^0-9A-Za-z\p{Han}-]+/u', '', $normalized);
        if (!$normalized) {
            $normalized = 'customer-' . $customerId;
        }
        return mb_substr($normalized, 0, 20, 'UTF-8');
    }

    private function getCategoryDirectory(string $category): string
    {
        return self::CATEGORY_DIR_MAP[$category] ?? self::CATEGORY_DIR_MAP['client_material'];
    }

    private function sanitizeFilenameBase(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = preg_replace('/[<>:"|?*]/u', '-', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        return $name ?: '';
    }

    private function sanitizePathSegment(string $segment): string
    {
        $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment);
        $segment = str_replace(['..', '/', '\\'], '-', $segment);
        return $segment;
    }

    private function ensureFilenameLength(string $filename, int $limit = 120): string
    {
        if (mb_strlen($filename, 'UTF-8') <= $limit) {
            return $filename;
        }

        $dotPos = mb_strrpos($filename, '.', 0, 'UTF-8');
        if ($dotPos === false) {
            return mb_substr($filename, 0, $limit, 'UTF-8');
        }

        $ext = mb_substr($filename, $dotPos, null, 'UTF-8');
        $base = mb_substr($filename, 0, $dotPos, 'UTF-8');
        $baseLimit = max(1, $limit - mb_strlen($ext, 'UTF-8'));
        return mb_substr($base, 0, $baseLimit, 'UTF-8') . $ext;
    }

    private function resolveClientPrefix(string $ext): string
    {
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return '图片';
        }
        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return '视频';
        }
        return '文件';
    }

    private function isImageMimeType(?string $mimeType): bool
    {
        if (empty($mimeType)) {
            return false;
        }
        return strpos($mimeType, 'image/') === 0;
    }

    private function isVideoMimeType(?string $mimeType): bool
    {
        if (empty($mimeType)) {
            return false;
        }
        return strpos($mimeType, 'video/') === 0;
    }

    private function fetchFolderAggregates(int $customerId, string $category, string $parentPath): array
    {
        $params = [
            'customer_id' => $customerId,
            'category' => $category,
        ];

        $sql = 'SELECT COALESCE(folder_path, "") AS folder_path, COUNT(*) AS file_count, COALESCE(SUM(filesize),0) AS total_size
                FROM customer_files cf
                WHERE cf.customer_id = :customer_id
                  AND cf.category = :category
                  AND cf.deleted_at IS NULL';

        if ($parentPath !== '') {
            $sql .= ' AND (cf.folder_path = :parent_path OR cf.folder_path LIKE :parent_like)';
            $params['parent_path'] = $parentPath;
            $params['parent_like'] = $parentPath . '/%';
        }

        $sql .= ' GROUP BY cf.folder_path';

        return Db::query($sql, $params);
    }

    private function buildTreePayload(string $category, string $parentPath, array $rows): array
    {
        $children = [];
        $currentDirectCount = 0;
        $currentDirectSize = 0;
        $currentTotalCount = 0;
        $currentTotalSize = 0;

        foreach ($rows as $row) {
            $path = $row['folder_path'] ?? '';
            $fileCount = (int)$row['file_count'];
            $totalSize = (int)$row['total_size'];

            if ($parentPath !== '') {
                $needle = $parentPath . '/';
                if ($path !== $parentPath && !$this->startsWith($path, $needle)) {
                    continue;
                }
            }

            if ($path === $parentPath) {
                $currentDirectCount += $fileCount;
                $currentDirectSize += $totalSize;
                $currentTotalCount += $fileCount;
                $currentTotalSize += $totalSize;
                continue;
            }

            $currentTotalCount += $fileCount;
            $currentTotalSize += $totalSize;

            $relative = $parentPath === '' ? $path : substr($path, strlen($parentPath) + 1);
            $relative = trim($relative, '/');
            if ($relative === '') {
                continue;
            }
            $segments = explode('/', $relative);
            $childSlug = $segments[0];
            $childFullPath = $parentPath === '' ? $childSlug : $parentPath . '/' . $childSlug;

            if (!isset($children[$childFullPath])) {
                $children[$childFullPath] = [
                    'full_path' => $childFullPath,
                    'name' => $childSlug,
                    'label' => $this->resolveNodeLabel($category, $childFullPath),
                    'file_count_total' => 0,
                    'file_count_direct' => 0,
                    'total_size' => 0,
                    'has_children' => false,
                ];
            }

            $children[$childFullPath]['file_count_total'] += $fileCount;
            $children[$childFullPath]['total_size'] += $totalSize;

            if (count($segments) === 1) {
                $children[$childFullPath]['file_count_direct'] += $fileCount;
            } else {
                $children[$childFullPath]['has_children'] = true;
            }
        }

        $childList = array_values($children);
        usort($childList, static fn($a, $b) => strcmp($a['label'], $b['label']));

        return [
            'category' => $category,
            'node' => [
                'full_path' => $parentPath,
                'label' => $this->resolveNodeLabel($category, $parentPath),
                'file_count_total' => $currentTotalCount,
                'file_count_direct' => $currentDirectCount,
                'total_size' => $currentTotalSize,
                'breadcrumbs' => $this->buildBreadcrumbs($category, $parentPath),
                'has_children' => !empty($childList),
            ],
            'children' => $childList,
        ];
    }

    private function buildBreadcrumbs(string $category, string $fullPath): array
    {
        $breadcrumbs = [
            [
                'label' => $this->resolveNodeLabel($category, ''),
                'full_path' => '',
            ],
        ];

        if ($fullPath === '') {
            return $breadcrumbs;
        }

        $segments = explode('/', $fullPath);
        $current = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $breadcrumbs[] = [
                'label' => $segment,
                'full_path' => $current,
            ];
        }
        return $breadcrumbs;
    }

    private function resolveNodeLabel(string $category, string $fullPath): string
    {
        if ($fullPath === '') {
            return $this->getCategoryLabel($category);
        }
        $segments = explode('/', $fullPath);
        $label = end($segments);
        return $label ?: $this->getCategoryLabel($category);
    }

    private function getCategoryLabel(string $category): string
    {
        return self::CATEGORY_DIR_MAP[$category] ?? self::CATEGORY_DIR_MAP['client_material'];
    }

    private function fetchFilesByFolder(int $customerId, string $category, ?string $folderPath, bool $includeChildren): array
    {
        $where = [
            'cf.customer_id = :customer_id',
            'cf.category = :category',
            'cf.deleted_at IS NULL',
        ];
        $params = [
            'customer_id' => $customerId,
            'category' => $category,
        ];

        $clause = $this->buildFolderWhereClause($folderPath, $includeChildren, 'cf.', 'download');
        if ($clause) {
            $where[] = $clause['sql'];
            $params = array_merge($params, $clause['params']);
        }

        $sql = 'SELECT cf.id, cf.filename, cf.folder_path, cf.storage_key, cf.filesize
                FROM customer_files cf
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY cf.folder_path ASC, cf.filename ASC';

        return Db::query($sql, $params);
    }

    private function fetchFilesByIds(int $customerId, string $category, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $params = [
            'customer_id' => $customerId,
            'category' => $category,
        ];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'dl_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $sql = sprintf(
            'SELECT cf.id, cf.filename, cf.folder_path, cf.storage_key, cf.filesize
             FROM customer_files cf
             WHERE cf.customer_id = :customer_id
               AND cf.category = :category
               AND cf.deleted_at IS NULL
               AND cf.id IN (%s)
             ORDER BY cf.folder_path ASC, cf.filename ASC',
            implode(',', $placeholders)
        );

        return Db::query($sql, $params);
    }

    private function enforceZipLimits(array $files): void
    {
        $maxFiles = $this->zipLimit('max_files', 1000);
        if (count($files) > $maxFiles) {
            throw new RuntimeException('批量下载文件数量超过限制（最多 ' . $maxFiles . ' 个）');
        }

        $totalBytes = array_sum(array_map(static fn($item) => (int)($item['filesize'] ?? 0), $files));
        $maxBytes = $this->zipLimit('max_total_bytes', 5 * 1024 * 1024 * 1024);
        if ($totalBytes > $maxBytes) {
            throw new RuntimeException('批量下载大小超过限制（最多 ' . $this->formatBytes($maxBytes) . '）');
        }
    }

    private function buildZipArchive(array $customer, string $category, array $files, array $options): array
    {
        $folderPath = $options['folder_path'] ?? null;
        $selectionType = $options['selection_type'] ?? 'tree_node';

        $zipRoot = $this->buildZipRootName($customer, $category, $folderPath, $selectionType);
        $zipPath = tempnam(sys_get_temp_dir(), 'cust_zip_');
        if ($zipPath === false) {
            throw new RuntimeException('无法创建临时文件用于压缩包');
        }

        $zip = new ZipArchiveService($zipPath);
        try {
            foreach ($files as $file) {
                $relativeFolder = $this->resolveZipRelativePath($file['folder_path'] ?? '', $folderPath);
                $zipInternalPath = $zipRoot;
                if ($relativeFolder !== '') {
                    $zipInternalPath .= '/' . $relativeFolder;
                }
                $zipInternalPath .= '/' . $file['filename'];

                $stream = $this->storage->readStream($file['storage_key']);
                $zip->addStream($stream, $zipInternalPath);
            }
            $zip->finish();
        } catch (Throwable $e) {
            $zip->abort();
            throw $e;
        }

        return [
            'path' => $zipPath,
            'download_name' => $zipRoot . '.zip',
        ];
    }

    private function resolveZipRelativePath(string $fileFolderPath, ?string $selectedFolderPath): string
    {
        $fileFolderPath = trim((string)$fileFolderPath, '/');
        $selectedFolderPath = $selectedFolderPath === null ? null : trim((string)$selectedFolderPath, '/');

        if ($selectedFolderPath === null || $selectedFolderPath === '') {
            return $fileFolderPath;
        }

        if ($fileFolderPath === $selectedFolderPath || $fileFolderPath === '') {
            return '';
        }

        $needle = $selectedFolderPath . '/';
        if ($this->startsWith($fileFolderPath, $needle)) {
            return substr($fileFolderPath, strlen($needle));
        }

        return $fileFolderPath;
    }

    private function buildZipRootName(array $customer, string $category, ?string $folderPath, string $selectionType): string
    {
        $shortName = $this->normalizeCustomerShortName($customer['name'] ?? '', (int)$customer['id']);
        $alias = $this->resolveZipAlias($category, $folderPath, $selectionType);
        $alias = $this->sanitizeFilenameBase($alias);
        if ($alias === '') {
            $alias = '资料';
        }
        return $shortName . '-' . $alias . '-' . date('Ymd');
    }

    private function resolveZipAlias(string $category, ?string $folderPath, string $selectionType): string
    {
        if ($selectionType === 'selection') {
            return '所选文件';
        }
        if ($folderPath === null || $folderPath === '') {
            return $this->getCategoryLabel($category);
        }
        $segments = explode('/', $folderPath);
        $last = end($segments);
        return $last ?: $this->getCategoryLabel($category);
    }

    private function normalizeIdList($ids): array
    {
        if ($ids === null) {
            return [];
        }
        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }
        $normalized = [];
        foreach ($ids as $id) {
            $int = (int)$id;
            if ($int > 0) {
                $normalized[] = $int;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private function zipLimit(string $key, int $default): int
    {
        $value = $this->zipLimits[$key] ?? null;
        if (is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }

    /**
     * 检查FFmpeg是否可用
     * @return bool
     */
    private function isFFmpegAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        
        // 检查FFmpeg命令是否可用
        $command = escapeshellcmd('ffmpeg');
        $output = [];
        $returnVar = 0;
        @exec($command . ' -version 2>&1', $output, $returnVar);
        $available = ($returnVar === 0 && !empty($output));
        
        if (!$available) {
            error_log('[CustomerFileService] FFmpeg不可用，无法进行音频格式转换');
        }
        
        return $available;
    }

    /**
     * 将音频文件转换为MP3格式（支持多种输入格式）
     * @param string $audioFilePath 音频文件路径
     * @param string $sourceFormat 源文件格式（webm, wav, ogg等）
     * @return string|null 转换后的MP3文件路径，失败时返回null
     */
    private function convertAudioToMp3(string $audioFilePath, string $sourceFormat = 'webm'): ?string
    {
        if (!$this->isFFmpegAvailable()) {
            error_log('[CustomerFileService] FFmpeg不可用，跳过音频转换');
            return null;
        }
        
        if (!file_exists($audioFilePath)) {
            error_log('[CustomerFileService] 源文件不存在: ' . $audioFilePath);
            return null;
        }
        
        // 创建临时MP3文件
        $mp3FilePath = tempnam(sys_get_temp_dir(), 'audio_convert_') . '.mp3';
        
        // FFmpeg转换命令（支持多种输入格式：webm, wav, ogg, m4a, aac等）
        // -i: 输入文件
        // -vn: 不包含视频（纯音频）
        // -acodec libmp3lame: 使用MP3编码器
        // -ar 44100: 采样率44.1kHz（CD质量）
        // -ac 2: 立体声
        // -b:a 128k: 音频比特率128kbps（平衡质量和文件大小）
        // -y: 覆盖输出文件
        // 注意：FFmpeg会自动识别输入格式，无需指定
        $command = sprintf(
            'ffmpeg -i %s -vn -acodec libmp3lame -ar 44100 -ac 2 -b:a 128k -y %s 2>&1',
            escapeshellarg($audioFilePath),
            escapeshellarg($mp3FilePath)
        );
        
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($mp3FilePath)) {
            $errorMsg = implode("\n", $output);
            error_log('[CustomerFileService] FFmpeg转换失败 (' . $sourceFormat . ' -> mp3): ' . $errorMsg);
            if (file_exists($mp3FilePath)) {
                @unlink($mp3FilePath);
            }
            return null;
        }
        
        // 检查转换后的文件大小
        $mp3Size = filesize($mp3FilePath);
        if ($mp3Size === 0 || $mp3Size === false) {
            error_log('[CustomerFileService] 转换后的MP3文件大小为0或无效');
            @unlink($mp3FilePath);
            return null;
        }
        
        error_log(sprintf(
            '[CustomerFileService] 音频转换成功 (%s -> mp3): %s -> %s bytes',
            $sourceFormat,
            $this->formatBytes(filesize($audioFilePath)),
            $this->formatBytes($mp3Size)
        ));
        
        return $mp3FilePath;
    }
}

