<?php

/**
 * 数据库迁移：群码（group_code）与技术资源元数据
 *
 * 用法：
 * php 20251220_group_code_and_tech_resources.php            # 执行迁移
 * php 20251220_group_code_and_tech_resources.php --rollback # 回滚
 *
 * 变更内容：
 * 1. customers 表添加 group_code 字段（唯一、不可变）
 * 2. 新增 group_code_sequence 表（用于并发安全生成 QYYYYMMDDNN）
 * 3. 新增 tech_resources 表（技术资源文件元数据）
 */

require_once __DIR__ . '/../../core/db.php';

$options = getopt('', ['rollback']);
$rollback = array_key_exists('rollback', $options);

if ($rollback) {
    echo ">>> Rolling back group_code and tech_resources..." . PHP_EOL;
    
    // 删除技术资源表
    Db::execute('DROP TABLE IF EXISTS tech_resources');
    echo "  - Dropped tech_resources table" . PHP_EOL;
    
    // 删除群码序列表
    Db::execute('DROP TABLE IF EXISTS group_code_sequence');
    echo "  - Dropped group_code_sequence table" . PHP_EOL;
    
    // 删除 customers 表的 group_code 字段
    try {
        $column = Db::queryOne("SHOW COLUMNS FROM customers LIKE 'group_code'");
        if ($column) {
            Db::execute('ALTER TABLE customers DROP INDEX IF EXISTS uniq_group_code');
            Db::execute('ALTER TABLE customers DROP COLUMN group_code');
            echo "  - Dropped group_code column from customers" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "  - Warning: " . $e->getMessage() . PHP_EOL;
    }
    
    echo "Rollback completed." . PHP_EOL;
    exit;
}

echo "=== Migration: Group Code & Tech Resources ===" . PHP_EOL;
echo PHP_EOL;

// 1. 为 customers 表添加 group_code 字段
echo ">>> Step 1: Adding group_code column to customers table..." . PHP_EOL;
$column = Db::queryOne("SHOW COLUMNS FROM customers LIKE 'group_code'");
if (!$column) {
    Db::execute("
        ALTER TABLE customers
        ADD COLUMN group_code VARCHAR(20) DEFAULT NULL COMMENT '群码（不可变唯一标识，格式 QYYYYMMDDNN）' AFTER customer_code
    ");
    echo "  - Added group_code column" . PHP_EOL;
    
    // 添加唯一索引
    Db::execute("
        ALTER TABLE customers
        ADD UNIQUE INDEX uniq_group_code (group_code)
    ");
    echo "  - Added unique index on group_code" . PHP_EOL;
} else {
    echo "  - group_code column already exists, skipping" . PHP_EOL;
}

// 2. 创建群码序列表（用于并发安全生成）
echo ">>> Step 2: Creating group_code_sequence table..." . PHP_EOL;
Db::execute("
    CREATE TABLE IF NOT EXISTS group_code_sequence (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        date_key DATE NOT NULL COMMENT '日期键（YYYY-MM-DD）',
        last_seq INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日最后使用的序号',
        PRIMARY KEY (id),
        UNIQUE KEY uniq_date_key (date_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='群码序列表（用于生成 QYYYYMMDDNN）'
");
echo "  - Created group_code_sequence table" . PHP_EOL;

// 3. 创建技术资源文件元数据表
echo ">>> Step 3: Creating tech_resources table..." . PHP_EOL;
Db::execute("
    CREATE TABLE IF NOT EXISTS tech_resources (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_code VARCHAR(20) NOT NULL COMMENT '群码（关联 customers.group_code）',
        asset_type ENUM('works', 'models', 'customer') NOT NULL COMMENT '资源类型：works=作品文件, models=模型文件, customer=客户文件',
        rel_path VARCHAR(512) NOT NULL COMMENT '相对路径（不含 group_code 和 asset_type 前缀）',
        filename VARCHAR(255) NOT NULL COMMENT '文件名',
        storage_disk VARCHAR(32) NOT NULL DEFAULT 's3' COMMENT '存储驱动：s3/local',
        storage_key VARCHAR(768) NOT NULL COMMENT 'MinIO/S3 对象键（完整路径）',
        filesize BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
        mime_type VARCHAR(120) DEFAULT NULL COMMENT 'MIME 类型',
        file_ext VARCHAR(16) DEFAULT NULL COMMENT '文件扩展名',
        etag VARCHAR(64) DEFAULT NULL COMMENT 'S3 ETag（用于校验）',
        checksum_sha256 CHAR(64) DEFAULT NULL COMMENT 'SHA256 校验和（可选）',
        uploaded_by INT NOT NULL COMMENT '上传人 ID',
        uploaded_at INT NOT NULL COMMENT '上传时间戳',
        updated_at INT DEFAULT NULL COMMENT '更新时间戳',
        deleted_at INT DEFAULT NULL COMMENT '软删除时间戳',
        deleted_by INT DEFAULT NULL COMMENT '删除人 ID',
        extra JSON DEFAULT NULL COMMENT '扩展字段',
        PRIMARY KEY (id),
        UNIQUE KEY uniq_group_asset_path (group_code, asset_type, rel_path(255)),
        KEY idx_group_code (group_code),
        KEY idx_asset_type (asset_type),
        KEY idx_uploaded_by (uploaded_by),
        KEY idx_deleted_at (deleted_at),
        KEY idx_storage_key (storage_key(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技术资源文件元数据表'
");
echo "  - Created tech_resources table" . PHP_EOL;

// 4. 为现有客户生成群码（可选，仅当有无群码的客户时）
echo ">>> Step 4: Generating group_code for existing customers without one..." . PHP_EOL;
$countWithout = Db::queryOne("SELECT COUNT(*) as cnt FROM customers WHERE group_code IS NULL AND deleted_at IS NULL");
$cnt = $countWithout['cnt'] ?? 0;
if ($cnt > 0) {
    echo "  - Found {$cnt} customers without group_code, generating..." . PHP_EOL;
    
    // 获取所有无群码的客户
    $customers = Db::query("SELECT id, create_time FROM customers WHERE group_code IS NULL AND deleted_at IS NULL ORDER BY id ASC");
    
    foreach ($customers as $customer) {
        // 使用创建时间的日期，如果没有则使用当前日期
        $date = $customer['create_time'] ? date('Y-m-d', $customer['create_time']) : date('Y-m-d');
        
        // 获取或创建当天序列
        $dateKey = $date;
        Db::execute("
            INSERT INTO group_code_sequence (date_key, last_seq)
            VALUES (?, 0)
            ON DUPLICATE KEY UPDATE id = id
        ", [$dateKey]);
        
        // 原子递增并获取新序号
        Db::execute("
            UPDATE group_code_sequence SET last_seq = last_seq + 1 WHERE date_key = ?
        ", [$dateKey]);
        
        $seq = Db::queryOne("SELECT last_seq FROM group_code_sequence WHERE date_key = ?", [$dateKey]);
        $seqNum = $seq['last_seq'] ?? 1;
        
        // 生成群码：QYYYYMMDDNN
        $groupCode = 'Q' . str_replace('-', '', $dateKey) . str_pad($seqNum, 2, '0', STR_PAD_LEFT);
        
        // 更新客户
        Db::execute("UPDATE customers SET group_code = ? WHERE id = ?", [$groupCode, $customer['id']]);
    }
    
    echo "  - Generated group_code for {$cnt} customers" . PHP_EOL;
} else {
    echo "  - All existing customers already have group_code, skipping" . PHP_EOL;
}

echo PHP_EOL;
echo "=== Migration completed successfully ===" . PHP_EOL;
