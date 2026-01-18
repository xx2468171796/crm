<?php
require_once __DIR__ . '/../../core/db.php';

$rollback = in_array('--rollback', $argv, true);

function tableExists(string $table): bool {
    $r = Db::queryOne('SHOW TABLES LIKE :t', ['t' => $table]);
    return !empty($r);
}

function columnExists(string $table, string $column): bool {
    $r = Db::queryOne('SHOW COLUMNS FROM `' . $table . '` LIKE :c', ['c' => $column]);
    return !empty($r);
}

if ($rollback) {
    echo ">>> Rolling back manual status changes..." . PHP_EOL;

    if (tableExists('finance_status_change_logs')) {
        Db::execute('DROP TABLE finance_status_change_logs');
    }

    if (tableExists('finance_installments') && columnExists('finance_installments', 'manual_status')) {
        Db::execute('ALTER TABLE finance_installments DROP COLUMN manual_status');
    }

    if (tableExists('finance_contracts') && columnExists('finance_contracts', 'manual_status')) {
        Db::execute('ALTER TABLE finance_contracts DROP COLUMN manual_status');
    }

    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo ">>> Ensuring finance_installments.manual_status exists..." . PHP_EOL;
if (tableExists('finance_installments') && !columnExists('finance_installments', 'manual_status')) {
    Db::execute('ALTER TABLE finance_installments ADD COLUMN manual_status varchar(20) DEFAULT NULL AFTER status');
    Db::execute('ALTER TABLE finance_installments ADD INDEX idx_manual_status (manual_status)');
}

echo ">>> Ensuring finance_contracts.manual_status exists..." . PHP_EOL;
if (tableExists('finance_contracts') && !columnExists('finance_contracts', 'manual_status')) {
    Db::execute('ALTER TABLE finance_contracts ADD COLUMN manual_status varchar(20) DEFAULT NULL AFTER status');
    Db::execute('ALTER TABLE finance_contracts ADD INDEX idx_manual_status (manual_status)');
}

echo ">>> Creating finance_status_change_logs table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS finance_status_change_logs (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        entity_type enum("contract","installment") NOT NULL,
        entity_id bigint UNSIGNED NOT NULL,
        customer_id int NOT NULL,
        contract_id bigint UNSIGNED DEFAULT NULL,
        installment_id bigint UNSIGNED DEFAULT NULL,
        old_status varchar(50) DEFAULT NULL,
        new_status varchar(50) NOT NULL,
        reason varchar(255) NOT NULL,
        actor_user_id int NOT NULL,
        change_time int NOT NULL,
        PRIMARY KEY (id),
        KEY idx_entity (entity_type, entity_id),
        KEY idx_customer_id (customer_id),
        KEY idx_contract_id (contract_id),
        KEY idx_installment_id (installment_id),
        KEY idx_change_time (change_time),
        KEY idx_actor_user_id (actor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo "Migration completed successfully." . PHP_EOL;
