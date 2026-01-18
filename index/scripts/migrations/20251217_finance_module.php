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
    echo ">>> Rolling back finance module tables..." . PHP_EOL;

    if (tableExists('finance_installment_change_logs')) {
        Db::execute('DROP TABLE finance_installment_change_logs');
    }
    if (tableExists('finance_collection_logs')) {
        Db::execute('DROP TABLE finance_collection_logs');
    }
    if (tableExists('finance_prepay_ledger')) {
        Db::execute('DROP TABLE finance_prepay_ledger');
    }
    if (tableExists('finance_receipts')) {
        Db::execute('DROP TABLE finance_receipts');
    }
    if (tableExists('finance_installments')) {
        Db::execute('DROP TABLE finance_installments');
    }
    if (tableExists('finance_contracts')) {
        Db::execute('DROP TABLE finance_contracts');
    }
    if (tableExists('finance_saved_views')) {
        Db::execute('DROP TABLE finance_saved_views');
    }

    if (tableExists('customers') && columnExists('customers', 'activity_tag')) {
        Db::execute('ALTER TABLE customers DROP COLUMN activity_tag');
    }

    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo ">>> Creating finance_contracts table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_contracts (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,
        contract_no varchar(64) DEFAULT NULL,
        title varchar(255) DEFAULT NULL,
        sales_user_id int NOT NULL,
        sign_date date DEFAULT NULL,

        gross_amount decimal(12,2) NOT NULL DEFAULT 0.00,
        discount_in_calc tinyint(1) NOT NULL DEFAULT 0,
        discount_type enum("amount","rate") DEFAULT NULL,
        discount_value decimal(12,4) DEFAULT NULL,
        discount_note varchar(255) DEFAULT NULL,
        net_amount decimal(12,2) NOT NULL DEFAULT 0.00,

        status varchar(20) NOT NULL DEFAULT "active",

        create_time int NOT NULL,
        update_time int NOT NULL,
        create_user_id int DEFAULT NULL,
        update_user_id int DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_customer_id (customer_id),
        KEY idx_sales_user_id (sales_user_id),
        KEY idx_sign_date (sign_date),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_installments table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_installments (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        contract_id bigint UNSIGNED NOT NULL,
        customer_id int NOT NULL,
        installment_no int NOT NULL DEFAULT 1,

        due_date date NOT NULL,
        amount_due decimal(12,2) NOT NULL DEFAULT 0.00,
        amount_paid decimal(12,2) NOT NULL DEFAULT 0.00,

        status varchar(20) NOT NULL DEFAULT "pending",

        create_time int NOT NULL,
        update_time int NOT NULL,
        create_user_id int DEFAULT NULL,
        update_user_id int DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_contract_id (contract_id),
        KEY idx_customer_id (customer_id),
        KEY idx_due_date (due_date),
        KEY idx_status (status),
        UNIQUE KEY uk_contract_installment (contract_id, installment_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_receipts table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_receipts (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,
        contract_id bigint UNSIGNED NOT NULL,
        installment_id bigint UNSIGNED NOT NULL,

        received_date date NOT NULL,
        amount_received decimal(12,2) NOT NULL DEFAULT 0.00,

        amount_applied decimal(12,2) NOT NULL DEFAULT 0.00,
        amount_overflow decimal(12,2) NOT NULL DEFAULT 0.00,

        method varchar(30) DEFAULT NULL,
        note varchar(255) DEFAULT NULL,

        create_time int NOT NULL,
        create_user_id int DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_customer_id (customer_id),
        KEY idx_contract_id (contract_id),
        KEY idx_installment_id (installment_id),
        KEY idx_received_date (received_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_prepay_ledger table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_prepay_ledger (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,

        direction enum("in","out") NOT NULL,
        amount decimal(12,2) NOT NULL DEFAULT 0.00,

        source_type enum("receipt_overflow","apply_to_installment","manual_adjust") NOT NULL,
        source_id bigint UNSIGNED DEFAULT NULL,

        note varchar(255) DEFAULT NULL,
        created_at int NOT NULL,
        created_by int DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_customer_id (customer_id),
        KEY idx_created_at (created_at),
        KEY idx_source (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_collection_logs table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_collection_logs (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,
        contract_id bigint UNSIGNED DEFAULT NULL,
        installment_id bigint UNSIGNED NOT NULL,

        actor_user_id int NOT NULL,
        action_time int NOT NULL,
        method varchar(30) DEFAULT NULL,
        result varchar(50) DEFAULT NULL,
        note varchar(255) DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_installment_id (installment_id),
        KEY idx_customer_id (customer_id),
        KEY idx_action_time (action_time),
        KEY idx_actor_user_id (actor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_installment_change_logs table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_installment_change_logs (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        installment_id bigint UNSIGNED NOT NULL,
        contract_id bigint UNSIGNED NOT NULL,
        customer_id int NOT NULL,

        actor_user_id int NOT NULL,
        change_time int NOT NULL,

        old_due_date date DEFAULT NULL,
        new_due_date date DEFAULT NULL,
        old_amount_due decimal(12,2) DEFAULT NULL,
        new_amount_due decimal(12,2) DEFAULT NULL,
        note varchar(255) DEFAULT NULL,

        PRIMARY KEY (id),
        KEY idx_installment_id (installment_id),
        KEY idx_change_time (change_time),
        KEY idx_customer_id (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Creating finance_saved_views table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_saved_views (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id int NOT NULL,
        page_key varchar(50) NOT NULL,
        name varchar(80) NOT NULL,
        filters_json text NOT NULL,
        sort_json text DEFAULT NULL,
        is_default tinyint(1) NOT NULL DEFAULT 0,
        status tinyint(1) NOT NULL DEFAULT 1,
        create_time int NOT NULL,
        update_time int NOT NULL,
        PRIMARY KEY (id),
        KEY idx_user_page (user_id, page_key),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Ensuring customers.activity_tag exists..." . PHP_EOL;
if (!columnExists('customers', 'activity_tag')) {
    Db::execute('ALTER TABLE customers ADD COLUMN activity_tag varchar(50) DEFAULT NULL AFTER demand_time_type');
}

echo "Migration completed successfully." . PHP_EOL;
