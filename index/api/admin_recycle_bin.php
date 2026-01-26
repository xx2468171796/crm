<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 后台回收站管理 API
 * 
 * GET  ?action=list              - 获取回收站文件列表
 * POST action=restore&id=X       - 恢复文件
 * POST action=delete&id=X        - 真删除（含S3）
 * POST action=empty              - 清空回收站（30天前的文件）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../services/S3Service.php';

$user = desktop_auth_require();

// 仅管理员可访问
$managerRoles = ['admin', 'super_admin', 'manager', 'tech_manager'];
if (!in_array($user['role'] ?? '', $managerRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '无权访问回收站'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handleList();
            break;
        case 'restore':
            handleRestore($user);
            break;
        case 'delete':
            handleDelete($user);
            break;
        case 'empty':
            handleEmpty($user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[admin_recycle_bin] 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取回收站文件列表
 */
function handleList() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 20)));
    $projectId = (int)($_GET['project_id'] ?? 0);
    $search = trim($_GET['search'] ?? '');
    
    $offset = ($page - 1) * $pageSize;
    
    $conditions = ['d.deleted_at IS NOT NULL'];
    $params = [];
    
    if ($projectId > 0) {
        $conditions[] = 'd.project_id = ?';
        $params[] = $projectId;
    }
    
    if ($search) {
        $conditions[] = 'd.deliverable_name LIKE ?';
        $params[] = "%{$search}%";
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // 查询总数
    $countSql = "SELECT COUNT(*) as total FROM deliverables d WHERE {$whereClause}";
    $total = (int)Db::queryOne($countSql, $params)['total'];
    
    // 查询列表
    $sql = "
        SELECT 
            d.id, d.deliverable_name as filename, d.file_path, d.file_size,
            d.file_category, d.deleted_at, d.deleted_by,
            d.project_id,
            p.project_name, p.project_code,
            u.realname as deleted_by_name,
            DATEDIFF(DATE_ADD(FROM_UNIXTIME(d.deleted_at), INTERVAL 30 DAY), NOW()) as days_remaining
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN users u ON d.deleted_by = u.id
        WHERE {$whereClause}
        ORDER BY d.deleted_at DESC
        LIMIT {$offset}, {$pageSize}
    ";
    
    $files = Db::query($sql, $params);
    
    $result = [];
    foreach ($files as $file) {
        $result[] = [
            'id' => (int)$file['id'],
            'filename' => $file['filename'],
            'file_path' => $file['file_path'],
            'file_size' => (int)$file['file_size'],
            'file_category' => $file['file_category'],
            'project_id' => (int)$file['project_id'],
            'project_name' => $file['project_name'],
            'project_code' => $file['project_code'],
            'deleted_at' => $file['deleted_at'] ? date('Y-m-d H:i', $file['deleted_at']) : null,
            'deleted_by' => (int)($file['deleted_by'] ?? 0),
            'deleted_by_name' => $file['deleted_by_name'],
            'days_remaining' => max(0, (int)$file['days_remaining']),
            'can_restore' => ((int)$file['days_remaining']) > 0,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'files' => $result,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => ceil($total / $pageSize),
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 恢复文件
 */
function handleRestore($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = (int)($input['id'] ?? $_POST['id'] ?? 0);
    
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'error' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $pdo = Db::pdo();
    
    // 检查文件是否在回收站
    $file = Db::queryOne("SELECT id, deliverable_name, deleted_at FROM deliverables WHERE id = ? AND deleted_at IS NOT NULL", [$fileId]);
    if (!$file) {
        echo json_encode(['success' => false, 'error' => '文件不存在或未在回收站中'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 恢复文件
    $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = NULL, deleted_by = NULL, update_time = ? WHERE id = ?");
    $stmt->execute([time(), $fileId]);
    
    echo json_encode([
        'success' => true,
        'message' => '文件已恢复',
        'data' => ['id' => $fileId, 'filename' => $file['deliverable_name']],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 真删除文件（含S3，异步删除S3）
 */
function handleDelete($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = (int)($input['id'] ?? $_POST['id'] ?? 0);
    
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'error' => '缺少文件ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $pdo = Db::pdo();
    
    // 获取文件信息
    $file = Db::queryOne("SELECT id, deliverable_name, file_path, deleted_at FROM deliverables WHERE id = ?", [$fileId]);
    if (!$file) {
        echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $filePath = $file['file_path'];
    
    // 先删除数据库记录
    $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
    $stmt->execute([$fileId]);
    
    // 先返回响应
    $response = json_encode([
        'success' => true,
        'message' => '文件已彻底删除',
        'data' => [
            'id' => $fileId,
            'filename' => $file['deliverable_name'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    echo $response;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else flush();
    
    // 后台异步删除S3文件
    if (!empty($filePath)) {
        try {
            $s3 = new S3Service();
            $s3->deleteObject($filePath);
            error_log("[admin_recycle_bin] S3删除成功: $filePath");
        } catch (Exception $e) {
            error_log('[admin_recycle_bin] S3删除失败: ' . $e->getMessage());
        }
    }
}

/**
 * 清空回收站（删除30天前的文件）
 */
function handleEmpty($user) {
    $pdo = Db::pdo();
    
    // 获取30天前的文件
    $cutoffTime = time() - (30 * 24 * 60 * 60);
    $files = Db::query("SELECT id, deliverable_name, file_path FROM deliverables WHERE deleted_at IS NOT NULL AND deleted_at < ?", [$cutoffTime]);
    
    if (empty($files)) {
        echo json_encode(['success' => true, 'message' => '没有需要清理的文件', 'data' => ['deleted_count' => 0]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $s3 = new S3Service();
    $deletedCount = 0;
    $errors = [];
    
    foreach ($files as $file) {
        try {
            // 删除S3文件
            if (!empty($file['file_path'])) {
                $s3->deleteObject($file['file_path']);
            }
            
            // 删除数据库记录
            $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
            $stmt->execute([$file['id']]);
            $deletedCount++;
        } catch (Exception $e) {
            $errors[] = $file['deliverable_name'] . ': ' . $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "已清理 {$deletedCount} 个过期文件",
        'data' => [
            'deleted_count' => $deletedCount,
            'errors' => $errors,
        ],
    ], JSON_UNESCAPED_UNICODE);
}
