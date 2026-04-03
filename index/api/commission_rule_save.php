<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$ruleType = trim((string)($_POST['rule_type'] ?? ''));
$fixedRateStr = trim((string)($_POST['fixed_rate'] ?? ''));
$tiersJson = trim((string)($_POST['tiers_json'] ?? ''));
$includePrepay = (int)($_POST['include_prepay'] ?? 0);
$currency = trim((string)($_POST['currency'] ?? 'CNY'));
$departmentIdsStr = trim((string)($_POST['department_ids'] ?? ''));
$userIdsStr = trim((string)($_POST['user_ids'] ?? ''));

$validCurrencies = ['CNY', 'USD', 'EUR', 'GBP', 'JPY', 'HKD', 'TWD'];
if (!in_array($currency, $validCurrencies)) {
    $currency = 'CNY';
}

$departmentIds = $departmentIdsStr !== '' ? array_map('intval', explode(',', $departmentIdsStr)) : [];
$userIds = $userIdsStr !== '' ? array_map('intval', explode(',', $userIdsStr)) : [];
$departmentIds = array_filter($departmentIds, fn($v) => $v > 0);
$userIds = array_filter($userIds, fn($v) => $v > 0);

if ($name === '' || mb_strlen($name) > 80) {
    echo json_encode(['success' => false, 'message' => '规则名称必填且最多80字'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($ruleType, ['fixed', 'tier'], true)) {
    echo json_encode(['success' => false, 'message' => '规则类型错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fixedRate = null;
$tiers = [];

if ($ruleType === 'fixed') {
    if ($fixedRateStr === '' || !is_numeric($fixedRateStr)) {
        echo json_encode(['success' => false, 'message' => '固定比例必须填写数字'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $fixedRate = (float)$fixedRateStr;
    if ($fixedRate < 0 || $fixedRate > 1) {
        echo json_encode(['success' => false, 'message' => '固定比例范围应为 0~1（例如 0.03）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    if ($tiersJson === '') {
        echo json_encode(['success' => false, 'message' => '阶梯规则必须填写 tiers_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $decoded = json_decode($tiersJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        echo json_encode(['success' => false, 'message' => 'tiers_json 格式错误或为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach ($decoded as $i => $t) {
        if (!is_array($t)) continue;
        $from = (float)($t['tier_from'] ?? 0);
        $to = $t['tier_to'] ?? null;
        $rate = (float)($t['rate'] ?? 0);
        if ($from < 0) {
            echo json_encode(['success' => false, 'message' => '阶梯起始金额不能小于0'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($to !== null && $to !== '' && is_numeric($to)) {
            $to = (float)$to;
            if ($to <= $from) {
                echo json_encode(['success' => false, 'message' => '阶梯结束金额必须大于起始金额'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            $to = null;
        }
        if ($rate < 0 || $rate > 1) {
            echo json_encode(['success' => false, 'message' => '阶梯比例范围应为 0~1'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tiers[] = [
            'tier_from' => round($from, 2),
            'tier_to' => $to === null ? null : round((float)$to, 2),
            'rate' => (float)$rate,
            'sort_order' => (int)($t['sort_order'] ?? ($i + 1)),
        ];
    }
}

try {
    Db::beginTransaction();

    $now = time();
    $uid = (int)($user['id'] ?? 0);

    if ($id > 0) {
        $row = Db::queryOne('SELECT id FROM commission_rule_sets WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $id]);
        if (!$row) {
            Db::rollback();
            echo json_encode(['success' => false, 'message' => '规则不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        Db::execute(
            'UPDATE commission_rule_sets SET name = :name, rule_type = :rule_type, fixed_rate = :fixed_rate, include_prepay = :include_prepay, currency = :currency, updated_at = :t, updated_by = :uid WHERE id = :id',
            [
                'name' => $name,
                'rule_type' => $ruleType,
                'fixed_rate' => $ruleType === 'fixed' ? $fixedRate : null,
                'include_prepay' => $includePrepay ? 1 : 0,
                'currency' => $currency,
                't' => $now,
                'uid' => $uid,
                'id' => $id,
            ]
        );

        Db::execute('DELETE FROM commission_rule_tiers WHERE rule_set_id = :id', ['id' => $id]);
    } else {
        Db::execute(
            'INSERT INTO commission_rule_sets (name, rule_type, fixed_rate, include_prepay, currency, is_active, created_at, created_by, updated_at, updated_by)
             VALUES (:name, :rule_type, :fixed_rate, :include_prepay, :currency, 1, :t, :uid, :t, :uid)',
            [
                'name' => $name,
                'rule_type' => $ruleType,
                'fixed_rate' => $ruleType === 'fixed' ? $fixedRate : null,
                'include_prepay' => $includePrepay ? 1 : 0,
                'currency' => $currency,
                't' => $now,
                'uid' => $uid,
            ]
        );
        $id = (int)Db::lastInsertId();
    }

    if ($ruleType === 'tier') {
        foreach ($tiers as $t) {
            Db::execute(
                'INSERT INTO commission_rule_tiers (rule_set_id, tier_from, tier_to, rate, sort_order)
                 VALUES (:rid, :tier_from, :tier_to, :rate, :sort_order)',
                [
                    'rid' => $id,
                    'tier_from' => $t['tier_from'],
                    'tier_to' => $t['tier_to'],
                    'rate' => $t['rate'],
                    'sort_order' => (int)$t['sort_order'],
                ]
            );
        }
    }

    Db::execute('DELETE FROM commission_rule_departments WHERE rule_set_id = :id', ['id' => $id]);
    Db::execute('DELETE FROM commission_rule_users WHERE rule_set_id = :id', ['id' => $id]);

    foreach ($departmentIds as $deptId) {
        Db::execute(
            'INSERT INTO commission_rule_departments (rule_set_id, department_id, created_at) VALUES (:rid, :did, :t)',
            ['rid' => $id, 'did' => $deptId, 't' => $now]
        );
    }
    foreach ($userIds as $userId) {
        Db::execute(
            'INSERT INTO commission_rule_users (rule_set_id, user_id, created_at) VALUES (:rid, :uid, :t)',
            ['rid' => $id, 'uid' => $userId, 't' => $now]
        );
    }

    Db::commit();

    echo json_encode(['success' => true, 'message' => '已保存', 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    try { Db::rollback(); } catch (Exception $ignore) {}
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
