<?php
/**
 * 支付方式手续费计算API
 * 
 * GET ?action=config - 获取所有支付方式及其手续费配置
 * GET ?action=calculate&amount=100&method=wechat - 计算指定金额和支付方式的手续费
 */

require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'config';

switch ($action) {
    case 'config':
        // 获取所有支付方式及其手续费配置
        $methods = getPaymentMethodsWithFee();
        echo json_encode(['success' => true, 'data' => $methods], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'calculate':
        // 计算手续费
        $amount = (float)($_GET['amount'] ?? 0);
        $method = trim($_GET['method'] ?? '');
        
        if ($amount <= 0) {
            echo json_encode(['success' => true, 'data' => [
                'original_amount' => 0,
                'fee_type' => null,
                'fee_value' => null,
                'fee_amount' => 0,
                'total_amount' => 0,
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $result = calculatePaymentFee($amount, $method);
        echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
}
