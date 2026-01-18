<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rules = Db::query(
        'SELECT id, name, rule_type, fixed_rate
         FROM commission_rule_sets
         WHERE is_active = 1
         ORDER BY id DESC'
    );
    
    $data = [];
    foreach ($rules as $r) {
        $data[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'] ?? '',
            'rule_type' => $r['rule_type'] ?? 'fixed',
            'fixed_rate' => $r['fixed_rate'] !== null ? (float)$r['fixed_rate'] : null,
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
