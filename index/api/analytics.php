<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 数据分析API
 * 提供数据统计和员工KPI查询
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

// 验证登录
auth_require();
$user = current_user();

// 获取请求参数
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$dateRange = $_POST['date_range'] ?? 'today';
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$departmentId = intval($_POST['department_id'] ?? 0);
$userId = intval($_POST['user_id'] ?? 0);

try {
    // 权限检查
    $permission = getAnalyticsPermission($user, $departmentId, $userId);
    if (!$permission['allowed']) {
        throw new Exception('无权限访问此数据');
    }
    
    // 获取时间范围
    $timeRange = getDateRange($dateRange, $startDate, $endDate);
    error_log("Analytics: Time range - " . json_encode($timeRange));
    error_log("Analytics: Permission - " . json_encode($permission));
    
    // 根据action执行不同操作
    switch ($action) {
        case 'get_stats':
            // 获取统计数据
            $data = getStatisticsData($permission, $timeRange);
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
            break;
            
        case 'get_employee_kpi':
            // 获取员工KPI数据
            $kpiData = getEmployeeKPI($permission, $timeRange);
            echo json_encode([
                'success' => true,
                'data' => $kpiData
            ]);
            break;
            
        default:
            throw new Exception('无效的操作');
    }
    
} catch (PDOException $e) {
    error_log("Analytics API PDO Error: " . $e->getMessage());
    error_log("SQL Error Info: " . print_r($e->errorInfo, true));
    echo json_encode([
        'success' => false,
        'message' => '数据库查询错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Analytics API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 权限检查函数
 */
function getAnalyticsPermission($user, $requestedDeptId, $requestedUserId) {
    // 管理员或有数据分析权限可以查看任何数据
    if (canOrAdmin(PermissionCode::ANALYTICS_VIEW) || canOrAdmin(PermissionCode::ALL_DATA_VIEW)) {
        return [
            'allowed' => true,
            'dept_id' => $requestedDeptId,
            'user_id' => $requestedUserId,
            'can_view_kpi' => true
        ];
    }
    
    // 部门管理员或有部门数据查看权限
    if (canOrAdmin(PermissionCode::DEPT_DATA_VIEW) || RoleCode::isDeptManagerRole($user['role'])) {
        // 部门管理员只能查看本部门数据
        if ($requestedDeptId != 0 && $requestedDeptId != $user['department_id']) {
            return ['allowed' => false];
        }
        
        // 如果指定了员工，检查是否是本部门员工
        if ($requestedUserId != 0) {
            $targetUser = Db::queryOne('SELECT department_id FROM users WHERE id = ?', [$requestedUserId]);
            if (!$targetUser || $targetUser['department_id'] != $user['department_id']) {
                return ['allowed' => false];
            }
        }
        
        return [
            'allowed' => true,
            'dept_id' => $user['department_id'],
            'user_id' => $requestedUserId,
            'can_view_kpi' => true
        ];
    }
    
    // 普通员工只能查看自己的数据
    return [
        'allowed' => true,
        'dept_id' => $user['department_id'],
        'user_id' => $user['id'],
        'can_view_kpi' => false
    ];
}

/**
 * 获取时间范围
 */
function getDateRange($dateRange, $startDate = null, $endDate = null) {
    $now = time();
    
    switch ($dateRange) {
        case 'today':
            $start = strtotime('today');
            $end = strtotime('tomorrow') - 1;
            break;
            
        case 'yesterday':
            $start = strtotime('yesterday');
            $end = strtotime('today') - 1;
            break;
            
        case 'week':
            $start = strtotime('monday this week');
            $end = $now;
            break;
            
        case 'month':
            $start = strtotime('first day of this month');
            $end = $now;
            break;
            
        case 'custom':
            if ($startDate && $endDate) {
                $start = strtotime($startDate . ' 00:00:00');
                $end = strtotime($endDate . ' 23:59:59');
            } else {
                $start = strtotime('today');
                $end = strtotime('tomorrow') - 1;
            }
            break;
            
        default:
            $start = strtotime('today');
            $end = strtotime('tomorrow') - 1;
    }
    
    return [
        'start' => $start,
        'end' => $end,
        'start_date' => date('Y-m-d', $start),
        'end_date' => date('Y-m-d', $end)
    ];
}

/**
 * 获取统计数据
 */
function getStatisticsData($permission, $timeRange) {
    $deptId = $permission['dept_id'];
    $userId = $permission['user_id'];
    $startTime = $timeRange['start'];
    $endTime = $timeRange['end'];
    
    // 每日新建客户统计
    $dailyNewCustomers = getDailyNewCustomers($deptId, $userId, $startTime, $endTime);
    
    // 每日更新客户统计
    $dailyUpdatedCustomers = getDailyUpdatedCustomers($deptId, $userId, $startTime, $endTime);
    
    // 首通数据统计
    $firstContactStats = getFirstContactStats($deptId, $userId, $startTime, $endTime);
    
    // 汇总数据
    $summary = getSummaryData($deptId, $userId, $startTime, $endTime);
    
    return [
        'daily_new_customers' => $dailyNewCustomers,
        'daily_updated_customers' => $dailyUpdatedCustomers,
        'first_contact_stats' => $firstContactStats,
        'summary' => $summary
    ];
}

/**
 * 每日新建客户统计
 */
function getDailyNewCustomers($deptId, $userId, $startTime, $endTime) {
    $where = [];
    $params = [
        'start_time' => $startTime,
        'end_time' => $endTime
    ];
    
    $where[] = "create_time >= :start_time";
    $where[] = "create_time <= :end_time";
    
    if ($userId > 0) {
        $where[] = "create_user_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    if ($deptId > 0) {
        $where[] = "department_id = :dept_id";
        $params['dept_id'] = $deptId;
    }
    
    $sql = "SELECT 
        DATE(FROM_UNIXTIME(create_time)) as date,
        COUNT(*) as count
    FROM customers
    WHERE " . implode(' AND ', $where) . "
    GROUP BY DATE(FROM_UNIXTIME(create_time))
    ORDER BY date ASC";
    
    return Db::query($sql, $params);
}

/**
 * 每日更新客户统计
 */
function getDailyUpdatedCustomers($deptId, $userId, $startTime, $endTime) {
    $where = [];
    $params = [
        'start_time' => $startTime,
        'end_time' => $endTime
    ];
    
    $where[] = "update_time >= :start_time";
    $where[] = "update_time <= :end_time";
    $where[] = "update_time > create_time";
    
    if ($userId > 0) {
        $where[] = "create_user_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    if ($deptId > 0) {
        $where[] = "department_id = :dept_id";
        $params['dept_id'] = $deptId;
    }
    
    $sql = "SELECT 
        DATE(FROM_UNIXTIME(update_time)) as date,
        COUNT(DISTINCT id) as count
    FROM customers
    WHERE " . implode(' AND ', $where) . "
    GROUP BY DATE(FROM_UNIXTIME(update_time))
    ORDER BY date ASC";
    
    return Db::query($sql, $params);
}

/**
 * 首通数据统计
 */
function getFirstContactStats($deptId, $userId, $startTime, $endTime) {
    $where = [];
    $params = [
        'start_time' => $startTime,
        'end_time' => $endTime
    ];
    
    $where[] = "fc.create_time >= :start_time";
    $where[] = "fc.create_time <= :end_time";
    
    if ($userId > 0) {
        $where[] = "c.create_user_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    if ($deptId > 0) {
        $where[] = "c.department_id = :dept_id";
        $params['dept_id'] = $deptId;
    }
    
    // 首通总体统计
    $sql = "SELECT 
        COUNT(*) as total_first_contacts,
        SUM(CASE WHEN next_follow_time IS NOT NULL AND next_follow_time < UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as completed_first_contacts,
        SUM(CASE WHEN next_follow_time IS NULL OR next_follow_time >= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as pending_first_contacts
    FROM first_contact fc
    JOIN customers c ON fc.customer_id = c.id
    WHERE " . implode(' AND ', $where);
    
    $stats = Db::queryOne($sql, $params);
    
    // 每日首通数量
    $dailySql = "SELECT 
        DATE(FROM_UNIXTIME(fc.create_time)) as date,
        COUNT(*) as count
    FROM first_contact fc
    JOIN customers c ON fc.customer_id = c.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY DATE(FROM_UNIXTIME(fc.create_time))
    ORDER BY date ASC";
    
    $dailyStats = Db::query($dailySql, $params);
    
    return [
        'total_first_contacts' => intval($stats['total_first_contacts'] ?? 0),
        'completed_first_contacts' => intval($stats['completed_first_contacts'] ?? 0),
        'pending_first_contacts' => intval($stats['pending_first_contacts'] ?? 0),
        'daily_first_contacts' => $dailyStats
    ];
}

/**
 * 汇总数据
 */
function getSummaryData($deptId, $userId, $startTime, $endTime) {
    $where = [];
    $params = [
        'start_time' => $startTime,
        'end_time' => $endTime
    ];
    
    if ($userId > 0) {
        $where[] = "c.create_user_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    if ($deptId > 0) {
        $where[] = "c.department_id = :dept_id";
        $params['dept_id'] = $deptId;
    }
    
    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT 
        COUNT(DISTINCT c.id) as total_customers,
        COUNT(DISTINCT CASE WHEN c.create_time >= :start_time AND c.create_time <= :end_time THEN c.id END) as new_this_period,
        COUNT(DISTINCT CASE WHEN c.update_time >= :start_time AND c.update_time <= :end_time AND c.update_time > c.create_time THEN c.id END) as updated_this_period
    FROM customers c
    $whereClause";
    
    $summary = Db::queryOne($sql, $params);
    
    // 首通数量
    $fcWhere = [];
    $fcParams = [
        'start_time' => $startTime,
        'end_time' => $endTime
    ];
    
    $fcWhere[] = "fc.create_time >= :start_time";
    $fcWhere[] = "fc.create_time <= :end_time";
    
    if ($userId > 0) {
        $fcWhere[] = "c.create_user_id = :user_id";
        $fcParams['user_id'] = $userId;
    }
    
    if ($deptId > 0) {
        $fcWhere[] = "c.department_id = :dept_id";
        $fcParams['dept_id'] = $deptId;
    }
    
    $fcSql = "SELECT COUNT(*) as first_contact_this_period
    FROM first_contact fc
    JOIN customers c ON fc.customer_id = c.id
    WHERE " . implode(' AND ', $fcWhere);
    
    $fcCount = Db::queryOne($fcSql, $fcParams);
    
    return [
        'total_customers' => intval($summary['total_customers'] ?? 0),
        'new_this_period' => intval($summary['new_this_period'] ?? 0),
        'updated_this_period' => intval($summary['updated_this_period'] ?? 0),
        'first_contact_this_period' => intval($fcCount['first_contact_this_period'] ?? 0)
    ];
}

/**
 * 获取员工KPI数据（仅管理员和部门管理员可见）
 * 统计每个员工填写的字段数量和记录数量
 */
function getEmployeeKPI($permission, $timeRange) {
    if (!$permission['can_view_kpi']) {
        return [];
    }
    
    $deptId = $permission['dept_id'];
    $startTime = intval($timeRange['start']);
    $endTime = intval($timeRange['end']);
    
    // 获取所有活跃用户（包括管理员，因为管理员也可能创建客户）
    $userSql = "SELECT u.id as user_id, u.realname as user_name, u.department_id, d.name as department_name
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.status = 1";
    
    $userParams = [];
    if ($deptId > 0) {
        $userSql .= " AND u.department_id = :dept_id";
        $userParams['dept_id'] = $deptId;
    }
    
    $users = Db::query($userSql, $userParams);
    
    $employees = [];
    foreach ($users as $user) {
        $userId = $user['user_id'];
        
        // 首通模块统计
        $firstContactStats = getFirstContactFieldStats($userId, $startTime, $endTime);
        
        // 异议处理模块统计
        $objectionStats = getObjectionFieldStats($userId, $startTime, $endTime);
        
        // 成交模块统计
        $dealStats = getDealFieldStats($userId, $startTime, $endTime);
        
        // 自评模块统计
        $evaluationStats = getEvaluationFieldStats($userId, $startTime, $endTime);
        
        // 汇总
        $totalFields = $firstContactStats['fields'] + 
                      $objectionStats['fields'] + 
                      $dealStats['fields'] + 
                      $evaluationStats['fields'];
        
        $totalRecords = $firstContactStats['records'] + 
                       $objectionStats['records'] + 
                       $dealStats['records'] + 
                       $evaluationStats['records'];
        
        $employees[] = [
            'user_id' => $userId,
            'user_name' => $user['user_name'],
            'department_id' => $user['department_id'],
            'department_name' => $user['department_name'],
            'firstcontact_fields' => $firstContactStats['fields'],
            'firstcontact_records' => $firstContactStats['records'],
            'objection_fields' => $objectionStats['fields'],
            'objection_records' => $objectionStats['records'],
            'deal_fields' => $dealStats['fields'],
            'deal_records' => $dealStats['records'],
            'evaluation_fields' => $evaluationStats['fields'],
            'evaluation_records' => $evaluationStats['records'],
            'total_fields' => $totalFields,
            'total_records' => $totalRecords,
            'total_score' => $totalFields + $totalRecords
        ];
    }
    
    // 按总分降序排序
    usort($employees, function($a, $b) {
        return $b['total_score'] - $a['total_score'];
    });
    
    // 添加排名
    $rank = 1;
    foreach ($employees as &$emp) {
        $emp['rank'] = $rank++;
    }
    
    return $employees;
}

/**
 * 统计首通模块的字段数和记录数
 */
function getFirstContactFieldStats($userId, $startTime, $endTime) {
    $sql = "SELECT 
        COUNT(*) as record_count,
        SUM(CASE WHEN demand_time_type IS NOT NULL AND demand_time_type != '' THEN 1 ELSE 0 END) as field_demand_time,
        SUM(CASE WHEN key_questions IS NOT NULL AND key_questions != '' THEN 1 ELSE 0 END) as field_questions,
        SUM(CASE WHEN key_messages IS NOT NULL AND key_messages != '' THEN 1 ELSE 0 END) as field_messages,
        SUM(CASE WHEN materials_to_send IS NOT NULL AND materials_to_send != '' THEN 1 ELSE 0 END) as field_materials,
        SUM(CASE WHEN helpers IS NOT NULL AND helpers != '' THEN 1 ELSE 0 END) as field_helpers,
        SUM(CASE WHEN next_follow_time IS NOT NULL THEN 1 ELSE 0 END) as field_follow_time,
        SUM(CASE WHEN remark IS NOT NULL AND remark != '' THEN 1 ELSE 0 END) as field_remark
    FROM first_contact fc
    JOIN customers c ON fc.customer_id = c.id
    WHERE c.create_user_id = $userId
    AND fc.create_time >= $startTime 
    AND fc.create_time <= $endTime";
    
    $result = Db::queryOne($sql);
    
    $fieldCount = intval($result['field_demand_time'] ?? 0) +
                 intval($result['field_questions'] ?? 0) +
                 intval($result['field_messages'] ?? 0) +
                 intval($result['field_materials'] ?? 0) +
                 intval($result['field_helpers'] ?? 0) +
                 intval($result['field_follow_time'] ?? 0) +
                 intval($result['field_remark'] ?? 0);
    
    return [
        'fields' => $fieldCount,
        'records' => intval($result['record_count'] ?? 0)
    ];
}

/**
 * 统计异议处理模块的字段数和记录数
 */
function getObjectionFieldStats($userId, $startTime, $endTime) {
    $sql = "SELECT 
        COUNT(*) as record_count,
        SUM(CASE WHEN method IS NOT NULL AND method != '' THEN 1 ELSE 0 END) as field_method,
        SUM(CASE WHEN objection_content IS NOT NULL AND objection_content != '' THEN 1 ELSE 0 END) as field_content,
        SUM(CASE WHEN response_script IS NOT NULL AND response_script != '' THEN 1 ELSE 0 END) as field_script,
        SUM(CASE WHEN result IS NOT NULL AND result != '' THEN 1 ELSE 0 END) as field_result
    FROM objection obj
    JOIN customers c ON obj.customer_id = c.id
    WHERE c.create_user_id = $userId
    AND obj.create_time >= $startTime 
    AND obj.create_time <= $endTime";
    
    $result = Db::queryOne($sql);
    
    $fieldCount = intval($result['field_method'] ?? 0) +
                 intval($result['field_content'] ?? 0) +
                 intval($result['field_script'] ?? 0) +
                 intval($result['field_result'] ?? 0);
    
    return [
        'fields' => $fieldCount,
        'records' => intval($result['record_count'] ?? 0)
    ];
}

/**
 * 统计成交模块的字段数（勾选项）和记录数
 */
function getDealFieldStats($userId, $startTime, $endTime) {
    // 成交模块有20多个勾选项，勾选了（=1）才算
    $sql = "SELECT 
        COUNT(*) as record_count,
        SUM(payment_confirmed) as f1,
        SUM(payment_invoice) as f2,
        SUM(payment_stored) as f3,
        SUM(payment_reply) as f4,
        SUM(notify_receipt) as f5,
        SUM(notify_schedule) as f6,
        SUM(notify_timeline) as f7,
        SUM(notify_group) as f8,
        SUM(group_invite) as f9,
        SUM(group_intro) as f10,
        SUM(collect_materials) as f11,
        SUM(collect_timeline) as f12,
        SUM(collect_photos) as f13,
        SUM(handover_designer) as f14,
        SUM(handover_confirm) as f15,
        SUM(report_progress) as f16,
        SUM(report_new) as f17,
        SUM(report_care) as f18,
        SUM(care_message) as f19,
        SUM(CASE WHEN other_notes IS NOT NULL AND other_notes != '' THEN 1 ELSE 0 END) as f20
    FROM deal_record dr
    JOIN customers c ON dr.customer_id = c.id
    WHERE c.create_user_id = $userId
    AND dr.create_time >= $startTime 
    AND dr.create_time <= $endTime";
    
    $result = Db::queryOne($sql);
    
    $fieldCount = 0;
    for ($i = 1; $i <= 20; $i++) {
        $fieldCount += intval($result['f' . $i] ?? 0);
    }
    
    return [
        'fields' => $fieldCount,
        'records' => intval($result['record_count'] ?? 0)
    ];
}

/**
 * 统计自评模块的字段数和记录数
 */
function getEvaluationFieldStats($userId, $startTime, $endTime) {
    $sql = "SELECT 
        COUNT(*) as record_count,
        SUM(CASE WHEN evaluation_data IS NOT NULL AND evaluation_data != '' THEN 1 ELSE 0 END) as field_data,
        SUM(CASE WHEN total_score IS NOT NULL THEN 1 ELSE 0 END) as field_score,
        SUM(CASE WHEN summary IS NOT NULL AND summary != '' THEN 1 ELSE 0 END) as field_summary
    FROM self_evaluation se
    JOIN customers c ON se.customer_id = c.id
    WHERE c.create_user_id = $userId
    AND se.create_time >= $startTime 
    AND se.create_time <= $endTime";
    
    $result = Db::queryOne($sql);
    
    $fieldCount = intval($result['field_data'] ?? 0) +
                 intval($result['field_score'] ?? 0) +
                 intval($result['field_summary'] ?? 0);
    
    return [
        'fields' => $fieldCount,
        'records' => intval($result['record_count'] ?? 0)
    ];
}
