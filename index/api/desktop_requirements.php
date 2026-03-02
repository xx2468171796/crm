<?php
/**
 * 桌面端 - 客户需求文档 API
 *
 * GET ?action=list - 获取需求文档列表
 * GET ?action=get&customer_id=X - 获取指定客户的需求文档
 * POST action=save - 保存需求文档
 * GET ?action=sync_list&last_sync_time=X - 获取更新列表（用于同步）
 */

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handleList($user);
            break;
        case 'get':
            handleGet($user);
            break;
        case 'save':
            handleSave($user);
            break;
        case 'sync_list':
            handleSyncList($user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_requirements 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取需求文档列表（技术人员相关的项目）
 */
function handleList($user) {
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'update_time';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // 验证排序字段
    $allowedSortFields = ['update_time', 'create_time', 'customer_name'];
    if (!in_array($sortBy, $allowedSortFields)) {
        $sortBy = 'update_time';
    }

    $conditions = ["cr.id IS NOT NULL"];
    $params = [];

    // 只显示技术人员参与的项目的客户
    $conditions[] = "EXISTS (
        SELECT 1 FROM projects p
        INNER JOIN project_tech_assignments pta ON p.customer_id = c.id AND pta.project_id = p.id
        WHERE pta.tech_user_id = ?
    )";
    $params[] = $user['id'];

    // 搜索
    if ($search) {
        $conditions[] = "(c.name LIKE ? OR c.customer_code LIKE ? OR c.group_code LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = implode(' AND ', $conditions);

    // 处理排序
    $orderByClause = '';
    if ($sortBy === 'customer_name') {
        $orderByClause = "c.name {$sortOrder}";
    } else {
        $orderByClause = "cr.{$sortBy} {$sortOrder}";
    }

    $sql = "
        SELECT
            cr.id,
            cr.customer_id,
            cr.content,
            cr.version,
            cr.create_time,
            cr.update_time,
            cr.last_sync_time,
            c.name as customer_name,
            c.customer_code,
            c.group_code,
            u1.realname as creator_name,
            u2.realname as updater_name,
            (SELECT COUNT(*) FROM projects WHERE customer_id = c.id) as project_count
        FROM customer_requirements cr
        INNER JOIN customers c ON cr.customer_id = c.id
        LEFT JOIN users u1 ON cr.create_user_id = u1.id
        LEFT JOIN users u2 ON cr.update_user_id = u2.id
        WHERE {$whereClause}
        ORDER BY {$orderByClause}
        LIMIT 100
    ";

    $requirements = Db::query($sql, $params);

    $result = [];
    foreach ($requirements as $req) {
        // 计算内容预览（前200个字符）
        $contentPreview = mb_substr(strip_tags($req['content'] ?? ''), 0, 200);
        if (mb_strlen($req['content'] ?? '') > 200) {
            $contentPreview .= '...';
        }

        $result[] = [
            'id' => (int)$req['id'],
            'customer_id' => (int)$req['customer_id'],
            'customer_name' => $req['customer_name'],
            'customer_code' => $req['customer_code'],
            'group_code' => $req['group_code'],
            'content_preview' => $contentPreview,
            'version' => (int)$req['version'],
            'project_count' => (int)$req['project_count'],
            'create_time' => $req['create_time'] ? date('Y-m-d H:i:s', $req['create_time']) : null,
            'update_time' => $req['update_time'] ? date('Y-m-d H:i:s', $req['update_time']) : null,
            'last_sync_time' => $req['last_sync_time'] ? date('Y-m-d H:i:s', $req['last_sync_time']) : null,
            'creator_name' => $req['creator_name'],
            'updater_name' => $req['updater_name'],
        ];
    }

    // 统计
    $stats = Db::queryOne("
        SELECT COUNT(*) as total
        FROM customer_requirements cr
        INNER JOIN customers c ON cr.customer_id = c.id
        WHERE {$whereClause}
    ", $params);

    echo json_encode([
        'success' => true,
        'data' => [
            'requirements' => $result,
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取指定客户的需求文档
 */
function handleGet($user) {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查权限：技术人员只能查看自己参与的项目的客户
    $hasAccess = Db::queryOne("
        SELECT 1
        FROM projects p
        INNER JOIN project_tech_assignments pta ON p.id = pta.project_id
        WHERE p.customer_id = ? AND pta.tech_user_id = ?
        LIMIT 1
    ", [$customerId, $user['id']]);

    if (!$hasAccess) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权访问此客户的需求文档'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取需求文档
    $requirement = Db::queryOne('
        SELECT cr.*,
               c.name as customer_name,
               c.customer_code,
               c.group_code,
               u1.realname as creator_name,
               u2.realname as updater_name
        FROM customer_requirements cr
        INNER JOIN customers c ON cr.customer_id = c.id
        LEFT JOIN users u1 ON cr.create_user_id = u1.id
        LEFT JOIN users u2 ON cr.update_user_id = u2.id
        WHERE cr.customer_id = ?
    ', [$customerId]);

    if (!$requirement) {
        // 如果没有需求文档，返回空内容
        $customer = Db::queryOne('SELECT name, customer_code, group_code FROM customers WHERE id = ?', [$customerId]);
        echo json_encode([
            'success' => true,
            'data' => [
                'customer_id' => $customerId,
                'customer_name' => $customer['name'] ?? '',
                'customer_code' => $customer['customer_code'] ?? '',
                'group_code' => $customer['group_code'] ?? '',
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
            'customer_name' => $requirement['customer_name'],
            'customer_code' => $requirement['customer_code'],
            'group_code' => $requirement['group_code'],
            'content' => $requirement['content'] ?? '',
            'version' => (int)$requirement['version'],
            'create_time' => $requirement['create_time'] ? date('Y-m-d H:i:s', $requirement['create_time']) : null,
            'update_time' => $requirement['update_time'] ? date('Y-m-d H:i:s', $requirement['update_time']) : null,
            'last_sync_time' => $requirement['last_sync_time'] ? date('Y-m-d H:i:s', $requirement['last_sync_time']) : null,
            'creator_name' => $requirement['creator_name'],
            'updater_name' => $requirement['updater_name'],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 保存需求文档（桌面端）
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

    // 检查权限
    $hasAccess = Db::queryOne("
        SELECT 1
        FROM projects p
        INNER JOIN project_tech_assignments pta ON p.id = pta.project_id
        WHERE p.customer_id = ? AND pta.tech_user_id = ?
        LIMIT 1
    ", [$customerId, $user['id']]);

    if (!$hasAccess) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权编辑此客户的需求文档'], JSON_UNESCAPED_UNICODE);
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
            '桌面端编辑'
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
 * 获取更新列表（用于同步）
 */
function handleSyncList($user) {
    $lastSyncTime = (int)($_GET['last_sync_time'] ?? 0);

    // 获取自上次同步后更新的需求文档
    $sql = "
        SELECT
            cr.id,
            cr.customer_id,
            cr.version,
            cr.update_time,
            cr.last_sync_time,
            c.name as customer_name,
            c.customer_code
        FROM customer_requirements cr
        INNER JOIN customers c ON cr.customer_id = c.id
        WHERE cr.last_sync_time > ?
        AND EXISTS (
            SELECT 1 FROM projects p
            INNER JOIN project_tech_assignments pta ON p.id = pta.project_id
            WHERE p.customer_id = c.id AND pta.tech_user_id = ?
        )
        ORDER BY cr.last_sync_time DESC
        LIMIT 50
    ";

    $updates = Db::query($sql, [$lastSyncTime, $user['id']]);

    $result = [];
    foreach ($updates as $update) {
        $result[] = [
            'id' => (int)$update['id'],
            'customer_id' => (int)$update['customer_id'],
            'customer_name' => $update['customer_name'],
            'customer_code' => $update['customer_code'],
            'version' => (int)$update['version'],
            'update_time' => $update['update_time'] ? date('Y-m-d H:i:s', $update['update_time']) : null,
            'last_sync_time' => $update['last_sync_time'] ? date('Y-m-d H:i:s', $update['last_sync_time']) : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'updates' => $result,
            'current_time' => time(),
        ]
    ], JSON_UNESCAPED_UNICODE);
}
