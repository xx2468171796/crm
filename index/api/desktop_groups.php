<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 群列表 API
 *
 * GET /api/desktop_groups.php
 *
 * 查询参数：
 * - keyword: 搜索关键字（群名/群码）
 * - page: 页码（默认1）
 * - per_page: 每页数量（默认50，最大200）
 *
 * 响应：
 * {
 *   "success": true,
 *   "data": {
 *     "items": [...],
 *     "total": 100,
 *     "page": 1,
 *     "per_page": 50
 *   }
 * }
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

// 获取参数
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

try {
    // 构建查询条件
    $conditions = ["1=1"];
    $params = [];
    
    if ($keyword) {
        $conditions[] = "(c.group_code LIKE ? OR c.group_name LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%{$keyword}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 查询总数
    $countSql = "
        SELECT COUNT(DISTINCT c.group_code) as total
        FROM customers c
        WHERE c.group_code IS NOT NULL AND c.group_code != '' AND {$whereClause}
    ";
    $totalResult = Db::queryOne($countSql, $params);
    $total = (int)($totalResult['total'] ?? 0);
    
    // 查询群列表
    $sql = "
        SELECT 
            c.group_code,
            c.group_name,
            c.id as customer_id,
            c.name as customer_name,
            c.owner_user_id,
            u.realname as owner_name,
            c.create_time
        FROM customers c
        LEFT JOIN users u ON c.owner_user_id = u.id
        WHERE c.group_code IS NOT NULL AND c.group_code != '' AND {$whereClause}
        GROUP BY c.group_code
        ORDER BY c.create_time DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    
    $groups = Db::query($sql, $params);
    
    // 获取每个群的资源统计
    $items = [];
    foreach ($groups as $group) {
        $groupCode = $group['group_code'];
        
        // 统计资源数量
        $resourceCounts = [
            'works' => 0,
            'models' => 0,
            'customer' => 0,
        ];
        
        // 查询作品数量
        $worksCount = Db::queryOne("
            SELECT COUNT(*) as cnt FROM project_deliverables pd
            JOIN projects p ON pd.project_id = p.id
            JOIN customers c ON p.customer_id = c.id
            WHERE c.group_code = ? AND pd.deleted_at IS NULL
        ", [$groupCode]);
        $resourceCounts['works'] = (int)($worksCount['cnt'] ?? 0);
        
        $items[] = [
            'group_code' => $group['group_code'],
            'group_name' => $group['group_name'] ?: $group['customer_name'],
            'customer_id' => (int)$group['customer_id'],
            'customer_name' => $group['customer_name'],
            'owner_user_id' => (int)$group['owner_user_id'],
            'owner_name' => $group['owner_name'],
            'create_time' => (int)$group['create_time'],
            'resource_counts' => $resourceCounts,
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
    error_log('[API] desktop_groups 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}
