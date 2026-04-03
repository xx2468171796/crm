<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 设计对接资料问卷 API
 * 
 * POST ?action=generate_token  - 生成/获取外部访问token
 * GET  ?action=get_token&customer_id=X - 获取token
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = desktop_auth_require();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_token';

try {
    switch ($action) {
        case 'generate_token':
            $input = json_decode(file_get_contents('php://input'), true);
            $customerId = (int)($input['customer_id'] ?? 0);
            if (!$customerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $existing = Db::queryOne('SELECT id, token FROM design_questionnaires WHERE customer_id = ?', [$customerId]);

            if ($existing && !empty($existing['token'])) {
                echo json_encode(['success' => true, 'data' => ['token' => $existing['token']]], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $token = bin2hex(random_bytes(24));
            $now = time();

            if ($existing) {
                Db::execute('UPDATE design_questionnaires SET token = ?, update_time = ? WHERE id = ?', [$token, $now, $existing['id']]);
            } else {
                Db::execute(
                    'INSERT INTO design_questionnaires (customer_id, token, version, create_user_id, update_user_id, create_time, update_time) VALUES (?, ?, 1, ?, ?, ?, ?)',
                    [$customerId, $token, $user['id'], $user['id'], $now, $now]
                );
            }

            echo json_encode(['success' => true, 'data' => ['token' => $token]], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_token':
            $customerId = (int)($_GET['customer_id'] ?? 0);
            if (!$customerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '缺少客户ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $row = Db::queryOne('SELECT token FROM design_questionnaires WHERE customer_id = ?', [$customerId]);
            echo json_encode(['success' => true, 'data' => ['token' => $row ? $row['token'] : null]], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_design_questionnaire 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}
