<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户门户 - 项目阶段时间 API（无需登录）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/stage_time_calc.php';

$projectId = intval($_GET['project_id'] ?? 0);
$token = trim($_GET['token'] ?? '');

if ($projectId <= 0 || empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Db::pdo();

try {
    // 验证token和项目访问权限
    $stmt = $pdo->prepare("
        SELECT pl.customer_id, p.id as project_id
        FROM portal_links pl
        JOIN projects p ON p.customer_id = pl.customer_id
        WHERE pl.token = ? AND p.id = ? AND pl.enabled = 1 AND p.deleted_at IS NULL
    ");
    $stmt->execute([$token, $projectId]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$access) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 获取项目完工状态和起始日期
    $projectStmt = $pdo->prepare("SELECT completed_at, timeline_start_date FROM projects WHERE id = ?");
    $projectStmt->execute([$projectId]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
    $isCompleted = !empty($project['completed_at']);
    
    // 获取阶段时间
    $stageStmt = $pdo->prepare("
        SELECT pst.*, 
               DATEDIFF(pst.planned_end_date, CURDATE()) as remaining_days
        FROM project_stage_times pst
        WHERE pst.project_id = ?
        ORDER BY pst.stage_order ASC
    ");
    $stageStmt->execute([$projectId]);
    $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 使用公共函数计算摘要
    $summary = calculateStageTimeSummary($project, $stages);
    
    // 处理完工后的阶段状态
    $stages = processStagesForCompletion($stages, $isCompleted);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stages' => $stages,
            'summary' => $summary
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[PORTAL_STAGE_TIME_API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '获取失败'], JSON_UNESCAPED_UNICODE);
}
