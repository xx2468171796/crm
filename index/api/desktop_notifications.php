<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 消息通知 API
 * 
 * GET /api/desktop_notifications.php - 获取通知列表
 * POST /api/desktop_notifications.php - 标记已读
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';

$user = desktop_auth_require();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // 检查模式：返回数量和新任务
            if (isset($_GET['check']) && $_GET['check'] == '1') {
                handleCheck($user);
            } elseif (isset($_GET['unread_count']) && $_GET['unread_count'] == '1') {
                handleUnreadCount($user);
            } else {
                handleGet($user);
            }
            break;
        case 'POST':
            handlePost($user);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => '不支持的方法']], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[API] desktop_notifications 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => $e->getMessage()]], JSON_UNESCAPED_UNICODE);
}

/**
 * 检查模式：返回通知数量和新任务（供悬浮窗轮询检查）
 */
function handleCheck($user) {
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    
    // 1. 查询需求表单数量（待沟通状态）
    $formConditions = ["ft.form_type = 'requirement'", "(fi.requirement_status IS NULL OR fi.requirement_status = 'pending')"];
    $formParams = [];
    
    if (!$isManager) {
        $formConditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $formParams[] = $user['id'];
    }
    
    $formWhereClause = implode(' AND ', $formConditions);
    $formCountResult = Db::queryOne("
        SELECT COUNT(*) as cnt FROM form_instances fi
        LEFT JOIN form_templates ft ON fi.template_id = ft.id
        WHERE {$formWhereClause}
    ", $formParams);
    $formCount = (int)($formCountResult['cnt'] ?? 0);
    
    // 2. 查询评价表单数量
    $evalConditions = ["ft.form_type = 'evaluation'", "(fi.requirement_status IS NULL OR fi.requirement_status = 'pending')"];
    $evalParams = [];
    
    if (!$isManager) {
        $evalConditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $evalParams[] = $user['id'];
    }
    
    $evalWhereClause = implode(' AND ', $evalConditions);
    $evalCountResult = Db::queryOne("
        SELECT COUNT(*) as cnt FROM form_instances fi
        LEFT JOIN form_templates ft ON fi.template_id = ft.id
        WHERE {$evalWhereClause}
    ", $evalParams);
    $evalCount = (int)($evalCountResult['cnt'] ?? 0);
    
    // 3. 查询最近 30 分钟内分配给用户的新任务（未读）
    $thirtyMinutesAgo = time() - 1800;
    $newTasks = [];
    
    // 查询分配给当前用户、最近创建的、未完成的任务
    $taskResult = Db::query("
        SELECT t.id, t.title, t.create_time
        FROM tasks t
        WHERE t.assignee_id = ? 
          AND t.create_time >= ?
          AND t.status != 'completed'
          AND t.created_by != ?
          AND t.deleted_at IS NULL
        ORDER BY t.create_time DESC
        LIMIT 5
    ", [$user['id'], $thirtyMinutesAgo, $user['id']]);
    
    // 获取已通知的任务 ID（存储在 notification_reads 表中）
    ensureReadTableExists();
    $readTasks = Db::query("SELECT notification_id FROM notification_reads WHERE user_id = ? AND notification_id LIKE 'task_%'", [$user['id']]);
    $readTaskIds = array_map(function($r) { return str_replace('task_', '', $r['notification_id']); }, $readTasks);
    
    foreach ($taskResult as $task) {
        // 只返回未通知过的任务
        if (!in_array($task['id'], $readTaskIds)) {
            $newTasks[] = [
                'id' => (int)$task['id'],
                'title' => $task['title'],
            ];
            // 标记为已通知
            try {
                Db::execute("INSERT IGNORE INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, ?)", [
                    $user['id'],
                    'task_' . $task['id'],
                    time()
                ]);
            } catch (Exception $e) {}
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'form_count' => $formCount,
            'eval_count' => $evalCount,
            'new_tasks' => $newTasks,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取未读通知数量（轻量级API）
 */
function handleUnreadCount($user) {
    ensureReadTableExists();
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    $fifteenDaysAgo = strtotime('-15 days');
    
    // 获取已读的通知ID
    $readNotifications = Db::query("SELECT notification_id FROM notification_reads WHERE user_id = ?", [$user['id']]);
    $readIds = array_column($readNotifications, 'notification_id');
    
    // 1. 统计未读表单数量
    $formConditions = ["(fi.requirement_status IS NULL OR fi.requirement_status IN ('pending', 'communicating'))", "fi.create_time >= ?", "p.deleted_at IS NULL"];
    $formParams = [$fifteenDaysAgo];
    
    if (!$isManager) {
        $formConditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
        $formParams[] = $user['id'];
    }
    
    $formWhereClause = implode(' AND ', $formConditions);
    $formIds = Db::query("SELECT fi.id FROM form_instances fi LEFT JOIN projects p ON fi.project_id = p.id WHERE {$formWhereClause}", $formParams);
    $unreadForms = 0;
    foreach ($formIds as $f) {
        if (!in_array('form_' . $f['id'], $readIds)) {
            $unreadForms++;
        }
    }
    
    // 2. 统计未读任务/项目通知数量
    $notifyConditions = ["n.user_id = ?", "n.create_time >= ?"];
    $notifyParams = [$user['id'], $fifteenDaysAgo];
    $notifyWhereClause = implode(' AND ', $notifyConditions);
    
    $notifications = Db::query("SELECT n.id, n.type, n.is_read FROM notifications n WHERE {$notifyWhereClause}", $notifyParams);
    $unreadTaskProject = 0;
    foreach ($notifications as $n) {
        $notificationId = $n['type'] . '_' . $n['id'];
        if ($n['is_read'] != 1 && !in_array($notificationId, $readIds)) {
            $unreadTaskProject++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total' => $unreadForms + $unreadTaskProject,
            'form' => $unreadForms,
            'task_project' => $unreadTaskProject,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 确保已读记录表存在，并清理超过 15 天的旧记录
 */
function ensureReadTableExists() {
    Db::execute("
        CREATE TABLE IF NOT EXISTS `notification_reads` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL COMMENT '用户ID',
          `notification_id` varchar(100) NOT NULL COMMENT '通知ID',
          `read_at` int(11) NOT NULL COMMENT '已读时间戳',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_notification` (`user_id`, `notification_id`),
          KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息已读记录'
    ");
    
    // 清理超过 15 天的旧记录（每次请求时执行，使用概率控制避免频繁清理）
    if (rand(1, 100) <= 5) { // 5% 概率执行清理
        $fifteenDaysAgo = time() - (15 * 24 * 60 * 60);
        try {
            $deleted = Db::execute("DELETE FROM notification_reads WHERE read_at < ?", [$fifteenDaysAgo]);
            if ($deleted > 0) {
                error_log("[desktop_notifications] 清理了 {$deleted} 条超过 15 天的通知记录");
            }
        } catch (Exception $e) {
            error_log("[desktop_notifications] 清理旧记录失败: " . $e->getMessage());
        }
    }
}

/**
 * 获取通知列表
 */
function handleGet($user) {
    // 确保表存在
    ensureReadTableExists();
    $isManager = in_array($user['role'], ['admin', 'super_admin', 'manager', 'tech_manager']);
    $notifications = [];
    
    // 时间筛选参数
    $filter = $_GET['filter'] ?? 'all';
    // 类型筛选参数：all, form, task, project
    $typeFilter = $_GET['type'] ?? 'all';
    // 自定义时间范围
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    // 1. 获取待处理的表单（需求表单和评价表单）
    $today = date('Y-m-d');
    $todayStart = strtotime($today . ' 00:00:00');
    $yesterdayStart = strtotime('-1 day', $todayStart);
    
    // 计算时间范围
    $timeStart = null;
    $timeEnd = null;
    if ($startDate && $endDate) {
        // 自定义时间范围
        $timeStart = strtotime($startDate . ' 00:00:00');
        $timeEnd = strtotime($endDate . ' 23:59:59');
    } elseif ($filter === 'today') {
        $timeStart = $todayStart;
        $timeEnd = null;
    } elseif ($filter === 'yesterday') {
        $timeStart = $yesterdayStart;
        $timeEnd = $todayStart;
    }
    
    // 默认只显示最近 15 天的通知
    $fifteenDaysAgo = strtotime('-15 days');
    
    // 获取用户已读的通知ID列表和已删除的通知ID列表
    $readNotifications = Db::query("SELECT notification_id FROM notification_reads WHERE user_id = ?", [$user['id']]);
    $readIds = array_column($readNotifications, 'notification_id');
    
    // 提取已删除的通知ID（以 deleted_ 开头的记录）
    $deletedIds = [];
    foreach ($readIds as $id) {
        if (strpos($id, 'deleted_') === 0) {
            $deletedIds[] = substr($id, 8); // 去掉 'deleted_' 前缀
        }
    }
    
    $unreadCount = 0;
    
    // 只有当类型筛选不是 task/project 时才查询表单
    if ($typeFilter !== 'task' && $typeFilter !== 'project') {
        // 构建查询条件 - 获取所有待处理或沟通中的表单
        $conditions = ["(fi.requirement_status IS NULL OR fi.requirement_status IN ('pending', 'communicating'))"];
        $params = [];
        
        // 排除已删除的项目
        $conditions[] = "(p.deleted_at IS NULL)";
        
        $conditions[] = "fi.create_time >= ?";
        $params[] = $fifteenDaysAgo;
        
        // 根据时间筛选（使用统一的 timeStart/timeEnd）
        if ($timeStart !== null) {
            $conditions[] = "fi.create_time >= ?";
            $params[] = $timeStart;
        }
        if ($timeEnd !== null) {
            $conditions[] = "fi.create_time < ?";
            $params[] = $timeEnd;
        }
        
        // 非管理员只能看到自己项目的表单
        if (!$isManager) {
            $conditions[] = "EXISTS (SELECT 1 FROM project_tech_assignments pta WHERE pta.project_id = fi.project_id AND pta.tech_user_id = ?)";
            $params[] = $user['id'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $sql = "
            SELECT 
                fi.id,
                fi.instance_name,
                fi.create_time,
                fi.requirement_status,
                ft.form_type,
                ft.name as template_name,
                p.project_name,
                p.project_code,
                c.name as customer_name
            FROM form_instances fi
            LEFT JOIN form_templates ft ON fi.template_id = ft.id
            LEFT JOIN projects p ON fi.project_id = p.id
            LEFT JOIN customers c ON p.customer_id = c.id
            WHERE {$whereClause}
            ORDER BY fi.create_time DESC
            LIMIT 20
        ";
        
        $newForms = Db::query($sql, $params);
        
        foreach ($newForms as $form) {
            $formType = $form['form_type'] === 'requirement' ? '需求表单' : '评价表单';
            $statusLabel = '待沟通';
            if ($form['requirement_status'] === 'communicating') {
                $statusLabel = '沟通中';
            } elseif ($form['requirement_status'] === 'confirmed') {
                $statusLabel = '已确认';
            }
            
            // 判断是否是今日创建
            $isToday = $form['create_time'] >= $todayStart;
            $title = $isToday ? "新{$formType}" : "{$formType}待处理";
            
            $notificationId = 'form_' . $form['id'];
            
            // 跳过已删除的消息
            if (in_array($notificationId, $deletedIds)) {
                continue;
            }
            
            $isRead = in_array($notificationId, $readIds);
            if (!$isRead) $unreadCount++;
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'form',
                'form_type' => $form['form_type'],
                'title' => $title,
                'content' => "{$form['project_name']} - {$form['customer_name']}",
                'status' => $statusLabel,
                'time' => date('m-d H:i', $form['create_time']),
                'full_time' => date('Y-m-d H:i:s', $form['create_time']),
                'is_read' => $isRead,
                'data' => [
                    'form_id' => (int)$form['id'],
                    'project_code' => $form['project_code'],
                ]
            ];
        }
    } // 结束表单查询的 if 块
    
    // 2. 获取任务和项目通知（从 notifications 表）- 仅当类型筛选不是 form 时
    if ($typeFilter !== 'form') {
        $notifyConditions = ["n.user_id = ?"];
        $notifyParams = [$user['id']];
        
        // 15 天限制
        $notifyConditions[] = "n.create_time >= ?";
        $notifyParams[] = $fifteenDaysAgo;
        
        // 时间筛选（与表单使用相同逻辑）
        if ($timeStart !== null) {
            $notifyConditions[] = "n.create_time >= ?";
            $notifyParams[] = $timeStart;
        }
        if ($timeEnd !== null) {
            $notifyConditions[] = "n.create_time < ?";
            $notifyParams[] = $timeEnd;
        }
        
        // 类型筛选
        // 注意：project_assign 属于项目类型通知
        if ($typeFilter === 'task') {
            $notifyConditions[] = "n.type = 'task'";
        } elseif ($typeFilter === 'project') {
            $notifyConditions[] = "n.type IN ('project', 'project_assign')";
        } elseif ($typeFilter === 'system') {
            $notifyConditions[] = "n.type = 'system'";
        } else {
            $notifyConditions[] = "n.type IN ('task', 'project', 'project_assign', 'system')";
        }
        
        $notifyWhereClause = implode(' AND ', $notifyConditions);
        
        $taskProjectNotifications = Db::query("
            SELECT n.id, n.type, n.title, n.content, n.priority, n.related_type, n.related_id, n.is_read, n.create_time
            FROM notifications n
            WHERE {$notifyWhereClause}
            ORDER BY FIELD(n.priority, 'urgent', 'high', 'normal', 'low'), n.create_time DESC
            LIMIT 20
        ", $notifyParams);
        
        foreach ($taskProjectNotifications as $notify) {
            $notificationId = $notify['type'] . '_' . $notify['id'];
            
            // 跳过已删除的消息
            if (in_array($notificationId, $deletedIds)) {
                continue;
            }
            
            $isRead = $notify['is_read'] == 1 || in_array($notificationId, $readIds);
            if (!$isRead) $unreadCount++;
            
            // 将 project_assign 映射为 project 类型（前端统一显示）
            $displayType = ($notify['type'] === 'project_assign') ? 'project' : $notify['type'];
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => $displayType,
                'title' => $notify['title'],
                'content' => $notify['content'],
                'priority' => $notify['priority'] ?? 'normal',
                'time' => date('m-d H:i', $notify['create_time']),
                'full_time' => date('Y-m-d H:i:s', $notify['create_time']),
                'is_read' => $isRead,
                'data' => [
                    'related_type' => $notify['related_type'],
                    'related_id' => $notify['related_id']
                ]
            ];
        }
    }
    
    // 按时间排序（最新的在前）
    usort($notifications, function($a, $b) {
        return strcmp($b['time'], $a['time']);
    });
    
    // 限制返回数量
    $notifications = array_slice($notifications, 0, 30);
    
    // 3. 统计各类型数量
    $formCount = count(array_filter($notifications, fn($n) => $n['type'] === 'form'));
    $taskCount = count(array_filter($notifications, fn($n) => $n['type'] === 'task'));
    $projectCount = count(array_filter($notifications, fn($n) => $n['type'] === 'project'));
    
    echo json_encode([
        'success' => true,
        'data' => $notifications,
        'unread_count' => $unreadCount,
        'summary' => [
            'total' => count($notifications),
            'form' => $formCount,
            'task' => $taskCount,
            'project' => $projectCount
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 标记通知已读
 */
function handlePost($user) {
    ensureReadTableExists();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            $notificationId = $input['id'] ?? $input['notification_id'] ?? null;
            if (!$notificationId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少通知ID']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            // 插入已读记录（忽略重复）
            try {
                Db::execute("INSERT IGNORE INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, ?)", [
                    $user['id'],
                    $notificationId,
                    time()
                ]);
                echo json_encode(['success' => true, 'message' => '已标记为已读'], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("[desktop_notifications] 标记已读失败: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => '标记失败']], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'mark_all_read':
            // 标记所有未读通知为已读
            try {
                $notificationIds = $input['notification_ids'] ?? [];
                if (empty($notificationIds)) {
                    echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少通知ID列表']], JSON_UNESCAPED_UNICODE);
                    return;
                }
                
                $now = time();
                foreach ($notificationIds as $notificationId) {
                    Db::execute("INSERT IGNORE INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, ?)", [
                        $user['id'],
                        $notificationId,
                        $now
                    ]);
                }
                echo json_encode(['success' => true, 'message' => '已全部标记为已读', 'count' => count($notificationIds)], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("[desktop_notifications] 批量标记已读失败: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => '标记失败']], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'delete':
            // 删除单条消息（软删除 - 标记为已删除）
            $notificationId = $input['id'] ?? $input['notification_id'] ?? null;
            if (!$notificationId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少通知ID']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            try {
                // 使用特殊标记表示已删除
                Db::execute("INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE read_at = ?", [
                    $user['id'],
                    'deleted_' . $notificationId,
                    time(),
                    time()
                ]);
                echo json_encode(['success' => true, 'message' => '已删除'], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("[desktop_notifications] 删除通知失败: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => '删除失败']], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'batch_delete':
            // 批量删除消息
            $notificationIds = $input['notification_ids'] ?? [];
            if (empty($notificationIds)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_PARAMS', 'message' => '缺少通知ID列表']], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            try {
                $now = time();
                foreach ($notificationIds as $notificationId) {
                    Db::execute("INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE read_at = ?", [
                        $user['id'],
                        'deleted_' . $notificationId,
                        $now,
                        $now
                    ]);
                }
                echo json_encode(['success' => true, 'message' => '已批量删除', 'count' => count($notificationIds)], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("[desktop_notifications] 批量删除通知失败: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => ['code' => 'DB_ERROR', 'message' => '批量删除失败']], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_ACTION', 'message' => '无效的操作']], JSON_UNESCAPED_UNICODE);
    }
}
