<?php
/**
 * 诊断收款方式字段 - 临时调试用
 * 访问: /api/debug_payment_method.php
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 只允许管理员访问
if (!in_array($user['role'] ?? '', ['admin', 'system_admin'])) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 统计收款方式分布
$methodStats = Db::query("
    SELECT 
        COALESCE(method, '(NULL)') as method_value,
        COUNT(*) as count,
        SUM(amount_applied) as total_amount
    FROM finance_receipts 
    GROUP BY method
    ORDER BY count DESC
");

// 获取字典配置
$dictMethods = Db::query("
    SELECT dict_code, dict_label, is_enabled
    FROM system_dict 
    WHERE dict_type = 'payment_method'
    ORDER BY sort_order
");

// 检查不匹配的数据
$unmatchedMethods = Db::query("
    SELECT DISTINCT r.method, COUNT(*) as count
    FROM finance_receipts r
    LEFT JOIN system_dict d ON d.dict_type = 'payment_method' AND (d.dict_code = r.method OR d.dict_label = r.method)
    WHERE r.method IS NOT NULL 
      AND r.method != ''
      AND d.id IS NULL
    GROUP BY r.method
");

echo json_encode([
    'success' => true,
    'data' => [
        'method_distribution' => $methodStats,
        'dict_config' => $dictMethods,
        'unmatched_methods' => $unmatchedMethods,
        'summary' => [
            'total_receipts' => array_sum(array_column($methodStats, 'count')),
            'dict_count' => count($dictMethods),
            'unmatched_count' => count($unmatchedMethods)
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
