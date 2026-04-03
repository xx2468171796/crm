<?php
/**
 * 汇率列表API - 获取所有货币及汇率
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/auth.php';
auth_require();

try {
    $currencies = Db::query("
        SELECT code, name, symbol, floating_rate, fixed_rate, is_base, status, sort_order, updated_at
        FROM currencies 
        WHERE status = 1 
        ORDER BY sort_order ASC
    ");
    
    foreach ($currencies as &$c) {
        $c['floating_rate'] = $c['floating_rate'] ? floatval($c['floating_rate']) : null;
        $c['fixed_rate'] = $c['fixed_rate'] ? floatval($c['fixed_rate']) : null;
        $c['is_base'] = (bool)$c['is_base'];
        $c['updated_at_formatted'] = $c['updated_at'] ? date('Y-m-d H:i:s', $c['updated_at']) : null;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $currencies
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
