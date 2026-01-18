<?php

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

// 启用输出缓冲，用于大文件上传时保持连接活跃
if (ob_get_level() === 0) {
    ob_start();
}
// 设置较长的执行时间限制
set_time_limit(600); // 10分钟
ignore_user_abort(false); // 如果客户端断开连接，停止脚本执行

// 辅助函数
function parseSize($size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int)$size;
    switch ($last) {
        case 'g': $size *= 1024;
        case 'm': $size *= 1024;
        case 'k': $size *= 1024;
    }
    return $size;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// 检查 PHP 上传配置（仅在 POST 请求时）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadMaxSize = ini_get('upload_max_filesize');
    $postMaxSize = ini_get('post_max_size');
    
    $uploadMaxBytes = parseSize($uploadMaxSize);
    $postMaxBytes = parseSize($postMaxSize);
    
    // 检查 $_FILES 是否为空但可能是配置问题
    if (empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $contentLength = (int)$_SERVER['CONTENT_LENGTH'];
        if ($contentLength > $postMaxBytes) {
            http_response_code(413);
            echo json_encode([
                'success' => false,
                'message' => "请求体大小 ({$contentLength} bytes) 超过 PHP post_max_size 限制 ({$postMaxSize})。请检查 PHP 配置。",
                'config' => [
                    'post_max_size' => $postMaxSize,
                    'upload_max_filesize' => $uploadMaxSize,
                ]
            ]);
            exit;
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customerId = 0;
if ($method === 'GET') {
    $customerId = (int)($_GET['customer_id'] ?? 0);
} else {
    $customerId = (int)($_POST['customer_id'] ?? 0);
}

if ($customerId <= 0) {
    http_response_code(400);
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
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
}

$service = new CustomerFileService();

try {
    $isTreeRequest = ($method === 'GET' && ($_GET['tree'] ?? '') === '1');
    if ($isTreeRequest) {
        $category = $_GET['category'] ?? 'client_material';
        $parentPath = $_GET['parent_path'] ?? '';
        $data = $service->getFolderTree($customerId, $user, [
            'category' => $category,
            'parent_path' => $parentPath,
        ]);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
        
        // 获取文件信息接口
        if ($action === 'get_file') {
            $fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
            if ($fileId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '文件ID无效']);
                exit;
            }

            try {
                $file = $service->getFileOrFail($fileId);
                if ($file['deleted_at']) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => '文件已删除']);
                    exit;
                }

                $customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => (int)$file['customer_id']]);
                if (!$customer) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => '客户不存在']);
                    exit;
                }

                $link = Db::queryOne(
                    'SELECT * FROM customer_links WHERE customer_id = :customer_id AND deleted_at IS NULL LIMIT 1',
                    ['customer_id' => (int)$file['customer_id']]
                );
                
                require_once __DIR__ . '/../services/CustomerFilePolicy.php';
                CustomerFilePolicy::authorize($user, $customer, 'view', $link);

                // 转换文件信息格式
                $fileInfo = [
                    'id' => (int)$file['id'],
                    'customer_id' => (int)$file['customer_id'],
                    'category' => $file['category'],
                    'filename' => $file['filename'],
                    'filesize' => (int)$file['filesize'],
                    'mime_type' => $file['mime_type'],
                    'folder_path' => $file['folder_path'] ?? '',
                    'display_folder' => ($file['folder_path'] ?? '') === '' ? '根目录' : ($file['folder_path'] ?? ''),
                    'uploaded_at' => (int)$file['uploaded_at'],
                    'uploaded_by' => (int)$file['uploaded_by'],
                    'uploaded_by_name' => $file['uploader_name'] ?? '',
                    'preview_supported' => (bool)$file['preview_supported'],
                    'notes' => $file['notes'],
                ];

                echo json_encode([
                    'success' => true,
                    'data' => $fileInfo,
                ]);
                exit;
            } catch (RuntimeException $e) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        }

        // 预览接口
        if ($action === 'preview') {
            $fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
            if ($fileId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '文件ID无效']);
                exit;
            }

            try {
                $expiresIn = isset($_GET['expires_in']) ? (int)$_GET['expires_in'] : 300;
                $previewUrl = $service->getPreviewUrl($fileId, $user, $expiresIn);
                
                if ($previewUrl === null) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => '该文件类型不支持预览']);
                    exit;
                }

                // 获取文件信息用于前端显示
                $file = Db::queryOne(
                    'SELECT cf.*, u.realname AS uploader_name
                     FROM customer_files cf
                     LEFT JOIN users u ON u.id = cf.uploaded_by
                     WHERE cf.id = :id',
                    ['id' => $fileId]
                );
                
                if (!$file) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => '文件不存在']);
                    exit;
                }

                // 转换文件信息格式
                $fileInfo = [
                    'id' => (int)$file['id'],
                    'customer_id' => (int)$file['customer_id'],
                    'category' => $file['category'],
                    'filename' => $file['filename'],
                    'filesize' => (int)$file['filesize'],
                    'mime_type' => $file['mime_type'],
                    'folder_path' => $file['folder_path'] ?? '',
                    'display_folder' => ($file['folder_path'] ?? '') === '' ? '根目录' : ($file['folder_path'] ?? ''),
                    'uploaded_at' => (int)$file['uploaded_at'],
                    'uploaded_by' => (int)$file['uploaded_by'],
                    'uploaded_by_name' => $file['uploader_name'] ?? '',
                    'preview_supported' => (bool)$file['preview_supported'],
                    'notes' => $file['notes'],
                ];

                // 如果是图片，查询同文件夹下的其他图片文件
                $siblingImages = [];
                $currentIndex = -1;
                $prevFileId = null;
                $nextFileId = null;
                
                if (strpos($file['mime_type'] ?? '', 'image/') === 0) {
                    $folderPath = $file['folder_path'] ?? '';
                    $folderCondition = $folderPath === '' 
                        ? 'cf.folder_path IS NULL OR cf.folder_path = \'\''
                        : 'cf.folder_path = :folder_path';
                    $folderParams = $folderPath === '' ? [] : ['folder_path' => $folderPath];
                    
                    // 查询同文件夹下的所有图片文件
                    $siblingFiles = Db::query(
                        "SELECT cf.id, cf.filename, cf.mime_type, cf.uploaded_at
                         FROM customer_files cf
                         WHERE cf.customer_id = :customer_id 
                           AND cf.category = :category
                           AND cf.deleted_at IS NULL
                           AND ($folderCondition)
                           AND cf.mime_type LIKE 'image/%'
                         ORDER BY cf.uploaded_at ASC, cf.id ASC",
                        array_merge([
                            'customer_id' => (int)$file['customer_id'],
                            'category' => $file['category'],
                        ], $folderParams)
                    );
                    
                    // 找到当前文件在列表中的位置
                    foreach ($siblingFiles as $index => $sibling) {
                        if ((int)$sibling['id'] === $fileId) {
                            $currentIndex = $index;
                            break;
                        }
                    }
                    
                    // 构建图片列表（包含基本信息）
                    foreach ($siblingFiles as $sibling) {
                        $siblingImages[] = [
                            'id' => (int)$sibling['id'],
                            'filename' => $sibling['filename'],
                            'mime_type' => $sibling['mime_type'],
                        ];
                    }
                    
                    // 确定上一张和下一张的ID
                    if ($currentIndex > 0) {
                        $prevFileId = (int)$siblingFiles[$currentIndex - 1]['id'];
                    }
                    if ($currentIndex >= 0 && $currentIndex < count($siblingFiles) - 1) {
                        $nextFileId = (int)$siblingFiles[$currentIndex + 1]['id'];
                    }
                }

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'file' => $fileInfo,
                        'preview_url' => $previewUrl,
                        'expires_in' => $expiresIn,
                        'sibling_images' => $siblingImages,
                        'current_index' => $currentIndex,
                        'prev_file_id' => $prevFileId,
                        'next_file_id' => $nextFileId,
                    ],
                ]);
                exit;
            } catch (RuntimeException $e) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            } catch (Throwable $e) {
                error_log('预览文件失败: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => '服务器错误']);
                exit;
            }
        }

        // 列表接口
        $filters = [
            'category' => $_GET['category'] ?? null,
            'uploader_id' => $_GET['uploader_id'] ?? null,
            'start_at' => $_GET['start_at'] ?? null,
            'end_at' => $_GET['end_at'] ?? null,
            'page' => $_GET['page'] ?? 1,
            'page_size' => $_GET['page_size'] ?? 20,
            'folder_path' => $_GET['folder_path'] ?? null,
            'include_children' => $_GET['include_children'] ?? null,
            'keyword' => $_GET['keyword'] ?? '',
        ];
        $data = $service->listFiles($customerId, $filters, $user);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    if ($method === 'POST') {
        // 记录上传请求开始信息
        $requestStartTime = microtime(true);
        $requestInfo = [
            'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? 'N/A',
            'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'N/A',
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'HTTP_CONTENT_LENGTH' => $_SERVER['HTTP_CONTENT_LENGTH'] ?? 'N/A',
            'FILES_count' => count($_FILES),
            'POST_count' => count($_POST),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'tmp_dir_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
        ];
        error_log('[CustomerFiles API] 上传请求开始: ' . json_encode($requestInfo, JSON_UNESCAPED_UNICODE));
        
        if (empty($_FILES['files'])) {
            // 检查是否是配置问题导致 $_FILES 为空
            if (!empty($_SERVER['CONTENT_LENGTH'])) {
                $contentLength = (int)$_SERVER['CONTENT_LENGTH'];
                $postMaxBytes = parseSize($postMaxSize);
                
                // 记录详细日志用于调试
                error_log(sprintf(
                    '[CustomerFiles API] $_FILES 为空但 CONTENT_LENGTH=%d bytes (%s), post_max_size=%s (%d bytes), upload_max_filesize=%s',
                    $contentLength,
                    formatBytes($contentLength),
                    $postMaxSize,
                    $postMaxBytes,
                    $uploadMaxSize
                ));
                
                // 检查临时目录空间
                $tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
                if (is_dir($tmpDir)) {
                    $freeSpace = disk_free_space($tmpDir);
                    error_log(sprintf(
                        '[CustomerFiles API] 临时目录: %s, 可用空间: %s',
                        $tmpDir,
                        formatBytes($freeSpace)
                    ));
                }
                
                if ($contentLength > $postMaxBytes) {
                    http_response_code(413);
                    echo json_encode([
                        'success' => false,
                        'message' => "请求体大小 (" . formatBytes($contentLength) . ") 超过 PHP post_max_size 限制 ({$postMaxSize})。请检查 PHP 配置。",
                        'config' => [
                            'post_max_size' => $postMaxSize,
                            'upload_max_filesize' => $uploadMaxSize,
                        ]
                    ]);
                    exit;
                }
            } else {
                // 记录没有 CONTENT_LENGTH 的情况
                error_log('[CustomerFiles API] $_FILES 为空且 CONTENT_LENGTH 也为空');
            }
            throw new RuntimeException('请选择要上传的文件');
        }

        // 检查文件上传错误
        $uploadErrors = [];
        $totalSize = 0;
        foreach ($_FILES['files']['error'] as $index => $error) {
            $fileName = $_FILES['files']['name'][$index] ?? "文件 #{$index}";
            $fileSize = $_FILES['files']['size'][$index] ?? 0;
            
            if ($error !== UPLOAD_ERR_OK) {
                $errorMsg = '';
                switch ($error) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = "文件大小超过限制";
                        // 记录详细的大小信息
                        error_log(sprintf(
                            '[CustomerFiles API] 文件大小超限: %s, 大小=%d bytes (%s), upload_max_filesize=%s (%d bytes), post_max_size=%s',
                            $fileName,
                            $fileSize,
                            formatBytes($fileSize),
                            $uploadMaxSize,
                            $uploadMaxBytes,
                            $postMaxSize
                        ));
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errorMsg = "文件只上传了一部分";
                        error_log(sprintf('[CustomerFiles API] 文件部分上传: %s, 大小=%d bytes', $fileName, $fileSize));
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = "没有文件被上传";
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errorMsg = "缺少临时文件夹";
                        error_log(sprintf('[CustomerFiles API] 缺少临时目录: %s', $fileName));
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $errorMsg = "文件写入失败";
                        error_log(sprintf('[CustomerFiles API] 文件写入失败: %s, 大小=%d bytes', $fileName, $fileSize));
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $errorMsg = "文件上传被扩展阻止";
                        error_log(sprintf('[CustomerFiles API] 扩展阻止上传: %s', $fileName));
                        break;
                    default:
                        $errorMsg = "未知错误 (错误代码: {$error})";
                        error_log(sprintf('[CustomerFiles API] 未知上传错误: %s, 错误代码=%d, 大小=%d bytes', $fileName, $error, $fileSize));
                }
                $uploadErrors[] = "{$fileName}: {$errorMsg}";
            } else {
                $totalSize += $fileSize;
            }
        }

        // 记录成功接收到的文件信息
        error_log(sprintf(
            '[CustomerFiles API] 成功接收到 %d 个文件，总大小: %s',
            count($_FILES['files']['name']),
            formatBytes($totalSize)
        ));

        if (!empty($uploadErrors)) {
            // 如果是大小限制错误，返回更详细的信息
            if (strpos(implode(' ', $uploadErrors), '超过限制') !== false) {
                http_response_code(413);
                $errorDetails = [];
                foreach ($uploadErrors as $err) {
                    if (strpos($err, '超过限制') !== false) {
                        $errorDetails[] = $err;
                    }
                }
                $errorMessage = "文件大小超过服务器限制。\n";
                if (!empty($errorDetails)) {
                    $errorMessage .= "详情：" . implode('; ', $errorDetails) . "\n";
                }
                $errorMessage .= "总大小：" . formatBytes($totalSize);
                
                echo json_encode([
                    'success' => false,
                    'message' => $errorMessage,
                    'config' => [
                        'post_max_size' => $postMaxSize,
                        'upload_max_filesize' => $uploadMaxSize,
                    ]
                ]);
                exit;
            }
            throw new RuntimeException("文件上传失败：" . implode('; ', $uploadErrors));
        }

        $folderPaths = $_POST['folder_paths'] ?? [];
        if (!is_array($folderPaths)) {
            $folderPaths = ($folderPaths === null || $folderPaths === '') ? [] : [$folderPaths];
        }

        $payload = [
            'category' => $_POST['category'] ?? 'client_material',
            'notes' => $_POST['notes'] ?? '',
            'folder_paths' => $folderPaths,
            'folder_root' => $_POST['folder_root'] ?? '',
            'upload_mode' => $_POST['upload_mode'] ?? '',
            'upload_source' => $_POST['upload_source'] ?? '', // 支持 first_contact 或 objection
        ];
        
        // 记录开始处理文件的时间
        $processStartTime = microtime(true);
        error_log(sprintf(
            '[CustomerFiles API] 开始处理文件上传，客户ID: %d, 文件数: %d, 总大小: %s',
            $customerId,
            count($_FILES['files']['name']),
            formatBytes($totalSize)
        ));
        
        // 刷新输出缓冲，确保浏览器知道连接仍然活跃
        if (ob_get_level() > 0) {
            ob_flush();
            flush();
        }
        
        $created = $service->uploadFiles($customerId, $user, $_FILES['files'], $payload, function($fileIndex, $totalFiles, $fileName) use ($processStartTime) {
            // 每处理一个文件后刷新输出缓冲
            if (ob_get_level() > 0) {
                ob_flush();
                flush();
            }
            $elapsed = microtime(true) - $processStartTime;
            error_log(sprintf(
                '[CustomerFiles API] 已处理文件 %d/%d: %s (耗时: %.2f秒)',
                $fileIndex + 1,
                $totalFiles,
                $fileName,
                $elapsed
            ));
        });
        
        // 记录处理完成的时间
        $processDuration = microtime(true) - $processStartTime;
        $requestDuration = microtime(true) - $requestStartTime;
        error_log(sprintf(
            '[CustomerFiles API] 文件处理完成，耗时: %.2f秒，总请求耗时: %.2f秒，成功上传 %d 个文件',
            $processDuration,
            $requestDuration,
            count($created)
        ));
        
        echo json_encode([
            'success' => true,
            'message' => '上传成功',
            'files' => $created,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
} catch (Exception $e) {
    $message = $e->getMessage();
    $statusCode = 400;
    
    // 如果是文件大小相关的错误，返回 413 状态码和配置信息
    if (strpos($message, '超出大小限制') !== false || 
        strpos($message, '超过限制') !== false ||
        strpos($message, '文件太大') !== false ||
        strpos($message, '文件总容量超过限制') !== false ||
        strpos($message, '单次上传总大小不可超过') !== false ||
        strpos($message, '单次最多上传') !== false) {
        $statusCode = 413;
        $uploadMaxSize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'config' => [
                'post_max_size' => $postMaxSize,
                'upload_max_filesize' => $uploadMaxSize,
            ]
        ]);
    } else {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
        ]);
    }
}

