<?php
/**
 * 简易 CLI 冒烟测试，验证 OKR 关键流程（KR 进度 / 容器进度 / 权限 where clause）
 *
 * 运行方式：php project/tests/okr_cli_smoke.php
 * 脚本默认在事务内执行，不会污染现有数据。
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/okr_progress.php';
require_once __DIR__ . '/../core/okr_permission.php';

echo "== OKR CLI Smoke Tests ==\n";

Db::beginTransaction();

try {
    $testUser = Db::queryOne('SELECT id, department_id, role FROM users ORDER BY id ASC LIMIT 1');
    assertTrue($testUser !== null, '存在基础用户数据');

    $now = time();
    $cycleId = insertCycle('测试周期', 'quarter', '2025-01-01', '2025-03-31', $testUser['id'], $now);
    $containerId = insertContainer($cycleId, $testUser['id'], $testUser['department_id'], $now);
    $objectiveId = insertObjective($containerId, '测试 O1', $testUser['id'], $now);

    $kr1 = insertKr($objectiveId, 'KR1', 100, 0, 100, 50, 5, $testUser['id'], $now);
    $kr2 = insertKr($objectiveId, 'KR2', 100, 0, 100, 50, 5, $testUser['id'], $now);

    // 更新 KR 进度
    Db::execute('UPDATE okr_key_results SET progress = :progress, current_value = :progress WHERE id = :id', ['progress' => 80, 'id' => $kr1]);
    Db::execute('UPDATE okr_key_results SET progress = :progress, current_value = :progress WHERE id = :id', ['progress' => 40, 'id' => $kr2]);

    recalculateObjectiveProgress($objectiveId);
    recalculateContainerProgress($containerId);

    $objective = Db::queryOne('SELECT progress FROM okr_objectives WHERE id = :id', ['id' => $objectiveId]);
    assertNear((float)$objective['progress'], 60.0, 0.01, 'KR 进度影响 Objective 进度');

    $container = Db::queryOne('SELECT progress FROM okr_containers WHERE id = :id', ['id' => $containerId]);
    assertNear((float)$container['progress'], 60.0, 0.01, 'Objective 进度影响 Container 进度');

    // 权限 where clause 验证
    $deptAdmin = ['id' => 99, 'role' => 'dept_admin', 'department_id' => 7];
    $deptWhere = buildOkrTaskWhereClause($deptAdmin, 't');
    assertTrue(strpos($deptWhere, "t.department_id = 7") !== false, '部门管理员 where 包含部门限制');

    $employee = ['id' => 55, 'role' => 'employee', 'department_id' => 3];
    $empWhere = buildOkrTaskWhereClause($employee, 't');
    assertTrue(strpos($empWhere, "t.executor_id = 55") !== false, '普通员工 where 包含个人任务');

    echo "[PASS] 所有断言通过\n";
    Db::rollback();
    exit(0);
} catch (Throwable $e) {
    Db::rollback();
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
    exit(1);
}

function insertCycle(string $name, string $type, string $start, string $end, int $userId, int $now): int
{
    Db::execute(
        'INSERT INTO okr_cycles (name, type, start_date, end_date, status, create_user_id, create_time, update_time) VALUES (:name, :type, :start, :end, 1, :uid, :create, :update)',
        ['name' => $name, 'type' => $type, 'start' => $start, 'end' => $end, 'uid' => $userId, 'create' => $now, 'update' => $now]
    );
    return (int)Db::lastInsertId();
}

function insertContainer(int $cycleId, int $userId, ?int $departmentId, int $now): int
{
    Db::execute(
        'INSERT INTO okr_containers (cycle_id, user_id, level, department_id, progress, status, create_user_id, create_time, update_time) VALUES (:cycle, :user, :level, :dept, 0, 1, :user, :create, :update)',
        ['cycle' => $cycleId, 'user' => $userId, 'level' => 'personal', 'dept' => $departmentId, 'create' => $now, 'update' => $now]
    );
    return (int)Db::lastInsertId();
}

function insertObjective(int $containerId, string $title, int $userId, int $now): int
{
    Db::execute(
        'INSERT INTO okr_objectives (container_id, title, sort_order, progress, status, create_user_id, create_time, update_time) VALUES (:container, :title, 1, 0, :status, :user, :create, :update)',
        ['container' => $containerId, 'title' => $title, 'status' => 'normal', 'user' => $userId, 'create' => $now, 'update' => $now]
    );
    return (int)Db::lastInsertId();
}

function insertKr(int $objectiveId, string $title, float $target, float $start, float $current, float $weight, int $confidence, int $userId, int $now): int
{
    Db::execute(
        'INSERT INTO okr_key_results (objective_id, title, target_value, start_value, current_value, unit, weight, confidence, progress_mode, progress, owner_user_ids, sort_order, status, create_user_id, create_time, update_time)
         VALUES (:objective, :title, :target, :start, :current, :unit, :weight, :confidence, :mode, :progress, :owners, 1, :status, :user, :create, :update)',
        [
            'objective' => $objectiveId,
            'title' => $title,
            'target' => $target,
            'start' => $start,
            'current' => $current,
            'unit' => '%',
            'weight' => $weight,
            'confidence' => $confidence,
            'mode' => 'value',
            'progress' => 0,
            'owners' => json_encode([$userId], JSON_UNESCAPED_UNICODE),
            'status' => 'normal',
            'user' => $userId,
            'create' => $now,
            'update' => $now
        ]
    );
    return (int)Db::lastInsertId();
}

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
}

function assertNear(float $actual, float $expected, float $delta, string $message): void
{
    if (abs($actual - $expected) > $delta) {
        throw new RuntimeException($message . " (actual={$actual}, expected={$expected})");
    }
    echo "[PASS] {$message}\n";
}

