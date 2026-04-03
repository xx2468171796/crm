<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 悬浮窗任务 API
 * 
 * GET /api/desktop_floating_tasks.php
 * 返回今日任务和项目进度
 */

// CORS
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

// 认证
$user = desktop_auth_require();

try {
    $today = date('Y-m-d');
    $tasks = [];
    $projects = [];
    
    // 获取今日任务
    try {
        $taskRows = Db::query(
            "SELECT 
                dt.id,
                dt.title,
                dt.priority,
                dt.status,
                dt.deadline,
                p.project_name
            FROM daily_tasks dt
            LEFT JOIN projects p ON dt.project_id = p.id
            WHERE dt.user_id = ? 
            AND (DATE(FROM_UNIXTIME(dt.create_time)) = ? OR dt.status != 'completed')
            ORDER BY 
                FIELD(dt.priority, 'high', 'medium', 'low'),
                dt.deadline ASC
            LIMIT 10",
            [$user['id'], $today]
        );
        
        foreach ($taskRows as $row) {
            $tasks[] = [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'project_name' => $row['project_name'] ?? '未关联项目',
                'priority' => $row['priority'] ?? 'medium',
                'deadline' => $row['deadline'] ? date('m-d H:i', strtotime($row['deadline'])) : null,
                'status' => $row['status'] ?? 'pending',
            ];
        }
    } catch (Exception $e) {
        // daily_tasks 表可能不存在
    }
    
    // 获取进行中的项目进度
    try {
        $projectRows = Db::query(
            "SELECT 
                p.id,
                p.project_name as name,
                p.current_status
            FROM projects p
            LEFT JOIN project_tech_assignments pta ON p.id = pta.project_id
            WHERE (pta.tech_user_id = ? OR p.created_by = ?)
            AND p.current_status NOT IN ('completed', 'cancelled', '')
            ORDER BY p.id DESC
            LIMIT 5",
            [$user['id'], $user['id']]
        );
        
        // 状态到进度的映射
        $statusProgress = [
            '待沟通' => 10,
            '需求确认' => 20,
            '设计中' => 40,
            '建模中' => 60,
            '渲染中' => 75,
            '后期中' => 85,
            '审核中' => 95,
            'requirement' => 20,
            'design' => 40,
            'modeling' => 60,
            'rendering' => 75,
            'post' => 85,
            'review' => 95,
        ];
        
        foreach ($projectRows as $row) {
            $status = $row['current_status'] ?? '';
            $progress = $statusProgress[$status] ?? 30;
            
            $projects[] = [
                'name' => $row['name'] ?? '未命名项目',
                'progress' => $progress,
                'status' => $status,
            ];
        }
    } catch (Exception $e) {
        // 忽略
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'tasks' => $tasks,
            'projects' => $projects,
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('[API] desktop_floating_tasks 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '服务器错误']], JSON_UNESCAPED_UNICODE);
}
