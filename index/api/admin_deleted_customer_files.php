<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 管理员查看已删除客户文件接口
 * 权限：仅系统管理员可访问
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../services/CustomerFileService.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CUSTOMER_DELETE)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '无权限访问'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取筛选参数
    $filters = [
        'page' => max(1, intval($_GET['page'] ?? 1)),
        'page_size' => min(100, max(10, intval($_GET['page_size'] ?? 50))),
    ];
    
    if (!empty($_GET['customer_id'])) {
        $filters['customer_id'] = intval($_GET['customer_id']);
    }
    
    if (!empty($_GET['category']) && in_array($_GET['category'], ['client_material', 'internal_solution'], true)) {
        $filters['category'] = $_GET['category'];
    }
    
    if (!empty($_GET['keyword'])) {
        $filters['keyword'] = trim($_GET['keyword']);
    }
    
    if (!empty($_GET['deleted_start_at'])) {
        $filters['deleted_start_at'] = $_GET['deleted_start_at'];
    }
    
    if (!empty($_GET['deleted_end_at'])) {
        $filters['deleted_end_at'] = $_GET['deleted_end_at'];
    }
    
    // 调用服务方法
    $service = new CustomerFileService();
    $result = $service->listDeletedCustomerFiles($filters, $user);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Admin deleted customer files list error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '查询失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

