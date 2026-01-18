<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== 为每个合同创建项目 ===\n\n";

// 获取所有合同
$contracts = Db::query('
    SELECT fc.id as contract_id, fc.customer_id, fc.contract_no, c.name as customer_name
    FROM finance_contracts fc 
    LEFT JOIN customers c ON c.id = fc.customer_id 
    ORDER BY fc.id
');

echo "合同总数: " . count($contracts) . "\n\n";

// 获取已有项目
$existingProjects = Db::query('SELECT id, customer_id, project_name FROM projects');
$projectsByCustomer = [];
foreach ($existingProjects as $p) {
    if (!isset($projectsByCustomer[$p['customer_id']])) {
        $projectsByCustomer[$p['customer_id']] = [];
    }
    $projectsByCustomer[$p['customer_id']][] = $p;
}

$created = 0;
$skipped = 0;

foreach ($contracts as $contract) {
    $customerId = $contract['customer_id'];
    $contractNo = $contract['contract_no'];
    $customerName = $contract['customer_name'];
    
    // 检查该客户是否已有项目
    $customerProjects = $projectsByCustomer[$customerId] ?? [];
    
    // 如果该客户只有一个项目且只有一个合同，跳过
    // 如果该客户有多个合同但只有一个项目，需要创建更多项目
    $customerContractCount = 0;
    foreach ($contracts as $c) {
        if ($c['customer_id'] == $customerId) {
            $customerContractCount++;
        }
    }
    
    if (count($customerProjects) >= $customerContractCount) {
        // 已有足够的项目
        $skipped++;
        continue;
    }
    
    // 需要创建新项目
    $projectCode = 'PRJ' . date('Ymd') . sprintf('%04d', $contract['contract_id']);
    $projectName = "项目-" . $contractNo;
    
    // 检查是否已存在同名项目
    $exists = Db::queryOne("SELECT id FROM projects WHERE customer_id = ? AND project_name = ?", [$customerId, $projectName]);
    if ($exists) {
        $skipped++;
        continue;
    }
    
    Db::execute("INSERT INTO projects (customer_id, project_code, project_name, current_status, created_by, create_time, update_time) VALUES (?, ?, ?, 'pending', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [$customerId, $projectCode, $projectName]);
    
    echo "创建: 客户$customerId ($customerName) - $projectName\n";
    $created++;
    
    // 更新本地缓存
    $projectsByCustomer[$customerId][] = ['id' => 0, 'customer_id' => $customerId, 'project_name' => $projectName];
}

echo "\n已创建 $created 个新项目，跳过 $skipped 个\n";

// 确认结果
$total = Db::queryOne('SELECT COUNT(*) as cnt FROM projects');
echo "当前项目总数: " . $total['cnt'] . "\n";
