<?php
/**
 * 定时任务：自动完工超时未评价的项目
 * 
 * 运行方式: 
 * - 手动: php cron/auto_complete_projects.php
 * - Cron: 0 * * * * php /path/to/cron/auto_complete_projects.php >> /var/log/auto_complete.log 2>&1
 * 
 * 逻辑：
 * - 查找状态为"设计评价"且 evaluation_deadline 已过期的项目
 * - 自动将项目标记为完工
 */

require_once __DIR__ . '/../core/db.php';

echo "[" . date('Y-m-d H:i:s') . "] 开始检查超时项目...\n";

try {
    // 查找需要自动完工的项目
    // 条件：evaluation_deadline 已过期 且 未完工 且 状态为"设计评价"
    $projects = Db::query("
        SELECT id, project_name, project_code, evaluation_deadline
        FROM projects
        WHERE current_status = '设计评价'
          AND evaluation_deadline IS NOT NULL
          AND evaluation_deadline < NOW()
          AND completed_at IS NULL
          AND deleted_at IS NULL
    ");
    
    if (empty($projects)) {
        echo "[" . date('Y-m-d H:i:s') . "] 没有需要自动完工的项目\n";
        exit(0);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 找到 " . count($projects) . " 个需要自动完工的项目\n";
    
    foreach ($projects as $project) {
        // 检查是否已有评价
        $hasEvaluation = Db::queryOne("
            SELECT id FROM project_evaluations WHERE project_id = ?
        ", [$project['id']]);
        
        if ($hasEvaluation) {
            // 已有评价，跳过
            echo "[" . date('Y-m-d H:i:s') . "] 项目 {$project['project_code']} 已有评价，跳过\n";
            continue;
        }
        
        // 自动完工
        Db::execute("
            UPDATE projects SET completed_at = NOW(), completed_by = 'auto'
            WHERE id = ?
        ", [$project['id']]);
        
        // 记录日志
        Db::execute("
            INSERT INTO project_logs (project_id, user_id, action, details, created_at)
            VALUES (?, 0, 'auto_complete', ?, NOW())
        ", [$project['id'], json_encode([
            'reason' => '评价超时自动完工',
            'deadline' => $project['evaluation_deadline']
        ])]);
        
        echo "[" . date('Y-m-d H:i:s') . "] 项目 {$project['project_code']} ({$project['project_name']}) 已自动完工\n";
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 处理完成\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] 错误: " . $e->getMessage() . "\n";
    exit(1);
}
