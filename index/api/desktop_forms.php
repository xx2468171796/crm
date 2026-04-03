<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 表单处理 API
 * 
 * GET ?action=list - 获取待处理表单列表
 * GET ?action=detail&id=X - 获取表单详情
 * POST action=process - 处理表单
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = desktop_auth_require();

// 角色判断
$isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);

// 调试日志
error_log("[desktop_forms] user_id={$user['id']}, role={$user['role']}, isManager=" . ($isManager ? 'true' : 'false'));

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handleList($user, $isManager);
            break;
        case 'detail':
            handleDetail($user);
            break;
        case 'process':
            handleProcess($user);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_forms 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取表单列表 - 查询 form_instances 表（需求表单、评价表单等）
 */
function handleList($user, $isManager) {
    $status = $_GET['status'] ?? '';
    $projectId = $_GET['project_id'] ?? '';
    $search = $_GET['search'] ?? '';
    $formType = $_GET['form_type'] ?? ''; // requirement / evaluation
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $filterUserId = $_GET['user_id'] ?? ''; // 管理员筛选特定用户
    $sortBy = $_GET['sort_by'] ?? 'create_time'; // create_time / update_time
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    
    // 验证排序字段
    $allowedSortFields = ['create_time', 'update_time'];
    if (!in_array($sortBy, $allowedSortFields)) {
        $sortBy = 'create_time';
    }
    
    $conditions = ["fi.id IS NOT NULL"];
    $params = [];
    
    // 非管理员只能看到自己项目的表单
    if (!$isManager) {
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $params[] = $user['id'];
    } elseif ($filterUserId) {
        // 管理员筛选特定用户的表单
        $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $params[] = (int)$filterUserId;
    }
    
    // 状态筛选 - 基于 requirement_status（需求沟通状态）
    if ($status) {
        switch ($status) {
            case 'pending':
                $conditions[] = "(fi.requirement_status IS NULL OR fi.requirement_status = 'pending')";
                break;
            case 'in_progress':
                $conditions[] = "fi.requirement_status = 'communicating'";
                break;
            case 'completed':
                $conditions[] = "fi.requirement_status = 'confirmed'";
                break;
        }
    }
    
    // 项目筛选
    if ($projectId) {
        $conditions[] = "fi.project_id = ?";
        $params[] = (int)$projectId;
    }
    
    // 表单类型筛选
    if ($formType) {
        $conditions[] = "ft.form_type = ?";
        $params[] = $formType;
    }
    
    // 搜索
    if ($search) {
        $conditions[] = "(p.project_name LIKE ? OR c.name LIKE ? OR ft.name LIKE ? OR fi.instance_name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // 日期筛选
    if ($startDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(fi.create_time)) >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $conditions[] = "DATE(FROM_UNIXTIME(fi.create_time)) <= ?";
        $params[] = $endDate;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    $sql = "
        SELECT 
            fi.id,
            fi.project_id,
            fi.instance_name,
            fi.status as instance_status,
            fi.requirement_status,
            fi.create_time,
            fi.update_time,
            ft.name as form_type_name,
            ft.form_type,
            p.project_name,
            p.project_code,
            c.name as customer_name,
            (SELECT COUNT(*) FROM form_submissions fs WHERE fs.instance_id = fi.id) as submission_count
        FROM form_instances fi
        LEFT JOIN form_templates ft ON fi.template_id = ft.id
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE {$whereClause}
        ORDER BY fi.{$sortBy} {$sortOrder}
        LIMIT 100
    ";
    
    $forms = Db::query($sql, $params);
    
    $result = [];
    foreach ($forms as $form) {
        // 使用 requirement_status（需求沟通状态）作为主要状态显示
        // pending = 待沟通, communicating = 沟通中, confirmed = 已确认
        $reqStatus = $form['requirement_status'] ?? 'pending';
        $displayStatus = 'pending'; // 待处理（待沟通）
        if ($reqStatus === 'confirmed') {
            $displayStatus = 'completed'; // 已完成（已确认）
        } elseif ($reqStatus === 'communicating') {
            $displayStatus = 'in_progress'; // 处理中（沟通中）
        }
        
        $result[] = [
            'id' => (int)$form['id'],
            'project_id' => (int)$form['project_id'],
            'project_name' => $form['project_name'],
            'project_code' => $form['project_code'],
            'customer_name' => $form['customer_name'],
            'form_type' => $form['form_type'],
            'form_type_name' => $form['instance_name'] ?: ($form['form_type_name'] ?? $form['form_type']),
            'status' => $displayStatus,
            'instance_status' => $form['instance_status'],
            'requirement_status' => $reqStatus,
            'submission_count' => (int)$form['submission_count'],
            'create_time' => $form['create_time'] ? date('Y-m-d H:i', $form['create_time']) : null,
            'update_time' => $form['update_time'] ? date('Y-m-d H:i', $form['update_time']) : null,
        ];
    }
    
    // 统计 - 基于 requirement_status（需求沟通状态）
    // 使用不含状态筛选的基础条件，确保各状态数量始终正确显示
    $statsConditions = ["fi.id IS NOT NULL"];
    $statsParams = [];
    
    // 非管理员只能看到自己项目的表单
    if (!$isManager) {
        $statsConditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $statsParams[] = $user['id'];
    } elseif ($filterUserId) {
        $statsConditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $statsParams[] = (int)$filterUserId;
    }
    
    // 表单类型筛选（保留此筛选，因为需要区分需求表单和评价表单）
    if ($formType) {
        $statsConditions[] = "ft.form_type = ?";
        $statsParams[] = $formType;
    }
    
    // 搜索条件（保留搜索筛选）
    if ($search) {
        $statsConditions[] = "(p.project_name LIKE ? OR c.name LIKE ? OR ft.name LIKE ? OR fi.instance_name LIKE ?)";
        $searchTerm = "%{$search}%";
        $statsParams[] = $searchTerm;
        $statsParams[] = $searchTerm;
        $statsParams[] = $searchTerm;
        $statsParams[] = $searchTerm;
    }
    
    // 日期筛选（保留日期筛选）
    if ($startDate) {
        $statsConditions[] = "DATE(FROM_UNIXTIME(fi.create_time)) >= ?";
        $statsParams[] = $startDate;
    }
    if ($endDate) {
        $statsConditions[] = "DATE(FROM_UNIXTIME(fi.create_time)) <= ?";
        $statsParams[] = $endDate;
    }
    
    $statsWhereClause = implode(' AND ', $statsConditions);
    
    $stats = Db::queryOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN fi.requirement_status IS NULL OR fi.requirement_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN fi.requirement_status = 'communicating' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN fi.requirement_status = 'confirmed' THEN 1 ELSE 0 END) as completed
        FROM form_instances fi
        LEFT JOIN form_templates ft ON fi.template_id = ft.id
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE {$statsWhereClause}
    ", $statsParams);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'forms' => $result,
            'stats' => [
                'total' => (int)($stats['total'] ?? 0),
                'pending' => (int)($stats['pending'] ?? 0),
                'in_progress' => (int)($stats['in_progress'] ?? 0),
                'completed' => (int)($stats['completed'] ?? 0),
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取表单详情 - 基于 form_instances 表
 */
function handleDetail($user) {
    $formId = (int)($_GET['id'] ?? 0);
    if (!$formId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少表单ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $form = Db::queryOne("
        SELECT 
            fi.*,
            ft.name as form_type_name,
            ft.form_type,
            ftv.schema_json,
            p.project_name,
            p.project_code,
            c.name as customer_name
        FROM form_instances fi
        LEFT JOIN form_templates ft ON fi.template_id = ft.id
        LEFT JOIN form_template_versions ftv ON fi.template_version_id = ftv.id
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE fi.id = ?
    ", [$formId]);
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '表单不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取提交记录
    $submissions = Db::query("
        SELECT fs.*, u.realname as submitter_name
        FROM form_submissions fs
        LEFT JOIN users u ON fs.submitted_by = u.id
        WHERE fs.instance_id = ?
        ORDER BY fs.submit_time DESC
    ", [$formId]);
    
    // 映射状态
    $displayStatus = 'pending';
    if ($form['status'] === 'confirmed') {
        $displayStatus = 'completed';
    } elseif (in_array($form['status'], ['filling', 'submitted'])) {
        $displayStatus = 'in_progress';
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'form' => [
                'id' => (int)$form['id'],
                'project_id' => (int)$form['project_id'],
                'project_name' => $form['project_name'],
                'project_code' => $form['project_code'],
                'customer_name' => $form['customer_name'],
                'form_type' => $form['form_type'],
                'form_type_name' => $form['instance_name'] ?: ($form['form_type_name'] ?? $form['form_type']),
                'status' => $displayStatus,
                'instance_status' => $form['status'],
                'requirement_status' => $form['requirement_status'],
                'schema' => json_decode($form['schema_json'], true),
                'create_time' => $form['create_time'] ? date('Y-m-d H:i', $form['create_time']) : null,
                'update_time' => $form['update_time'] ? date('Y-m-d H:i', $form['update_time']) : null,
            ],
            'submissions' => $submissions,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 处理表单 - 更新 requirement_status（需求沟通状态）
 */
function handleProcess($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $formId = (int)($input['form_id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    $notes = $input['notes'] ?? '';
    
    if (!$formId || !$newStatus) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 映射前端状态到 requirement_status
    // pending = 待沟通, in_progress = 沟通中, completed = 已确认
    $requirementStatus = 'pending';
    switch ($newStatus) {
        case 'in_progress':
            $requirementStatus = 'communicating';
            break;
        case 'completed':
            $requirementStatus = 'confirmed';
            break;
        case 'pending':
        default:
            $requirementStatus = 'pending';
            break;
    }
    
    // 更新需求沟通状态
    Db::execute("
        UPDATE form_instances 
        SET requirement_status = ?, update_time = ?
        WHERE id = ?
    ", [$requirementStatus, time(), $formId]);
    
    error_log("[desktop_forms] 更新表单 {$formId} 状态为 {$requirementStatus}");
    
    echo json_encode(['success' => true, 'message' => '状态更新成功'], JSON_UNESCAPED_UNICODE);
}
