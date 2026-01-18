<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目阶段时间管理 API
 * GET - 获取项目阶段时间
 * POST - 调整阶段时间（支持顺延计算）
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/stage_time_calc.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Db::pdo();

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo, $user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[PROJECT_STAGE_TIME_API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function handleGet($pdo) {
    $projectId = intval($_GET['project_id'] ?? 0);
    
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '项目ID不能为空'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取项目阶段时间
    $stmt = $pdo->prepare("
        SELECT pst.*, 
               DATEDIFF(pst.planned_end_date, CURDATE()) as remaining_days,
               CASE 
                   WHEN pst.status = 'completed' THEN 100
                   WHEN pst.status = 'in_progress' THEN 
                       GREATEST(0, LEAST(100, 
                           ROUND((DATEDIFF(CURDATE(), pst.planned_start_date) + 1) * 100 / pst.planned_days)
                       ))
                   ELSE 0
               END as progress_percent
        FROM project_stage_times pst
        WHERE pst.project_id = ?
        ORDER BY pst.stage_order ASC
    ");
    $stmt->execute([$projectId]);
    $stageTimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取项目当前状态（包括完工信息）
    $projectStmt = $pdo->prepare("SELECT current_status, timeline_enabled, timeline_start_date, completed_at, completed_by FROM projects WHERE id = ?");
    $projectStmt->execute([$projectId]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
    
    // 使用公共函数计算摘要
    $isCompleted = !empty($project['completed_at']);
    $summary = calculateStageTimeSummary($project, $stageTimes);
    
    // 处理完工后的阶段状态
    $stageTimes = processStagesForCompletion($stageTimes, $isCompleted);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stages' => $stageTimes,
            'project' => $project,
            'summary' => $summary
        ]
    ], JSON_UNESCAPED_UNICODE);
}

function handlePost($pdo, $user) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? 'update';
    
    switch ($action) {
        case 'update':
            updateStageTimes($pdo, $user, $data);
            break;
        case 'adjust':
            adjustStageTime($pdo, $user, $data);
            break;
        case 'batch_adjust':
            batchAdjustStageTimes($pdo, $user, $data);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * 批量更新阶段时间
 */
function updateStageTimes($pdo, $user, $data) {
    $projectId = intval($data['project_id'] ?? 0);
    $stages = $data['stages'] ?? [];
    
    if ($projectId <= 0 || empty($stages)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        $updateStmt = $pdo->prepare("
            UPDATE project_stage_times SET
                planned_days = ?,
                planned_start_date = ?,
                planned_end_date = ?,
                updated_at = NOW()
            WHERE id = ? AND project_id = ?
        ");
        
        foreach ($stages as $stage) {
            $updateStmt->execute([
                intval($stage['planned_days']),
                $stage['planned_start_date'],
                $stage['planned_end_date'],
                intval($stage['id']),
                $projectId
            ]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '阶段时间更新成功'], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 调整单个阶段时间并顺延后续阶段
 */
function adjustStageTime($pdo, $user, $data) {
    $stageId = intval($data['stage_id'] ?? 0);
    $newDays = intval($data['new_days'] ?? 0);
    
    if ($stageId <= 0 || $newDays < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    // 获取当前阶段信息
    $stmt = $pdo->prepare("SELECT * FROM project_stage_times WHERE id = ?");
    $stmt->execute([$stageId]);
    $currentStage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentStage) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '阶段不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $projectId = $currentStage['project_id'];
    $oldDays = intval($currentStage['planned_days']);
    $daysDiff = $newDays - $oldDays;
    
    $pdo->beginTransaction();
    
    try {
        // 更新当前阶段
        $startDate = new DateTime($currentStage['planned_start_date']);
        $newEndDate = clone $startDate;
        $newEndDate->modify('+' . ($newDays - 1) . ' days');
        
        $updateCurrentStmt = $pdo->prepare("
            UPDATE project_stage_times SET
                planned_days = ?,
                planned_end_date = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateCurrentStmt->execute([$newDays, $newEndDate->format('Y-m-d'), $stageId]);
        
        // 顺延后续阶段
        if ($daysDiff != 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM project_stage_times 
                WHERE project_id = ? AND stage_order > ?
                ORDER BY stage_order ASC
            ");
            $stmt->execute([$projectId, $currentStage['stage_order']]);
            $subsequentStages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $nextStartDate = clone $newEndDate;
            $nextStartDate->modify('+1 day');
            
            $updateSubStmt = $pdo->prepare("
                UPDATE project_stage_times SET
                    planned_start_date = ?,
                    planned_end_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            foreach ($subsequentStages as $subStage) {
                $subDays = intval($subStage['planned_days']);
                $subEndDate = clone $nextStartDate;
                $subEndDate->modify('+' . ($subDays - 1) . ' days');
                
                $updateSubStmt->execute([
                    $nextStartDate->format('Y-m-d'),
                    $subEndDate->format('Y-m-d'),
                    $subStage['id']
                ]);
                
                $nextStartDate = clone $subEndDate;
                $nextStartDate->modify('+1 day');
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $daysDiff > 0 ? "已延长{$daysDiff}天，后续阶段已顺延" : "已缩短" . abs($daysDiff) . "天，后续阶段已调整",
            'data' => ['days_diff' => $daysDiff]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 批量调整多个阶段的时间
 */
function batchAdjustStageTimes($pdo, $user, $data) {
    $projectId = intval($data['project_id'] ?? 0);
    $changes = $data['changes'] ?? [];
    
    if ($projectId <= 0 || empty($changes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // 获取所有阶段
        $stmt = $pdo->prepare("SELECT * FROM project_stage_times WHERE project_id = ? ORDER BY stage_order ASC");
        $stmt->execute([$projectId]);
        $allStages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allStages)) {
            throw new Exception('未找到阶段数据');
        }
        
        // 构建阶段映射
        $stageMap = [];
        foreach ($allStages as $idx => $stage) {
            $stageMap[$stage['id']] = ['index' => $idx, 'data' => $stage];
        }
        
        // 按 stage_order 排序 changes
        usort($changes, function($a, $b) use ($stageMap) {
            $orderA = $stageMap[$a['stage_id']]['index'] ?? 999;
            $orderB = $stageMap[$b['stage_id']]['index'] ?? 999;
            return $orderA - $orderB;
        });
        
        // 依次处理每个变更
        foreach ($changes as $change) {
            $stageId = intval($change['stage_id']);
            $newDays = intval($change['new_days']);
            
            if ($stageId <= 0 || $newDays < 1) continue;
            
            // 重新获取当前阶段（因为前面的变更可能影响了它）
            $stageStmt = $pdo->prepare("SELECT * FROM project_stage_times WHERE id = ?");
            $stageStmt->execute([$stageId]);
            $currentStage = $stageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentStage) continue;
            
            $oldDays = intval($currentStage['planned_days']);
            $daysDiff = $newDays - $oldDays;
            
            if ($daysDiff == 0) continue;
            
            // 更新当前阶段
            $newEndDate = date('Y-m-d', strtotime($currentStage['planned_start_date'] . " + " . ($newDays - 1) . " days"));
            $updateStmt = $pdo->prepare("UPDATE project_stage_times SET planned_days = ?, planned_end_date = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$newDays, $newEndDate, $stageId]);
            
            // 顺延后续阶段
            $laterStmt = $pdo->prepare("SELECT * FROM project_stage_times WHERE project_id = ? AND stage_order > ? ORDER BY stage_order ASC");
            $laterStmt->execute([$projectId, $currentStage['stage_order']]);
            $laterStages = $laterStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $prevEndDate = $newEndDate;
            foreach ($laterStages as $later) {
                $laterStartDate = date('Y-m-d', strtotime($prevEndDate . " + 1 day"));
                $laterEndDate = date('Y-m-d', strtotime($laterStartDate . " + " . ($later['planned_days'] - 1) . " days"));
                
                $laterUpdateStmt = $pdo->prepare("UPDATE project_stage_times SET planned_start_date = ?, planned_end_date = ?, updated_at = NOW() WHERE id = ?");
                $laterUpdateStmt->execute([$laterStartDate, $laterEndDate, $later['id']]);
                
                $prevEndDate = $laterEndDate;
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '批量调整成功',
            'data' => ['count' => count($changes)]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[BATCH_ADJUST_ERROR] ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '批量调整失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
