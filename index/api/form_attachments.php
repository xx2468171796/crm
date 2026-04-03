<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 表单附件查询 API
 * 
 * GET /api/form_attachments.php?instance_id=123
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/S3Service.php';

$instanceId = intval($_GET['instance_id'] ?? 0);

if ($instanceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少表单实例ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询表单附件
    $stmt = $pdo->prepare("
        SELECT id, deliverable_name as filename, file_size, mime_type, storage_key, 
               status, create_time
        FROM deliverables 
        WHERE form_instance_id = ? AND status = 'approved'
        ORDER BY create_time DESC
    ");
    $stmt->execute([$instanceId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 生成下载链接
    $s3 = new S3Service();
    foreach ($attachments as &$att) {
        $att['download_url'] = $s3->getPresignedUrl($att['storage_key'], 3600);
        $att['file_size_formatted'] = formatFileSize($att['file_size']);
        $att['create_time_formatted'] = date('Y-m-d H:i', $att['create_time']);
        unset($att['storage_key']); // 不暴露存储路径
    }
    
    echo json_encode([
        'success' => true,
        'data' => $attachments
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}
