<?php
/**
 * API 统一初始化文件
 * 
 * 所有 API 文件只需引入这一个文件即可：
 * require_once __DIR__ . '/../core/api_init.php';
 * 
 * 自动处理：
 * 1. CORS 跨域
 * 2. JSON 响应头
 * 3. 错误处理
 * 4. Fatal Error 捕获
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

require_once __DIR__ . '/db.php';

// ========== 全局异常/错误处理 ==========
// 捕获未处理的异常
set_exception_handler(function($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false, 
        'message' => '服务器错误',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log('[API Exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    exit;
});

// 捕获 Fatal Error（防止进程崩溃）
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false, 
            'message' => '服务器错误',
            'error' => $error['message']
        ], JSON_UNESCAPED_UNICODE);
        error_log('[API Fatal] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

// 1. CORS 处理（无硬编码）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// 2. OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 3. JSON 响应头
header('Content-Type: application/json; charset=utf-8');

// 4. 禁用缓存（防止反向代理缓存API响应）
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
