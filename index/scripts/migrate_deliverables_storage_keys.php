<?php
/**
 * 迁移 deliverables 表历史文件：将旧前缀 deliverables/{projectCode}/{file_category}/... 迁移到新项目级路径
 *   groups/{groupCode}/{projectName}/{客户文件|作品文件|模型文件}/...
 * 并更新 deliverables.file_path 为新的 storage_key。
 *
 * 默认 dry-run：只打印计划迁移的映射，不会修改数据库，也不会复制/删除对象。
 *
 * 用法：
 *   php scripts/migrate_deliverables_storage_keys.php
 *   php scripts/migrate_deliverables_storage_keys.php --apply=1
 *   php scripts/migrate_deliverables_storage_keys.php --apply=1 --delete_old=1
 *   php scripts/migrate_deliverables_storage_keys.php --limit=200
 *   php scripts/migrate_deliverables_storage_keys.php --project_id=123
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

function parseArgs(array $argv): array {
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

function sanitizeProjectName(string $name): string {
    return preg_replace('/[\/\\:*?"<>|]/', '_', $name);
}

function sanitizeSegment(string $segment): string {
    $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment);
    $segment = str_replace(['..', '/', '\\'], '-', $segment);
    $segment = preg_replace('/[<>:"|?*]/u', '-', $segment);
    return $segment;
}

function mapFileCategoryToDir(string $fileCategory): string {
    return match ($fileCategory) {
        'customer_file' => '客户文件',
        'model_file' => '模型文件',
        default => '作品文件',
    };
}

$args = parseArgs($argv);
$apply = ($args['apply'] ?? '0') === '1';
$deleteOld = ($args['delete_old'] ?? '0') === '1';
$limit = isset($args['limit']) ? max(1, (int)$args['limit']) : 0;
$projectId = isset($args['project_id']) ? max(0, (int)$args['project_id']) : 0;

$storage = storage_provider();
$now = time();

$where = ["d.deleted_at IS NULL", "d.is_folder = 0", "d.file_path LIKE 'deliverables/%'"];
$params = [];
if ($projectId > 0) {
    $where[] = 'd.project_id = ?';
    $params[] = $projectId;
}
$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        d.id,
        d.project_id,
        d.file_category,
        d.file_path,
        p.project_name,
        p.project_code,
        c.group_code
    FROM deliverables d
    LEFT JOIN projects p ON d.project_id = p.id
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE {$whereSql}
    ORDER BY d.id ASC
";
if ($limit > 0) {
    $sql .= ' LIMIT ' . (int)$limit;
}

$rows = Db::query($sql, $params);

echo "[migrate_deliverables_storage_keys] mode=" . ($apply ? 'APPLY' : 'DRY_RUN') . " delete_old=" . ($deleteOld ? '1' : '0') . " count=" . count($rows) . "\n";

if (empty($rows)) {
    echo "No rows to migrate.\n";
    exit(0);
}

$updated = 0;
$skipped = 0;
$failed = 0;

if ($apply) {
    Db::beginTransaction();
}

try {
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $projId = (int)$r['project_id'];
        $fileCategory = (string)($r['file_category'] ?? '');
        $oldKey = (string)($r['file_path'] ?? '');

        if ($oldKey === '' || strpos($oldKey, 'deliverables/') !== 0) {
            $skipped++;
            continue;
        }

        $groupCode = (string)($r['group_code'] ?? '');
        if ($groupCode === '') {
            $groupCode = 'P' . $projId;
        }

        $projectName = (string)($r['project_name'] ?? '');
        if ($projectName === '') {
            $projectName = (string)($r['project_code'] ?? '');
        }
        if ($projectName === '') {
            $projectName = '项目' . $projId;
        }
        $projectName = sanitizeProjectName($projectName);

        $categoryDir = mapFileCategoryToDir($fileCategory);

        // 计算旧 key 的 remainder：去掉 deliverables/{projectCode}/{fileCategory}/
        $projectCode = (string)($r['project_code'] ?? '');
        $oldPrefix = '';
        if ($projectCode !== '' && $fileCategory !== '') {
            $oldPrefix = 'deliverables/' . $projectCode . '/' . $fileCategory . '/';
        }

        $remainder = '';
        if ($oldPrefix !== '' && strpos($oldKey, $oldPrefix) === 0) {
            $remainder = substr($oldKey, strlen($oldPrefix));
        } else {
            // fallback：去掉 deliverables/{projectCode}/ 再去掉第一个 segment
            if ($projectCode !== '' && strpos($oldKey, 'deliverables/' . $projectCode . '/') === 0) {
                $tmp = substr($oldKey, strlen('deliverables/' . $projectCode . '/'));
                $parts = explode('/', $tmp);
                array_shift($parts);
                $remainder = implode('/', $parts);
            }
        }

        if ($remainder === '') {
            $remainder = basename($oldKey);
        }

        // sanitize remainder segments
        $safeParts = [];
        foreach (explode('/', $remainder) as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            $safeParts[] = sanitizeSegment($seg);
        }
        $remainderSafe = implode('/', $safeParts);

        $newKey = 'groups/' . $groupCode . '/' . $projectName . '/' . $categoryDir . '/' . ltrim($remainderSafe, '/');
        $newKey = preg_replace('#/+#', '/', $newKey);

        echo "#{$id} project={$projId} {$oldKey} => {$newKey}\n";

        if (!$apply) {
            $updated++;
            continue;
        }

        // copy & (optional) delete
        $ok = $storage->copyObject($oldKey, $newKey);
        if (!$ok) {
            $failed++;
            echo "  [FAIL] copyObject failed\n";
            continue;
        }

        if ($deleteOld) {
            $storage->deleteObject($oldKey);
        }

        Db::execute(
            'UPDATE deliverables SET file_path = :file_path, update_time = :update_time WHERE id = :id',
            ['file_path' => $newKey, 'update_time' => $now, 'id' => $id]
        );

        $updated++;
    }

    if ($apply) {
        Db::commit();
    }
} catch (Throwable $e) {
    if ($apply) {
        Db::rollback();
    }
    throw $e;
}

echo "Done. updated={$updated} skipped={$skipped} failed={$failed}\n";
