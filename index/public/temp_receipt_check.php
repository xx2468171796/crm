<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../core/db.php';

$sql = "SELECT c.contract_no, c.sign_date, SUM(r.amount_applied) as total_receipt, c.currency 
FROM finance_receipts r 
JOIN finance_contracts c ON r.contract_id = c.id 
WHERE r.received_date >= '2026-01-01 00:00:00' AND r.received_date <= '2026-01-31 23:59:59' 
GROUP BY c.id 
ORDER BY total_receipt DESC";
$rows = Db::query($sql);

echo "<h3>本月(2026-01)有收款的合同：</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>合同号</th><th>签约日期</th><th>本月收款金额</th><th>货币</th></tr>";
$totalTWD = 0;
$totalCNY = 0;
foreach ($rows as $row) {
    echo "<tr>";
    echo "<td>{$row['contract_no']}</td>";
    echo "<td>{$row['sign_date']}</td>";
    echo "<td>" . number_format($row['total_receipt'], 2) . "</td>";
    echo "<td>{$row['currency']}</td>";
    echo "</tr>";
    if ($row['currency'] === 'TWD') {
        $totalTWD += $row['total_receipt'];
    } else {
        $totalCNY += $row['total_receipt'];
    }
}
echo "</table>";
echo "<p><strong>合同总数: " . count($rows) . "</strong></p>";
echo "<p><strong>TWD总计: " . number_format($totalTWD, 2) . " TWD</strong></p>";
echo "<p><strong>CNY总计: " . number_format($totalCNY, 2) . " CNY</strong></p>";
