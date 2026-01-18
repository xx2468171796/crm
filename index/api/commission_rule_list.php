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

try {
    $sets = Db::query(
        'SELECT id, name, rule_type, fixed_rate, include_prepay, currency, is_active, created_at, created_by, updated_at, updated_by
         FROM commission_rule_sets
         ORDER BY is_active DESC, id DESC'
    );

    $rows = [];
    $ids = [];
    foreach ($sets as $s) {
        $ids[] = (int)($s['id'] ?? 0);
    }

    $tiersMap = [];
    $deptsMap = [];
    $usersMap = [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tiers = Db::query(
            'SELECT id, rule_set_id, tier_from, tier_to, rate, sort_order
             FROM commission_rule_tiers
             WHERE rule_set_id IN (' . $placeholders . ')
             ORDER BY rule_set_id ASC, sort_order ASC, id ASC',
            $ids
        );
        foreach ($tiers as $t) {
            $rid = (int)($t['rule_set_id'] ?? 0);
            if (!isset($tiersMap[$rid])) {
                $tiersMap[$rid] = [];
            }
            $tiersMap[$rid][] = [
                'id' => (int)($t['id'] ?? 0),
                'tier_from' => (float)($t['tier_from'] ?? 0),
                'tier_to' => $t['tier_to'] !== null ? (float)$t['tier_to'] : null,
                'rate' => (float)($t['rate'] ?? 0),
                'sort_order' => (int)($t['sort_order'] ?? 0),
            ];
        }

        $depts = Db::query(
            'SELECT rd.rule_set_id, rd.department_id, d.name AS department_name
             FROM commission_rule_departments rd
             LEFT JOIN departments d ON d.id = rd.department_id
             WHERE rd.rule_set_id IN (' . $placeholders . ')',
            $ids
        );
        foreach ($depts as $d) {
            $rid = (int)($d['rule_set_id'] ?? 0);
            if (!isset($deptsMap[$rid])) {
                $deptsMap[$rid] = [];
            }
            $deptsMap[$rid][] = [
                'id' => (int)($d['department_id'] ?? 0),
                'name' => (string)($d['department_name'] ?? ''),
            ];
        }

        $users = Db::query(
            'SELECT ru.rule_set_id, ru.user_id, u.realname AS user_name
             FROM commission_rule_users ru
             LEFT JOIN users u ON u.id = ru.user_id
             WHERE ru.rule_set_id IN (' . $placeholders . ')',
            $ids
        );
        foreach ($users as $u) {
            $rid = (int)($u['rule_set_id'] ?? 0);
            if (!isset($usersMap[$rid])) {
                $usersMap[$rid] = [];
            }
            $usersMap[$rid][] = [
                'id' => (int)($u['user_id'] ?? 0),
                'name' => (string)($u['user_name'] ?? ''),
            ];
        }
    }

    foreach ($sets as $s) {
        $rid = (int)($s['id'] ?? 0);
        $rows[] = [
            'id' => $rid,
            'name' => (string)($s['name'] ?? ''),
            'rule_type' => (string)($s['rule_type'] ?? ''),
            'fixed_rate' => $s['fixed_rate'] !== null ? (float)$s['fixed_rate'] : null,
            'include_prepay' => (int)($s['include_prepay'] ?? 0),
            'currency' => (string)($s['currency'] ?? 'CNY'),
            'is_active' => (int)($s['is_active'] ?? 0),
            'tiers' => $tiersMap[$rid] ?? [],
            'departments' => $deptsMap[$rid] ?? [],
            'users' => $usersMap[$rid] ?? [],
            'updated_at' => (int)($s['updated_at'] ?? 0),
        ];
    }

    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
