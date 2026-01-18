<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 作品审批 API
 * 
 * GET /api/desktop_work_approval.php - 获取审批列表
 * POST /api/desktop_work_approval.php - 提交审批
 * PUT /api/desktop_work_approval.php?id=123 - 审批操作
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];

function tableColumns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $pdoObj = Db::pdo();
    $stmt = $pdoObj->query("SHOW COLUMNS FROM `{$table}`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['Field']] = strtolower((string)($row['Type'] ?? ''));
    }
    $cache[$table] = $cols;
    return $cols;
}

function isIntColumnType(?string $type): bool {
    if (!$type) return false;
    return str_contains($type, 'int');
}

$waCols = tableColumns(Db::pdo(), 'work_approvals');
$approvedAtIsInt = isIntColumnType($waCols['approved_at'] ?? null);

try {
    switch ($method) {
        case 'GET':
            handleGet($user);
            break;
        case 'POST':
            handlePost($user);
            break;
        case 'PUT':
            handlePut($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '不支持的方法']], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_work_approval 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}

function handleGet($user) {
    $status = $_GET['status'] ?? 'all';
    $role = $_GET['role'] ?? 'submitter'; // submitter 或 approver
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    
    $where = [];
    $params = [];
    
    if ($role === 'approver' && ($user['role'] === 'manager' || $user['role'] === 'admin')) {
        // 审批人视角：查看需要我审批的
        // 可以审批同部门的提交
    } else {
        // 提交人视角：只看自己的
        $where[] = 'wa.submitter_id = ?';
        $params[] = $user['id'];
    }
    
    if ($status !== 'all') {
        $where[] = 'wa.status = ?';
        $params[] = $status;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 计算总数
    $countSql = "SELECT COUNT(*) as total FROM work_approvals wa $whereClause";
    $total = Db::queryOne($countSql, $params)['total'];
    
    // 查询列表
    $offset = ($page - 1) * $perPage;
    $sql = "
        SELECT 
            wa.*,
            p.project_name,
            c.name as customer_name,
            u1.realname as submitter_name,
            u2.realname as approver_name
        FROM work_approvals wa
        LEFT JOIN projects p ON wa.project_id = p.id
        LEFT JOIN customers c ON wa.customer_id = c.id
        LEFT JOIN users u1 ON wa.submitter_id = u1.id
        LEFT JOIN users u2 ON wa.approver_id = u2.id
        $whereClause
        ORDER BY wa.submitted_at DESC
        LIMIT $offset, $perPage
    ";
    
    $items = Db::query($sql, $params);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $perPage,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handlePost($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required = ['project_id', 'customer_id', 'file_type', 'file_path', 'file_name'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => "缺少必填字段: $field"]], JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    
    $data = [
        'project_id' => (int)$input['project_id'],
        'customer_id' => (int)$input['customer_id'],
        'submitter_id' => $user['id'],
        'file_type' => $input['file_type'],
        'file_path' => $input['file_path'],
        'file_name' => $input['file_name'],
        'submit_note' => $input['submit_note'] ?? null,
        'status' => 'pending',
    ];
    
    $id = Db::insert('work_approvals', $data);
    
    // 保存版本历史
    Db::insert('work_approval_versions', [
        'approval_id' => $id,
        'version' => 1,
        'file_path' => $data['file_path'],
        'file_name' => $data['file_name'],
        'submit_note' => $data['submit_note'],
    ]);
    
    $approval = Db::queryOne("SELECT * FROM work_approvals WHERE id = ?", [$id]);
    echo json_encode(['success' => true, 'data' => $approval], JSON_UNESCAPED_UNICODE);
}

function handlePut($user) {
    global $approvedAtIsInt;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少审批ID']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $approval = Db::queryOne("SELECT * FROM work_approvals WHERE id = ?", [$id]);
    if (!$approval) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => '审批不存在']], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    // 权限检查
    $canApprove = $user['role'] === 'manager' || $user['role'] === 'admin';
    $isSubmitter = $approval['submitter_id'] == $user['id'];
    
    switch ($action) {
        case 'approve':
        case 'reject':
        case 'revision':
            if (!$canApprove) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '无审批权限']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            $status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'revision');
            if ($approvedAtIsInt) {
                Db::execute(
                    "UPDATE work_approvals SET status = ?, approver_id = ?, approval_note = ?, approved_at = ? WHERE id = ?",
                    [$status, $user['id'], $input['note'] ?? null, time(), $id]
                );
            } else {
                Db::execute(
                    "UPDATE work_approvals SET status = ?, approver_id = ?, approval_note = ?, approved_at = NOW() WHERE id = ?",
                    [$status, $user['id'], $input['note'] ?? null, $id]
                );
            }
            break;
            
        case 'resubmit':
            if (!$isSubmitter) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => '只能重新提交自己的作品']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            if (empty($input['file_path']) || empty($input['file_name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少文件信息']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            $newVersion = $approval['version'] + 1;
            Db::execute("UPDATE work_approvals SET file_path = ?, file_name = ?, submit_note = ?, version = ?, status = 'pending', approved_at = NULL WHERE id = ?",
                [$input['file_path'], $input['file_name'], $input['submit_note'] ?? null, $newVersion, $id]);
            
            // 保存版本历史
            Db::insert('work_approval_versions', [
                'approval_id' => $id,
                'version' => $newVersion,
                'file_path' => $input['file_path'],
                'file_name' => $input['file_name'],
                'submit_note' => $input['submit_note'] ?? null,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_ACTION', 'message' => '无效的操作']], JSON_UNESCAPED_UNICODE);
            return;
    }
    
    $approval = Db::queryOne("SELECT * FROM work_approvals WHERE id = ?", [$id]);
    echo json_encode(['success' => true, 'data' => $approval], JSON_UNESCAPED_UNICODE);
}
