<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 文件审批 API
 * 
 * GET ?action=list - 获取待审批文件列表
 * GET ?action=my_files - 获取自己上传的文件
 * POST action=approve - 通过审批
 * POST action=reject - 驳回审批
 * POST action=batch_approve - 批量通过
 * POST action=batch_reject - 批量驳回
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// 权限判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
$isTechManager = $user['role'] === 'tech_manager';
$isTech = $user['role'] === 'tech';

try {
    switch ($action) {
        case 'list':
            handleList($user, $isManager, $isTechManager);
            break;
        case 'my_files':
            handleMyFiles($user);
            break;
        case 'approve':
            handleApprove($user, $isManager);
            break;
        case 'reject':
            handleReject($user, $isManager);
            break;
        case 'batch_approve':
            handleBatchApprove($user, $isManager);
            break;
        case 'batch_reject':
            handleBatchReject($user, $isManager);
            break;
        case 'stats':
            handleStats($user, $isManager, $isTechManager);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_approval 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取待审批文件列表（管理员/技术主管）
 */
function handleList($user, $isManager, $isTechManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限查看审批列表'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $status = $_GET['status'] ?? 'pending'; // pending, approved, rejected, all
    $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $uploaderId = isset($_GET['uploader_id']) ? (int)$_GET['uploader_id'] : null;
    $techUserId = isset($_GET['tech_user_id']) ? (int)$_GET['tech_user_id'] : null;
    $projectStatus = $_GET['project_status'] ?? null;
    $fileCategories = isset($_GET['file_categories']) ? explode(',', $_GET['file_categories']) : null;
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    $conditions = ["d.deleted_at IS NULL"];
    $params = [];
    
    // 状态筛选
    if ($status === 'pending') {
        $conditions[] = "d.approval_status = 'pending'";
    } elseif ($status === 'approved') {
        $conditions[] = "d.approval_status = 'approved'";
    } elseif ($status === 'rejected') {
        $conditions[] = "d.approval_status = 'rejected'";
    }
    
    // 技术主管只能审批技术人员上传的文件
    if ($isTechManager) {
        $conditions[] = "u.role = 'tech'";
    }
    
    // 项目筛选
    if ($projectId) {
        $conditions[] = "d.project_id = ?";
        $params[] = (int)$projectId;
    }
    
    // 上传人筛选
    if ($uploaderId) {
        $conditions[] = "d.submitted_by = ?";
        $params[] = $uploaderId;
    }
    
    // 技术人员筛选
    if ($techUserId) {
        $conditions[] = "pta.tech_user_id = ?";
        $params[] = $techUserId;
    }
    
    // 项目阶段筛选
    if ($projectStatus) {
        $conditions[] = "p.current_status = ?";
        $params[] = $projectStatus;
    }
    
    // 文件类型筛选
    if ($fileCategories && !empty($fileCategories)) {
        $placeholders = implode(',', array_fill(0, count($fileCategories), '?'));
        $conditions[] = "d.file_category IN ($placeholders)";
        $params = array_merge($params, $fileCategories);
    }
    
    // 时间筛选（按上传时间）
    if ($startDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(d.create_time)) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(d.create_time)) <= ?";
        $params[] = $endDate;
    }
    
    $whereClause = implode(' AND ', $conditions);
    $offset = ($page - 1) * $perPage;
    
    // 查询文件列表
    $sql = "
        SELECT 
            d.id, d.deliverable_name as filename, d.file_path, d.file_size, d.file_category,
            d.approval_status, d.approved_by, d.approved_at, d.reject_reason as rejection_reason,
            d.submitted_at as upload_time,
            p.id as project_id, p.project_code, p.project_name, p.current_status as project_status,
            c.id as customer_id, c.name as customer_name,
            u.id as uploader_id, u.realname as uploader_name, u.role as uploader_role,
            approver.realname as approver_name,
            pta.tech_user_id, tech_user.realname as tech_user_name
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON d.submitted_by = u.id
        LEFT JOIN users approver ON d.approved_by = approver.id
        LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
        LEFT JOIN users tech_user ON pta.tech_user_id = tech_user.id
        WHERE {$whereClause}
        ORDER BY d.submitted_at DESC
        LIMIT {$offset}, {$perPage}
    ";
    
    $files = Db::query($sql, $params);
    
    // 查询总数
    $countSql = "
        SELECT COUNT(DISTINCT d.id) as total
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN users u ON d.submitted_by = u.id
        LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
        WHERE {$whereClause}
    ";
    $countResult = Db::queryOne($countSql, $params);
    $total = (int)($countResult['total'] ?? 0);
    
    // 统计数据 - 使用不含状态筛选的基础条件
    $statsConditions = ["d.deleted_at IS NULL"];
    $statsParams = [];
    
    if ($isTechManager) {
        $statsConditions[] = "u.role = 'tech'";
    }
    
    // 项目筛选
    if ($projectId) {
        $statsConditions[] = "d.project_id = ?";
        $statsParams[] = (int)$projectId;
    }
    
    // 上传人筛选
    if ($uploaderId) {
        $statsConditions[] = "d.submitted_by = ?";
        $statsParams[] = $uploaderId;
    }
    
    // 技术人员筛选
    if ($techUserId) {
        $statsConditions[] = "pta.tech_user_id = ?";
        $statsParams[] = $techUserId;
    }
    
    // 项目阶段筛选
    if ($projectStatus) {
        $statsConditions[] = "p.current_status = ?";
        $statsParams[] = $projectStatus;
    }
    
    // 文件类型筛选
    if ($fileCategories && !empty($fileCategories)) {
        $placeholders = implode(',', array_fill(0, count($fileCategories), '?'));
        $statsConditions[] = "d.file_category IN ($placeholders)";
        $statsParams = array_merge($statsParams, $fileCategories);
    }
    
    // 时间筛选
    if ($startDate) {
        $statsConditions[] = "DATE(FROM_UNIXTIME(d.create_time)) >= ?";
        $statsParams[] = $startDate;
    }
    if ($endDate) {
        $statsConditions[] = "DATE(FROM_UNIXTIME(d.create_time)) <= ?";
        $statsParams[] = $endDate;
    }
    
    $statsWhere = implode(' AND ', $statsConditions);
    
    // 构建统计查询 - 根据是否有tech_user筛选决定是否需要JOIN
    $needTechJoin = $techUserId ? true : false;
    
    if ($needTechJoin) {
        $statsSql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN d.approval_status = 'pending' THEN d.id END) as pending,
                COUNT(DISTINCT CASE WHEN d.approval_status = 'approved' THEN d.id END) as approved,
                COUNT(DISTINCT CASE WHEN d.approval_status = 'rejected' THEN d.id END) as rejected
            FROM deliverables d
            LEFT JOIN users u ON d.submitted_by = u.id
            LEFT JOIN projects p ON d.project_id = p.id
            LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
            WHERE {$statsWhere}
        ";
    } else {
        // 移除tech相关条件，简化查询
        $simpleStatsConditions = ["d.deleted_at IS NULL"];
        $simpleStatsParams = [];
        
        if ($isTechManager) {
            $simpleStatsConditions[] = "u.role = 'tech'";
        }
        if ($projectId) {
            $simpleStatsConditions[] = "d.project_id = ?";
            $simpleStatsParams[] = (int)$projectId;
        }
        if ($uploaderId) {
            $simpleStatsConditions[] = "d.submitted_by = ?";
            $simpleStatsParams[] = $uploaderId;
        }
        if ($projectStatus) {
            $simpleStatsConditions[] = "p.current_status = ?";
            $simpleStatsParams[] = $projectStatus;
        }
        if ($fileCategories && !empty($fileCategories)) {
            $placeholders = implode(',', array_fill(0, count($fileCategories), '?'));
            $simpleStatsConditions[] = "d.file_category IN ($placeholders)";
            $simpleStatsParams = array_merge($simpleStatsParams, $fileCategories);
        }
        if ($startDate) {
            $simpleStatsConditions[] = "DATE(FROM_UNIXTIME(d.create_time)) >= ?";
            $simpleStatsParams[] = $startDate;
        }
        if ($endDate) {
            $simpleStatsConditions[] = "DATE(FROM_UNIXTIME(d.create_time)) <= ?";
            $simpleStatsParams[] = $endDate;
        }
        
        $simpleStatsWhere = implode(' AND ', $simpleStatsConditions);
        $statsParams = $simpleStatsParams;
        
        $statsSql = "
            SELECT 
                SUM(CASE WHEN d.approval_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN d.approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN d.approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM deliverables d
            LEFT JOIN users u ON d.submitted_by = u.id
            LEFT JOIN projects p ON d.project_id = p.id
            WHERE {$simpleStatsWhere}
        ";
    }
    $stats = Db::queryOne($statsSql, $statsParams);
    
    // 格式化结果
    $items = [];
    foreach ($files as $file) {
        $mimeType = '';
        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
        if (in_array($ext, $imageExts)) {
            $mimeType = 'image/' . $ext;
        }
        
        $items[] = [
            'id' => (int)$file['id'],
            'filename' => $file['filename'],
            'file_path' => $file['file_path'],
            'file_size' => (int)$file['file_size'],
            'file_category' => $file['file_category'],
            'mime_type' => $mimeType,
            'approval_status' => $file['approval_status'] === 'approved' ? 1 : ($file['approval_status'] === 'rejected' ? 2 : 0),
            'approved_by' => $file['approved_by'] ? (int)$file['approved_by'] : null,
            'approver_name' => $file['approver_name'],
            'approved_at' => $file['approved_at'] ? date('Y-m-d H:i', $file['approved_at']) : null,
            'rejection_reason' => $file['rejection_reason'],
            'project' => [
                'id' => (int)$file['project_id'],
                'code' => $file['project_code'],
                'name' => $file['project_name'],
                'status' => $file['project_status'],
                'customer_id' => $file['customer_id'] ? (int)$file['customer_id'] : null,
                'customer_name' => $file['customer_name'],
            ],
            'uploader' => [
                'id' => (int)$file['uploader_id'],
                'name' => $file['uploader_name'],
                'role' => $file['uploader_role'],
            ],
            'tech_user' => $file['tech_user_id'] ? [
                'id' => (int)$file['tech_user_id'],
                'name' => $file['tech_user_name'],
            ] : null,
            'upload_time' => $file['upload_time'] ? date('Y-m-d H:i', $file['upload_time']) : null,
            'is_image' => in_array($ext, $imageExts),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'stats' => [
                'pending' => (int)($stats['pending'] ?? 0),
                'approved' => (int)($stats['approved'] ?? 0),
                'rejected' => (int)($stats['rejected'] ?? 0),
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取自己上传的文件
 */
function handleMyFiles($user) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $status = $_GET['status'] ?? 'all';
    
    $conditions = ["d.deleted_at IS NULL", "d.submitted_by = ?"];
    $params = [$user['id']];
    
    if ($status === 'pending') {
        $conditions[] = "d.approval_status = 'pending'";
    } elseif ($status === 'approved') {
        $conditions[] = "d.approval_status = 'approved'";
    } elseif ($status === 'rejected') {
        $conditions[] = "d.approval_status = 'rejected'";
    }
    
    $whereClause = implode(' AND ', $conditions);
    $offset = ($page - 1) * $perPage;
    
    $sql = "
        SELECT 
            d.id, d.deliverable_name as filename, d.file_path, d.file_size, d.file_category,
            d.approval_status, d.approved_at, d.reject_reason as rejection_reason,
            d.submitted_at as upload_time,
            p.id as project_id, p.project_code, p.project_name, p.current_status as project_status,
            c.name as customer_name,
            u.id as uploader_id, u.realname as uploader_name, u.role as uploader_role,
            approver.realname as approver_name,
            pta.tech_user_id, tech_user.realname as tech_user_name
        FROM deliverables d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        LEFT JOIN users u ON d.submitted_by = u.id
        LEFT JOIN users approver ON d.approved_by = approver.id
        LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
        LEFT JOIN users tech_user ON pta.tech_user_id = tech_user.id
        WHERE {$whereClause}
        ORDER BY d.submitted_at DESC
        LIMIT {$offset}, {$perPage}
    ";
    
    $files = Db::query($sql, $params);
    
    // 统计
    $statsSql = "
        SELECT 
            SUM(CASE WHEN d.approval_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN d.approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN d.approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM deliverables d
        WHERE d.deleted_at IS NULL AND d.submitted_by = ?
    ";
    $stats = Db::queryOne($statsSql, [$user['id']]);
    
    $items = [];
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
        
        $items[] = [
            'id' => (int)$file['id'],
            'filename' => $file['filename'],
            'file_path' => $file['file_path'],
            'file_size' => (int)$file['file_size'],
            'file_category' => $file['file_category'] ?? '',
            'mime_type' => in_array($ext, $imageExts) ? 'image/' . $ext : '',
            'approval_status' => $file['approval_status'] === 'approved' ? 1 : ($file['approval_status'] === 'rejected' ? 2 : 0),
            'approver_name' => $file['approver_name'],
            'approved_at' => $file['approved_at'] ? date('Y-m-d H:i', $file['approved_at']) : null,
            'rejection_reason' => $file['rejection_reason'],
            'project' => [
                'id' => (int)($file['project_id'] ?? 0),
                'code' => $file['project_code'] ?? '',
                'name' => $file['project_name'] ?? '未知项目',
                'status' => $file['project_status'] ?? '',
                'customer_name' => $file['customer_name'] ?? '',
            ],
            'uploader' => [
                'id' => (int)($file['uploader_id'] ?? 0),
                'name' => $file['uploader_name'] ?? '',
                'role' => $file['uploader_role'] ?? '',
            ],
            'tech_user' => $file['tech_user_id'] ? [
                'id' => (int)$file['tech_user_id'],
                'name' => $file['tech_user_name'] ?? '',
            ] : null,
            'upload_time' => $file['upload_time'] ? date('Y-m-d H:i', $file['upload_time']) : null,
            'is_image' => in_array($ext, $imageExts),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'stats' => [
                'pending' => (int)($stats['pending'] ?? 0),
                'approved' => (int)($stats['approved'] ?? 0),
                'rejected' => (int)($stats['rejected'] ?? 0),
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 通过审批
 */
function handleApprove($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限审批'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = (int)($input['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("
        UPDATE deliverables 
        SET approval_status = 'approved', approved_by = ?, approved_at = ?, reject_reason = NULL, update_time = ?
        WHERE id = ?
    ", [$user['id'], $now, $now, $fileId]);
    
    echo json_encode(['success' => true, 'message' => '审批通过'], JSON_UNESCAPED_UNICODE);
}

/**
 * 驳回审批
 */
function handleReject($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限审批'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = (int)($input['file_id'] ?? 0);
    $reason = trim($input['reason'] ?? '');
    
    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '参数错误'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    Db::execute("
        UPDATE deliverables 
        SET approval_status = 'rejected', approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
        WHERE id = ?
    ", [$user['id'], $now, $reason ?: null, $now, $fileId]);
    
    echo json_encode(['success' => true, 'message' => '已驳回'], JSON_UNESCAPED_UNICODE);
}

/**
 * 批量通过
 */
function handleBatchApprove($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限审批'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $fileIds = $input['file_ids'] ?? [];
    
    if (empty($fileIds) || !is_array($fileIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '请选择要审批的文件'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $params = array_merge([$user['id'], $now, $now], array_map('intval', $fileIds));
    
    Db::execute("
        UPDATE deliverables 
        SET approval_status = 'approved', approved_by = ?, approved_at = ?, reject_reason = NULL, update_time = ?
        WHERE id IN ({$placeholders})
    ", $params);
    
    echo json_encode(['success' => true, 'message' => '批量通过成功', 'count' => count($fileIds)], JSON_UNESCAPED_UNICODE);
}

/**
 * 批量驳回
 */
function handleBatchReject($user, $isManager) {
    if (!$isManager) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '无权限审批'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $fileIds = $input['file_ids'] ?? [];
    $reason = trim($input['reason'] ?? '');
    
    if (empty($fileIds) || !is_array($fileIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '请选择要驳回的文件'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $now = time();
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $params = array_merge([$user['id'], $now, $reason ?: null, $now], array_map('intval', $fileIds));
    
    Db::execute("
        UPDATE deliverables 
        SET approval_status = 'rejected', approved_by = ?, approved_at = ?, reject_reason = ?, update_time = ?
        WHERE id IN ({$placeholders})
    ", $params);
    
    echo json_encode(['success' => true, 'message' => '批量驳回成功', 'count' => count($fileIds)], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取统计数据
 */
function handleStats($user, $isManager, $isTechManager) {
    $conditions = ["d.deleted_at IS NULL"];
    
    if (!$isManager) {
        // 非管理员只看自己的
        $conditions[] = "d.submitted_by = " . (int)$user['id'];
    } elseif ($isTechManager) {
        // 技术主管只看技术人员的
        $conditions[] = "u.role = 'tech'";
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $sql = "
        SELECT 
            SUM(CASE WHEN d.approval_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN d.approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN d.approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM deliverables d
        LEFT JOIN users u ON d.submitted_by = u.id
        WHERE {$whereClause}
    ";
    $stats = Db::queryOne($sql);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'pending' => (int)($stats['pending'] ?? 0),
            'approved' => (int)($stats['approved'] ?? 0),
            'rejected' => (int)($stats['rejected'] ?? 0),
        ]
    ], JSON_UNESCAPED_UNICODE);
}
