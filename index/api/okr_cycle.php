<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * OKR 周期管理 API
 * 提供周期的增删改查功能
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 检查登录
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$user = current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 检查并修复 type 列（如果不存在）
checkAndFixTypeColumn();

try {
    switch ($action) {
        case 'list':
            getCycleList();
            break;
            
        case 'get':
            getCycle();
            break;
            
        case 'save':
            saveCycle();
            break;
            
        case 'delete':
            deleteCycle();
            break;
            
        case 'set_current':
            setCurrentCycle();
            break;
            
        default:
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 检查并修复 type 列（如果不存在）
 */
function checkAndFixTypeColumn() {
    try {
        $checkSql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'okr_cycles' 
                     AND COLUMN_NAME = 'type'";
        $result = Db::queryOne($checkSql);
        
        if (!$result || $result['cnt'] == 0) {
            // 列不存在，添加它
            Db::execute("ALTER TABLE okr_cycles 
                         ADD COLUMN type varchar(20) NOT NULL DEFAULT 'month' 
                         COMMENT '类型：week/2week/month/quarter/4month/half_year/year/custom' 
                         AFTER name");
            
            // 如果表中有数据，根据日期范围自动推断并更新 type
            Db::execute("UPDATE okr_cycles 
                         SET type = CASE
                             WHEN DATEDIFF(end_date, start_date) <= 7 THEN 'week'
                             WHEN DATEDIFF(end_date, start_date) <= 14 THEN '2week'
                             WHEN DATEDIFF(end_date, start_date) <= 35 THEN 'month'
                             WHEN DATEDIFF(end_date, start_date) <= 100 THEN 'quarter'
                             WHEN DATEDIFF(end_date, start_date) <= 130 THEN '4month'
                             WHEN DATEDIFF(end_date, start_date) <= 200 THEN 'half_year'
                             WHEN DATEDIFF(end_date, start_date) <= 400 THEN 'year'
                             ELSE 'custom'
                         END");
        }
    } catch (Exception $e) {
        // 如果修复失败，记录错误但不中断流程
        error_log("Failed to check/fix type column: " . $e->getMessage());
    }
}

/**
 * 获取周期列表
 */
function getCycleList() {
    global $user;
    
    $status = $_GET['status'] ?? null; // null=全部, 1=启用, 0=归档
    
    $sql = "SELECT * FROM okr_cycles WHERE 1=1";
    $params = [];
    
    if ($status !== null) {
        $sql .= " AND status = :status";
        $params['status'] = intval($status);
    }
    
    $sql .= " ORDER BY start_date DESC, id DESC";
    
    $cycles = Db::query($sql, $params);
    
    // 计算剩余天数并确保 type 字段存在
    foreach ($cycles as &$cycle) {
        // 如果 type 字段不存在，根据日期范围推断
        if (!isset($cycle['type']) || empty($cycle['type'])) {
            $startDate = new DateTime($cycle['start_date']);
            $endDate = new DateTime($cycle['end_date']);
            $daysDiff = $startDate->diff($endDate)->days;
            
            if ($daysDiff <= 7) {
                $cycle['type'] = 'week';
            } elseif ($daysDiff <= 14) {
                $cycle['type'] = '2week';
            } elseif ($daysDiff <= 35) {
                $cycle['type'] = 'month';
            } elseif ($daysDiff <= 100) {
                $cycle['type'] = 'quarter';
            } elseif ($daysDiff <= 130) {
                $cycle['type'] = '4month';
            } elseif ($daysDiff <= 200) {
                $cycle['type'] = 'half_year';
            } elseif ($daysDiff <= 400) {
                $cycle['type'] = 'year';
            } else {
                $cycle['type'] = 'custom';
            }
        }
        
        // 计算剩余天数（只比较日期，不考虑时间）
        $endDate = new DateTime($cycle['end_date']);
        $endDate->setTime(0, 0, 0);
        $today = new DateTime('today');
        $diff = $today->diff($endDate);
        $daysLeft = $diff->days;
        // 如果结束日期在过去，返回负数；如果结束日期在未来，返回正数
        if ($endDate < $today) {
            $daysLeft = -$daysLeft;
        }
        $cycle['days_left'] = $daysLeft;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $cycles
    ]);
}

/**
 * 获取单个周期
 */
function getCycle() {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('周期ID无效');
    }
    
    $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $id]);
    if (!$cycle) {
        throw new Exception('周期不存在');
    }
    
    // 如果 type 字段不存在，根据日期范围推断
    if (!isset($cycle['type']) || empty($cycle['type'])) {
        $startDate = new DateTime($cycle['start_date']);
        $endDate = new DateTime($cycle['end_date']);
        $daysDiff = $startDate->diff($endDate)->days;
        
        if ($daysDiff <= 7) {
            $cycle['type'] = 'week';
        } elseif ($daysDiff <= 14) {
            $cycle['type'] = '2week';
        } elseif ($daysDiff <= 35) {
            $cycle['type'] = 'month';
        } elseif ($daysDiff <= 100) {
            $cycle['type'] = 'quarter';
        } elseif ($daysDiff <= 130) {
            $cycle['type'] = '4month';
        } elseif ($daysDiff <= 200) {
            $cycle['type'] = 'half_year';
        } elseif ($daysDiff <= 400) {
            $cycle['type'] = 'year';
        } else {
            $cycle['type'] = 'custom';
        }
    }
    
    // 计算剩余天数（只比较日期，不考虑时间）
    $endDate = new DateTime($cycle['end_date']);
    $endDate->setTime(0, 0, 0);
    $today = new DateTime('today');
    $diff = $today->diff($endDate);
    $daysLeft = $diff->days;
    // 如果结束日期在过去，返回负数；如果结束日期在未来，返回正数
    if ($endDate < $today) {
        $daysLeft = -$daysLeft;
    }
    $cycle['days_left'] = $daysLeft;
    
    echo json_encode([
        'success' => true,
        'data' => $cycle
    ]);
}

/**
 * 保存周期（新增或更新）
 */
function saveCycle() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? 'month');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $status = intval($_POST['status'] ?? 1);
    
    if (empty($name)) {
        throw new Exception('周期名称不能为空');
    }
    
    if (empty($startDate) || empty($endDate)) {
        throw new Exception('开始日期和结束日期不能为空');
    }
    
    // 验证日期格式
    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end) {
        throw new Exception('日期格式错误');
    }
    
    if ($start > $end) {
        throw new Exception('开始日期不能晚于结束日期');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $id]);
        if (!$cycle) {
            throw new Exception('周期不存在');
        }
        
        Db::execute(
            'UPDATE okr_cycles SET name = :name, type = :type, start_date = :start_date, end_date = :end_date, status = :status, update_time = :update_time WHERE id = :id',
            [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'update_time' => $now
            ]
        );
    } else {
        // 新增
        Db::execute(
            'INSERT INTO okr_cycles (name, type, start_date, end_date, status, create_user_id, create_time, update_time) VALUES (:name, :type, :start_date, :end_date, :status, :create_user_id, :create_time, :update_time)',
            [
                'name' => $name,
                'type' => $type,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'create_user_id' => $user['id'],
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
    }
    
    $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $id]);
    
    // 计算剩余天数
    if ($cycle) {
        $endDate = new DateTime($cycle['end_date']);
        $endDate->setTime(0, 0, 0);
        $today = new DateTime('today');
        $diff = $today->diff($endDate);
        $daysLeft = $diff->days;
        if ($endDate < $today) {
            $daysLeft = -$daysLeft;
        }
        $cycle['days_left'] = $daysLeft;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $cycle,
        'message' => $id > 0 ? '周期保存成功' : '周期创建成功'
    ]);
}

/**
 * 删除周期
 */
function deleteCycle() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('周期ID无效');
    }
    
    $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $id]);
    if (!$cycle) {
        throw new Exception('周期不存在');
    }
    
    // 检查是否有 OKR 容器使用此周期
    $count = Db::queryOne('SELECT COUNT(*) as cnt FROM okr_containers WHERE cycle_id = :cycle_id', ['cycle_id' => $id]);
    if ($count && $count['cnt'] > 0) {
        throw new Exception('该周期下存在 OKR，无法删除');
    }
    
    Db::execute('DELETE FROM okr_cycles WHERE id = :id', ['id' => $id]);
    
    echo json_encode([
        'success' => true,
        'message' => '周期删除成功'
    ]);
}

/**
 * 设置当前周期（前端使用，不存储到数据库）
 */
function setCurrentCycle() {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('周期ID无效');
    }
    
    $cycle = Db::queryOne('SELECT * FROM okr_cycles WHERE id = :id', ['id' => $id]);
    if (!$cycle) {
        throw new Exception('周期不存在');
    }
    
    // 如果 type 字段不存在，根据日期范围推断
    if (!isset($cycle['type']) || empty($cycle['type'])) {
        $startDate = new DateTime($cycle['start_date']);
        $endDate = new DateTime($cycle['end_date']);
        $daysDiff = $startDate->diff($endDate)->days;
        
        if ($daysDiff <= 7) {
            $cycle['type'] = 'week';
        } elseif ($daysDiff <= 14) {
            $cycle['type'] = '2week';
        } elseif ($daysDiff <= 35) {
            $cycle['type'] = 'month';
        } elseif ($daysDiff <= 100) {
            $cycle['type'] = 'quarter';
        } elseif ($daysDiff <= 130) {
            $cycle['type'] = '4month';
        } elseif ($daysDiff <= 200) {
            $cycle['type'] = 'half_year';
        } elseif ($daysDiff <= 400) {
            $cycle['type'] = 'year';
        } else {
            $cycle['type'] = 'custom';
        }
    }
    
    // 计算剩余天数
    $endDate = new DateTime($cycle['end_date']);
    $endDate->setTime(0, 0, 0);
    $today = new DateTime('today');
    $diff = $today->diff($endDate);
    $daysLeft = $diff->days;
    if ($endDate < $today) {
        $daysLeft = -$daysLeft;
    }
    $cycle['days_left'] = $daysLeft;
    
    // 这里只是验证周期存在，实际当前周期由前端 localStorage 管理
    echo json_encode([
        'success' => true,
        'data' => $cycle,
        'message' => '当前周期已切换'
    ]);
}

