<?php
require_once __DIR__ . '/../core/api_init.php';
// 保存敲定成交记录

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

// 需要登录
$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$customerId = intval($_POST['customer_id'] ?? 0);

if ($customerId === 0) {
    echo json_encode(['success' => false, 'message' => '客户ID无效']);
    exit;
}

// 权限检查
$customer = Db::queryOne('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在']);
    exit;
}

$hasPermission = false;
if (canOrAdmin(PermissionCode::DEAL_MANAGE) || canOrAdmin(PermissionCode::CUSTOMER_EDIT)) {
    $hasPermission = true;
} elseif (RoleCode::isDeptManagerRole($user['role']) && $customer['department_id'] == $user['department_id']) {
    $hasPermission = true;
} elseif ($customer['owner_user_id'] == $user['id']) {
    $hasPermission = true;
}

if (!$hasPermission) {
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

// 获取所有任务字段
$fields = [
    'payment_confirmed', 'payment_invoice', 'payment_stored', 'payment_reply',
    'notify_receipt', 'notify_schedule', 'notify_timeline', 'notify_group',
    'group_invite', 'group_intro',
    'collect_materials', 'collect_timeline', 'collect_photos',
    'handover_designer', 'handover_confirm',
    'report_progress', 'report_new', 'report_care',
    'care_message',
];

$data = [];
foreach ($fields as $field) {
    $data[$field] = isset($_POST[$field]) ? 1 : 0;
    // 保存每个任务的备注
    $noteField = 'note_' . $field;
    $data[$noteField] = trim($_POST[$noteField] ?? '');
}

// 保存其他待办事项
$data['other_notes'] = trim($_POST['other_notes'] ?? '');

$data['update_time'] = time();
$data['update_user_id'] = $user['id'];

try {
    // 检查是否已存在记录
    $existing = Db::queryOne('SELECT id FROM deal_record WHERE customer_id = :id', ['id' => $customerId]);
    
    if ($existing) {
        // 更新记录
        $setClauses = [];
        foreach ($fields as $field) {
            $setClauses[] = "$field = :$field";
            $setClauses[] = "note_$field = :note_$field";
        }
        $setClauses[] = "other_notes = :other_notes";
        $setClauses[] = "update_time = :update_time";
        $setClauses[] = "update_user_id = :update_user_id";
        
        $sql = 'UPDATE deal_record SET ' . implode(', ', $setClauses) . ' WHERE customer_id = :customer_id';
        $data['customer_id'] = $customerId;
        
        Db::execute($sql, $data);
    } else {
        // 插入新记录
        $data['customer_id'] = $customerId;
        $data['create_time'] = time();
        $data['create_user_id'] = $user['id'];
        
        $columns = array_keys($data);
        $placeholders = array_map(function($col) { return ":$col"; }, $columns);
        
        $sql = 'INSERT INTO deal_record (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        
        Db::execute($sql, $data);
    }
    
    // 更新客户的更新时间
    Db::execute('UPDATE customers SET update_time = :now WHERE id = :id', [
        'now' => time(),
        'id' => $customerId
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => '敲定成交记录保存成功！'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '保存失败: ' . $e->getMessage()
    ]);
}
