<?php

/**
 * 数据库迁移：customer_files / customer_logs / operation_logs 扩展
 *
 * 用法：
 * php 20251120_customer_files.php            # 执行迁移
 * php 20251120_customer_files.php --rollback # 回滚
 */

require_once __DIR__ . '/../../core/db.php';

$options = getopt('', ['rollback']);
$rollback = array_key_exists('rollback', $options);

if ($rollback) {
    echo ">>> Rolling back customer file tables..." . PHP_EOL;
    Db::execute('DROP TABLE IF EXISTS customer_logs');
    Db::execute('DROP TABLE IF EXISTS customer_files');
    Db::execute('ALTER TABLE operation_logs
        DROP COLUMN customer_id,
        DROP COLUMN file_id,
        DROP COLUMN description,
        DROP COLUMN extra,
        DROP COLUMN created_at,
        ADD COLUMN summary varchar(255) NULL,
        ADD COLUMN create_time int NULL');
    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo ">>> Creating customer_files table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS customer_files (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,
        category enum("client_material","internal_solution") NOT NULL DEFAULT "client_material",
        folder_path varchar(255) NOT NULL DEFAULT "",
        filename varchar(255) NOT NULL,
        storage_disk varchar(32) NOT NULL DEFAULT "local",
        storage_key varchar(512) NOT NULL,
        filesize bigint UNSIGNED NOT NULL DEFAULT 0,
        mime_type varchar(120) DEFAULT NULL,
        file_ext varchar(16) DEFAULT NULL,
        checksum_md5 char(32) DEFAULT NULL,
        preview_supported tinyint(1) NOT NULL DEFAULT 0,
        uploaded_by int NOT NULL,
        uploaded_at int NOT NULL,
        notes varchar(255) DEFAULT NULL,
        deleted_at int DEFAULT NULL,
        deleted_by int DEFAULT NULL,
        extra json DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_customer_category (customer_id, category),
        KEY idx_storage_key (storage_key(191)),
        KEY idx_uploaded_by (uploaded_by),
        KEY idx_deleted_at (deleted_at),
        KEY idx_customer_folder (customer_id, folder_path(120))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Ensuring folder_path column exists..." . PHP_EOL;
$folderColumn = Db::queryOne("SHOW COLUMNS FROM customer_files LIKE 'folder_path'");
if (!$folderColumn) {
    Db::execute('ALTER TABLE customer_files ADD COLUMN folder_path varchar(255) NOT NULL DEFAULT "" AFTER category');
}
$folderIndex = Db::queryOne("SHOW INDEX FROM customer_files WHERE Key_name = 'idx_customer_folder'");
if (!$folderIndex) {
    Db::execute('ALTER TABLE customer_files ADD KEY idx_customer_folder (customer_id, folder_path(120))');
}

echo ">>> Creating customer_logs table..." . PHP_EOL;
Db::execute('
    CREATE TABLE IF NOT EXISTS customer_logs (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id int NOT NULL,
        file_id bigint UNSIGNED DEFAULT NULL,
        action varchar(50) NOT NULL,
        actor_id int NOT NULL,
        ip varchar(45) DEFAULT NULL,
        extra json DEFAULT NULL,
        created_at int NOT NULL,
        PRIMARY KEY (id),
        KEY idx_customer_action (customer_id, action),
        KEY idx_file_id (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

echo ">>> Altering operation_logs table..." . PHP_EOL;
Db::execute('
    ALTER TABLE operation_logs
        MODIFY COLUMN id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        ADD COLUMN customer_id int NULL AFTER target_id,
        ADD COLUMN file_id bigint NULL AFTER customer_id,
        ADD COLUMN description varchar(255) NULL AFTER file_id,
        ADD COLUMN extra json NULL AFTER description,
        ADD COLUMN created_at int NOT NULL AFTER extra,
        DROP COLUMN summary,
        DROP COLUMN create_time,
        ADD KEY idx_customer_id (customer_id),
        ADD KEY idx_file_id (file_id),
        ADD KEY idx_created_at (created_at)
');

echo "Migration completed successfully." . PHP_EOL;

