<?php
/**
 * 群名称搜索API
 * 返回匹配的群名称列表及对应的合同数量
 */
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

$keyword = trim($_GET['keyword'] ?? '');

if (strlen($keyword) < 1) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    // 搜索群名称，返回匹配的群名称及合同数量
    $sql = "SELECT 
                cu.customer_group AS group_name,
                COUNT(DISTINCT c.id) AS contract_count,
                COUNT(DISTINCT cu.id) AS customer_count
            FROM customers cu
            LEFT JOIN finance_contracts c ON c.customer_id = cu.id
            WHERE cu.customer_group IS NOT NULL 
              AND cu.customer_group != ''
              AND cu.customer_group LIKE :keyword
            GROUP BY cu.customer_group
            ORDER BY contract_count DESC, group_name ASC
            LIMIT 20";
    
    $params = ['keyword' => '%' . $keyword . '%'];
    
    // 如果是销售角色，只显示自己的客户群
    if (($user['role'] ?? '') === 'sales') {
        $sql = "SELECT 
                    cu.customer_group AS group_name,
                    COUNT(DISTINCT c.id) AS contract_count,
                    COUNT(DISTINCT cu.id) AS customer_count
                FROM customers cu
                LEFT JOIN finance_contracts c ON c.customer_id = cu.id
                WHERE cu.customer_group IS NOT NULL 
                  AND cu.customer_group != ''
                  AND cu.customer_group LIKE :keyword
                  AND (cu.owner_user_id = :user_id OR c.sales_user_id = :user_id2)
                GROUP BY cu.customer_group
                ORDER BY contract_count DESC, group_name ASC
                LIMIT 20";
        $params['user_id'] = (int)($user['id'] ?? 0);
        $params['user_id2'] = (int)($user['id'] ?? 0);
    }
    
    $results = Db::query($sql, $params);
    
    $data = [];
    foreach ($results as $row) {
        $data[] = [
            'group_name' => $row['group_name'],
            'contract_count' => (int)$row['contract_count'],
            'customer_count' => (int)$row['customer_count'],
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
