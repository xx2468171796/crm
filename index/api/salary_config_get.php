<?php
/**
 * 获取工资组成配置
 */
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

$ruleId = (int)($_GET['rule_id'] ?? 0);

if ($ruleId <= 0) {
    echo json_encode(['success' => false, 'message' => '规则ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rule = Db::queryOne("SELECT salary_config FROM commission_rule_sets WHERE id = ?", [$ruleId]);
    
    if (!$rule) {
        echo json_encode(['success' => false, 'message' => '规则不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $config = $rule['salary_config'] ? json_decode($rule['salary_config'], true) : null;
    
    // 如果没有配置，返回默认配置
    if (!$config || empty($config['components'])) {
        $config = [
            'components' => [
                ['code' => 'base_salary', 'name' => '底薪', 'type' => 'fixed', 'default' => 5000, 'op' => '+'],
                ['code' => 'attendance', 'name' => '全勤奖', 'type' => 'manual', 'default' => 500, 'op' => '+'],
                ['code' => 'commission', 'name' => '销售提成', 'type' => 'calculated', 'default' => 0, 'op' => '+'],
                ['code' => 'incentive', 'name' => '激励奖金', 'type' => 'calculated', 'default' => 0, 'op' => '+'],
                ['code' => 'adjustment', 'name' => '手动调整', 'type' => 'manual', 'default' => 0, 'op' => '+'],
                ['code' => 'deduction', 'name' => '扣款', 'type' => 'manual', 'default' => 0, 'op' => '-'],
            ]
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $config], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
