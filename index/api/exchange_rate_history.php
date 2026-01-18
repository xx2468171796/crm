<?php
/**
 * 汇率历史API - 查看汇率变更历史
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/auth.php';
auth_require();

$code = $_GET['code'] ?? '';
$type = $_GET['type'] ?? '';
$limit = min(intval($_GET['limit'] ?? 50), 200);

try {
    $where = "1=1";
    $params = [];
    
    if ($code) {
        $where .= " AND h.currency_code = ?";
        $params[] = $code;
    }
    
    if ($type && in_array($type, ['floating', 'fixed'])) {
        $where .= " AND h.rate_type = ?";
        $params[] = $type;
    }
    
    $history = Db::query("
        SELECT h.id, h.currency_code, h.rate_type, h.rate, h.created_at, h.created_by,
               c.name as currency_name, c.symbol,
               u.realname as operator_name
        FROM exchange_rate_history h
        LEFT JOIN currencies c ON h.currency_code = c.code
        LEFT JOIN users u ON h.created_by = u.id
        WHERE {$where}
        ORDER BY h.created_at DESC
        LIMIT ?
    ", array_merge($params, [$limit]));
    
    foreach ($history as &$h) {
        $h['rate'] = floatval($h['rate']);
        $h['created_at_formatted'] = date('Y-m-d H:i:s', $h['created_at']);
        $h['operator'] = $h['created_by'] ? $h['operator_name'] : '系统自动';
    }
    
    echo json_encode([
        'success' => true,
        'data' => $history
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
