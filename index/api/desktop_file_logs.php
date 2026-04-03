<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件同步日志 API
 * 
 * GET /api/desktop_file_logs.php
 * 
 * 查询参数：
 * - operation: upload|download (可选)
 * - page: 页码
 * - per_page: 每页数量
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

// 参数
$operation = $_GET['operation'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

try {
    // 构建查询条件
    $conditions = [];
    $params = [];
    
    // 非管理员只能看自己的日志
    if (!$isManager) {
        $conditions[] = "user_id = ?";
        $params[] = $user['id'];
    }
    
    // 操作类型筛选
    if ($operation && in_array($operation, ['upload', 'download'])) {
        $conditions[] = "operation = ?";
        $params[] = $operation;
    }
    
    $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM file_sync_logs $whereClause";
    $countResult = Db::queryOne($countSql, $params);
    $total = (int)($countResult['total'] ?? 0);
    
    // 查询数据
    $offset = ($page - 1) * $perPage;
    $sql = "
        SELECT 
            l.id,
            l.filename,
            l.operation,
            l.status,
            l.size,
            l.folder_type,
            l.error_message,
            l.create_time,
            p.project_name
        FROM file_sync_logs l
        LEFT JOIN projects p ON l.project_id = p.id
        $whereClause
        ORDER BY l.create_time DESC
        LIMIT $perPage OFFSET $offset
    ";
    
    $logs = Db::query($sql, $params);
    
    // 格式化数据
    $items = [];
    foreach ($logs as $log) {
        $items[] = [
            'id' => (int)$log['id'],
            'filename' => $log['filename'],
            'operation' => $log['operation'],
            'status' => $log['status'],
            'size' => (int)$log['size'],
            'project_name' => $log['project_name'] ?? '未知项目',
            'folder_type' => $log['folder_type'] ?? '未知',
            'error_message' => $log['error_message'],
            'created_at' => date('Y-m-d H:i', $log['create_time']),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 如果表不存在，返回空数据
    if (strpos($e->getMessage(), "doesn't exist") !== false || 
        strpos($e->getMessage(), "Table") !== false) {
        echo json_encode([
            'success' => true,
            'data' => [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => $perPage,
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        error_log('[API] desktop_file_logs 错误: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
    }
}
