<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户门户访问验证 API
 * GET /api/portal_access.php?token=xxx&password=xxx
 * 验证token和密码，返回客户项目列表
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$password = trim($_GET['password'] ?? $_POST['password'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 查询门户链接
    $stmt = $pdo->prepare("
        SELECT pl.*, c.name as customer_name, c.alias as customer_alias, c.customer_group, c.group_code
        FROM portal_links pl
        JOIN customers c ON pl.customer_id = c.id
        WHERE pl.token = ?
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$link) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '链接不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查是否禁用
    if ($link['enabled'] == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '链接已禁用'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查是否过期
    if ($link['expires_at'] && $link['expires_at'] < time()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '链接已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查session是否已验证
    $sessionKey = 'portal_verified_' . $token;
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        // 已验证，直接返回项目列表
        $projects = getCustomerProjects($pdo, $link['customer_id']);
        echo json_encode([
            'success' => true,
            'verified' => true,
            'data' => [
                'customer_name' => $link['customer_name'],
                'customer_alias' => $link['customer_alias'],
                'customer_group' => $link['customer_group'],
                'group_code' => $link['group_code'],
                'projects' => $projects
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 检查是否需要密码验证（空密码hash表示无需密码）
    $needPassword = !empty($link['password_hash']) && !password_verify('', $link['password_hash']);
    
    if ($needPassword) {
        // 需要密码验证
        if (empty($password)) {
            echo json_encode([
                'success' => true,
                'verified' => false,
                'message' => '请输入密码'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 验证密码
        if (!password_verify($password, $link['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '密码错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 密码正确，记录session和访问日志
    $_SESSION[$sessionKey] = true;
    
    $now = time();
    $updateStmt = $pdo->prepare("
        UPDATE portal_links 
        SET last_access_at = ?, access_count = access_count + 1 
        WHERE id = ?
    ");
    $updateStmt->execute([$now, $link['id']]);
    
    // 返回项目列表
    $projects = getCustomerProjects($pdo, $link['customer_id']);
    
    echo json_encode([
        'success' => true,
        'verified' => true,
        'data' => [
            'customer_name' => $link['customer_name'],
            'customer_alias' => $link['customer_alias'],
            'customer_group' => $link['customer_group'],
            'group_code' => $link['group_code'],
            'projects' => $projects
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '验证失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function getCustomerProjects($pdo, $customerId) {
    $stmt = $pdo->prepare("
        SELECT id, project_name, project_code, current_status, update_time, completed_at, show_model_files
        FROM projects
        WHERE customer_id = ? AND deleted_at IS NULL
        ORDER BY update_time DESC
    ");
    $stmt->execute([$customerId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 为每个项目计算 overall_progress
    foreach ($projects as &$project) {
        // 已完工项目进度为100%
        if (!empty($project['completed_at'])) {
            $project['overall_progress'] = 100;
        } else {
            $project['overall_progress'] = calculateProjectProgress($pdo, $project['id']);
        }
    }
    
    return $projects;
}

function calculateProjectProgress($pdo, $projectId) {
    // 获取阶段时间数据（与桌面端计算逻辑一致）
    $stmt = $pdo->prepare("
        SELECT planned_days, planned_start_date, status
        FROM project_stage_times
        WHERE project_id = ?
        ORDER BY stage_order ASC
    ");
    $stmt->execute([$projectId]);
    $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stages)) {
        return 0;
    }
    
    $totalDays = 0;
    $elapsedDays = 0;
    $today = new DateTime();
    
    foreach ($stages as $stage) {
        $plannedDays = intval($stage['planned_days'] ?? 0);
        $totalDays += $plannedDays;
        
        if ($stage['status'] === 'completed') {
            $elapsedDays += $plannedDays;
        } elseif ($stage['status'] === 'in_progress' && $stage['planned_start_date']) {
            // 进行中阶段：计算从开始日期到今天的实际天数
            $startDate = new DateTime($stage['planned_start_date']);
            $daysPassed = max(0, $today->diff($startDate)->days + 1);
            $elapsedDays += min($daysPassed, $plannedDays);
        }
    }
    
    if ($totalDays <= 0) {
        return 0;
    }
    
    return min(100, round($elapsedDays * 100 / $totalDays));
}
