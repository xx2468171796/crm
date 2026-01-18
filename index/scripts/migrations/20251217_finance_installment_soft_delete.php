<?php
// Add soft delete fields to finance_installments

require_once __DIR__ . '/../../core/db.php';

try {
    Db::beginTransaction();

    // Add columns if not exists (MySQL 5.7/8.0 compatibility - attempt and ignore duplicate errors)
    try {
        Db::execute('ALTER TABLE finance_installments ADD COLUMN deleted_at INT NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }

    try {
        Db::execute('ALTER TABLE finance_installments ADD COLUMN deleted_by INT NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }

    try {
        Db::execute('ALTER TABLE finance_installments ADD INDEX idx_deleted_at (deleted_at)');
    } catch (Exception $e) {
        // ignore
    }

    Db::commit();
    echo "OK\n";
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
