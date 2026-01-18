<?php
require_once __DIR__ . '/../core/db.php';

$contractNo = $argv[1] ?? 'CON-2026-000031';
echo "查询合同: $contractNo\n\n";

$contract = Db::queryOne('SELECT id, contract_no FROM finance_contracts WHERE contract_no = ? LIMIT 1', [$contractNo]);
if (!$contract) {
    echo "合同不存在\n";
    exit;
}
$contractId = $contract['id'];
echo "合同ID: $contractId\n\n";

$installments = Db::query('SELECT id, amount_due, amount_paid FROM finance_installments WHERE contract_id = ?', [$contractId]);
echo "分期记录 (" . count($installments) . " 条):\n";
foreach ($installments as $i) {
    echo "  ID={$i['id']}, 应收={$i['amount_due']}, 已收={$i['amount_paid']}\n";
}

$receipts = Db::query('SELECT id, installment_id, method, amount_applied, received_date FROM finance_receipts WHERE contract_id = ?', [$contractId]);
echo "\n收款记录 (" . count($receipts) . " 条):\n";
if (count($receipts) == 0) {
    echo "  (无收款记录)\n";
} else {
    foreach ($receipts as $r) {
        echo "  ID={$r['id']}, 分期ID={$r['installment_id']}, 方式={$r['method']}, 金额={$r['amount_applied']}, 日期={$r['received_date']}\n";
    }
}
