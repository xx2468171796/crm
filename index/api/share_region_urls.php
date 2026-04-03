<?php
/**
 * 分享区域链接API
 * 
 * 统一入口：所有前端（桌面端、客户门户等）都通过此API获取多区域分享链接
 * 内部调用 ShareRegionService 统一服务层处理业务逻辑
 * 
 * @endpoint GET/POST /api/share_region_urls.php?token=xxx
 * @response {success: bool, data: [{region_name, url, is_default, ...}], default_url: string}
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../services/ShareRegionService.php';

header('Content-Type: application/json; charset=utf-8');

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => '缺少token参数']);
    exit;
}

try {
    $regionUrls = ShareRegionService::generateRegionUrls($token);
    
    echo json_encode([
        'success' => true,
        'data' => $regionUrls,
        'default_url' => ShareRegionService::getDefaultUrl($token)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '获取区域链接失败: ' . $e->getMessage()]);
}
