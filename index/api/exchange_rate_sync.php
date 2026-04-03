<?php
/**
 * 汇率同步API - 从免费API获取实时汇率
 * 可通过cron定时调用或手动触发
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/auth.php';

$isManual = isset($_GET['manual']) && $_GET['manual'] == '1';
$isCron = php_sapi_name() === 'cli' || isset($_GET['cron']);

if ($isManual) {
    auth_require();
}

try {
    $apiUrl = 'https://api.exchangerate-api.com/v4/latest/CNY';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        throw new Exception('无法连接汇率API');
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['rates'])) {
        throw new Exception('汇率API返回数据格式错误');
    }
    
    $rates = $data['rates'];
    $updated = 0;
    $now = time();
    
    $currencies = Db::query("SELECT code FROM currencies WHERE status = 1 AND is_base = 0");
    
    foreach ($currencies as $currency) {
        $code = $currency['code'];
        if (isset($rates[$code])) {
            $rate = $rates[$code];
            
            $oldRate = Db::queryOne("SELECT floating_rate FROM currencies WHERE code = ?", [$code]);
            
            Db::execute(
                "UPDATE currencies SET floating_rate = ?, updated_at = ? WHERE code = ?",
                [$rate, $now, $code]
            );
            
            Db::execute(
                "INSERT INTO exchange_rate_history (currency_code, rate_type, rate, created_at) VALUES (?, 'floating', ?, ?)",
                [$code, $rate, $now]
            );
            
            if (empty($oldRate['floating_rate'])) {
                Db::execute(
                    "UPDATE currencies SET fixed_rate = ? WHERE code = ? AND (fixed_rate IS NULL OR fixed_rate = 0)",
                    [$rate, $code]
                );
            }
            
            $updated++;
        }
    }
    
    Db::execute("UPDATE currencies SET floating_rate = 1, fixed_rate = 1, updated_at = ? WHERE code = 'CNY'", [$now]);
    
    $result = [
        'success' => true,
        'message' => "汇率同步完成，更新了 {$updated} 个货币",
        'updated_count' => $updated,
        'sync_time' => date('Y-m-d H:i:s', $now)
    ];
    
    if ($isCron) {
        echo "[" . date('Y-m-d H:i:s') . "] " . $result['message'] . PHP_EOL;
    } else {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    $error = [
        'success' => false,
        'message' => $e->getMessage()
    ];
    
    if ($isCron) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . PHP_EOL;
        exit(1);
    } else {
        echo json_encode($error, JSON_UNESCAPED_UNICODE);
    }
}
