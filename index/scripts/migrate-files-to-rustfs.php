<?php

/**
 * 将 legacy `files` 表中的数据迁移到 `customer_files` 并上传到当前 StorageProvider。
 *
 * 用法：
 * php migrate-files-to-storage.php --batch-size=100 --resume-from=0 [--dry-run] [--customer-id=123]
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

$options = getopt('', [
    'batch-size::',
    'resume-from::',
    'dry-run',
    'customer-id::',
]);

$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : 100;
$resumeFrom = isset($options['resume-from']) ? (int)$options['resume-from'] : 0;
$dryRun = array_key_exists('dry-run', $options);
$customerFilter = isset($options['customer-id']) ? (int)$options['customer-id'] : 0;

echo "=== 客户文件迁移脚本（StorageProvider）===" . PHP_EOL;
echo "Batch Size: {$batchSize}, Resume From: {$resumeFrom}, Dry Run: " . ($dryRun ? 'YES' : 'NO') . PHP_EOL;
if ($customerFilter) {
    echo "Customer Filter: #{$customerFilter}" . PHP_EOL;
}

$storage = storage_provider();
$stats = [
    'processed' => 0,
    'uploaded' => 0,
    'skipped' => 0,
    'missing' => 0,
];

while (true) {
    $sql = 'SELECT * FROM files WHERE id > :last_id';
    $params = ['last_id' => $resumeFrom];
    if ($customerFilter) {
        $sql .= ' AND customer_id = :customer_id';
        $params['customer_id'] = $customerFilter;
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . (int)$batchSize;
    $rows = Db::query($sql, $params);
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $resumeFrom = (int)$row['id'];
        $stats['processed']++;
        $legacyPath = realpath(__DIR__ . '/../uploads/customer_' . $row['customer_id'] . '/' . $row['file_path']);

        if (!$legacyPath || !is_file($legacyPath)) {
            $stats['missing']++;
            echo "[MISS] File not found for legacy ID {$row['id']} ({$row['file_path']})" . PHP_EOL;
            continue;
        }

        $md5 = md5_file($legacyPath);
        $existing = Db::queryOne(
            'SELECT id FROM customer_files WHERE checksum_md5 = :md5 AND customer_id = :customer_id LIMIT 1',
            ['md5' => $md5, 'customer_id' => $row['customer_id']]
        );
        if ($existing) {
            $stats['skipped']++;
            continue;
        }

        if ($dryRun) {
            echo "[DRY-RUN] would migrate legacy file ID {$row['id']} for customer {$row['customer_id']}" . PHP_EOL;
            continue;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'legacy_file_');
        copy($legacyPath, $tmpPath);
        $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
        $storageKey = sprintf(
            'customer/%d/legacy-%d-%s.%s',
            $row['customer_id'],
            $row['id'],
            date('YmdHis'),
            $ext ?: 'bin'
        );
        $mime = $row['mime_type'] ?: mime_content_type($legacyPath) ?: 'application/octet-stream';

        $storageMeta = $storage->putObject($storageKey, $tmpPath, ['mime_type' => $mime]);

        Db::execute(
            'INSERT INTO customer_files
                 (customer_id, category, filename, storage_disk, storage_key, filesize, mime_type, file_ext,
                  checksum_md5, preview_supported, uploaded_by, uploaded_at, notes, extra)
             VALUES
                 (:customer_id, :category, :filename, :storage_disk, :storage_key, :filesize, :mime_type, :file_ext,
                  :checksum_md5, :preview_supported, :uploaded_by, :uploaded_at, :notes, :extra)',
            [
                'customer_id' => $row['customer_id'],
                'category' => $row['file_type'] === 'our' ? 'internal_solution' : 'client_material',
                'filename' => $row['file_name'],
                'storage_disk' => $storageMeta['disk'],
                'storage_key' => $storageMeta['storage_key'],
                'filesize' => $storageMeta['bytes'],
                'mime_type' => $mime,
                'file_ext' => $ext,
                'checksum_md5' => $md5,
                'preview_supported' => $storage->supportsPreview($mime) ? 1 : 0,
                'uploaded_by' => $row['uploader_user_id'] ?: 0,
                'uploaded_at' => $row['create_time'] ?: time(),
                'notes' => '[legacy migration]',
                'extra' => json_encode(['legacy_file_id' => $row['id']]),
            ]
        );

        $stats['uploaded']++;
        echo "[OK] migrated legacy file ID {$row['id']} to {$storageMeta['storage_key']}" . PHP_EOL;
    }
}

echo PHP_EOL . "=== Migration Finished ===" . PHP_EOL;
echo "Processed: {$stats['processed']}" . PHP_EOL;
echo "Uploaded : {$stats['uploaded']}" . PHP_EOL;
echo "Skipped  : {$stats['skipped']} (duplicate MD5)" . PHP_EOL;
echo "Missing  : {$stats['missing']} (file not found)" . PHP_EOL;

