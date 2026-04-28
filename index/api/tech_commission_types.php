<?php
/**
 * 技术提成类型 - CRUD API
 *
 * GET  ?action=list                 列出全部（含停用）
 * GET  ?action=options              列出启用的（给下拉用）
 * POST ?action=save  {id?, name, sort_order, remark, status}   新增/编辑
 * POST ?action=toggle {id}          启用/停用切换
 * POST ?action=delete {id}          删除（被引用过则改为停用）
 *
 * 权限：管理员（admin/super_admin/system_admin）才能改；options 已登录即可读
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireAdmin(array $user): void
{
    if (!isAdmin($user)) {
        jsonOut(['success' => false, 'error' => '无权限，仅管理员可操作'], 403);
    }
}

try {
    switch ($action) {
        case 'list':
            $rows = Db::query('SELECT id, name, sort_order, status, remark, create_time, update_time
                FROM tech_commission_types
                ORDER BY status DESC, sort_order ASC, id ASC');
            // 附带使用次数（管理员判断能否删除）
            $usage = [];
            foreach (Db::query('SELECT commission_type_id, COUNT(*) AS cnt
                FROM project_tech_assignments
                WHERE commission_type_id IS NOT NULL
                GROUP BY commission_type_id') as $u) {
                $usage[(int)$u['commission_type_id']] = (int)$u['cnt'];
            }
            foreach ($rows as &$row) {
                $row['used_count'] = $usage[(int)$row['id']] ?? 0;
            }
            unset($row);
            jsonOut(['success' => true, 'data' => $rows]);
            break;

        case 'options':
            $rows = Db::query('SELECT id, name FROM tech_commission_types
                WHERE status = 1
                ORDER BY sort_order ASC, id ASC');
            jsonOut(['success' => true, 'data' => $rows]);
            break;

        case 'save':
            requireAdmin($user);
            $input = readJsonInput();
            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            $sort = (int)($input['sort_order'] ?? 0);
            $status = isset($input['status']) ? ((int)$input['status'] === 1 ? 1 : 0) : 1;
            $remark = trim((string)($input['remark'] ?? ''));

            if ($name === '') {
                jsonOut(['success' => false, 'error' => '类型名称不能为空'], 400);
            }
            if (mb_strlen($name) > 64) {
                jsonOut(['success' => false, 'error' => '类型名称过长'], 400);
            }

            $now = time();
            if ($id > 0) {
                $exist = Db::queryOne('SELECT id FROM tech_commission_types WHERE id = :id', ['id' => $id]);
                if (!$exist) {
                    jsonOut(['success' => false, 'error' => '类型不存在'], 404);
                }
                $dup = Db::queryOne('SELECT id FROM tech_commission_types WHERE name = :name AND id <> :id LIMIT 1',
                    ['name' => $name, 'id' => $id]);
                if ($dup) {
                    jsonOut(['success' => false, 'error' => '类型名称已存在'], 400);
                }
                Db::execute('UPDATE tech_commission_types
                    SET name = :name, sort_order = :sort, status = :status, remark = :remark, update_time = :now
                    WHERE id = :id',
                    ['name' => $name, 'sort' => $sort, 'status' => $status, 'remark' => $remark, 'now' => $now, 'id' => $id]);
                jsonOut(['success' => true, 'message' => '已更新', 'data' => ['id' => $id]]);
            } else {
                $dup = Db::queryOne('SELECT id FROM tech_commission_types WHERE name = :name LIMIT 1', ['name' => $name]);
                if ($dup) {
                    jsonOut(['success' => false, 'error' => '类型名称已存在'], 400);
                }
                Db::execute('INSERT INTO tech_commission_types (name, sort_order, status, remark, create_time, update_time)
                    VALUES (:name, :sort, :status, :remark, :now, :now)',
                    ['name' => $name, 'sort' => $sort, 'status' => $status, 'remark' => $remark, 'now' => $now]);
                $newId = (int)Db::lastInsertId();
                jsonOut(['success' => true, 'message' => '已新增', 'data' => ['id' => $newId]]);
            }
            break;

        case 'toggle':
            requireAdmin($user);
            $input = readJsonInput();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                jsonOut(['success' => false, 'error' => '参数错误'], 400);
            }
            $row = Db::queryOne('SELECT id, status FROM tech_commission_types WHERE id = :id', ['id' => $id]);
            if (!$row) {
                jsonOut(['success' => false, 'error' => '类型不存在'], 404);
            }
            $newStatus = (int)$row['status'] === 1 ? 0 : 1;
            Db::execute('UPDATE tech_commission_types SET status = :s, update_time = :now WHERE id = :id',
                ['s' => $newStatus, 'now' => time(), 'id' => $id]);
            jsonOut(['success' => true, 'data' => ['id' => $id, 'status' => $newStatus]]);
            break;

        case 'delete':
            requireAdmin($user);
            $input = readJsonInput();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                jsonOut(['success' => false, 'error' => '参数错误'], 400);
            }
            $row = Db::queryOne('SELECT id FROM tech_commission_types WHERE id = :id', ['id' => $id]);
            if (!$row) {
                jsonOut(['success' => false, 'error' => '类型不存在'], 404);
            }
            $usedRow = Db::queryOne('SELECT COUNT(*) AS cnt FROM project_tech_assignments WHERE commission_type_id = :id', ['id' => $id]);
            $used = (int)($usedRow['cnt'] ?? 0);
            if ($used > 0) {
                // 已被引用：不能真删，只允许停用，避免历史数据丢分类
                Db::execute('UPDATE tech_commission_types SET status = 0, update_time = :now WHERE id = :id',
                    ['now' => time(), 'id' => $id]);
                jsonOut(['success' => true, 'message' => '该类型已被 ' . $used . ' 条提成记录引用，无法真正删除，已自动停用', 'data' => ['soft_deleted' => true]]);
            }
            Db::execute('DELETE FROM tech_commission_types WHERE id = :id', ['id' => $id]);
            jsonOut(['success' => true, 'message' => '已删除']);
            break;

        default:
            jsonOut(['success' => false, 'error' => '未知操作'], 400);
    }
} catch (Throwable $e) {
    error_log('[tech_commission_types] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => '服务器错误'], 500);
}
