<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== 重命名项目为默认项目一/二/三格式 ===\n\n";

// 获取所有项目，按客户分组
$projects = Db::query('SELECT id, customer_id, project_name FROM projects ORDER BY customer_id, id');

// 按客户分组
$byCustomer = [];
foreach ($projects as $p) {
    $cid = $p['customer_id'];
    if (!isset($byCustomer[$cid])) {
        $byCustomer[$cid] = [];
    }
    $byCustomer[$cid][] = $p;
}

$numToChar = ['一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
$updated = 0;

foreach ($byCustomer as $customerId => $customerProjects) {
    $count = count($customerProjects);
    
    foreach ($customerProjects as $index => $project) {
        if ($count == 1) {
            // 只有一个项目，命名为"默认项目"
            $newName = '默认项目';
        } else {
            // 多个项目，命名为"默认项目一/二/三"
            $suffix = $index < 10 ? $numToChar[$index] : ($index + 1);
            $newName = '默认项目' . $suffix;
        }
        
        if ($project['project_name'] !== $newName) {
            Db::execute("UPDATE projects SET project_name = ? WHERE id = ?", [$newName, $project['id']]);
            echo "项目ID {$project['id']}: '{$project['project_name']}' -> '$newName'\n";
            $updated++;
        }
    }
}

echo "\n已更新 $updated 个项目名称\n";
