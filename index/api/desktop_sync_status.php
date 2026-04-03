<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端同步状态 API
 * 
 * GET: 获取用户的同步状态概览
 * - 待下载的客户文件数量
 * - 待审批的作品文件数量
 * - 最近同步时间
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

if ($method === 'GET') {
    try {
        $pdo = Db::pdo();
        $thresholdTs = time() - 7 * 86400;
        // 获取用户负责的项目
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id, p.project_name, p.project_code, p.customer_id, c.group_name, c.group_code
            FROM projects p
            JOIN project_tech_assignments pta ON p.id = pta.project_id
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE pta.user_id = ? AND pta.deleted_at IS NULL
        ");
        $stmt->execute([$user['id']]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $customerIds = array_column($projects, 'customer_id');
        $projectGroups = [];
        foreach ($projects as $p) {
            $projectGroups[$p['id']] = $p['group_name'];
        }
        
        // 统计待下载的客户文件（最近7天内上传的）
        $pendingDownloads = 0;
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));

            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt
                    FROM customer_files cf
                    WHERE cf.customer_id IN ({$placeholders})
                    AND cf.folder_type = '客户文件'
                    AND cf.deleted_at IS NULL
                    AND cf.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                $stmt->execute($customerIds);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $pendingDownloads = (int)($result['cnt'] ?? 0);
            } catch (Exception $e) {
                // 兼容新表结构：uploaded_at(INT) + folder_path
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt
                    FROM customer_files cf
                    WHERE cf.customer_id IN ({$placeholders})
                    AND cf.folder_path = '客户文件'
                    AND cf.deleted_at IS NULL
                    AND cf.uploaded_at > ?
                ");
                $params = array_merge($customerIds, [$thresholdTs]);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $pendingDownloads = (int)($result['cnt'] ?? 0);
            }
        }
        
        // 统计待审批的文件（提交人是当前用户）
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM file_approvals fa
            WHERE fa.submitter_id = ? AND fa.status = 'pending'
        ");
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingApprovals = (int)$result['cnt'];
        
        // 如果是管理员/主管，统计需要审批的文件
        $toReview = 0;
        if ($user['role'] === 'admin' || $user['role'] === 'tech_lead') {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM file_approvals WHERE status = 'pending'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $toReview = (int)$result['cnt'];
        }
        
        // 获取最近的文件变动
        $recentFiles = [];
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));

            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        cf.id,
                        cf.original_name,
                        cf.folder_type,
                        cf.file_size,
                        cf.created_at,
                        c.group_name,
                        u.name as uploader_name
                    FROM customer_files cf
                    LEFT JOIN customers c ON cf.customer_id = c.id
                    LEFT JOIN users u ON cf.uploader_id = u.id
                    WHERE cf.customer_id IN ({$placeholders})
                    AND cf.deleted_at IS NULL
                    ORDER BY cf.created_at DESC
                    LIMIT 10
                ");
                $stmt->execute($customerIds);
                $recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // 兼容新表结构：filename/filesize/uploaded_at/uploaded_by + folder_path
                $stmt = $pdo->prepare("
                    SELECT 
                        cf.id,
                        cf.filename as original_name,
                        cf.folder_path as folder_type,
                        cf.filesize as file_size,
                        cf.uploaded_at as created_at,
                        c.group_name,
                        u.name as uploader_name
                    FROM customer_files cf
                    LEFT JOIN customers c ON cf.customer_id = c.id
                    LEFT JOIN users u ON cf.uploaded_by = u.id
                    WHERE cf.customer_id IN ({$placeholders})
                    AND cf.deleted_at IS NULL
                    ORDER BY cf.uploaded_at DESC
                    LIMIT 10
                ");
                $stmt->execute($customerIds);
                $recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // 统一输出时间格式，避免前端直接渲染成 [object Object] 或时间戳
            $recentFiles = array_map(static function($row) {
                $value = $row['created_at'] ?? null;
                if (is_numeric($value)) {
                    $row['created_at'] = date('Y-m-d H:i', (int)$value);
                }
                return $row;
            }, $recentFiles);
        }
        
        echo json_encode([
            'success' => true,
            'sync_status' => [
                'pending_downloads' => $pendingDownloads,
                'pending_approvals' => $pendingApprovals,
                'to_review' => $toReview,
                'project_count' => count($projects),
            ],
            'projects' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'project_name' => $p['project_name'] ?? null,
                    'project_code' => $p['project_code'] ?? null,
                    'group_code' => $p['group_code'] ?? null,
                    'group_name' => $p['group_name'],
                ];
            }, $projects),
            'recent_files' => $recentFiles,
            'server_time' => time(),
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
}
