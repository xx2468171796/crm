<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 设计对接资料问卷 API
 *
 * 内部接口（需登录）:
 *   GET  ?action=get&customer_id=X        - 获取问卷
 *   POST ?action=save                     - 保存问卷
 *   POST ?action=generate_token           - 生成外部访问token
 *   GET  ?action=get_token&customer_id=X  - 获取外部访问token
 *
 * 外部接口（通过token访问）:
 *   GET  ?action=external_get&token=xxx   - 外部获取问卷（只读）
 *   POST ?action=external_save&token=xxx  - 外部保存问卷（需验证）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

// 外部访问的action不需要登录
$externalActions = ['external_get', 'external_save', 'external_upload_file', 'external_list_files'];

if (in_array($action, $externalActions)) {
    handleExternalAction($action);
    exit;
}

// 内部接口需要登录
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'get':
            handleGet($user);
            break;
        case 'save':
            handleSave($user);
            break;
        case 'generate_token':
            handleGenerateToken($user);
            break;
        case 'get_token':
            handleGetToken($user);
            break;
        case 'upload_file':
            handleUploadFile($user);
            break;
        case 'list_files':
            handleListFiles($user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] design_questionnaire 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ==================== 内部接口 ====================

function handleGet($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $questionnaire = Db::queryOne('
        SELECT dq.*, c.customer_group, c.name as customer_name, c.alias as customer_alias,
               u1.realname as creator_name, u2.realname as updater_name
        FROM design_questionnaires dq
        JOIN customers c ON dq.customer_id = c.id
        LEFT JOIN users u1 ON dq.create_user_id = u1.id
        LEFT JOIN users u2 ON dq.update_user_id = u2.id
        WHERE dq.customer_id = ?
    ', [$customerId]);

    if (!$questionnaire) {
        echo json_encode([
            'success' => true,
            'data' => null,
            'message' => '暂无问卷数据'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => formatQuestionnaireData($questionnaire)
    ], JSON_UNESCAPED_UNICODE);
}

function handleSave($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    $customerId = (int)($input['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查客户是否存在
    $customer = Db::queryOne('SELECT id, name FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    $fields = extractQuestionnaireFields($input);

    // 检查是否已存在
    $existing = Db::queryOne('SELECT id, version FROM design_questionnaires WHERE customer_id = ?', [$customerId]);

    if ($existing) {
        // 记录修改历史
        saveChangeHistory($existing['id'], $customerId, $fields, 'internal', $user['id'], $user['name'] ?? $user['username'] ?? '');

        // 更新
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            $sets[] = "`$key` = ?";
            $params[] = $value;
        }
        $sets[] = "`version` = ?";
        $params[] = (int)$existing['version'] + 1;
        $sets[] = "`update_user_id` = ?";
        $params[] = $user['id'];
        $sets[] = "`update_time` = ?";
        $params[] = $now;
        $params[] = $customerId;

        Db::execute('UPDATE design_questionnaires SET ' . implode(', ', $sets) . ' WHERE customer_id = ?', $params);

        echo json_encode([
            'success' => true,
            'message' => '保存成功',
            'data' => ['version' => (int)$existing['version'] + 1]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 新建
        $token = bin2hex(random_bytes(24));
        $fields['customer_id'] = $customerId;
        $fields['token'] = $token;
        $fields['version'] = 1;
        $fields['create_user_id'] = $user['id'];
        $fields['update_user_id'] = $user['id'];
        $fields['create_time'] = $now;
        $fields['update_time'] = $now;

        $columns = array_keys($fields);
        $placeholders = array_fill(0, count($columns), '?');

        Db::execute(
            'INSERT INTO design_questionnaires (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')',
            array_values($fields)
        );

        echo json_encode([
            'success' => true,
            'message' => '创建成功',
            'data' => ['version' => 1, 'token' => $token]
        ], JSON_UNESCAPED_UNICODE);
    }
}

function handleGenerateToken($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    $customerId = (int)($input['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = Db::queryOne('SELECT id, token FROM design_questionnaires WHERE customer_id = ?', [$customerId]);

    if ($existing && !empty($existing['token'])) {
        echo json_encode([
            'success' => true,
            'data' => ['token' => $existing['token']]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = bin2hex(random_bytes(24));
    $now = time();

    if ($existing) {
        Db::execute('UPDATE design_questionnaires SET token = ?, update_time = ? WHERE id = ?', [$token, $now, $existing['id']]);
    } else {
        // 创建空问卷
        Db::execute('INSERT INTO design_questionnaires (customer_id, token, version, create_user_id, update_user_id, create_time, update_time) VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$customerId, $token, $user['id'], $user['id'], $now, $now]
        );
    }

    echo json_encode([
        'success' => true,
        'data' => ['token' => $token]
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetToken($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = Db::queryOne('SELECT token FROM design_questionnaires WHERE customer_id = ?', [$customerId]);

    echo json_encode([
        'success' => true,
        'data' => ['token' => $row ? $row['token'] : null]
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 外部接口 ====================

function handleExternalAction($action) {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    if (empty($token)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = trim($input['token'] ?? '');
    }

    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少访问token'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 查询问卷
    $questionnaire = Db::queryOne('
        SELECT dq.*, c.name as customer_name, c.alias as customer_alias
        FROM design_questionnaires dq
        JOIN customers c ON dq.customer_id = c.id
        WHERE dq.token = ? AND dq.status = 1
    ', [$token]);

    if (!$questionnaire) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '问卷不存在或已禁用'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($action === 'external_get') {
            echo json_encode([
                'success' => true,
                'data' => formatQuestionnaireData($questionnaire)
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'external_upload_file') {
            handleUploadFileWithCustomer($questionnaire['customer_id'], null);
            return;
        } elseif ($action === 'external_list_files') {
            handleListFilesForCustomer($questionnaire['customer_id']);
            return;
        } elseif ($action === 'external_save') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $now = time();
            $fields = extractQuestionnaireFields($input);

            // 记录修改历史
            saveChangeHistory($questionnaire['id'], $questionnaire['customer_id'], $fields, 'external', null, '外部用户');

            $sets = [];
            $params = [];
            foreach ($fields as $key => $value) {
                $sets[] = "`$key` = ?";
                $params[] = $value;
            }
            $sets[] = "`version` = ?";
            $params[] = (int)$questionnaire['version'] + 1;
            $sets[] = "`update_time` = ?";
            $params[] = $now;
            $params[] = $questionnaire['id'];

            Db::execute('UPDATE design_questionnaires SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

            echo json_encode([
                'success' => true,
                'message' => '保存成功',
                'data' => ['version' => (int)$questionnaire['version'] + 1]
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        error_log('[API] design_questionnaire external error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 文件上传 ====================

function handleUploadFile($user) {
    $customerId = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    handleUploadFileWithCustomer($customerId, $user);
}

function handleUploadFileWithCustomer($customerId, $user) {
    // 将单个文件包装成 $_FILES['files'] 格式供 CustomerFileService 使用
    $fileKey = isset($_FILES['file']) ? 'file' : (isset($_FILES['image']) ? 'image' : null);
    if (!$fileKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '没有上传文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $srcFile = $_FILES[$fileKey];

    // 构造 $_FILES['files'] 标准数组格式
    $filesPayload = [
        'name'     => [$srcFile['name']],
        'type'     => [$srcFile['type']],
        'tmp_name' => [$srcFile['tmp_name']],
        'error'    => [$srcFile['error']],
        'size'     => [$srcFile['size']],
    ];

    // 外部用户没有登录态，使用系统用户身份（admin, id=0 → 由 create_user_id 获取）
    if (!$user) {
        $owner = Db::queryOne('SELECT create_user_id FROM customers WHERE id = ? AND deleted_at IS NULL', [$customerId]);
        $ownerId = $owner ? (int)$owner['create_user_id'] : 1;
        $user = Db::queryOne('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$user) {
            $user = ['id' => 1, 'role' => 'admin', 'realname' => '系统'];
        }
    }

    try {
        $service = new CustomerFileService();
        $payload = [
            'category'      => 'client_material',
            'notes'         => '设计问卷上传',
            'folder_paths'  => [],
            'folder_root'   => '',
            'upload_mode'   => '',
            'upload_source' => 'questionnaire',
        ];

        $created = $service->uploadFiles($customerId, $user, $filesPayload, $payload);

        // 生成访问URL（用于图片预览）
        $url = '';
        if (!empty($created)) {
            $first = $created[0];
            if (isset($first['mime_type']) && strpos($first['mime_type'], 'image/') === 0 && !empty($first['id'])) {
                $url = '/api/customer_file_stream.php?id=' . (int)$first['id'] . '&mode=preview';
            }
        }

        $firstFile = $created[0] ?? [];

        // 先返回响应给前端（文件已入库+缓存到SSD）
        $response = json_encode([
            'success' => true,
            'data' => [
                'file_id'       => $firstFile['id'] ?? 0,
                'url'           => $url,
                'filename'      => $firstFile['filename'] ?? '',
                'original_name' => $srcFile['name'],
                'storage_key'   => $firstFile['storage_key'] ?? '',
                'size'          => $firstFile['filesize'] ?? $srcFile['size'],
                'mime_type'     => $firstFile['mime_type'] ?? '',
            ]
        ], JSON_UNESCAPED_UNICODE);

        // 清除输出缓冲，先返回响应
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

        // 异步上传到S3（前端已收到响应，不阻塞）
        $asyncFiles = $service->getAsyncUploadFiles();
        if (!empty($asyncFiles)) {
            $service->processAsyncUploads();
        }

    } catch (Exception $e) {
        error_log('[API] design_questionnaire upload_file error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '文件上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

// ==================== 文件列表 ====================

function handleListFiles($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    handleListFilesForCustomer($customerId);
}

function handleListFilesForCustomer($customerId) {
    $rows = Db::query(
        'SELECT id, filename, filesize, mime_type, file_ext, uploaded_at, folder_path
         FROM customer_files
         WHERE customer_id = ? AND folder_path = ? AND deleted_at IS NULL
         ORDER BY id DESC',
        [$customerId, '收集表单']
    );

    $files = [];
    foreach ($rows as $row) {
        $isImage = strpos($row['mime_type'], 'image/') === 0;
        $files[] = [
            'id'        => (int)$row['id'],
            'filename'  => $row['filename'],
            'filesize'  => (int)$row['filesize'],
            'mime_type' => $row['mime_type'],
            'file_ext'  => $row['file_ext'],
            'is_image'  => $isImage,
            'preview_url' => $isImage ? '/api/customer_file_stream.php?id=' . (int)$row['id'] . '&mode=preview' : null,
            'download_url' => '/api/customer_file_stream.php?id=' . (int)$row['id'] . '&mode=download',
            'uploaded_at' => $row['uploaded_at'] ? date('Y-m-d H:i', $row['uploaded_at']) : null,
        ];
    }

    echo json_encode(['success' => true, 'data' => $files], JSON_UNESCAPED_UNICODE);
}

// ==================== 工具函数 ====================

function extractQuestionnaireFields($input) {
    $allowedFields = [
        'client_name', 'contact_method', 'contact_phone', 'contact_time',
        'communication_style', 'service_items', 'rendering_type',
        'total_area', 'area_unit', 'house_status',
        'include_balcony_kitchen', 'ceiling_wall_modify', 'rewire_plumbing',
        'style_maturity', 'style_description', 'color_preference',
        'design_taboo', 'reference_images',
        'household_members', 'special_function_needs', 'life_focus',
        'budget_type', 'budget_range', 'delivery_deadline',
        'has_floor_plan', 'has_site_photos', 'has_key_dimensions',
        'original_files', 'extra_notes'
    ];

    $fields = [];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $value = $input[$field];
            // JSON数组字段需要序列化
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $fields[$field] = $value;
        }
    }

    return $fields;
}

function formatQuestionnaireData($row) {
    $jsonFields = ['communication_style', 'service_items', 'rendering_type', 'life_focus', 'reference_images', 'original_files', 'contact_method'];

    $data = [
        'id' => (int)$row['id'],
        'customer_id' => (int)$row['customer_id'],
        'token' => $row['token'] ?? '',
        'customer_name' => $row['customer_name'] ?? $row['client_name'] ?? '',
        'customer_alias' => $row['customer_alias'] ?? '',
        'customer_group' => $row['customer_group'] ?? '',
        'version' => (int)($row['version'] ?? 1),
        'create_time' => $row['create_time'] ? date('Y-m-d H:i:s', $row['create_time']) : null,
        'update_time' => $row['update_time'] ? date('Y-m-d H:i:s', $row['update_time']) : null,
        'creator_name' => $row['creator_name'] ?? null,
        'updater_name' => $row['updater_name'] ?? null,
    ];

    $allFields = [
        'client_name', 'contact_method', 'contact_phone', 'contact_time',
        'communication_style', 'service_items', 'rendering_type',
        'total_area', 'area_unit', 'house_status',
        'include_balcony_kitchen', 'ceiling_wall_modify', 'rewire_plumbing',
        'style_maturity', 'style_description', 'color_preference',
        'design_taboo', 'reference_images',
        'household_members', 'special_function_needs', 'life_focus',
        'budget_type', 'budget_range', 'delivery_deadline',
        'has_floor_plan', 'has_site_photos', 'has_key_dimensions',
        'original_files', 'extra_notes'
    ];

    foreach ($allFields as $field) {
        $value = $row[$field] ?? null;
        if (in_array($field, $jsonFields) && is_string($value)) {
            $decoded = json_decode($value, true);
            $data[$field] = $decoded !== null ? $decoded : $value;
        } else {
            $data[$field] = $value;
        }
    }

    return $data;
}

function saveChangeHistory($questionnaireId, $customerId, $newFields, $source, $userId, $userName) {
    $existing = Db::queryOne('SELECT * FROM design_questionnaires WHERE id = ?', [$questionnaireId]);
    if (!$existing) return;

    $now = time();
    foreach ($newFields as $field => $newValue) {
        $oldValue = $existing[$field] ?? null;
        if ($oldValue !== $newValue) {
            try {
                Db::execute('INSERT INTO design_questionnaire_history (questionnaire_id, customer_id, field_name, old_value, new_value, change_source, change_user_id, change_user_name, create_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$questionnaireId, $customerId, $field, $oldValue, $newValue, $source, $userId, $userName, $now]
                );
            } catch (Exception $e) {
                error_log('[design_questionnaire] Failed to save history: ' . $e->getMessage());
            }
        }
    }
}
