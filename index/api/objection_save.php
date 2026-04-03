<?php
require_once __DIR__ . '/../core/api_init.php';
// 保存异议处理记录

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 检查是否是外部访问（通过密码验证的）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerId = intval($_POST['customer_id'] ?? 0);
$isExternalEditable = false;

if ($customerId > 0 && isset($_SESSION['share_verified_' . $customerId]) && isset($_SESSION['share_editable_' . $customerId])) {
    // 外部访问但有编辑权限
    $isExternalEditable = true;
    $user = [
        'id' => 0,
        'username' => 'external',
        'role' => 'external',
        'department_id' => null
    ];
} else {
    // 内部用户需要登录
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => '请先登录',
            'redirect' => '/login.php'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 获取表单数据（只保存异议处理独有的内容）
$handlingMethods = isset($_POST['handling_methods']) ? implode('、', (array)$_POST['handling_methods']) : '';
$methodCustom = trim($_POST['method_custom'] ?? '');
$solution = trim($_POST['solution'] ?? '');

// 处理自定义字段
if ($methodCustom !== '') {
    $handlingMethods .= ($handlingMethods ? '、' : '') . $methodCustom;
}

if ($handlingMethods === '' && $solution === '') {
    echo json_encode([
        'success' => false,
        'message' => '请至少填写处理方法或话术方案'
    ]);
    exit;
}

$now = time();
$uid = $user['id'] ?? 0;

try {
    // 插入异议处理记录
    Db::execute('INSERT INTO objection
        (customer_id, method, response_script, create_time, update_time, create_user_id, update_user_id)
         VALUES
        (:customer_id, :method, :response_script, :create_time, :update_time, :create_user_id, :update_user_id)', [
        'customer_id'       => $customerId,
        'method'            => $handlingMethods,
        'response_script'   => $solution,
        'create_time'       => $now,
        'update_time'       => $now,
        'create_user_id'    => $uid,
        'update_user_id'    => $uid,
    ]);
    
    // 更新客户的更新时间
    Db::execute('UPDATE customers SET update_time = :now WHERE id = :id', [
        'now' => $now,
        'id' => $customerId
    ]);

    echo json_encode([
        'success' => true,
        'message' => '异议处理记录保存成功！',
        'redirect' => '/index.php?page=customer_detail&id=' . $customerId . '#tab-objection'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '保存失败: ' . $e->getMessage()
    ]);
}
