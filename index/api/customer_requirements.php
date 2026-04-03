<?php
/**
 * 客户需求文档 API
 *
 * GET ?action=get&customer_id=X - 获取客户需求文档
 * POST action=save - 保存需求文档
 * GET ?action=get_customer_info&customer_id=X - 获取客户信息（用于一键读取）
 * POST action=upload_image - 上传图片
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            handleGet($user);
            break;
        case 'save':
            handleSave($user);
            break;
        case 'get_customer_info':
            handleGetCustomerInfo($user);
            break;
        case 'upload_image':
            handleUploadImage($user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] customer_requirements 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取客户需求文档
 */
function handleGet($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查客户是否存在
    $customer = Db::queryOne('SELECT id, name FROM customers WHERE id = ?', [$customerId]);
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取需求文档
    $requirement = Db::queryOne('
        SELECT cr.*,
               u1.realname as creator_name,
               u2.realname as updater_name
        FROM customer_requirements cr
        LEFT JOIN users u1 ON cr.create_user_id = u1.id
        LEFT JOIN users u2 ON cr.update_user_id = u2.id
        WHERE cr.customer_id = ?
    ', [$customerId]);

    if (!$requirement) {
        // 如果没有需求文档，返回空内容
        echo json_encode([
            'success' => true,
            'data' => [
                'customer_id' => $customerId,
                'content' => '',
                'version' => 0,
                'create_time' => null,
                'update_time' => null,
                'creator_name' => null,
                'updater_name' => null,
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int)$requirement['id'],
            'customer_id' => (int)$requirement['customer_id'],
            'content' => $requirement['content'] ?? '',
            'version' => (int)$requirement['version'],
            'create_time' => $requirement['create_time'] ? date('Y-m-d H:i:s', $requirement['create_time']) : null,
            'update_time' => $requirement['update_time'] ? date('Y-m-d H:i:s', $requirement['update_time']) : null,
            'creator_name' => $requirement['creator_name'],
            'updater_name' => $requirement['updater_name'],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存需求文档
 */
function handleSave($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $customerId = (int)($input['customer_id'] ?? 0);
    $content = $input['content'] ?? '';

    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查客户是否存在
    $customer = Db::queryOne('SELECT id FROM customers WHERE id = ?', [$customerId]);
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();

    // 检查是否已存在需求文档
    $existing = Db::queryOne('SELECT id, version, content FROM customer_requirements WHERE customer_id = ?', [$customerId]);

    if ($existing) {
        // 更新现有文档
        $newVersion = (int)$existing['version'] + 1;

        // 保存历史版本
        Db::execute('
            INSERT INTO customer_requirements_history
            (requirement_id, customer_id, content, version, create_time, create_user_id, change_note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ', [
            $existing['id'],
            $customerId,
            $existing['content'],
            $existing['version'],
            $now,
            $user['id'],
            '自动保存历史版本'
        ]);

        // 更新主表
        Db::execute('
            UPDATE customer_requirements
            SET content = ?, version = ?, update_time = ?, update_user_id = ?, last_sync_time = ?
            WHERE customer_id = ?
        ', [$content, $newVersion, $now, $user['id'], $now, $customerId]);

        echo json_encode([
            'success' => true,
            'message' => '保存成功',
            'data' => [
                'version' => $newVersion,
                'update_time' => date('Y-m-d H:i:s', $now)
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 创建新文档
        Db::execute('
            INSERT INTO customer_requirements
            (customer_id, content, version, create_time, update_time, create_user_id, update_user_id, last_sync_time)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?)
        ', [$customerId, $content, $now, $now, $user['id'], $user['id'], $now]);

        echo json_encode([
            'success' => true,
            'message' => '创建成功',
            'data' => [
                'version' => 1,
                'create_time' => date('Y-m-d H:i:s', $now)
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 获取客户信息（用于一键读取）
 */
function handleGetCustomerInfo($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取客户基本信息
    $customer = Db::queryOne('
        SELECT
            c.*,
            u.realname as owner_name,
            d.name as department_name
        FROM customers c
        LEFT JOIN users u ON c.owner_user_id = u.id
        LEFT JOIN departments d ON c.department_id = d.id
        WHERE c.id = ?
    ', [$customerId]);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取首通信息
    $firstContact = Db::queryOne('SELECT * FROM first_contact WHERE customer_id = ?', [$customerId]);

    // 获取成交信息
    $dealRecord = Db::queryOne('SELECT * FROM deal_record WHERE customer_id = ?', [$customerId]);

    // 生成Markdown格式的客户信息
    $markdown = generateCustomerMarkdown($customer, $firstContact, $dealRecord);

    echo json_encode([
        'success' => true,
        'data' => [
            'customer' => [
                'id' => (int)$customer['id'],
                'name' => $customer['name'],
                'customer_code' => $customer['customer_code'],
                'group_code' => $customer['group_code'],
                'mobile' => $customer['mobile'],
                'gender' => $customer['gender'],
                'age' => $customer['age'],
                'identity' => $customer['identity'],
                'intent_level' => $customer['intent_level'],
                'intent_summary' => $customer['intent_summary'],
                'owner_name' => $customer['owner_name'],
                'department_name' => $customer['department_name'],
            ],
            'markdown' => $markdown
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 生成客户信息的Markdown格式
 */
function generateCustomerMarkdown($customer, $firstContact, $dealRecord) {
    $md = "# 客户需求文档\n\n";

    $md .= "## 基本信息\n\n";
    $md .= "| 项目 | 内容 |\n";
    $md .= "|------|------|\n";
    $md .= "| 客户姓名 | {$customer['name']} |\n";
    $md .= "| 客户编号 | {$customer['customer_code']} |\n";
    if ($customer['group_code']) {
        $md .= "| 群码 | {$customer['group_code']} |\n";
    }
    if ($customer['mobile']) {
        $md .= "| 联系方式 | {$customer['mobile']} |\n";
    }
    if ($customer['gender']) {
        $md .= "| 性别 | {$customer['gender']} |\n";
    }
    if ($customer['age']) {
        $md .= "| 年龄 | {$customer['age']} |\n";
    }
    if ($customer['identity']) {
        $md .= "| 身份 | {$customer['identity']} |\n";
    }
    if ($customer['intent_level']) {
        $intentMap = ['high' => '高', 'medium' => '中', 'low' => '低'];
        $md .= "| 意向等级 | {$intentMap[$customer['intent_level']]} |\n";
    }
    $md .= "| 归属销售 | {$customer['owner_name']} |\n";
    if ($customer['department_name']) {
        $md .= "| 所属部门 | {$customer['department_name']} |\n";
    }

    if ($customer['intent_summary']) {
        $md .= "\n## 意向总结\n\n";
        $md .= $customer['intent_summary'] . "\n";
    }

    if ($firstContact) {
        $md .= "\n## 首通信息\n\n";
        if ($firstContact['demand_time_type']) {
            $md .= "**需求时间**: {$firstContact['demand_time_type']}\n\n";
        }
        if ($firstContact['key_questions']) {
            $md .= "**关键疑问**: {$firstContact['key_questions']}\n\n";
        }
        if ($firstContact['key_messages']) {
            $md .= "**关键信息**: {$firstContact['key_messages']}\n\n";
        }
        if ($firstContact['remark']) {
            $md .= "**备注**: {$firstContact['remark']}\n\n";
        }
    }

    $md .= "\n## 需求详情\n\n";
    $md .= "> 请在此处填写详细的客户需求...\n\n";

    $md .= "### 项目背景\n\n";
    $md .= "...\n\n";

    $md .= "### 功能需求\n\n";
    $md .= "1. \n2. \n3. \n\n";

    $md .= "### 设计要求\n\n";
    $md .= "...\n\n";

    $md .= "### 时间要求\n\n";
    $md .= "...\n\n";

    $md .= "### 预算范围\n\n";
    $md .= "...\n\n";

    $md .= "### 其他说明\n\n";
    $md .= "...\n\n";

    return $md;
}

/**
 * 上传图片
 */
function handleUploadImage($user) {
    if (!isset($_FILES['image'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '没有上传文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = $_FILES['image'];

    // 验证上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '文件上传失败: ' . $file['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 验证文件类型
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    $mimeType = mime_content_type($file['tmp_name']) ?: $file['type'];
    if (!in_array($mimeType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '不支持的文件类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 验证文件大小（最大10MB）
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '文件大小超过限制（最大10MB）'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // 生成存储路径
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'req_' . uniqid() . '_' . time() . '.' . $ext;
        $storageKey = 'requirements/' . $filename;

        // 使用存储提供者上传
        $storage = storage_provider();
        $result = $storage->putObject($storageKey, $file['tmp_name'], [
            'mime_type' => $mimeType
        ]);

        // 生成访问URL
        $url = $storage->getTemporaryUrl($storageKey, 86400 * 365); // 1年有效期
        if (!$url) {
            // 如果无法生成临时URL，返回存储键
            $url = '/api/customer_file_stream.php?key=' . urlencode($storageKey);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'url' => $url,
                'filename' => $filename,
                'storage_key' => $storageKey,
                'size' => $result['bytes']
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('[API] 图片上传失败: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '文件上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
