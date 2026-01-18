<?php
/**
 * 客户门户 - 交付物 API
 * 通过客户token获取已审批的交付作品
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    require_once __DIR__ . '/../core/db.php';
    // require_once __DIR__ . '/../services/S3Service.php';
    
    $token = trim($_GET['token'] ?? '');
    $projectId = intval($_GET['project_id'] ?? 0);
    
    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => '缺少token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $pdo = Db::pdo();
    
    // 验证客户token（通过portal_links表）
    $stmt = $pdo->prepare("SELECT pl.customer_id, c.name as customer_name FROM portal_links pl LEFT JOIN customers c ON pl.customer_id = c.id WHERE pl.token = ? AND pl.enabled = 1 LIMIT 1");
    $stmt->execute([$token]);
    $portal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$portal) {
        echo json_encode(['success' => false, 'message' => '无效或已过期的访问链接'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $customerId = $portal['customer_id'];

// 构建查询条件
$conditions = [
    "d.deleted_at IS NULL",
    "d.approval_status = 'approved'",
    "d.visibility_level = 'client'",
    "d.is_folder = 0",
    "p.customer_id = ?"
];
$params = [$customerId];

// 按项目筛选
if ($projectId > 0) {
    $conditions[] = "d.project_id = ?";
    $params[] = $projectId;
}

// 按文件类别筛选
$fileCategory = trim($_GET['file_category'] ?? '');
if (!empty($fileCategory)) {
    $conditions[] = "d.file_category = ?";
    $params[] = $fileCategory;
}

$whereClause = implode(' AND ', $conditions);

$sql = "
    SELECT 
        d.id,
        d.deliverable_name,
        d.file_path,
        d.file_size,
        d.file_category,
        d.visibility_level,
        d.approval_status,
        d.share_enabled,
        d.create_time,
        p.id as project_id,
        p.project_name,
        p.project_code
    FROM deliverables d
    INNER JOIN projects p ON d.project_id = p.id
    WHERE {$whereClause}
    ORDER BY d.create_time DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliverables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 为每个文件生成URL - 从配置读取S3公开访问地址
    require_once __DIR__ . '/../core/storage/storage_provider.php';
    $storageConfig = storage_config();
    $s3Config = $storageConfig['s3'] ?? [];
    // 优先使用 public_url，否则拼接 endpoint + bucket
    $s3Endpoint = $s3Config['public_url'] 
        ?? rtrim($s3Config['endpoint'] ?? '', '/') . '/' . ($s3Config['bucket'] ?? '') . '/';
    
    foreach ($deliverables as &$d) {
        if (!empty($d['file_path'])) {
            // 直接拼接S3 URL（公开访问）
            $d['file_url'] = $s3Endpoint . $d['file_path'];
            // 只在启用分享时生成分享链接
            if (!empty($d['share_enabled'])) {
                $d['share_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                    . '://' . $_SERVER['HTTP_HOST'] 
                    . '/api/portal_file_share.php?id=' . $d['id'] . '&token=' . $token;
            } else {
                $d['share_url'] = '';
            }
        }
    }
    unset($d);

    echo json_encode([
        'success' => true,
        'data' => $deliverables
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
