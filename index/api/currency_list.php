<?php
/**
 * 获取货币列表
 * GET /api/currency_list.php
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

try {
    $currencies = Db::query(
        'SELECT code, name, symbol, status, sort_order FROM currencies WHERE status = 1 ORDER BY sort_order ASC'
    );
    
    echo json_encode([
        'success' => true,
        'data' => $currencies ?: []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
