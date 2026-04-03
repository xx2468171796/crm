<?php
/**
 * 客户门户文件管理 API
 * 支持删除和重命名客户上传的文件
 * POST /api/portal_file_manage.php
 */

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$token = trim($_POST['token'] ?? '');
$projectId = intval($_POST['project_id'] ?? 0);

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少token参数']);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证门户token
    $portalStmt = $pdo->prepare("SELECT * FROM portal_links WHERE token = ? AND enabled = 1");
    $portalStmt->execute([$token]);
    $portal = $portalStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$portal) {
        http_response_code(401);
        echo json_encode(['error' => '无效的访问令牌']);
        exit;
    }
    
    // 获取客户信息
    $customerId = $portal['customer_id'];
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(401);
        echo json_encode(['error' => '客户不存在']);
        exit;
    }
    
    // 根据action执行不同操作
    switch ($action) {
        case 'delete':
            handleDelete($pdo, $customer, $projectId);
            break;
        case 'batch_delete':
            handleBatchDelete($pdo, $customer, $projectId);
            break;
        case 'rename':
            handleRename($pdo, $customer, $projectId);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => '无效的操作']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
}

/**
 * 删除单个文件
 */
function handleDelete($pdo, $customer, $projectId) {
    $fileId = intval($_POST['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少文件ID']);
        exit;
    }
    
    // 验证文件属于该客户的项目且是客户上传的文件
    $stmt = $pdo->prepare("
        SELECT d.id, d.file_path, d.deliverable_name, p.customer_id
        FROM deliverables d
        JOIN projects p ON d.project_id = p.id
        WHERE d.id = ? AND p.customer_id = ? AND d.file_category = 'customer_file'
    ");
    $stmt->execute([$fileId, $customer['id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => '文件不存在或无权操作']);
        exit;
    }
    
    // 软删除：标记为已删除
    $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = ?, update_time = ? WHERE id = ?");
    $stmt->execute([time(), time(), $fileId]);
    
    echo json_encode([
        'success' => true,
        'message' => '文件已删除'
    ]);
}

/**
 * 批量删除文件
 */
function handleBatchDelete($pdo, $customer, $projectId) {
    $fileIds = $_POST['file_ids'] ?? '';
    
    if (empty($fileIds)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少文件ID列表']);
        exit;
    }
    
    // 解析文件ID列表
    $ids = array_filter(array_map('intval', explode(',', $fileIds)), fn($id) => $id > 0);
    
    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['error' => '文件ID无效']);
        exit;
    }
    
    $deletedCount = 0;
    $now = time();
    
    foreach ($ids as $fileId) {
        // 验证文件属于该客户的项目且是客户上传的文件
        $stmt = $pdo->prepare("
            SELECT d.id
            FROM deliverables d
            JOIN projects p ON d.project_id = p.id
            WHERE d.id = ? AND p.customer_id = ? AND d.file_category = 'customer_file'
        ");
        $stmt->execute([$fileId, $customer['id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($file) {
            $stmt = $pdo->prepare("UPDATE deliverables SET deleted_at = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$now, $now, $fileId]);
            $deletedCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'deleted_count' => $deletedCount,
        'message' => "已删除 {$deletedCount} 个文件"
    ]);
}

/**
 * 重命名文件
 */
function handleRename($pdo, $customer, $projectId) {
    $fileId = intval($_POST['file_id'] ?? 0);
    $newName = trim($_POST['new_name'] ?? '');
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '缺少文件ID']);
        exit;
    }
    
    if (empty($newName)) {
        http_response_code(400);
        echo json_encode(['error' => '新文件名不能为空']);
        exit;
    }
    
    // 验证文件属于该客户的项目且是客户上传的文件
    $stmt = $pdo->prepare("
        SELECT d.id, d.deliverable_name
        FROM deliverables d
        JOIN projects p ON d.project_id = p.id
        WHERE d.id = ? AND p.customer_id = ? AND d.file_category = 'customer_file'
    ");
    $stmt->execute([$fileId, $customer['id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => '文件不存在或无权操作']);
        exit;
    }
    
    // 保留前缀（客户上传+ 或 分享+）
    $oldName = $file['deliverable_name'];
    $prefix = '';
    if (strpos($oldName, '客户上传+') === 0) {
        $prefix = '客户上传+';
    } elseif (strpos($oldName, '分享+') === 0) {
        $prefix = '分享+';
    }
    
    $finalName = $prefix . $newName;
    
    // 更新文件名
    $stmt = $pdo->prepare("UPDATE deliverables SET deliverable_name = ?, update_time = ? WHERE id = ?");
    $stmt->execute([$finalName, time(), $fileId]);
    
    echo json_encode([
        'success' => true,
        'new_name' => $finalName,
        'message' => '重命名成功'
    ]);
}
