<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== 开始清理操作 ===\n\n";

// 1. 删除没有合同的项目
echo "1. 删除没有合同的客户项目\n";
$beforeProjects = Db::queryOne('SELECT COUNT(*) as cnt FROM projects')['cnt'];
echo "   清理前项目数: $beforeProjects\n";

// 获取有合同的客户ID列表
$customersWithContract = Db::query('SELECT DISTINCT customer_id FROM finance_contracts');
$customerIds = array_column($customersWithContract, 'customer_id');

if (!empty($customerIds)) {
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $deleted = Db::execute("DELETE FROM projects WHERE customer_id NOT IN ($placeholders)", $customerIds);
    echo "   删除了 $deleted 个没有合同的项目\n";
} else {
    $deleted = Db::execute("DELETE FROM projects");
    echo "   删除了 $deleted 个项目（无合同客户）\n";
}

$afterProjects = Db::queryOne('SELECT COUNT(*) as cnt FROM projects')['cnt'];
echo "   清理后项目数: $afterProjects\n\n";

// 2. 删除所有技术项目分配
echo "2. 删除所有技术项目分配\n";
$beforeAssign = Db::queryOne('SELECT COUNT(*) as cnt FROM project_tech_assignments')['cnt'];
echo "   清理前分配数: $beforeAssign\n";
$deletedAssign = Db::execute("DELETE FROM project_tech_assignments");
echo "   删除了 $deletedAssign 条技术分配记录\n\n";

// 3. 删除所有技术提成数据
echo "3. 删除所有技术提成数据\n";

// 删除提成结算明细
$beforeItems = Db::queryOne('SELECT COUNT(*) as cnt FROM commission_settlement_items')['cnt'];
$deletedItems = Db::execute("DELETE FROM commission_settlement_items");
echo "   删除了 $deletedItems 条提成结算明细（原有 $beforeItems 条）\n";

// 删除提成结算
$beforeSettlements = Db::queryOne('SELECT COUNT(*) as cnt FROM commission_settlements')['cnt'];
$deletedSettlements = Db::execute("DELETE FROM commission_settlements");
echo "   删除了 $deletedSettlements 条提成结算记录（原有 $beforeSettlements 条）\n";

echo "\n=== 清理完成 ===\n";
