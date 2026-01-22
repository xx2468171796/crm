<?php
/**
 * 项目服务层
 * 统一处理项目相关的业务逻辑，确保数据一致性
 * 
 * 所有 API（桌面端、悬浮窗、后端管理、客户门户）都应调用此服务
 */

require_once __DIR__ . '/../db.php';

class ProjectService {
    
    private static ?ProjectService $instance = null;
    
    // 项目阶段定义（按顺序）
    const STAGES = [
        '待沟通' => ['order' => 1, 'color' => '#6366f1'],
        '需求确认' => ['order' => 2, 'color' => '#8b5cf6'],
        '设计中' => ['order' => 3, 'color' => '#ec4899'],
        '设计核对' => ['order' => 4, 'color' => '#f97316'],
        '设计完工' => ['order' => 5, 'color' => '#14b8a6'],
        '设计评价' => ['order' => 6, 'color' => '#10b981'],
    ];
    
    // 最终阶段
    const FINAL_STAGE = '设计评价';
    
    private function __construct() {}
    
    public static function getInstance(): ProjectService {
        if (self::$instance === null) {
            self::$instance = new ProjectService();
        }
        return self::$instance;
    }
    
    /**
     * 更新项目状态（统一入口）
     * 确保状态变更的一致性
     * 
     * @param int $projectId 项目ID
     * @param string $newStatus 新状态
     * @param int $operatorId 操作者ID
     * @param string|null $operatorName 操作者名称
     * @param string|null $notes 备注
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function updateStatus(int $projectId, string $newStatus, int $operatorId, ?string $operatorName = null, ?string $notes = null): array {
        // 验证状态值
        if (!isset(self::STAGES[$newStatus])) {
            return ['success' => false, 'message' => '无效的状态值: ' . $newStatus];
        }
        
        // 获取项目
        $project = Db::queryOne("
            SELECT p.id, p.current_status, p.completed_at, p.customer_id
            FROM projects p
            WHERE p.id = ? AND p.deleted_at IS NULL
        ", [$projectId]);
        
        if (!$project) {
            return ['success' => false, 'message' => '项目不存在'];
        }
        
        // 如果项目已完成，不允许修改状态
        if ($project['completed_at'] && $newStatus !== self::FINAL_STAGE) {
            return ['success' => false, 'message' => '项目已完成，无法修改状态'];
        }
        
        $oldStatus = $project['current_status'];
        
        // 如果状态相同，直接返回
        if ($oldStatus === $newStatus) {
            return ['success' => true, 'message' => '状态未变更', 'data' => ['project_id' => $projectId]];
        }
        
        $now = time();
        
        try {
            Db::beginTransaction();
            
            // 更新状态
            // 如果进入"设计评价"阶段，设置评价截止时间（7天后）
            if ($newStatus === '设计评价') {
                Db::execute(
                    "UPDATE projects SET current_status = ?, update_time = ?, evaluation_deadline = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?",
                    [$newStatus, $now, $projectId]
                );
                
                // 自动创建评价表单实例
                $this->createEvaluationFormInstance($projectId, $project['customer_id']);
            } else {
                Db::execute(
                    "UPDATE projects SET current_status = ?, update_time = ? WHERE id = ?",
                    [$newStatus, $now, $projectId]
                );
            }
            
            // 写入状态日志
            Db::execute(
                "INSERT INTO project_status_log (project_id, from_status, to_status, changed_by, changed_at, notes) VALUES (?, ?, ?, ?, ?, ?)",
                [$projectId, $oldStatus, $newStatus, $operatorId, $now, $notes]
            );
            
            // 同步更新阶段时间表状态（关键：确保门户端进度条正确显示）
            $this->syncStageTimeStatus($projectId, $newStatus);
            
            // 记录时间线
            $this->recordTimeline($projectId, '状态变更', $operatorId, [
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'notes' => $notes,
                'operator_name' => $operatorName
            ]);
            
            Db::commit();
            
            // 发送项目状态变更通知给相关技术人员
            $this->notifyProjectStatusChange($projectId, $oldStatus, $newStatus, $operatorName);
            
            return [
                'success' => true,
                'message' => '状态变更成功',
                'data' => [
                    'project_id' => $projectId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ]
            ];
        } catch (Exception $e) {
            Db::rollBack();
            error_log('[ProjectService] updateStatus error: ' . $e->getMessage());
            return ['success' => false, 'message' => '状态更新失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 创建评价表单实例
     */
    private function createEvaluationFormInstance(int $projectId, ?int $customerId): void {
        // 获取默认评价模板配置
        $config = Db::queryOne("SELECT config_value FROM system_config WHERE config_key = 'default_evaluation_template_id'");
        $templateId = intval($config['config_value'] ?? 0);
        
        if ($templateId <= 0) {
            return; // 未配置默认模板
        }
        
        // 检查模板是否存在且已发布
        $template = Db::queryOne("SELECT id, name, current_version_id FROM form_templates WHERE id = ? AND status = 'published'", [$templateId]);
        if (!$template) {
            return; // 模板不存在或未发布
        }
        
        // 检查是否已存在评价表单实例
        $existing = Db::queryOne("SELECT id FROM form_instances WHERE project_id = ? AND purpose = 'evaluation'", [$projectId]);
        if ($existing) {
            return; // 已存在
        }
        
        // 创建表单实例
        $now = time();
        $instanceName = $template['name'] . ' - 项目评价';
        $fillToken = bin2hex(random_bytes(32));
        
        Db::execute("
            INSERT INTO form_instances (template_id, template_version_id, project_id, instance_name, status, purpose, fill_token, created_by, create_time, update_time)
            VALUES (?, ?, ?, ?, 'pending', 'evaluation', ?, 0, ?, ?)
        ", [$templateId, $template['current_version_id'], $projectId, $instanceName, $fillToken, $now, $now]);
    }
    
    /**
     * 完成项目（统一入口）
     * 自动同步更新 current_status 和 completed_at
     * 
     * @param int $projectId 项目ID
     * @param int $operatorId 操作者ID
     * @param string|null $operatorName 操作者名称
     * @return array ['success' => bool, 'message' => string]
     */
    public function completeProject(int $projectId, int $operatorId, ?string $operatorName = null): array {
        // 获取项目
        $project = Db::queryOne("SELECT id, current_status, completed_at FROM projects WHERE id = ?", [$projectId]);
        if (!$project) {
            return ['success' => false, 'message' => '项目不存在'];
        }
        
        // 检查是否已完成
        if ($project['completed_at']) {
            return ['success' => false, 'message' => '项目已完成'];
        }
        
        try {
            Db::beginTransaction();
            
            // 1. 更新 completed_at 和 completed_by
            Db::execute(
                "UPDATE projects SET completed_at = NOW(), completed_by = ? WHERE id = ?",
                [$operatorId, $projectId]
            );
            
            // 2. 同步更新 current_status 到最终阶段（关键！）
            Db::execute(
                "UPDATE projects SET current_status = ?, update_time = UNIX_TIMESTAMP() WHERE id = ?",
                [self::FINAL_STAGE, $projectId]
            );
            
            // 3. 记录时间线
            $this->recordTimeline($projectId, 'complete', $operatorId, [
                'action' => 'manual_complete',
                'operator_name' => $operatorName,
                'old_status' => $project['current_status'],
                'new_status' => self::FINAL_STAGE
            ]);
            
            Db::commit();
            
            return [
                'success' => true,
                'message' => '项目已完成',
                'data' => [
                    'project_id' => $projectId,
                    'completed_at' => date('Y-m-d H:i:s'),
                    'current_status' => self::FINAL_STAGE
                ]
            ];
        } catch (Exception $e) {
            Db::rollBack();
            error_log('[ProjectService] completeProject error: ' . $e->getMessage());
            return ['success' => false, 'message' => '操作失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取项目详情（统一格式）
     * 
     * @param int $projectId 项目ID
     * @param int|null $userId 当前用户ID（用于权限检查）
     * @return array|null
     */
    public function getProjectDetail(int $projectId, ?int $userId = null): ?array {
        $project = Db::queryOne("
            SELECT p.*, c.name as customer_name, c.group_code, c.phone as customer_phone
            FROM projects p
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE p.id = ?
        ", [$projectId]);
        
        if (!$project) {
            return null;
        }
        
        // 获取技术人员
        $techUsers = Db::query("
            SELECT u.id, u.realname, u.username, pta.assigned_at
            FROM project_tech_assignments pta
            JOIN users u ON pta.tech_user_id = u.id
            WHERE pta.project_id = ?
            ORDER BY pta.assigned_at DESC
        ", [$projectId]);
        
        // 获取阶段时间
        $stageTimes = Db::query("
            SELECT * FROM project_stage_times 
            WHERE project_id = ? 
            ORDER BY stage_order ASC
        ", [$projectId]);
        
        // 计算进度信息
        $daysInfo = $this->calculateDaysInfo($project, $stageTimes);
        
        return [
            'project' => array_merge($project, ['days_info' => $daysInfo]),
            'tech_users' => $techUsers,
            'stage_times' => $stageTimes,
            'statuses' => array_map(function($key, $value) {
                return ['key' => $key, 'label' => $key, 'color' => $value['color'], 'order' => $value['order']];
            }, array_keys(self::STAGES), self::STAGES),
            'current_status_order' => self::STAGES[$project['current_status']]['order'] ?? 0
        ];
    }
    
    /**
     * 获取项目列表
     * 
     * @param array $filters 过滤条件
     * @param int|null $userId 当前用户ID
     * @param string|null $userRole 用户角色
     * @return array
     */
    public function getProjects(array $filters = [], ?int $userId = null, ?string $userRole = null): array {
        $where = ['1=1'];
        $params = [];
        
        // 状态过滤
        if (!empty($filters['status'])) {
            $where[] = 'p.current_status = ?';
            $params[] = $filters['status'];
        }
        
        // 搜索
        if (!empty($filters['search'])) {
            $where[] = '(p.project_name LIKE ? OR p.project_code LIKE ? OR c.name LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // 技术人员过滤（如果是技术角色，只显示分配给自己的项目）
        if ($userRole === 'tech' && $userId) {
            $where[] = 'EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = p.id AND pta.tech_user_id = ?)';
            $params[] = $userId;
        }
        
        // 完成状态过滤
        if (isset($filters['completed'])) {
            if ($filters['completed']) {
                $where[] = 'p.completed_at IS NOT NULL';
            } else {
                $where[] = 'p.completed_at IS NULL';
            }
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "
            SELECT p.*, c.name as customer_name, c.group_code
            FROM projects p
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE {$whereClause}
            ORDER BY p.update_time DESC
        ";
        
        // 分页
        if (!empty($filters['limit'])) {
            $sql .= ' LIMIT ' . intval($filters['limit']);
            if (!empty($filters['offset'])) {
                $sql .= ' OFFSET ' . intval($filters['offset']);
            }
        }
        
        return Db::query($sql, $params);
    }
    
    /**
     * 记录时间线事件
     */
    private function recordTimeline(int $projectId, string $eventType, int $operatorId, array $eventData = []): void {
        Db::execute("
            INSERT INTO timeline_events (entity_type, entity_id, event_type, operator_user_id, event_data_json, create_time)
            VALUES ('project', ?, ?, ?, ?, UNIX_TIMESTAMP())
        ", [$projectId, $eventType, $operatorId, json_encode($eventData, JSON_UNESCAPED_UNICODE)]);
    }
    
    /**
     * 计算项目天数信息
     */
    private function calculateDaysInfo(array $project, array $stageTimes): array {
        $totalDays = 0;
        $firstStartDate = null;
        $lastEndDate = null;
        
        foreach ($stageTimes as $st) {
            $totalDays += intval($st['planned_days'] ?? 0);
            if (!$firstStartDate && !empty($st['planned_start_date'])) {
                $firstStartDate = $st['planned_start_date'];
            }
            if (!empty($st['planned_end_date'])) {
                $lastEndDate = $st['planned_end_date'];
            }
        }
        
        $isCompleted = !empty($project['completed_at']);
        $elapsedDays = 0;
        $remainingDays = $totalDays;
        
        if ($firstStartDate) {
            $start = new DateTime($firstStartDate);
            $now = new DateTime();
            $elapsedDays = max(0, $now->diff($start)->days);
            $remainingDays = max(0, $totalDays - $elapsedDays);
        }
        
        return [
            'total_days' => $totalDays,
            'elapsed_days' => $elapsedDays,
            'remaining_days' => $remainingDays,
            'is_completed' => $isCompleted,
            'date_range' => $firstStartDate && $lastEndDate ? "{$firstStartDate} ~ {$lastEndDate}" : null
        ];
    }
    
    /**
     * 同步更新阶段时间表状态
     * 根据项目当前状态更新 project_stage_times 表中各阶段的 status 字段
     * 
     * @param int $projectId 项目ID
     * @param string $currentStatus 当前项目状态
     */
    private function syncStageTimeStatus(int $projectId, string $currentStatus): void {
        $currentOrder = self::STAGES[$currentStatus]['order'] ?? 0;
        
        // 获取所有阶段时间记录
        $stages = Db::query("
            SELECT id, stage_to, stage_order 
            FROM project_stage_times 
            WHERE project_id = ? 
            ORDER BY stage_order ASC
        ", [$projectId]);
        
        if (empty($stages)) {
            return;
        }
        
        foreach ($stages as $stage) {
            $stageOrder = (int)$stage['stage_order'];
            $newStageStatus = 'pending';
            
            if ($stageOrder < $currentOrder) {
                // 已完成的阶段
                $newStageStatus = 'completed';
            } elseif ($stageOrder === $currentOrder) {
                // 当前进行中的阶段
                $newStageStatus = 'in_progress';
            }
            // 后续阶段保持 pending
            
            Db::execute(
                "UPDATE project_stage_times SET status = ?, updated_at = NOW() WHERE id = ?",
                [$newStageStatus, $stage['id']]
            );
        }
    }
    
    /**
     * 发送项目状态变更通知
     */
    private function notifyProjectStatusChange(int $projectId, string $oldStatus, string $newStatus, ?string $operatorName): void {
        // 获取项目信息
        $project = Db::queryOne("SELECT project_code, project_name FROM projects WHERE id = ?", [$projectId]);
        if (!$project) return;
        
        // 获取项目相关技术人员
        $techUsers = Db::query("SELECT tech_user_id FROM project_tech_assignments WHERE project_id = ?", [$projectId]);
        if (empty($techUsers)) return;
        
        $now = time();
        $content = "项目 [{$project['project_code']}] {$project['project_name']} 状态变更: {$oldStatus} → {$newStatus}";
        if ($operatorName) {
            $content .= " (操作人: {$operatorName})";
        }
        
        foreach ($techUsers as $tech) {
            // 使用正确的字段名：related_type 和 related_id（不是 data）
            Db::execute("
                INSERT INTO notifications (user_id, type, title, content, related_type, related_id, is_read, create_time)
                VALUES (?, 'project', '项目状态变更', ?, 'project', ?, 0, ?)
            ", [
                $tech['tech_user_id'],
                $content,
                $projectId,
                $now
            ]);
        }
    }
}
