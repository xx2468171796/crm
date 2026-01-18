<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 文件夹上传进度查询 API
 * GET /api/folder_upload_status.php?session_id=xxx
 * 
 * 参数:
 * - session_id: 上传会话ID（可选，用于跟踪特定批次）
 * - project_id: 项目ID（可选，用于查询项目下的上传进度）
 * 
 * 返回:
 * - total_files: 总文件数
 * - completed_files: 已完成文件数
 * - pending_files: 待上传文件数
 * - failed_files: 失败文件数
 */

require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$sessionId = $_GET['session_id'] ?? '';
$projectId = (int)($_GET['project_id'] ?? 0);

try {
    $pdo = Db::pdo();
    
    // 查询最近的上传日志统计
    $where = 'user_id = ? AND operation = ?';
    $params = [$user['id'], 'upload'];
    
    if ($projectId > 0) {
        $where .= ' AND project_id = ?';
        $params[] = $projectId;
    }
    
    // 最近24小时的上传统计
    $where .= ' AND create_time > ?';
    $params[] = time() - 86400;
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_files,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as completed_files,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_files,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_files
        FROM file_sync_logs
        WHERE {$where}
    ");
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_files' => (int)($stats['total_files'] ?? 0),
            'completed_files' => (int)($stats['completed_files'] ?? 0),
            'pending_files' => (int)($stats['pending_files'] ?? 0),
            'failed_files' => (int)($stats['failed_files'] ?? 0),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] folder_upload_status 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
