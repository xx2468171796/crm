<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端同步配置 API
 * 
 * GET: 获取当前用户的同步配置
 * POST: 更新同步配置（管理员）
 */

// CORS 配置
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';

// 验证 Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未登录']);
    exit;
}

$tokenRecord = Db::queryOne('SELECT user_id, expire_at FROM desktop_tokens WHERE token = ? LIMIT 1', [$token]);
if (!$tokenRecord || $tokenRecord['expire_at'] < time()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token 无效或已过期']);
    exit;
}

$user = Db::queryOne('SELECT id, username, realname, role, department_id FROM users WHERE id = ? AND status = 1 LIMIT 1', [$tokenRecord['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户不存在或已禁用']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = Db::pdo();

function tableColumns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['Field']] = strtolower((string)($row['Type'] ?? ''));
    }
    $cache[$table] = $cols;
    return $cols;
}

function isIntColumnType(?string $type): bool {
    if (!$type) return false;
    return str_contains($type, 'int');
}

$scCols = tableColumns($pdo, 'system_configs');
$updatedAtIsInt = isIntColumnType($scCols['updated_at'] ?? null);

// 默认配置
$defaultConfig = [
    'auto_sync_enabled' => true,
    'show_upload_notification' => true,
    'show_download_notification' => true,
    'sync_interval' => 300, // 5分钟
    'auto_download_customer_files' => true,
    'auto_upload_works_files' => false,
    'need_approval_for_works' => true,
];

if ($method === 'GET') {
    try {
        // 获取系统配置
        $stmt = $pdo->prepare("
            SELECT config_key, config_value 
            FROM system_configs 
            WHERE config_key LIKE 'desktop_sync_%'
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $config = $defaultConfig;
        foreach ($rows as $row) {
            $key = str_replace('desktop_sync_', '', $row['config_key']);
            $value = $row['config_value'];
            
            // 类型转换
            if ($value === 'true' || $value === '1') {
                $value = true;
            } elseif ($value === 'false' || $value === '0') {
                $value = false;
            } elseif (is_numeric($value)) {
                $value = (int)$value;
            }
            
            $config[$key] = $value;
        }
        
        // 是否是管理员
        $isAdmin = in_array($user['role'], ['admin', 'tech_lead', 'super_admin']);
        
        echo json_encode([
            'success' => true,
            'config' => $config,
            'is_admin' => $isAdmin,
            'can_edit' => $isAdmin,
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // 只有管理员可以修改配置
    if (!in_array($user['role'], ['admin', 'tech_lead', 'super_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限修改配置']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        $allowedKeys = array_keys($defaultConfig);
        $updated = [];
        
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                continue;
            }
            
            $configKey = 'desktop_sync_' . $key;
            $configValue = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            
            // 使用 REPLACE INTO 更新或插入
            if ($updatedAtIsInt) {
                $stmt = $pdo->prepare("
                    REPLACE INTO system_configs (config_key, config_value, updated_at)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$configKey, $configValue, time()]);
            } else {
                $stmt = $pdo->prepare("
                    REPLACE INTO system_configs (config_key, config_value, updated_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$configKey, $configValue]);
            }
            $updated[$key] = $value;
        }
        
        echo json_encode([
            'success' => true,
            'updated' => $updated,
            'message' => '配置已更新',
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
}
