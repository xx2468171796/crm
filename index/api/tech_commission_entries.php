<?php
/**
 * 技术提成明细记录 - 时间线 CRUD API
 *
 * 一个 (项目, 设计师) 可以有多条提成记录，每条 = 金额 / 备注 / 时间
 *
 * GET  ?action=list&assignment_id=N        列出某个 assignment 下所有 entries（按时间倒序）
 * POST ?action=add    {assignment_id, amount, note?, entry_at?}    新增一条
 * POST ?action=update {id, amount?, note?, entry_at?}              编辑一条
 * POST ?action=delete {id}                                         删除一条
 *
 * 权限：管理员 / 部门主管 可以增删改查；其他人只能看自己被分配的 assignment 下的 entries
 * 双轨认证：优先 Bearer Token（桌面），失败回退 session（Web）
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

// 双轨认证
$user = null;
$bearerToken = desktop_get_token();
if ($bearerToken) {
    $user = desktop_verify_token($bearerToken);
}
if (!$user) {
    $user = current_user();
}
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未认证'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

/**
 * 是否有权限管理某个 assignment 的 entries（管理员 / 主管 / 该 assignment 所在部门的 leader）
 */
function canManageAssignment(array $user, array $assignment): bool
{
    if (isAdmin($user)) return true;
    $role = $user['role'] ?? '';
    if (in_array($role, ['dept_leader', 'tech_manager', 'manager'], true)) {
        // 这里可补：限制本部门，但简化为主管角色即可
        return true;
    }
    return false;
}

/**
 * 是否有权限"看到"某个 assignment 下的 entries（管理员/主管/owner自己）
 */
function canViewAssignment(array $user, array $assignment): bool
{
    if (canManageAssignment($user, $assignment)) return true;
    return (int)$assignment['tech_user_id'] === (int)$user['id'];
}

try {
    switch ($action) {
        case 'list': {
            $assignmentId = (int)($_GET['assignment_id'] ?? 0);
            if ($assignmentId <= 0) jsonOut(['success' => false, 'error' => '缺少 assignment_id'], 400);

            $assignment = Db::queryOne('SELECT id, project_id, tech_user_id FROM project_tech_assignments WHERE id = ?', [$assignmentId]);
            if (!$assignment) jsonOut(['success' => false, 'error' => '分配记录不存在'], 404);
            if (!canViewAssignment($user, $assignment)) jsonOut(['success' => false, 'error' => '无权限查看'], 403);

            $rows = Db::query(
                'SELECT e.id, e.assignment_id, e.amount, e.note, e.entry_at, e.created_by, e.created_at, e.updated_at,
                        u.realname AS created_by_name
                 FROM tech_commission_entries e
                 LEFT JOIN users u ON u.id = e.created_by
                 WHERE e.assignment_id = ?
                 ORDER BY e.entry_at DESC, e.id DESC',
                [$assignmentId]
            );

            $totalAmount = 0.0;
            $entries = [];
            foreach ($rows as $r) {
                $amt = (float)$r['amount'];
                $totalAmount += $amt;
                $entries[] = [
                    'id' => (int)$r['id'],
                    'assignment_id' => (int)$r['assignment_id'],
                    'amount' => $amt,
                    'note' => $r['note'],
                    'entry_at' => (int)$r['entry_at'],
                    'created_by' => (int)$r['created_by'],
                    'created_by_name' => $r['created_by_name'],
                    'created_at' => (int)$r['created_at'],
                    'updated_at' => (int)$r['updated_at'],
                ];
            }

            jsonOut([
                'success' => true,
                'data' => [
                    'entries' => $entries,
                    'total_amount' => $totalAmount,
                    'entry_count' => count($entries),
                    'can_manage' => canManageAssignment($user, $assignment),
                ],
            ]);
            break;
        }

        case 'add': {
            $input = readJsonInput();
            $assignmentId = (int)($input['assignment_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $note = trim((string)($input['note'] ?? ''));
            $entryAt = isset($input['entry_at']) && $input['entry_at']
                ? (is_numeric($input['entry_at']) ? (int)$input['entry_at'] : strtotime((string)$input['entry_at']))
                : time();
            if ($entryAt === false || $entryAt <= 0) $entryAt = time();

            if ($assignmentId <= 0) jsonOut(['success' => false, 'error' => '缺少 assignment_id'], 400);
            if ($amount <= 0) jsonOut(['success' => false, 'error' => '金额必须大于 0'], 400);

            $assignment = Db::queryOne('SELECT id, project_id, tech_user_id FROM project_tech_assignments WHERE id = ?', [$assignmentId]);
            if (!$assignment) jsonOut(['success' => false, 'error' => '分配记录不存在'], 404);
            if (!canManageAssignment($user, $assignment)) jsonOut(['success' => false, 'error' => '无权限'], 403);

            $now = time();
            Db::execute(
                'INSERT INTO tech_commission_entries (assignment_id, amount, note, entry_at, created_by, created_at, updated_at)
                 VALUES (:aid, :amt, :note, :eat, :cby, :cat, :cat)',
                ['aid' => $assignmentId, 'amt' => $amount, 'note' => $note !== '' ? $note : null,
                 'eat' => $entryAt, 'cby' => (int)$user['id'], 'cat' => $now]
            );
            $newId = (int)Db::lastInsertId();

            // 同步缓存到 project_tech_assignments（最近一条 + 累计金额到旧字段，向下兼容）
            syncAssignmentCommissionCache($assignmentId, (int)$user['id']);

            jsonOut(['success' => true, 'data' => ['id' => $newId]]);
            break;
        }

        case 'update': {
            $input = readJsonInput();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) jsonOut(['success' => false, 'error' => '参数错误'], 400);

            $entry = Db::queryOne('SELECT e.*, pta.project_id, pta.tech_user_id
                FROM tech_commission_entries e
                INNER JOIN project_tech_assignments pta ON pta.id = e.assignment_id
                WHERE e.id = ?', [$id]);
            if (!$entry) jsonOut(['success' => false, 'error' => '记录不存在'], 404);
            if (!canManageAssignment($user, $entry)) jsonOut(['success' => false, 'error' => '无权限'], 403);

            $amount = isset($input['amount']) ? (float)$input['amount'] : (float)$entry['amount'];
            $note = isset($input['note']) ? trim((string)$input['note']) : (string)($entry['note'] ?? '');
            $entryAt = (int)$entry['entry_at'];
            if (isset($input['entry_at']) && $input['entry_at']) {
                $tmp = is_numeric($input['entry_at']) ? (int)$input['entry_at'] : strtotime((string)$input['entry_at']);
                if ($tmp !== false && $tmp > 0) $entryAt = $tmp;
            }
            if ($amount <= 0) jsonOut(['success' => false, 'error' => '金额必须大于 0'], 400);

            Db::execute(
                'UPDATE tech_commission_entries
                 SET amount = :amt, note = :note, entry_at = :eat, updated_at = :now
                 WHERE id = :id',
                ['amt' => $amount, 'note' => $note !== '' ? $note : null,
                 'eat' => $entryAt, 'now' => time(), 'id' => $id]
            );

            syncAssignmentCommissionCache((int)$entry['assignment_id'], (int)$user['id']);

            jsonOut(['success' => true]);
            break;
        }

        case 'delete': {
            $input = readJsonInput();
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) jsonOut(['success' => false, 'error' => '参数错误'], 400);

            $entry = Db::queryOne('SELECT e.*, pta.project_id, pta.tech_user_id
                FROM tech_commission_entries e
                INNER JOIN project_tech_assignments pta ON pta.id = e.assignment_id
                WHERE e.id = ?', [$id]);
            if (!$entry) jsonOut(['success' => false, 'error' => '记录不存在'], 404);
            if (!canManageAssignment($user, $entry)) jsonOut(['success' => false, 'error' => '无权限'], 403);

            Db::execute('DELETE FROM tech_commission_entries WHERE id = ?', [$id]);
            syncAssignmentCommissionCache((int)$entry['assignment_id'], (int)$user['id']);

            jsonOut(['success' => true]);
            break;
        }

        default:
            jsonOut(['success' => false, 'error' => '未知操作'], 400);
    }
} catch (Throwable $e) {
    error_log('[tech_commission_entries] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonOut(['success' => false, 'error' => '服务器错误'], 500);
}

/**
 * 同步 entries 累计金额 / 最近一条到 project_tech_assignments 的旧字段
 * （向下兼容：还有部分代码读 pta.commission_amount 来显示总额）
 */
function syncAssignmentCommissionCache(int $assignmentId, int $operatorUserId): void
{
    $row = Db::queryOne(
        'SELECT
            COALESCE(SUM(amount), 0) AS total_amount,
            MAX(entry_at) AS latest_at,
            (SELECT note FROM tech_commission_entries WHERE assignment_id = :aid ORDER BY entry_at DESC, id DESC LIMIT 1) AS latest_note,
            COUNT(*) AS cnt
         FROM tech_commission_entries
         WHERE assignment_id = :aid',
        ['aid' => $assignmentId]
    );
    $totalAmount = (float)($row['total_amount'] ?? 0);
    $latestAt = $row && $row['latest_at'] !== null ? (int)$row['latest_at'] : null;
    $latestNote = $row['latest_note'] ?? null;
    $count = (int)($row['cnt'] ?? 0);

    if ($count === 0) {
        // 没有 entry 了，清空旧字段
        Db::execute(
            'UPDATE project_tech_assignments
             SET commission_amount = NULL, commission_note = NULL, commission_set_at = NULL, commission_set_by = NULL
             WHERE id = ?',
            [$assignmentId]
        );
    } else {
        Db::execute(
            'UPDATE project_tech_assignments
             SET commission_amount = ?, commission_note = ?, commission_set_at = ?, commission_set_by = ?
             WHERE id = ?',
            [$totalAmount, $latestNote, $latestAt, $operatorUserId, $assignmentId]
        );
    }
}
