<?php
require_once __DIR__ . '/../core/db.php';
header('Content-Type: text/plain; charset=utf-8');

// 查看合同95的货币字段
$contract = Db::queryOne("SELECT id, contract_no, currency FROM finance_contracts WHERE contract_no = 'CON-2026-000095'");

echo "Contract 95 currency: " . ($contract['currency'] ?? 'NULL') . "\n";

// 删除自己
unlink(__FILE__);
