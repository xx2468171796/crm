<?php
/**
 * 迁移 customer_files 历史对象存储 key：
 *  - 识别旧格式：customer/{customerId}/{uuidHex32.ext}
 *  - 迁移到 CustomerFileService 的统一规则：
 *      customer/{customerId}/{MMDD-客户简称}/{客户文件|公司文件}/{folder_path?}/{filename}
 *
 * 说明：
 *  - 默认 dry-run：只打印映射，不复制对象、不更新 DB
 *  - apply 模式会 copyObject + UPDATE customer_files.storage_key
 *  - 可选 delete_old=1 删除旧对象（高危）
 *
 * 用法：
 *  php scripts/migrate_customer_files_storage_keys.php
 *  php scripts/migrate_customer_files_storage_keys.php --apply=1 --limit=200
 *  php scripts/migrate_customer_files_storage_keys.php --apply=1 --delete_old=1 --customer_id=123
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $item) {
        if (strpos($item, '--') !== 0) {
            continue;
        }
        $item = substr($item, 2);
        if (strpos($item, '=') !== false) {
            [$k, $v] = explode('=', $item, 2);
            $args[$k] = $v;
        } else {
            $args[$item] = '1';
        }
    }
    return $args;
}

function normalizeCustomerShortName(?string $name, int $customerId): string
{
    $normalized = preg_replace('/\s+/u', '', (string)$name);
    $normalized = preg_replace('/[^0-9A-Za-z\p{Han}-]+/u', '', $normalized);
    if (!$normalized) {
        $normalized = 'customer-' . $customerId;
    }
    return mb_substr($normalized, 0, 20, 'UTF-8');
}

function sanitizePathSegment(string $segment): string
{
    $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment);
    $segment = str_replace(['..', '/', '\\'], '-', $segment);
    return $segment;
}

function categoryDir(string $category): string
{
    return match ($category) {
        'internal_solution' => '公司文件',
        default => '客户文件',
    };
}

function isLegacyCustomerKey(string $key): bool
{
    if (strpos($key, 'customer/') !== 0) {
        return false;
    }
    $parts = explode('/', $key);
    if (count($parts) !== 3) {
        return false;
    }
    if ($parts[0] !== 'customer') {
        return false;
    }
    if (!ctype_digit((string)$parts[1])) {
        return false;
    }

    // 旧格式常见：customer/{id}/{uuid}.{ext}
    // - uuid 可能为 32 hex 或 36 位带横线
    // - 少量历史数据可能无扩展名
    $base = $parts[2];
    if (preg_match('/^[0-9a-f]{32}(\.[a-z0-9]+)?$/i', $base)) {
        return true;
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(\.[a-z0-9]+)?$/i', $base)) {
        return true;
    }
    return false;
}

$args = parseArgs($argv);
$apply = ($args['apply'] ?? '0') === '1';
$deleteOld = ($args['delete_old'] ?? '0') === '1';
$includeDeleted = ($args['include_deleted'] ?? '0') === '1';
$report = ($args['report'] ?? '0') === '1';
$sampleSize = isset($args['samples']) ? max(0, (int)$args['samples']) : 20;
$limit = isset($args['limit']) ? max(1, (int)$args['limit']) : 0;
$customerIdFilter = isset($args['customer_id']) ? max(0, (int)$args['customer_id']) : 0;
$categoryFilter = (string)($args['category'] ?? '');

$storage = storage_provider();

$where = [
    ($includeDeleted ? '1=1' : 'cf.deleted_at IS NULL'),
    "cf.storage_key LIKE 'customer/%'",
];
$params = [];

if ($customerIdFilter > 0) {
    $where[] = 'cf.customer_id = ?';
    $params[] = $customerIdFilter;
}

if ($categoryFilter !== '') {
    $where[] = 'cf.category = ?';
    $params[] = $categoryFilter;
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        cf.id,
        cf.customer_id,
        cf.category,
        cf.filename,
        cf.folder_path,
        cf.storage_key,
        cf.uploaded_at,
        c.name AS customer_name
    FROM customer_files cf
    LEFT JOIN customers c ON c.id = cf.customer_id
    WHERE {$whereSql}
    ORDER BY cf.id ASC
";
if ($limit > 0) {
    $sql .= ' LIMIT ' . (int)$limit;
}

$rows = Db::query($sql, $params);

if ($report) {
    $total = count($rows);
    $legacy32 = 0;
    $legacy36 = 0;
    $legacyNoExt = 0;
    $newStructured = 0;
    $other = 0;

    $samples = [
        'legacy32' => [],
        'legacy36' => [],
        'legacyNoExt' => [],
        'newStructured' => [],
        'other' => [],
    ];

    foreach ($rows as $r) {
        $key = (string)($r['storage_key'] ?? '');
        $parts = explode('/', $key);
        if (count($parts) >= 5) {
            $newStructured++;
            if (count($samples['newStructured']) < $sampleSize) {
                $samples['newStructured'][] = $key;
            }
            continue;
        }
        if (count($parts) === 3 && strpos($key, 'customer/') === 0) {
            $base = $parts[2];
            if (preg_match('/^[0-9a-f]{32}\.[a-z0-9]+$/i', $base)) {
                $legacy32++;
                if (count($samples['legacy32']) < $sampleSize) {
                    $samples['legacy32'][] = $key;
                }
                continue;
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]+$/i', $base)) {
                $legacy36++;
                if (count($samples['legacy36']) < $sampleSize) {
                    $samples['legacy36'][] = $key;
                }
                continue;
            }
            if (preg_match('/^[0-9a-f]{32}$/i', $base) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $base)) {
                $legacyNoExt++;
                if (count($samples['legacyNoExt']) < $sampleSize) {
                    $samples['legacyNoExt'][] = $key;
                }
                continue;
            }
        }

        $other++;
        if (count($samples['other']) < $sampleSize) {
            $samples['other'][] = $key;
        }
    }

    echo "[REPORT] total_rows={$total} include_deleted=" . ($includeDeleted ? '1' : '0') . "\n";
    echo "[REPORT] legacy32(uuid32.ext)={$legacy32}\n";
    echo "[REPORT] legacy36(uuid36.ext)={$legacy36}\n";
    echo "[REPORT] legacyNoExt(uuidOnly)={$legacyNoExt}\n";
    echo "[REPORT] newStructured(>=5 segments)={$newStructured}\n";
    echo "[REPORT] other={$other}\n";

    foreach ($samples as $k => $list) {
        if (empty($list)) {
            continue;
        }
        echo "[SAMPLES] {$k}\n";
        foreach ($list as $s) {
            echo "  - {$s}\n";
        }
    }

    exit(0);
}

$legacyRows = [];
foreach ($rows as $r) {
    $key = (string)($r['storage_key'] ?? '');
    if (isLegacyCustomerKey($key)) {
        $legacyRows[] = $r;
    }
}

echo "[migrate_customer_files_storage_keys] mode=" . ($apply ? 'APPLY' : 'DRY_RUN') . " delete_old=" . ($deleteOld ? '1' : '0') . " legacy_count=" . count($legacyRows) . "\n";

if (empty($legacyRows)) {
    echo "No legacy rows to migrate.\n";
    exit(0);
}

$updated = 0;
$skipped = 0;
$failed = 0;

foreach ($legacyRows as $r) {
    $id = (int)$r['id'];
    $customerId = (int)$r['customer_id'];
    $category = (string)$r['category'];
    $filename = (string)($r['filename'] ?? '');
    $folderPath = (string)($r['folder_path'] ?? '');
    $oldKey = (string)($r['storage_key'] ?? '');

    if ($filename === '') {
        // 回退：从 oldKey 取扩展名生成一个可用名字
        $oldBase = basename($oldKey);
        $ext = pathinfo($oldBase, PATHINFO_EXTENSION);
        $filename = '文件-' . $id . ($ext ? ('.' . $ext) : '');
    }

    $uploadedAt = (int)($r['uploaded_at'] ?? 0);
    if ($uploadedAt <= 0) {
        $uploadedAt = time();
    }

    $shortName = normalizeCustomerShortName($r['customer_name'] ?? '', $customerId);
    $folder = date('md', $uploadedAt) . '-' . $shortName;

    $dir = categoryDir($category);

    $segments = [
        'customer',
        $customerId,
        sanitizePathSegment($folder),
        $dir,
    ];

    if ($folderPath !== '') {
        foreach (explode('/', $folderPath) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            $segments[] = sanitizePathSegment($seg);
        }
    }

    $segments[] = sanitizePathSegment($filename);
    $newKey = implode('/', $segments);
    $newKey = preg_replace('#/+#', '/', $newKey);

    // 避免覆盖：如果已有其他记录占用该 storage_key，则改为追加 id
    $existing = Db::queryOne(
        'SELECT id FROM customer_files WHERE customer_id = :customer_id AND storage_key = :storage_key AND id <> :id LIMIT 1',
        [
            'customer_id' => $customerId,
            'storage_key' => $newKey,
            'id' => $id,
        ]
    );

    if ($existing) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext ? (mb_substr($filename, 0, -mb_strlen($ext, 'UTF-8') - 1, 'UTF-8')) : $filename;
        $altName = $base . '-' . $id . ($ext ? ('.' . $ext) : '');
        $segments[count($segments) - 1] = sanitizePathSegment($altName);
        $newKey = implode('/', $segments);
        $newKey = preg_replace('#/+#', '/', $newKey);
    }

    echo "#{$id} customer={$customerId} {$oldKey} => {$newKey}\n";

    if (!$apply) {
        $updated++;
        continue;
    }

    try {
        // 先复制对象
        $ok = $storage->copyObject($oldKey, $newKey);
        if (!$ok) {
            $failed++;
            echo "  [FAIL] copyObject failed\n";
            continue;
        }

        // 再更新 DB（确保 DB 不会指向不存在的新对象）
        Db::beginTransaction();
        Db::execute(
            'UPDATE customer_files SET storage_key = :storage_key WHERE id = :id',
            [
                'storage_key' => $newKey,
                'id' => $id,
            ]
        );
        Db::commit();

        if ($deleteOld) {
            $storage->deleteObject($oldKey);
        }

        $updated++;
    } catch (Throwable $e) {
        try {
            Db::rollback();
        } catch (Throwable $ignore) {
        }
        $failed++;
        echo "  [FAIL] " . $e->getMessage() . "\n";
        continue;
    }
}

echo "Done. updated={$updated} skipped={$skipped} failed={$failed}\n";
