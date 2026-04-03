<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 系统配置 API
 * GET: 获取配置
 * POST: 更新配置（需要管理员权限）
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = auth_require();

try {
    $pdo = Db::pdo();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $key = $_GET['key'] ?? '';
        
        if ($key) {
            // 获取单个配置
            $stmt = $pdo->prepare("SELECT config_key, config_value, description FROM system_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $config ?: null
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // 获取所有配置
            $stmt = $pdo->query("SELECT config_key, config_value, description FROM system_config ORDER BY config_key");
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 转换为键值对
            $result = [];
            foreach ($configs as $c) {
                $result[$c['config_key']] = [
                    'value' => $c['config_value'],
                    'description' => $c['description']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 需要管理员权限
        if (!isAdmin($user)) {
            echo json_encode(['success' => false, 'message' => '无权限操作'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $key = trim($data['key'] ?? '');
        $value = $data['value'] ?? '';
        
        if (empty($key)) {
            echo json_encode(['success' => false, 'message' => '配置键不能为空'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新或插入配置
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()
        ");
        $stmt->execute([$key, $value]);
        
        echo json_encode([
            'success' => true,
            'message' => '配置已更新'
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
