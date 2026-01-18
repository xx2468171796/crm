<?php
/**
 * 添加合同和分期相关字段
 * - finance_contracts: signer_user_id(签约人), currency(合同货币)
 * - finance_installments: collector_user_id(收款人), payment_method(收款方式), currency(货币)
 */

require_once __DIR__ . '/../../core/db.php';

function columnExists(string $table, string $column): bool
{
    $row = Db::queryOne("SHOW COLUMNS FROM `{$table}` LIKE :col", ['col' => $column]);
    return (bool)$row;
}

echo ">>> Adding fields to finance_contracts..." . PHP_EOL;

if (!columnExists('finance_contracts', 'signer_user_id')) {
    Db::execute('ALTER TABLE finance_contracts ADD COLUMN signer_user_id int DEFAULT NULL COMMENT "合同签约人" AFTER sales_user_id');
    echo "  - Added signer_user_id" . PHP_EOL;
}

if (!columnExists('finance_contracts', 'currency')) {
    Db::execute('ALTER TABLE finance_contracts ADD COLUMN currency varchar(10) DEFAULT "TWD" COMMENT "合同货币" AFTER net_amount');
    echo "  - Added currency" . PHP_EOL;
}

echo ">>> Adding fields to finance_installments..." . PHP_EOL;

if (!columnExists('finance_installments', 'collector_user_id')) {
    Db::execute('ALTER TABLE finance_installments ADD COLUMN collector_user_id int DEFAULT NULL COMMENT "收款人" AFTER amount_paid');
    echo "  - Added collector_user_id" . PHP_EOL;
}

if (!columnExists('finance_installments', 'payment_method')) {
    Db::execute('ALTER TABLE finance_installments ADD COLUMN payment_method varchar(30) DEFAULT NULL COMMENT "收款方式" AFTER collector_user_id');
    echo "  - Added payment_method" . PHP_EOL;
}

if (!columnExists('finance_installments', 'currency')) {
    Db::execute('ALTER TABLE finance_installments ADD COLUMN currency varchar(10) DEFAULT "TWD" COMMENT "货币" AFTER payment_method');
    echo "  - Added currency" . PHP_EOL;
}

echo "Migration completed." . PHP_EOL;
