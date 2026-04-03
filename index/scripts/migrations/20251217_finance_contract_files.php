<?php

require_once __DIR__ . '/../../core/db.php';

$options = getopt('', ['rollback']);
$rollback = array_key_exists('rollback', $options);

if ($rollback) {
    echo ">>> Rolling back finance_contract_files..." . PHP_EOL;
    Db::execute('DROP TABLE IF EXISTS finance_contract_files');
    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo ">>> Creating finance_contract_files table..." . PHP_EOL;
Db::execute('CREATE TABLE IF NOT EXISTS finance_contract_files (
    id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id bigint UNSIGNED NOT NULL,
    customer_id int NOT NULL,
    file_id bigint UNSIGNED NOT NULL,
    created_by int NOT NULL,
    created_at int NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contract_file (contract_id, file_id),
    KEY idx_contract_id (contract_id),
    KEY idx_customer_id (customer_id),
    KEY idx_file_id (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

echo "Migration completed successfully." . PHP_EOL;
