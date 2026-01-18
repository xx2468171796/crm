<?php
require_once __DIR__ . '/../core/api_init.php';

require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

function json_error(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_manager_role(string $role): bool {
    return in_array($role, ['admin', 'super_admin', 'manager', 'tech_manager'], true);
}

function safe_filename(string $name): string {
    $name = trim($name);
    // 禁止路径穿越
    $name = str_replace(['../', '..\\', '/', '\\'], '', $name);
    // 替换非法字符
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = preg_replace('/[\/:*?"<>|]/u', '_', $name);
    return trim($name);
}

function normalize_storage_key_no_prefix(string $storageKey): string {
    $config = storage_config();
    $s3Config = $config['s3'] ?? [];
    $prefix = trim((string)($s3Config['prefix'] ?? ''), '/');
    $key = ltrim((string)$storageKey, '/');
    if ($prefix !== '' && strpos($key, $prefix . '/') === 0) {
        $key = substr($key, strlen($prefix) + 1);
    }
    return $key;
}

function can_manage_file(array $user, array $fileRow): bool {
    $role = (string)($user['role'] ?? '');
    $isManager = is_manager_role($role);

    // deliverables 体系：submitted_by 是上传者
    $ownerId = (int)($fileRow['submitted_by'] ?? 0);
    $approvalStatus = (string)($fileRow['approval_status'] ?? 'pending');

    // 管理员/主管：直接放行
    if ($isManager) return true;

    // 文件上传者可对未通过的文件做改动
    if ($ownerId > 0 && (int)$user['id'] === $ownerId) {
        if (in_array($approvalStatus, ['pending', 'rejected'], true)) return true;
        // approved 的文件默认只允许管理员改动
        return false;
    }

    // 有 FILE_DELETE 权限的人也可删除/批量删除
    if (Permission::hasPermission((int)$user['id'], PermissionCode::FILE_DELETE)) {
        // approved 的文件仍然需要管理员（避免越权）
        return $approvalStatus !== 'approved';
    }

    return false;
}

if (!in_array($action, ['rename', 'delete', 'batch_delete', 'rename_by_key', 'delete_by_key'], true)) {
    json_error(400, '参数错误');
}

try {
    $pdo = Db::pdo();

    if ($action === 'rename') {
        $id = (int)($input['id'] ?? 0);
        $newName = safe_filename((string)($input['new_name'] ?? ''));

        if ($id <= 0 || $newName === '') {
            json_error(400, '缺少必填参数');
        }

        $stmt = $pdo->prepare('SELECT id, deliverable_name, file_path, approval_status, is_folder, submitted_by FROM deliverables WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            json_error(404, '文件不存在');
        }
        if ((int)($file['is_folder'] ?? 0) === 1) {
            json_error(400, '暂不支持重命名文件夹');
        }
        if (!can_manage_file($user, $file)) {
            json_error(403, '无权限重命名');
        }

        $oldPath = (string)($file['file_path'] ?? '');
        $newPath = $oldPath;

        // 兼容：如果 file_path 是 URL，不做对象存储操作
        if ($oldPath !== '' && !filter_var($oldPath, FILTER_VALIDATE_URL)) {
            $oldKey = normalize_storage_key_no_prefix($oldPath);

            $pathInfo = pathinfo($oldKey);
            $dir = $pathInfo['dirname'] ?? '';
            $oldExt = $pathInfo['extension'] ?? '';
            $newExt = pathinfo($newName, PATHINFO_EXTENSION);
            if ($newExt === '' && $oldExt !== '') {
                $newName .= '.' . $oldExt;
            }
            $destKey = ($dir && $dir !== '.') ? ($dir . '/' . $newName) : $newName;

            if ($destKey !== $oldKey) {
                $storage = storage_provider();
                $copied = $storage->copyObject($oldKey, $destKey);
                if ($copied) {
                    $storage->deleteObject($oldKey);
                    $newPath = $destKey;
                } else {
                    // 复制失败：只改名称
                    $newPath = $oldKey;
                }
            }
        }

        $pdo->prepare('UPDATE deliverables SET deliverable_name = ?, file_path = ?, update_time = ? WHERE id = ?')
            ->execute([$newName, $newPath, time(), $id]);

        echo json_encode([
            'success' => true,
            'message' => '重命名成功',
            'data' => [
                'id' => $id,
                'new_name' => $newName,
                'new_path' => $newPath,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rename_by_key') {
        if (!is_manager_role((string)($user['role'] ?? ''))) {
            json_error(403, '无权限重命名');
        }

        $storageKey = (string)($input['storage_key'] ?? '');
        $newName = safe_filename((string)($input['new_name'] ?? ''));

        if ($storageKey === '' || $newName === '') {
            json_error(400, '缺少必填参数');
        }
        if (filter_var($storageKey, FILTER_VALIDATE_URL)) {
            json_error(400, 'URL 文件不支持重命名');
        }

        $oldKey = normalize_storage_key_no_prefix($storageKey);
        $pathInfo = pathinfo($oldKey);
        $dir = $pathInfo['dirname'] ?? '';
        $oldExt = $pathInfo['extension'] ?? '';
        $newExt = pathinfo($newName, PATHINFO_EXTENSION);
        if ($newExt === '' && $oldExt !== '') {
            $newName .= '.' . $oldExt;
        }
        $destKey = ($dir && $dir !== '.') ? ($dir . '/' . $newName) : $newName;

        if ($destKey === $oldKey) {
            echo json_encode([
                'success' => true,
                'message' => '重命名成功',
                'data' => [
                    'new_name' => $newName,
                    'new_path' => $destKey,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $storage = storage_provider();
        if (!$storage->copyObject($oldKey, $destKey)) {
            json_error(500, '重命名失败');
        }
        $storage->deleteObject($oldKey);

        echo json_encode([
            'success' => true,
            'message' => '重命名成功',
            'data' => [
                'new_name' => $newName,
                'new_path' => $destKey,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            json_error(400, '缺少必填参数');
        }

        $stmt = $pdo->prepare('SELECT id, deliverable_name, file_path, approval_status, is_folder, submitted_by FROM deliverables WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            json_error(404, '文件不存在');
        }
        if (!can_manage_file($user, $file)) {
            json_error(403, '无权限删除');
        }

        $now = time();
        $pdo->prepare('UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?')
            ->execute([$now, (int)$user['id'], $id]);

        // 可选：同时删除对象存储文件（只对文件生效，文件夹暂不做）
        if ((int)($file['is_folder'] ?? 0) === 0) {
            $path = (string)($file['file_path'] ?? '');
            if ($path !== '' && !filter_var($path, FILTER_VALIDATE_URL)) {
                $storage = storage_provider();
                $key = normalize_storage_key_no_prefix($path);
                $storage->deleteObject($key);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => '删除成功',
            'data' => ['id' => $id],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_by_key') {
        if (!is_manager_role((string)($user['role'] ?? ''))) {
            json_error(403, '无权限删除');
        }

        $storageKey = (string)($input['storage_key'] ?? '');
        if ($storageKey === '') {
            json_error(400, '缺少必填参数');
        }
        if (filter_var($storageKey, FILTER_VALIDATE_URL)) {
            json_error(400, 'URL 文件不支持删除');
        }

        $key = normalize_storage_key_no_prefix($storageKey);
        $storage = storage_provider();
        $storage->deleteObject($key);

        echo json_encode([
            'success' => true,
            'message' => '删除成功',
            'data' => [
                'storage_key' => $key,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'batch_delete') {
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            json_error(400, '请提供要删除的文件ID列表');
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
        if (empty($ids)) {
            json_error(400, '文件ID无效');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, deliverable_name, file_path, approval_status, is_folder, submitted_by FROM deliverables WHERE id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }

        $deleted = 0;
        $errors = [];
        $now = time();

        foreach ($ids as $id) {
            $file = $byId[$id] ?? null;
            if (!$file) {
                $errors[] = "文件ID {$id} 不存在";
                continue;
            }
            if (!can_manage_file($user, $file)) {
                $errors[] = "文件ID {$id} 无权限删除";
                continue;
            }

            $pdo->prepare('UPDATE deliverables SET deleted_at = ?, deleted_by = ? WHERE id = ?')
                ->execute([$now, (int)$user['id'], $id]);

            if ((int)($file['is_folder'] ?? 0) === 0) {
                $path = (string)($file['file_path'] ?? '');
                if ($path !== '' && !filter_var($path, FILTER_VALIDATE_URL)) {
                    $storage = storage_provider();
                    $key = normalize_storage_key_no_prefix($path);
                    $storage->deleteObject($key);
                }
            }

            $deleted++;
        }

        echo json_encode([
            'success' => true,
            'message' => "已删除 {$deleted} 个文件",
            'data' => [
                'deleted_count' => $deleted,
                'total_requested' => count($ids),
                'errors' => $errors,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error(400, '未知操作');
} catch (Throwable $e) {
    error_log('[desktop_file_manage] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
