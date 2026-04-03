<?php
/**
 * 客户门户 - 创建分享链接
 * 
 * POST 参数:
 * - deliverable_id: 交付物ID
 * - portal_token: 门户访问token（用于验证权限）
 * - expire_hours: 过期时间（小时），0表示永不过期
 * - max_downloads: 最大下载次数，0表示无限制
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '仅支持POST请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../core/db.php';

// 获取请求参数
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$deliverableId = (int)($input['deliverable_id'] ?? $_POST['deliverable_id'] ?? 0);
$portalToken = trim($input['portal_token'] ?? $_POST['portal_token'] ?? '');
$expireHours = (int)($input['expire_hours'] ?? $_POST['expire_hours'] ?? 0);
$maxDownloads = (int)($input['max_downloads'] ?? $_POST['max_downloads'] ?? 0);

if (empty($deliverableId)) {
    echo json_encode(['success' => false, 'message' => '缺少交付物ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($portalToken)) {
    echo json_encode(['success' => false, 'message' => '缺少门户token'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Db::pdo();
    
    // 验证门户token和交付物权限（支持 portal_links 表）
    $stmt = $pdo->prepare("
        SELECT d.id, d.share_enabled
        FROM deliverables d
        INNER JOIN projects p ON p.id = d.project_id
        INNER JOIN portal_links pl ON pl.customer_id = p.customer_id
        WHERE d.id = ?
        AND pl.token = ?
        AND pl.enabled = 1
        AND d.deleted_at IS NULL
        AND d.approval_status = 'approved'
        AND d.visibility_level = 'client'
        LIMIT 1
    ");
    $stmt->execute([$deliverableId, $portalToken]);
    $deliverable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deliverable) {
        echo json_encode(['success' => false, 'message' => '无权访问此交付物或交付物不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 生成分享token
    $shareToken = bin2hex(random_bytes(24));
    
    // 计算过期时间
    $expireAt = null;
    if ($expireHours > 0) {
        $expireAt = date('Y-m-d H:i:s', time() + $expireHours * 3600);
    }
    
    // 插入分享记录
    $stmt = $pdo->prepare("
        INSERT INTO deliverable_shares 
        (deliverable_id, share_token, portal_token, expire_at, max_downloads, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $deliverableId, 
        $shareToken, 
        $portalToken, 
        $expireAt,
        $maxDownloads > 0 ? $maxDownloads : null
    ]);
    
    // 生成分享URL - 使用多区域服务
    require_once __DIR__ . '/../services/ShareRegionService.php';
    $regionUrls = ShareRegionService::generateRegionUrls($shareToken, '/portal_share.php?s=');
    
    // 默认URL（当前主机）
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $shareUrl = $baseUrl . '/portal_share.php?s=' . $shareToken;
    
    echo json_encode([
        'success' => true,
        'share_url' => $shareUrl,
        'share_token' => $shareToken,
        'expire_at' => $expireAt,
        'max_downloads' => $maxDownloads > 0 ? $maxDownloads : null,
        'region_urls' => $regionUrls
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
