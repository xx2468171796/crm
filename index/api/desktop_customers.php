<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 客户详情 API
 * 
 * GET ?id=123 - 获取客户详情
 * POST ?action=update_alias - 更新客户别名
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/constants.php';

// 认证
$user = desktop_auth_require();

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

// 处理 POST 请求
$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_alias') {
    handleUpdateAlias($user);
    exit;
}

$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '客户ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取客户基本信息
    $customer = Db::queryOne("
        SELECT 
            c.id, c.name, c.group_code, c.customer_group,
            c.mobile, c.alias, c.gender, c.age,
            c.create_time, c.update_time
        FROM customers c
        WHERE c.id = ? AND c.deleted_at IS NULL
    ", [$customerId]);
    
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '客户不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取客户的项目列表
    // 非管理员只能看到分配给自己的项目
    if ($isManager) {
        $projects = Db::query("
            SELECT 
                p.id, p.project_code, p.project_name, p.current_status,
                p.create_time, p.update_time
            FROM projects p
            WHERE p.customer_id = ? AND p.deleted_at IS NULL
            ORDER BY p.update_time DESC
            LIMIT 50
        ", [$customerId]);
    } else {
        $projects = Db::query("
            SELECT 
                p.id, p.project_code, p.project_name, p.current_status,
                p.create_time, p.update_time
            FROM projects p
            JOIN project_tech_assignments pta ON pta.project_id = p.id
            WHERE p.customer_id = ? AND p.deleted_at IS NULL AND pta.tech_user_id = ?
            ORDER BY p.update_time DESC
            LIMIT 50
        ", [$customerId, $user['id']]);
    }
    
    $projectList = [];
    foreach ($projects as $project) {
        $projectList[] = [
            'id' => (int)$project['id'],
            'project_code' => $project['project_code'],
            'project_name' => $project['project_name'],
            'current_status' => $project['current_status'],
            'create_time' => $project['create_time'] ? date('Y-m-d', $project['create_time']) : null,
            'update_time' => $project['update_time'] ? date('Y-m-d H:i', $project['update_time']) : null,
        ];
    }
    
    // 统计
    $stats = [
        'total_projects' => count($projectList),
        'in_progress' => 0,
        'completed' => 0,
    ];
    
    foreach ($projectList as $p) {
        // 使用 canManualComplete 函数判断是否为完工状态
        if (in_array($p['current_status'], ['设计完工', '设计评价']) || !empty($p['completed_at'])) {
            $stats['completed']++;
        } else if (!empty($p['current_status'])) {
            $stats['in_progress']++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'customer' => [
                'id' => (int)$customer['id'],
                'name' => $customer['name'],
                'group_code' => $customer['group_code'],
                'group_name' => $customer['alias'] ?? '',
                'customer_group' => $customer['customer_group'],
                'phone' => $customer['mobile'],
                'email' => '',
                'address' => '',
                'remark' => '',
                'create_time' => $customer['create_time'] ? date('Y-m-d H:i', $customer['create_time']) : null,
            ],
            'projects' => $projectList,
            'stats' => $stats,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_customers 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

/**
 * 更新客户别名
 */
function handleUpdateAlias($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $customerId = (int)($input['customer_id'] ?? 0);
    $alias = trim($input['alias'] ?? '');
    
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '客户ID无效'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 更新别名
    $now = time();
    Db::execute(
        "UPDATE customers SET alias = ?, update_time = ? WHERE id = ?",
        [$alias ?: null, $now, $customerId]
    );
    
    echo json_encode([
        'success' => true,
        'message' => '别名已更新',
    ], JSON_UNESCAPED_UNICODE);
}
