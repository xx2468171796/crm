<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 系统字典 API
 * 支持：列表、新增、编辑、删除、排序、启用/禁用
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$user = current_user();
if (!canOrAdmin(PermissionCode::FIELD_MANAGE)) {
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

// 确保表存在
ensureDictTableExists();

$action = $_REQUEST['action'] ?? 'list';
$dictType = trim($_REQUEST['dict_type'] ?? 'payment_method');

switch ($action) {
    case 'list':
        handleList($dictType);
        break;
    case 'save':
        handleSave($dictType);
        break;
    case 'delete':
        handleDelete();
        break;
    case 'toggle':
        handleToggle();
        break;
    case 'reorder':
        handleReorder();
        break;
    case 'options':
        handleOptions($dictType);
        break;
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
}

function ensureDictTableExists() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    try {
        Db::queryOne('SELECT 1 FROM system_dict LIMIT 1');
    } catch (Exception $e) {
        // 表不存在，创建
        $pdo = Db::pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_dict (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                dict_type VARCHAR(50) NOT NULL COMMENT '字典类型',
                dict_code VARCHAR(50) NOT NULL COMMENT '字典代码',
                dict_label VARCHAR(100) NOT NULL COMMENT '显示名称',
                sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
                is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
                create_time INT UNSIGNED DEFAULT NULL COMMENT '创建时间',
                update_time INT UNSIGNED DEFAULT NULL COMMENT '更新时间',
                UNIQUE KEY uk_type_code (dict_type, dict_code),
                KEY idx_type_enabled (dict_type, is_enabled, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统字典表'
        ");
        
        // 插入默认支付方式
        $now = time();
        $methods = [
            ['payment_method', 'cash', '现金', 1],
            ['payment_method', 'transfer', '转账', 2],
            ['payment_method', 'wechat', '微信', 3],
            ['payment_method', 'alipay', '支付宝', 4],
            ['payment_method', 'pos', 'POS', 5],
            ['payment_method', 'other', '其他', 99],
        ];
        
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        ");
        
        foreach ($methods as $m) {
            $stmt->execute([$m[0], $m[1], $m[2], $m[3], $now, $now]);
        }
    }
}

function handleList($dictType) {
    $rows = Db::query(
        'SELECT * FROM system_dict WHERE dict_type = ? ORDER BY sort_order ASC, id ASC',
        [$dictType]
    );
    echo json_encode(['success' => true, 'data' => $rows]);
}

function handleOptions($dictType) {
    $rows = Db::query(
        'SELECT dict_code AS value, dict_label AS label FROM system_dict WHERE dict_type = ? AND is_enabled = 1 ORDER BY sort_order ASC, id ASC',
        [$dictType]
    );
    echo json_encode(['success' => true, 'data' => $rows]);
}

function handleSave($dictType) {
    $id = intval($_POST['id'] ?? 0);
    $code = trim($_POST['dict_code'] ?? '');
    $label = trim($_POST['dict_label'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $isEnabled = intval($_POST['is_enabled'] ?? 1);
    
    // 手续费配置（仅payment_method类型支持）
    $feeType = null;
    $feeValue = null;
    if ($dictType === 'payment_method') {
        $feeType = trim($_POST['fee_type'] ?? '');
        $feeValue = $_POST['fee_value'] ?? null;
        
        // 验证手续费类型
        if ($feeType !== '' && !in_array($feeType, ['fixed', 'percent'])) {
            $feeType = null;
        }
        if ($feeType === '') {
            $feeType = null;
        }
        
        // 验证手续费值
        if ($feeType !== null && $feeValue !== null && $feeValue !== '') {
            $feeValue = (float)$feeValue;
            if ($feeValue < 0) {
                $feeValue = 0;
            }
        } else {
            $feeValue = null;
        }
    }
    
    if ($code === '' || $label === '') {
        echo json_encode(['success' => false, 'message' => '代码和名称不能为空']);
        return;
    }
    
    // 检查代码是否重复
    $exists = Db::queryOne(
        'SELECT id FROM system_dict WHERE dict_type = ? AND dict_code = ? AND id != ?',
        [$dictType, $code, $id]
    );
    if ($exists) {
        echo json_encode(['success' => false, 'message' => '代码已存在']);
        return;
    }
    
    $now = time();
    
    if ($id > 0) {
        Db::execute(
            'UPDATE system_dict SET dict_code = ?, dict_label = ?, sort_order = ?, is_enabled = ?, fee_type = ?, fee_value = ?, update_time = ? WHERE id = ?',
            [$code, $label, $sortOrder, $isEnabled, $feeType, $feeValue, $now, $id]
        );
    } else {
        Db::execute(
            'INSERT INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, fee_type, fee_value, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$dictType, $code, $label, $sortOrder, $isEnabled, $feeType, $feeValue, $now, $now]
        );
        $id = (int)Db::lastInsertId();
    }
    
    echo json_encode(['success' => true, 'data' => ['id' => $id]]);
}

function handleDelete() {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        return;
    }
    
    Db::execute('DELETE FROM system_dict WHERE id = ?', [$id]);
    echo json_encode(['success' => true]);
}

function handleToggle() {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        return;
    }
    
    $row = Db::queryOne('SELECT is_enabled FROM system_dict WHERE id = ?', [$id]);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => '记录不存在']);
        return;
    }
    
    $newStatus = $row['is_enabled'] ? 0 : 1;
    Db::execute('UPDATE system_dict SET is_enabled = ?, update_time = ? WHERE id = ?', [$newStatus, time(), $id]);
    echo json_encode(['success' => true, 'data' => ['is_enabled' => $newStatus]]);
}

function handleReorder() {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        return;
    }
    
    $now = time();
    foreach ($ids as $order => $id) {
        Db::execute('UPDATE system_dict SET sort_order = ?, update_time = ? WHERE id = ?', [$order, $now, (int)$id]);
    }
    echo json_encode(['success' => true]);
}
