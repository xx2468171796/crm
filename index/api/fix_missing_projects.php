<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

// 为有合同但没有项目的客户创建项目
$missing = Db::query('
    SELECT DISTINCT fc.customer_id, c.name 
    FROM finance_contracts fc 
    LEFT JOIN customers c ON c.id = fc.customer_id
    WHERE fc.customer_id NOT IN (SELECT customer_id FROM projects)
');

echo "有合同但无项目的客户数: " . count($missing) . "\n";

foreach($missing as $row) {
    $customerId = $row['customer_id'];
    $customerName = $row['name'];
    
    // 生成项目编号
    $projectCode = 'PRJ' . date('Ymd') . sprintf('%04d', $customerId);
    
    Db::execute("INSERT INTO projects (customer_id, project_code, project_name, current_status, created_by, create_time, update_time) VALUES (?, ?, '默认项目', 'pending', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [$customerId, $projectCode]);
    
    echo "已为客户 $customerId ($customerName) 创建项目\n";
}

// 确认结果
$total = Db::queryOne('SELECT COUNT(*) as cnt FROM projects');
echo "\n当前项目总数: " . $total['cnt'];
