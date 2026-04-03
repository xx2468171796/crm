<?php

require_once __DIR__ . '/../../core/db.php';

$options = getopt('', ['rollback']);
$rollback = array_key_exists('rollback', $options);

function columnExists(string $table, string $column): bool
{
    $row = Db::queryOne("SHOW COLUMNS FROM `{$table}` LIKE :col", ['col' => $column]);
    return (bool)$row;
}

function tableExists(string $table): bool
{
    $row = Db::queryOne("SHOW TABLES LIKE :tbl", ['tbl' => $table]);
    return (bool)$row;
}

if ($rollback) {
    echo ">>> Rolling back commission settlement..." . PHP_EOL;

    if (tableExists('commission_settlement_items')) {
        Db::execute('DROP TABLE commission_settlement_items');
    }
    if (tableExists('commission_settlements')) {
        Db::execute('DROP TABLE commission_settlements');
    }
    if (tableExists('commission_rule_tiers')) {
        Db::execute('DROP TABLE commission_rule_tiers');
    }
    if (tableExists('commission_rule_sets')) {
        Db::execute('DROP TABLE commission_rule_sets');
    }

    if (tableExists('finance_receipts') && columnExists('finance_receipts', 'sales_user_id_snapshot')) {
        Db::execute('ALTER TABLE finance_receipts DROP COLUMN sales_user_id_snapshot');
    }
    if (tableExists('finance_receipts') && columnExists('finance_receipts', 'source_type')) {
        Db::execute('ALTER TABLE finance_receipts DROP COLUMN source_type');
    }
    if (tableExists('finance_receipts') && columnExists('finance_receipts', 'source_id')) {
        Db::execute('ALTER TABLE finance_receipts DROP COLUMN source_id');
    }

    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo ">>> Ensuring finance_receipts snapshot fields..." . PHP_EOL;
if (tableExists('finance_receipts') && !columnExists('finance_receipts', 'sales_user_id_snapshot')) {
    Db::execute('ALTER TABLE finance_receipts ADD COLUMN sales_user_id_snapshot int DEFAULT NULL AFTER installment_id');
    Db::execute('ALTER TABLE finance_receipts ADD INDEX idx_sales_user_id_snapshot (sales_user_id_snapshot)');
}
if (tableExists('finance_receipts') && !columnExists('finance_receipts', 'source_type')) {
    Db::execute('ALTER TABLE finance_receipts ADD COLUMN source_type varchar(30) DEFAULT NULL AFTER sales_user_id_snapshot');
    Db::execute('ALTER TABLE finance_receipts ADD INDEX idx_source_type (source_type)');
}
if (tableExists('finance_receipts') && !columnExists('finance_receipts', 'source_id')) {
    Db::execute('ALTER TABLE finance_receipts ADD COLUMN source_id bigint UNSIGNED DEFAULT NULL AFTER source_type');
    Db::execute('ALTER TABLE finance_receipts ADD INDEX idx_source_id (source_id)');
}

echo ">>> Creating commission_rule_sets table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS commission_rule_sets (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        name varchar(80) NOT NULL,
        rule_type enum("fixed","tier") NOT NULL,
        fixed_rate decimal(10,6) DEFAULT NULL,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_at int NOT NULL,
        created_by int DEFAULT NULL,
        updated_at int NOT NULL,
        updated_by int DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_rule_type (rule_type),
        KEY idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating commission_rule_tiers table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS commission_rule_tiers (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        rule_set_id bigint UNSIGNED NOT NULL,
        tier_from decimal(12,2) NOT NULL DEFAULT 0.00,
        tier_to decimal(12,2) DEFAULT NULL,
        rate decimal(10,6) NOT NULL DEFAULT 0.00,
        sort_order int NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_rule_set_id (rule_set_id),
        KEY idx_sort_order (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating commission_settlements table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS commission_settlements (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        month_key varchar(7) NOT NULL,
        user_id int NOT NULL,
        rule_set_id bigint UNSIGNED DEFAULT NULL,
        settlement_type enum("main","supplement") NOT NULL DEFAULT "main",
        parent_settlement_id bigint UNSIGNED DEFAULT NULL,
        status enum("draft","locked","paid") NOT NULL DEFAULT "draft",
        total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
        commission_amount decimal(12,2) NOT NULL DEFAULT 0.00,
        notes varchar(255) DEFAULT NULL,
        created_at int NOT NULL,
        created_by int DEFAULT NULL,
        updated_at int NOT NULL,
        updated_by int DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_month_user_type (month_key, user_id, settlement_type),
        KEY idx_month_key (month_key),
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_parent (parent_settlement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating commission_settlement_items table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS commission_settlement_items (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        settlement_id bigint UNSIGNED NOT NULL,
        receipt_id bigint UNSIGNED NOT NULL,
        received_date date NOT NULL,
        customer_id int NOT NULL,
        contract_id bigint UNSIGNED NOT NULL,
        installment_id bigint UNSIGNED NOT NULL,
        amount_applied decimal(12,2) NOT NULL DEFAULT 0.00,
        source_type varchar(30) DEFAULT NULL,
        created_at int NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_settlement_receipt (settlement_id, receipt_id),
        KEY idx_settlement_id (settlement_id),
        KEY idx_receipt_id (receipt_id),
        KEY idx_received_date (received_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo "Migration completed successfully." . PHP_EOL;
