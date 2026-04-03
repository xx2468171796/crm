<?php
/**
 * 分享区域节点管理API
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

// 支持桌面端token认证和web session认证
$user = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    require_once __DIR__ . '/../core/desktop_auth.php';
    $user = desktop_verify_token($matches[1]);
}
if (!$user) {
    $user = current_user();
}
if (!$user) {
    echo json_encode(['success' => false, 'message' => '请先登录', 'code' => 'UNAUTHORIZED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'list';

// 列表接口所有人可访问，其他操作仅管理员
if ($action !== 'list' && !isAdmin($user)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'list':
            // 获取所有启用的节点（普通用户）或全部节点（管理员）
            $isAdminUser = $user && isAdmin($user);
            if ($isAdminUser) {
                $regions = Db::query("SELECT * FROM share_regions ORDER BY sort_order ASC, id ASC");
            } else {
                $regions = Db::query("SELECT * FROM share_regions WHERE status = 1 ORDER BY sort_order ASC, id ASC");
            }
            echo json_encode(['success' => true, 'data' => $regions], JSON_UNESCAPED_UNICODE);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $region = Db::queryOne("SELECT * FROM share_regions WHERE id = ?", [$id]);
            if (!$region) {
                echo json_encode(['success' => false, 'message' => '节点不存在'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $region], JSON_UNESCAPED_UNICODE);
            break;

        case 'save':
            csrf_require();
            $id = (int)($_POST['id'] ?? 0);
            $regionName = trim($_POST['region_name'] ?? '');
            $domain = trim($_POST['domain'] ?? '');
            $port = $_POST['port'] !== '' ? (int)$_POST['port'] : null;
            $protocol = in_array($_POST['protocol'] ?? '', ['http', 'https']) ? $_POST['protocol'] : 'https';
            $status = (int)($_POST['status'] ?? 1);
            $isDefault = (int)($_POST['is_default'] ?? 0);
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if (empty($regionName)) {
                echo json_encode(['success' => false, 'message' => '区域名称不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (empty($domain)) {
                echo json_encode(['success' => false, 'message' => '域名不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $now = time();

            // 如果设为默认，先取消其他默认
            if ($isDefault) {
                Db::exec("UPDATE share_regions SET is_default = 0");
            }

            if ($id > 0) {
                // 更新
                Db::exec(
                    "UPDATE share_regions SET region_name = ?, domain = ?, port = ?, protocol = ?, status = ?, is_default = ?, sort_order = ?, updated_at = ? WHERE id = ?",
                    [$regionName, $domain, $port, $protocol, $status, $isDefault, $sortOrder, $now, $id]
                );
                echo json_encode(['success' => true, 'message' => '更新成功'], JSON_UNESCAPED_UNICODE);
            } else {
                // 新增
                Db::exec(
                    "INSERT INTO share_regions (region_name, domain, port, protocol, status, is_default, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$regionName, $domain, $port, $protocol, $status, $isDefault, $sortOrder, $now, $now]
                );
                echo json_encode(['success' => true, 'message' => '添加成功'], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'delete':
            csrf_require();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID无效'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            Db::exec("DELETE FROM share_regions WHERE id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => '删除成功'], JSON_UNESCAPED_UNICODE);
            break;

        case 'toggle':
            csrf_require();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID无效'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            Db::exec("UPDATE share_regions SET status = 1 - status, updated_at = ? WHERE id = ?", [time(), $id]);
            echo json_encode(['success' => true, 'message' => '状态已切换'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
